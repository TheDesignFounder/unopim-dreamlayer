<?php

// Separate-process lock test. Uses only the named isolated fixture database.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'unopim_extension_test') {
    throw new RuntimeException('Wrong test database');
}
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ProductImages;
use DreamLayer\UnoPIM\Services\Workflows;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\AiAgent\Chat\ChatContext;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\MagicAI\Models\MagicAIPlatform;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

$admin = Admin::where('email', 'fixture@dreamlayer.invalid')->firstOrFail();
auth()->guard('admin')->login($admin);
$mode = $argv[1];
if ($mode === 'prepare') {
    Queue::fake();
    $a = Attribute::factory()->create(['code'=>'concurrency_'.Str::lower(Str::random(6)), 'type'=>'image', 'value_per_locale'=>false, 'value_per_channel'=>false]);
    $family = AttributeFamily::factory()->withRequiredAttributes($a)->create();
    $p = Product::factory()->create(['attribute_family_id'=>$family->id, 'values'=>['common'=>[$a->code=>'original.png', 'description'=>'Before']]]);
    $c = new ChatContext(message: 'Preview', history: [], productId: $p->id, productSku: null, productName: null, locale: 'en_US', channel: 'default', platform: new MagicAIPlatform(['provider'=>'openai']), user: $admin, conversationId: 'concurrency_'.Str::random(8), requestId: (string) Str::uuid());
    $w = app(Workflows::class)->start($c, ['steps'=>[['operation'=>'text_to_image', 'instruction'=>'Vase']]], true);
    $path = 'dreamlayer/'.$w->id.'/result.png';
    Storage::disk('local')->put($path, Storage::disk('local')->get('fixture-result.png'));
    $w->update(['status'=>'completed', 'result_path'=>$path]);
    echo $w->id;
    exit;
}
if (! Str::isUuid($argv[2])) {
    throw new RuntimeException('Invalid ID');
}
$w = Workflow::findOrFail($argv[2]);
$t = $w->target;
if ($mode === 'writer') {
    DB::transaction(function () use ($t, $w) {
        $p = Product::whereKey($t['product_id'])->lockForUpdate()->firstOrFail();
        $values = $p->values;
        $values['common']['description'] = 'Committed concurrent edit';
        $p->forceFill(['values'=>$values])->save();
        file_put_contents('/tmp/dreamlayer-lock-'.$w->id, 'locked');
        usleep(2000000);
    });
} elseif ($mode === 'apply') {
    app(ProductImages::class)->apply($w->id, $admin->id);
} elseif ($mode === 'verify') {
    $p = Product::findOrFail($t['product_id']);
    if ($p->values['common']['description'] !== 'Committed concurrent edit' || $p->values['common'][$t['attribute']] === 'original.png' || ! $w->fresh()->applied_at) {
        throw new RuntimeException('Concurrent update failed');
    }
    $files = Storage::disk('public')->allFiles('product/'.$p->id.'/'.$t['attribute']);
    if (count($files) !== 1) {
        throw new RuntimeException('Duplicate Apply created files');
    }
    echo 'PASS concurrent product writer plus two Apply processes: unrelated edit retained; one saved image';
}
