<?php

// Disposable review host only; never shipped. Uses previously generated images.
use DreamLayer\UnoPIM\Models\Settings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Models\Product;

$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.'.config('database.default').'.database'), '_test') || ! env('DREAMLAYER_REVIEW_ASSETS')) {
    throw new RuntimeException('An isolated review test host is required');
}
Settings::current()->update(['credential' => null, 'connection_id' => null, 'credential_version' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid(), 'enabled' => false, 'managed_chat' => false, 'save_chat_history' => false, 'native_chat_enabled' => false, 'privacy_consent_version' => 0]);
$family = AttributeFamily::firstOrFail();
$attribute = Attribute::firstOrCreate(['code' => 'image'], ['type' => 'image', 'value_per_locale' => 0, 'value_per_channel' => 0]);
$group = DB::table('attribute_family_group_mappings')->where('attribute_family_id', $family->id)->first();
DB::table('attribute_group_mappings')->updateOrInsert(['attribute_id' => $attribute->id, 'attribute_family_group_id' => $group->id], ['position' => 50]);
Storage::disk('public')->put('dreamlayer-review/mustang.webp', file_get_contents(env('DREAMLAYER_REVIEW_ASSETS').'/unopim-car-original.webp'));
$p = Product::firstOrCreate(['sku' => 'REVIEW-MUSTANG'], ['type' => 'simple', 'status' => 1, 'attribute_family_id' => $family->id]);
$p->forceFill(['values' => ['common' => ['sku' => $p->sku, 'name' => 'Ford Mustang', 'image' => 'dreamlayer-review/mustang.webp']]])->save();
echo "Review fixture ready; no real account or provider requests.\n";
