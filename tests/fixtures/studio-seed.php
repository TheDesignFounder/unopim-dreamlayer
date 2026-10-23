<?php

// Synthetic browser-test setup. Never run against a review or customer database.
$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config('database.default');
if (! app()->environment('testing') || ! env('DREAMLAYER_BROWSER_FIXTURES') || ! str_ends_with(config('database.connections.'.$connection.'.database'), '_test')) {
    throw new RuntimeException('Only run with browser fixtures on a disposable test database.');
}
$source = Webkul\Product\Models\Product::where('sku', 'DL-COMPAT-TEST')->firstOrFail();
DreamLayer\UnoPIM\Models\Settings::current()->update([
    'credential' => 'review-chat-key', 'enabled' => true, 'managed_chat' => true,
    'privacy_consent_version' => 1, 'save_chat_history' => true,
]);
foreach (['REVIEW-MUSTANG', 'REVIEW-SECOND'] as $sku) {
    $product = Webkul\Product\Models\Product::firstOrCreate(['sku' => $sku], [
        'type' => 'simple', 'status' => 1, 'attribute_family_id' => $source->attribute_family_id,
    ]);
    $product->forceFill(['values' => ['common' => [
        'sku' => $sku, 'name' => $sku, 'image' => 'dreamlayer-test/source.png',
    ]]])->save();
}
echo 'Disposable studio fixtures ready.'.PHP_EOL;
