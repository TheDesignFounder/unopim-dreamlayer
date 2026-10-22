<?php

// Run on a disposable, installed tagged host. Every outbound API call is faked.
use DreamLayer\UnoPIM\Compatibility\HostSupport;
use DreamLayer\UnoPIM\Compatibility\ImageContext;
use DreamLayer\UnoPIM\Jobs\RunWorkflow;
use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\CatalogImages;
use DreamLayer\UnoPIM\Services\ImageStore;
use DreamLayer\UnoPIM\Services\ProductImages;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Core\Core;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function ($error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage().'\n'.$error->getTraceAsString());
    exit(1);
});
$connection = config('database.default');
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.'.$connection.'.database'), '_test')) {
    throw new RuntimeException('Only run against a disposable test database.');
}
$passed = [];
function check(bool $ok, string $name): void
{
    global $passed;
    if (! $ok) {
        throw new RuntimeException('FAIL: '.$name);
    }
    $passed[] = $name;
    echo 'PASS: '.$name.PHP_EOL;
}
Http::preventStrayRequests();
Queue::fake();
check(HostSupport::available(), 'host product APIs available');
check(! HostSupport::nativeChat(), 'released host works without new native chat hooks');
$s = Settings::current();
check(! $s->managed_chat && ! $s->save_chat_history && ! $s->native_chat_enabled, 'optional features off by default');
check(! config('ai-agent.history.enabled', false), 'host-wide history not enabled');
DB::enableQueryLog();
DB::flushQueryLog();
for ($i = 0; $i < 10; $i++) {
    Settings::current();
}
check(count(DB::getQueryLog()) === 0, 'settings reused within request');
DB::disableQueryLog();
$role = Role::firstOrCreate(['name' => 'DreamLayer compatibility test'], ['permission_type' => 'all']);
$user = Admin::firstOrCreate(['email' => 'marketplace-test@example.test'], ['name' => 'Marketplace Test', 'password' => bcrypt('Local-Test-2026'), 'status' => 1, 'role_id' => $role->id, 'ui_locale_id' => DB::table('locales')->where('code', 'en_US')->value('id')]);
auth()->guard('admin')->login($user);
$s->update(['credential' => 'dl_test_only_local_not_a_real_key', 'credential_version' => (string) Str::uuid(), 'enabled' => true]);
$im = imagecreatetruecolor(64, 64);
imagefill($im, 0, 0, imagecolorallocate($im, 30, 100, 190));
ob_start();
imagepng($im);
$png = ob_get_clean();
imagedestroy($im);
Storage::disk('public')->put('dreamlayer-test/source.png', $png);
$family = AttributeFamily::firstOrFail();
$a = Attribute::firstOrCreate(['code' => 'image'], ['type' => 'image', 'value_per_locale' => 0, 'value_per_channel' => 0, 'is_required' => 0, 'is_unique' => 0]);
$group = DB::table('attribute_family_group_mappings')->where('attribute_family_id', $family->id)->first();
DB::table('attribute_group_mappings')->updateOrInsert(['attribute_id' => $a->id, 'attribute_family_group_id' => $group->id], ['position' => 50]);
$p = Product::firstOrCreate(['sku' => 'DL-COMPAT-TEST'], ['type' => 'simple', 'status' => 1, 'attribute_family_id' => $family->id]);
$p->forceFill(['values' => ['common' => ['sku' => $p->sku, 'image' => 'dreamlayer-test/source.png']]])->save();
$target = app(ProductImages::class)->target($p, 'image', 0, 'default', 'en_US');
check($target !== null, 'catalog image target resolved');
$selection = app(CatalogImages::class)->selection($p, $target, $user->id);
$c = new ImageContext(message: 'Make the background blue', history: [], productId: null, productSku: null, productName: null, locale: 'en_US', channel: 'default', user: $user, conversationId: (string) Str::uuid(), requestId: (string) Str::uuid(), extensions: ['dreamlayer' => ['images' => [['token' => $selection['token']]]]]);
$input = ['steps' => [['operation' => 'image_to_image', 'instruction' => 'Make the background blue']]];
$w = app(Workflows::class)->start($c, $input, false);
check(app(Workflows::class)->start($c, $input, false)->id === $w->id, 'request retry reuses workflow');
$changed = $input;
$changed['steps'][0]['instruction'] = 'Different instruction';
try {
    app(Workflows::class)->start($c, $changed, false);
    throw new RuntimeException('Changed replay accepted');
} catch (HttpExceptionInterface $e) {
    check($e->getStatusCode() === 409, 'changed request replay rejected');
}
$execution = (string) Str::uuid();
Http::fake(function ($request) use ($execution, $png) {
    check(DB::transactionLevel() === 0, 'HTTP call outside database transaction');
    if (str_ends_with($request->url(), '/capabilities')) {
        return Http::response(['api_version' => '1', 'image_workflows' => true, 'image_workflow_versions' => [1], 'operations' => ['image_to_image', 'text_to_image']]);
    }
    if (str_ends_with($request->url(), '/input-assets')) {
        return Http::response(['input_asset_id' => (string) Str::uuid()]);
    }
    if (str_ends_with($request->url(), '/image-workflows')) {
        return Http::response(['workflow_id' => $execution]);
    }
    if (str_contains($request->url(), '/image-workflows/')) {
        return Http::response(['contract_version' => 1, 'workflow_id' => $execution, 'status' => 'completed', 'steps' => [['description' => 'Blue background', 'status' => 'completed']], 'image' => ['download_url' => 'https://api.dreamlayer.io/v1/image-jobs/'.$execution.'/asset']]);
    }
    if (str_ends_with($request->url(), '/asset')) {
        return Http::response($png, 200, ['Content-Type' => 'image/png']);
    }
    throw new RuntimeException('Unexpected fake HTTP route: '.$request->url());
});
for ($i = 0; $i < 8 && ! $w->fresh()->terminal(); $i++) {
    (new RunWorkflow($w->id))->handle();
}
$w->refresh();
check($w->status === 'completed', 'preview processed through worker');
check(app(ImageStore::class)->valid($w->result_path), 'private PNG preview valid');
check($p->fresh()->values['common']['image'] === 'dreamlayer-test/source.png', 'preview does not change product');
$events = [];
Event::listen('catalog.product.update.before', function ($id) use (&$events) {
    $events[] = ['before', $id];
});
Event::listen('catalog.product.update.after', function ($product) use (&$events) {
    $events[] = ['after', $product->id];
});
app(ProductImages::class)->apply($w->id, $user->id);
check($p->fresh()->values['common']['image'] !== 'dreamlayer-test/source.png', 'explicit Apply updates product');
check($events === [['before', $p->id], ['after', $p->id]], 'both product update events fired once');
app(ProductImages::class)->apply($w->id, $user->id);
check(count($events) === 2, 'repeated Apply is idempotent');
$c2 = new ImageContext(message: 'Now make it white', history: [], productId: null, productSku: null, productName: null, locale: 'en_US', channel: 'default', user: $user, conversationId: $c->conversationId, requestId: (string) Str::uuid());
$follow = app(Workflows::class)->start($c2, ['steps' => [['operation' => 'image_to_image', 'instruction' => 'Make background white']], 'selected_result' => $w->id], false);
check(Storage::disk('local')->get($follow->source_path) === Storage::disk('local')->get($w->result_path), 'follow-up uses previous result');
$follow->update(['poll_deadline' => now()->subSecond()]);
(new RunWorkflow($follow->id))->handle();
check($follow->fresh()->status === 'failed', 'polling deadline terminates retries');
check((new RunWorkflow($w->id))->connection === config('dreamlayer.queue_connection'), 'queue connection configurable');
file_put_contents(getenv('DREAMLAYER_TEST_REPORT') ?: $host.'/storage/logs/dreamlayer-compatibility.json', json_encode(['host' => Core::VERSION, 'checks' => $passed], JSON_PRETTY_PRINT));
echo count($passed)." checks passed\n";
