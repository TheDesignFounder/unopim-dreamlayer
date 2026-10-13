<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\AiAgent\Events\AgentSystemPromptBuilding;
use Webkul\MagicAI\Models\MagicAIPlatform;

/** Request-scoped virtual provider; never saves a duplicate credential in Magic AI. */
class ManagedChat
{
    public const ID = -900;

    public bool $active = false;

    private array $calls = [];

    private int $identityVersion = 2;

    public ?string $turn = null;

    public ?string $key = null;

    public ?string $endpoint = null;

    public function platform(): ?MagicAIPlatform
    {
        $s = Settings::current();
        if (! $s->connected() || ! $s->managed_chat) {
            return null;
        }
        $this->active = true;
        $this->turn = request()->input('request_id');
        abort_unless(Str::isUuid($this->turn), 422, 'A stable chat request identity is required.');
        $this->key = $s->credential;
        $this->endpoint = Endpoints::api();
        $owner = auth()->guard('admin')->id();
        abort_unless($owner, 403);
        DB::table('dreamlayer_chat_turns')->insertOrIgnore(['user_id' => $owner, 'request_id' => $this->turn, 'credential_version' => $s->credential_version, 'api_url' => $this->endpoint, 'identity_version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $pinned = DB::table('dreamlayer_chat_turns')->where('user_id', $owner)->where('request_id', $this->turn)->first();
        $this->identityVersion = (int) $pinned->identity_version;
        abort_unless($pinned->credential_version === $s->credential_version && $pinned->api_url === $this->endpoint, 409, 'This chat request belongs to the previous connection. Review its saved results before starting a new request.');
        $p = new MagicAIPlatform(['label' => 'DreamLayer', 'provider' => 'custom', 'api_url' => $this->endpoint, 'api_key' => $this->key, 'models' => 'dreamlayer-chat', 'status' => true, 'is_default' => true]);
        $p->id = self::ID;
        $p->extras = ['models' => ['text' => ['default' => 'dreamlayer-chat'], 'embeddings' => ['default' => 'dreamlayer-embeddings']]];

        return $p;
    }

    public function pinPrompt(AgentSystemPromptBuilding $event): void
    {
        // Catalog context can change while a request is retried. Reuse the original
        // system prompt and reject changed user inputs under the same turn identity.
        if (! $this->active) {
            return;
        }
        $c = $event->context;
        $fingerprint = hash('sha256', json_encode([$c->message, $c->history, $c->extensions, $c->channel, $c->locale, $c->productId, $c->productSku, $c->currentPage]));
        DB::transaction(function () use ($event, $c, $fingerprint) {
            $q = DB::table('dreamlayer_chat_turns')->where('user_id', $c->user->id)->where('request_id', $c->requestId);
            $row = (clone $q)->lockForUpdate()->first();
            if ($row->system_prompt !== null) {
                abort_unless(hash_equals($row->input_fingerprint, $fingerprint), 409, 'Chat retry input changed. Open the saved result or start a new message.');
                $event->prompt = $row->system_prompt;
            } else {
                $q->update(['system_prompt' => $event->prompt, 'input_fingerprint' => $fingerprint]);
            }
        });
    }

    public function headers(string $body): array
    {
        $data = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        unset($data['stream'],$data['stream_options']);
        $sort = function (&$v) use (&$sort) {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v);
                }foreach ($v as &$x) {
                    $sort($x);
                }
            }
        };
        $sort($data);
        $digest = hash('sha256', json_encode($data));
        $this->calls[$digest] ??= count($this->calls);

        // Replayed tool receipts skip nested model calls. New turns therefore use
        // content identity rather than execution order. Retain legacy identities
        // for already submitted turns; an upgrade must not resubmit paid work.
        $identity = $this->identityVersion >= 2 ? $digest : $this->calls[$digest];

        return ['DreamLayer-Version' => '1', 'DreamLayer-Turn' => $this->turn, 'Idempotency-Key' => 'unopim-chat-'.$this->turn.'-'.$identity];
    }

    public function transport(callable $handler): callable
    {
        return function ($request, array $options) use ($handler) {
            if (! $this->active || $request->getHeaderLine('Authorization') !== 'Bearer '.$this->key) {
                return $handler($request, $options);
            }
            if (! str_starts_with((string) $request->getUri(), $this->endpoint.'/')) {
                throw new \RuntimeException('DreamLayer credential destination mismatch.');
            }
            $request = $this->middleware($request);
            $options['allow_redirects'] = false;

            // Reject inside the redirect handler as well: it must never resend this credential.
            return $handler($request, $options)->then(function ($response) {
                if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                    throw new \RuntimeException('DreamLayer redirects are not permitted.');
                }

                return $response;
            });
        };
    }

    public function middleware($request)
    {
        if (! $this->active) {
            return $request;
        }
        $url = (string) $request->getUri();
        if (! in_array($url, [$this->endpoint.'/chat/completions', $this->endpoint.'/embeddings'], true)) {
            return $request;
        }
        foreach ($this->headers((string) $request->getBody()) as $k => $v) {
            $request = $request->withHeader($k, $v);
        }

        return $request;
    }
}
