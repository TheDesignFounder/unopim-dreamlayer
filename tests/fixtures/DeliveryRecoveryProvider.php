<?php

namespace DreamLayer\UnoPIM\Tests\Fixtures;

use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Webkul\User\Models\Admin;

/** Opt-in crash fixture. Never register in a manual or production installation. */
class DeliveryRecoveryProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! app()->environment('testing') || getenv('DREAMLAYER_DELIVERY_RECOVERY_TEST') !== '1'
            || config('database.connections.pgsql.database') !== 'unopim_fresh_20261021_browser_test') {
            throw new \RuntimeException('Delivery recovery fixture requires its isolated browser test database');
        }
        config(['dreamlayer.queue'         => 'dreamlayer-recovery', 'queue.connections.dreamlayer.retry_after' => 15,
            'filesystems.disks.local.root' => storage_path('app/delivery-recovery-fixture')]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $disk = Storage::disk('local');
            $state = json_decode($disk->get('state.json'), true);
            if ($request->method() !== 'GET') {
                $disk->append('unexpected-writes.txt', $request->method());
                throw new \RuntimeException('Recovery must not submit image work');
            }
            $url = $request->url();
            if (preg_match('~/image-workflows/([a-f0-9-]+)$~', $url, $match)) {
                $w = Workflow::where('upstream_workflow_id', $match[1])->firstOrFail();
                $image = ['download_url' => Client::BASE_URL.'/image-jobs/'.$w->id.'/asset'];

                return Http::response(['contract_version' => 1, 'workflow_id' => $match[1], 'status' => 'completed',
                    'steps'                               => [['description' => 'Put the car by the beach', 'status' => 'completed', 'image' => $image]], 'image' => $image]);
            }
            if (preg_match('~/image-jobs/([a-f0-9-]+)/asset$~', $url, $match)) {
                $id = $match[1];
                $file = 'attempts-'.$id;
                $count = (int) ($disk->exists($file) ? $disk->get($file) : 0) + 1;
                $disk->put($file, (string) $count);
                if ($id === $state['children'][0] && $count === 1) {
                    throw new ConnectionException('Synthetic interrupted download');
                }
                if ($id === $state['children'][0] && $count === 2) {
                    $disk->put('download-blocked', 'ready');
                    sleep(30); // The harness SIGKILLs this worker while the transfer is pending.
                }
                usleep(250000);

                return Http::response($disk->get('result.png'), 200, ['Content-Type' => 'image/png']);
            }
            if (str_contains($url, 'gravatar.com')) {
                return Http::response('', 404);
            }
            throw new \RuntimeException('Unexpected fixture request');
        });
        Route::middleware('web')->get('/__delivery-recovery/login', function () {
            auth()->guard('admin')->login(Admin::where('email', 'fixture@dreamlayer.invalid')->firstOrFail());

            return redirect('/admin/catalog/products');
        });
    }
}
