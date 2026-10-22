<?php

namespace DreamLayer\UnoPIM\Providers;

use DreamLayer\UnoPIM\Chat\ConversationImages;
use DreamLayer\UnoPIM\Chat\Tools\EditImage;
use DreamLayer\UnoPIM\Chat\Tools\GenerateImage;
use DreamLayer\UnoPIM\Compatibility\HostSupport;
use DreamLayer\UnoPIM\Console\RecoverWorkflows;
use DreamLayer\UnoPIM\Models\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Webkul\AiAgent\Chat\ExtensionRegistry;
use Webkul\AiAgent\Chat\ResultRendererRegistry;
use Webkul\AiAgent\Chat\ToolRegistry;
use Webkul\AiAgent\Events\AgentSystemPromptBuilding;

class DreamLayerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/dreamlayer.php', 'dreamlayer');
        $this->app->scoped('dreamlayer.settings.current', fn () => Settings::firstOrCreate(['id' => 1], ['installation_id' => (string) Str::uuid()]));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'dreamlayer');
        if (! HostSupport::available()) {
            Log::warning('DreamLayer is disabled: this host does not expose the required product APIs.');

            return;
        }
        $this->mergeConfigFrom(__DIR__.'/../../config/acl.php', 'acl');
        $this->mergeConfigFrom(__DIR__.'/../../config/menu.php', 'menu.admin');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'dreamlayer');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        Route::middleware('web')->group(__DIR__.'/../../routes/admin.php');
        $this->commands([RecoverWorkflows::class]);
        Event::listen('unopim.admin.catalog.product.edit.actions.after', function ($manager) {
            if (Settings::current()->enabled) {
                $manager->addTemplate('dreamlayer::product-shortcut');
            }
        });
        Event::listen('unopim.admin.layout.head', fn ($manager) => $manager->addTemplate('dreamlayer::navigation-icons'));
        if (HostSupport::nativeChat()) {
            $this->nativeChat();
        }
    }

    private function nativeChat(): void
    {
        // Only use documented extension registries. The host's provider, HTTP
        // client, embedding service and history configuration remain its own.
        $this->app->extend(ToolRegistry::class, function ($registry, $app) {
            if (! Settings::current()->enabled || ! Settings::current()->native_chat_enabled) {
                return $registry;
            }
            foreach (['generate_image' => GenerateImage::class, 'edit_image' => EditImage::class] as $name => $class) {
                $registry->disable($name);
                $registry->register($app->make($class), ['name' => $name, 'group' => 'content', 'write' => true,
                    'permission' => 'catalog.products.edit', 'guidance' => 'DreamLayer returns a preview only. Apply is a separate user action.']);
            }

            return $registry;
        });
        $this->app->afterResolving(ExtensionRegistry::class, function ($registry) {
            if (! Settings::current()->enabled || ! Settings::current()->native_chat_enabled) {
                return;
            }
            $registry->composer('dreamlayer', 'v-dreamlayer-picker', 'dreamlayer::picker');
            foreach (['generate_image', 'edit_image'] as $key) {
                $registry->capability($key, ['description' => trans('dreamlayer::app.native-capability'), 'extension_event' => $key === 'edit_image' ? 'ai-agent:picker' : null]);
            }
        });
        Event::listen(AgentSystemPromptBuilding::class, function ($event) {
            if (Settings::current()->native_chat_enabled) {
                app(ConversationImages::class)->handle($event);
            }
        });
        $this->app->afterResolving(ResultRendererRegistry::class, function ($registry) {
            $registry->register('dreamlayer.workflow', 'v-dreamlayer-workflow', 'dreamlayer::workflow', 'dreamlayer');
            $registry->register('dreamlayer.batch', 'v-dreamlayer-batch', 'dreamlayer::batch', 'dreamlayer');
        });
    }
}
