<?php

namespace DreamLayer\UnoPIM\Http\Controllers;

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class StudioController extends Controller
{
    private function user(): int
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);

        return auth()->guard('admin')->id();
    }

    public function config(Request $request)
    {
        $user = $this->user();
        $settings = Settings::current();
        $data = [
            'connected' => $settings->connected(), 'linked' => filled($settings->credential),
            'permissions' => ['settings' => bouncer()->hasPermission('dreamlayer'), 'upload' => bouncer()->hasPermission('dreamlayer_results.studio_upload') && bouncer()->hasPermission('dreamlayer_results.studio_upload_image'), 'feedback' => bouncer()->hasPermission('dreamlayer_results.feedback'), 'apply' => bouncer()->hasPermission('dreamlayer_results.apply')], 'history' => $settings->save_chat_history,
            'assistance' => $settings->managed_chat && $settings->privacy_consent_version >= 1,
            'sessionKey' => hash_hmac('sha256', $user.'|'.$request->session()->getId(), config('app.key')),
            'csrf' => csrf_token(), 'channel' => core()->getRequestedChannelCode(), 'locale' => core()->getRequestedLocaleCode(),
            'maxUploadBytes' => config('dreamlayer.max_image_bytes'),
            'base' => url(config('app.admin_url').'/dreamlayer'),
            'labels' => array_merge(trans('dreamlayer::app'), trans('dreamlayer::studio')),
        ];

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function page(Request $request)
    {
        $config = $this->config($request)->getData(true);
        if (! $request->expectsJson() && $request->header('Sec-Fetch-Dest') !== 'iframe') {
            return redirect()->route('dreamlayer.choose');
        }

        return response()->view('dreamlayer::studio.index', compact('config'))
            ->header('Cache-Control', 'private, no-store')->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function upload(Request $request)
    {
        $owner = $this->user();
        abort_unless(Settings::current()->connected(), 422, trans('dreamlayer::app.editor-entry-setup'));
        $request->validate(['conversation_id' => 'required|uuid', 'image' => 'required|file|mimes:png,jpg,jpeg,webp|max:'.(int) (config('dreamlayer.max_image_bytes') / 1024)]);
        app(\DreamLayer\UnoPIM\Services\EditorHistory::class)->assertAvailable($owner, $request->input('conversation_id'));

        return response()->json(app(\DreamLayer\UnoPIM\Services\StudioUploads::class)->store($request->file('image'), $owner, $request->input('conversation_id')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function uploadImage(Request $request)
    {
        $owner = $this->user();
        $request->validate(['conversation_id' => 'required|uuid', 'token' => 'required|string|max:8000']);
        $path = app(\DreamLayer\UnoPIM\Services\StudioUploads::class)->resolve($request->input('token'), $owner, $request->input('conversation_id'));

        return response()->file($path, ['Content-Type' => mime_content_type($path), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function threads(Request $request)
    {
        $user = $this->user();
        $request->validate(['page' => 'sometimes|integer|min:1', 'bookmarked' => 'sometimes|boolean']);
        $query = DB::table('dreamlayer_editor_threads')->where('user_id', $user)->whereNull('deleted_at');
        if ($request->boolean('bookmarked')) {
            $query->where('bookmarked', true);
        }
        $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(30);

        return response()->json(['items' => collect($rows->items())->map(fn ($row) => [
            'id' => $row->conversation_id,
            'title' => $row->title ? Crypt::decryptString($row->title) : trans('dreamlayer::app.conversation'),
            'bookmarked' => (bool) $row->bookmarked, 'updated_at' => $row->updated_at,
        ]), 'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null])->header('Cache-Control', 'private, no-store');
    }

    private function owned(string $id)
    {
        return DB::table('dreamlayer_editor_threads')->where('user_id', $this->user())
            ->where('conversation_id', $id)->whereNull('deleted_at')->firstOrFail();
    }

    public function show(string $id)
    {
        $thread = $this->owned($id);
        $rows = DB::table('dreamlayer_editor_history')->where('user_id', $thread->user_id)
            ->where('conversation_id', $id)->orderBy('created_at')->orderBy('id')->get();
        $workflows = Workflow::where('user_id', $thread->user_id)->where('conversation_id', $id)->get()->groupBy('request_id');
        $turns = $rows->map(function ($row) use ($workflows) {
            $messages = json_decode(Crypt::decryptString($row->messages), true);

            return ['id' => $row->request_id, 'created_at' => $row->created_at, 'message' => $messages[0]['content'] ?? '',
                'reply' => $messages[1]['content'] ?? '', 'workflow_ids' => ($workflows[$row->request_id] ?? collect())->pluck('id')->values()];
        });

        return response()->json(['id' => $id, 'turns' => $turns, 'saved' => true,
            'omitted' => $workflows->keys()->diff($rows->pluck('request_id'))->isNotEmpty(),
            'title' => $thread->title ? Crypt::decryptString($thread->title) : trans('dreamlayer::app.conversation')])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $id)
    {
        $thread = $this->owned($id);
        $data = $request->validate(['title' => 'sometimes|required|string|max:120', 'bookmarked' => 'sometimes|required|boolean']);
        if (isset($data['title'])) {
            $data['title'] = Crypt::encryptString($data['title']);
        }
        DB::table('dreamlayer_editor_threads')->where('id', $thread->id)->update($data);

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }

    public function delete(string $id)
    {
        $thread = $this->owned($id);
        DB::transaction(function () use ($thread) {
            Settings::whereKey(1)->lockForUpdate()->firstOrFail();
            DB::table('dreamlayer_editor_threads')->where('id', $thread->id)->update([
                'deleted_at' => now(), 'title' => null, 'bookmarked' => false,
            ]);
            DB::table('dreamlayer_editor_history')->where('user_id', $thread->user_id)
                ->where('conversation_id', $thread->conversation_id)->delete();
        });

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }

    public function images(Request $request)
    {
        $user = $this->user();
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $rows = Workflow::where('user_id', $user)->where('status', 'completed')->latest('created_at')->paginate(20);
        $deleted = DB::table('dreamlayer_editor_threads')->where('user_id', $user)->whereNotNull('deleted_at')->whereIn('conversation_id', $rows->pluck('conversation_id'))->pluck('conversation_id');

        return response()->json(['items' => $rows->map(fn ($w) => [
            'id' => $w->id, 'conversation_id' => $w->conversation_id, 'created_at' => $w->created_at,
            'can_refine' => ! $deleted->contains($w->conversation_id), 'sku' => $w->target['sku'] ?? null, 'applied' => (bool) $w->applied_at,
            'image_url' => route('dreamlayer.image', $w->id),
        ])->values(), 'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null])
            ->header('Cache-Control', 'private, no-store');
    }
}
