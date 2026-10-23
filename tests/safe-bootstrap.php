<?php

// Exercise the early-disable path without any host product or AI classes.

namespace Illuminate\Support { class ServiceProvider
{
    public function loadTranslationsFrom(...$args) {}
} }

namespace Illuminate\Support\Facades { class Log
{
    public static array $messages = [];

    public static function warning($message)
    {
        self::$messages[] = $message;
    }
} }

namespace {
    use DreamLayer\UnoPIM\Providers\DreamLayerServiceProvider;
    use Illuminate\Support\Facades\Log;

    require __DIR__.'/../src/Compatibility/HostSupport.php';
    require __DIR__.'/../src/Providers/DreamLayerServiceProvider.php';
    (new DreamLayerServiceProvider)->boot();
    if (count(Log::$messages) !== 1) {
        throw new RuntimeException('Missing warning');
    }
    echo "PASS: unsupported host logs a warning and returns without loading routes or optional AI classes\n";
}
