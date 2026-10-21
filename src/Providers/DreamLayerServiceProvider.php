<?php

namespace DreamLayer\UnoPIM\Providers;

use DreamLayer\UnoPIM\Chat\ConversationImages;
use DreamLayer\UnoPIM\Chat\JournaledTool;
use DreamLayer\UnoPIM\Chat\Tools\EditImage;
use DreamLayer\UnoPIM\Chat\Tools\GenerateImage;
use DreamLayer\UnoPIM\Console\RecoverWorkflows;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\ManagedApiClient;
use DreamLayer\UnoPIM\Services\ManagedChat;
use DreamLayer\UnoPIM\Services\ManagedSimilarity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\AiAgent\Chat\ExtensionRegistry;
use Webkul\AiAgent\Chat\MessageDisplay;
use Webkul\AiAgent\Chat\ResultRendererRegistry;
use Webkul\AiAgent\Chat\ToolRegistry;
use Webkul\AiAgent\Events\AgentSystemPromptBuilding;
use Webkul\AiAgent\Http\Client\AiApiClient;
use Webkul\AiAgent\Services\EmbeddingSimilarityService;

/**
 * Wires the package into generic UnoPIM hooks; core contains no DreamLayer routing.
 * See docs/REVIEWING.md for the request path and each authorization boundary.
 */
class DreamLayerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/dreamlayer.php', 'dreamlayer');
        // Per-request state includes the selected credential and retry identity.
        // Do not make this a singleton in long-lived application/worker processes.
        $this->app->scoped(ManagedChat::class);
        $this->app->bind(AiApiClient::class, ManagedApiClient::class);
        $this->app->bind(EmbeddingSimilarityService::class, ManagedSimilarity::class);
        config(['queue.connections.dreamlayer' => ['driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'dreamlayer', 'retry_after' => 180, 'after_commit' => true]]);
        $this->mergeConfigFrom(__DIR__.'/../../config/acl.php', 'acl');
        $this->mergeConfigFrom(__DIR__.'/../../config/menu.php', 'menu.admin');
        $this->app->extend(ToolRegistry::class, function ($registry, $app) {
            foreach (['generate_image' => GenerateImage::class, 'edit_image' => EditImage::class] as $name => $class) {
                $registry->disable($name);
                $registry->register($app->make($class), ['name' => $name, 'group' => 'content', 'write' => true,
                    'permission'                                => 'catalog.products.edit', 'guidance' => 'DreamLayer, when enabled, returns a preview only. Submit all requested image steps in one call. The preview card continues independently; never resubmit pending work.']);
            }

            // Managed-chat retries may replay a tool call. Journal those calls only;
            // other providers keep their native tool execution path.
            $registry->decorate(fn ($tool, $context, $meta) => $context->platform->id === ManagedChat::ID
                ? new JournaledTool($tool, $context, $meta) : $tool);

            return $registry;
        });
    }

    public function boot(): void
    {
        if (config('dreamlayer.persist_chat_history')) {
            config(['ai-agent.history.enabled' => true]);
        }

        if (! defined(ExtensionRegistry::class.'::COMPOSER_REFERENCES') || ExtensionRegistry::COMPOSER_REFERENCES < 2 || ! class_exists(ExtensionRegistry::class) || ! class_exists(ResultRendererRegistry::class) || ! property_exists(ChatContext::class, 'hasNewImages') || ! class_exists(MessageDisplay::class)) {
            throw new \RuntimeException('DreamLayer requires UnoPIM with the merged chat extension hooks. See docs/CORE_PATCH.md for supported versions and upgrade instructions.');
        }
        $this->app->afterResolving(ExtensionRegistry::class, function ($r) {
            if (! Settings::current()->enabled) {
                return;
            }
            $r->composer('dreamlayer', 'v-dreamlayer-picker', 'dreamlayer::picker');
            foreach (['generate_image', 'edit_image'] as $key) {
                $r->capability($key, ['description' => 'Powered by DreamLayer · preview, download and Apply', 'extension_event' => $key === 'edit_image' ? 'ai-agent:picker' : null]);
            }
        });
        // The transport checks the active request and credential before intercepting
        // anything; unrelated UnoPIM HTTP traffic passes through unchanged.
        Http::globalMiddleware(fn ($handler) => app(ManagedChat::class)->transport($handler));
        $this->app->afterResolving(ExtensionRegistry::class, function ($r) {
            $s = Settings::current();
            if ($s->connected() && $s->managed_chat) {
                // A virtual platform keeps installation credentials out of MagicAI's
                // provider table. Its single internal model still travels with the request.
                $r->provider(ManagedChat::ID, fn () => app(ManagedChat::class)->platform(),
                    ['label'                        => 'DreamLayer – Chat & Images', 'models' => ['dreamlayer-chat'], 'hide_single_model' => true, 'is_default' => true]);
            }
        });
        Event::listen('unopim.admin.catalog.product.edit.actions.after', function ($manager) {
            if (Settings::current()->enabled) {
                $manager->addTemplate('dreamlayer::product-shortcut');
            }
        });
        Event::listen(AgentSystemPromptBuilding::class, [ConversationImages::class, 'handle']);
        Event::listen(AgentSystemPromptBuilding::class, fn ($event) => app(ManagedChat::class)->pinPrompt($event));
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'dreamlayer');
        Event::listen('unopim.admin.layout.head', function ($manager) {
            $manager->addTemplate('dreamlayer::navigation-icons');
        });
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'dreamlayer');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        Route::middleware('web')->group(__DIR__.'/../../routes/admin.php');
        $this->app->afterResolving(ResultRendererRegistry::class, fn ($r) => $r->register('dreamlayer.workflow', 'v-dreamlayer-workflow', 'dreamlayer::workflow', 'dreamlayer'));
        $this->app->afterResolving(ResultRendererRegistry::class, fn ($r) => $r->register('dreamlayer.batch', 'v-dreamlayer-batch', 'dreamlayer::batch', 'dreamlayer'));
        $this->commands([RecoverWorkflows::class]);
    }
}
