<?php

use DreamLayer\UnoPIM\Models\Settings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Core\Models\CoreConfig;
use Webkul\MagicAI\Models\MagicAIPlatform;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'unopim_extension_test') {
    throw new RuntimeException('Wrong fixture database');
}

$role = Role::firstOrCreate(['name'=>'Fixture administrator'], ['permission_type'=>'all']);
Admin::firstOrCreate(['email'=>'fixture@dreamlayer.invalid'], ['name'=>'Local Fixture', 'password'=>bcrypt(bin2hex(random_bytes(24))), 'status'=>1, 'role_id'=>$role->id, 'ui_locale_id'=>1]);
foreach (['enabled', 'open_by_default'] as $key) {
    CoreConfig::updateOrCreate(['code'=>'general.magic_ai.agentic_pim.'.$key, 'channel_code'=>null, 'locale_code'=>null], ['value'=>'1']);
}
MagicAIPlatform::updateOrCreate(['label'=>'Local fixture text model'], ['provider'=>'openai', 'api_key'=>'fixture-not-a-real-key', 'models'=>'gpt-4o', 'status'=>true, 'is_default'=>true]);
Settings::current()->update(['enabled'=>true, 'credential'=>'dl_test_local_fixture_only_'.str_repeat('x', 32), 'credential_version'=>(string) Str::uuid()]);
$im = imagecreatetruecolor(400, 400);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledellipse($im, 200, 230, 150, 240, imagecolorallocatealpha($im, 45, 100, 190, 0));
imagefilledrectangle($im, 160, 60, 240, 170, imagecolorallocatealpha($im, 45, 100, 190, 0));
ob_start();
imagepng($im);
$png = ob_get_clean();
Storage::disk('local')->put('fixture-result.png', $png);
Storage::disk('public')->put('fixture-original.png', $png);
$a = Attribute::where('code', 'image')->firstOrFail();
$family = AttributeFamily::first();
$p = Product::firstOrCreate(['sku'=>'EXTENSION-FIXTURE'], ['type'=>'simple', 'status'=>1, 'attribute_family_id'=>$family->id]);
$p->forceFill(['values'=>['common'=>['sku'=>$p->sku, 'image'=>'fixture-original.png']]])->save();
echo 'Fixture product ID: '.$p->id.PHP_EOL;
