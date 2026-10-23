<?php

// Render real host components on each released tag, with outbound HTTP disabled.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Webkul\User\Models\Admin;

$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
});
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.'.config('database.default').'.database'), '_test')) {
    throw new RuntimeException('Disposable test host required');
}
Http::preventStrayRequests();
Http::fake(['*' => Http::response([])]);
auth()->guard('admin')->login(Admin::where('email', 'marketplace-test@example.test')->firstOrFail());
foreach (['choose' => 'DreamLayer image editor', 'workflows' => 'My image results', 'settings' => 'What data is shared'] as $path => $label) {
    fwrite(STDERR, 'Rendering '.$path.PHP_EOL);
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('/admin/dreamlayer/'.$path));
    if ($response->getStatusCode() !== 200 || ! str_contains($response->getContent(), $label)) {
        fwrite(STDERR, 'FAIL: '.$path.' HTTP '.$response->getStatusCode().PHP_EOL);
        exit(1);
    }
    echo 'PASS: host renders '.$path.' with native components'.PHP_EOL;
}
