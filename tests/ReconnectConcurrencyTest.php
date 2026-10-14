<?php

use DreamLayer\UnoPIM\Models\Settings;
use DreamLayer\UnoPIM\Services\PendingConnection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

// Real commits and a separate PostgreSQL connection exercise the gap that a rollback-only
// HTTP fake cannot detect. This runs only against the isolated regression database.
final class ReconnectConcurrencyTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_new_credential_is_committed_and_account_writers_are_locked_during_revoke(): void
    {
        $database = getenv('DREAMLAYER_CONCURRENCY_TEST_DATABASE') ?: 'unopim_release_catalog_test';
        $this->assertTrue(app()->environment('testing'));
        $this->assertStringEndsWith('_test', $database);
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame($database, DB::connection()->getDatabaseName());
        $original = Settings::current()->getRawOriginal();
        $role = Role::factory()->create(['permission_type' => 'all']);
        $admin = Admin::factory()->create(['role_id' => $role->id]);
        $this->actingAs($admin, 'admin');
        config(['dreamlayer.connections_enabled' => true, 'database.connections.reconnect_observer' => config('database.connections.'.config('database.default'))]);
        $observer = DB::connection('reconnect_observer');
        $observer->statement("SET lock_timeout = '150ms'");
        $seenCommitted = false;
        $blocked = false;
        try {
            Settings::current()->update(['credential' => 'previous-fixture', 'connection_id' => (string) Str::uuid(), 'authorization_generation' => (string) Str::uuid()]);
            $this->startSession();
            $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
            $request = Request::create('/');
            $request->setLaravelSession(app('session.store'));
            PendingConnection::put($request, ['generation' => Settings::current()->authorization_generation, 'device_code' => 'fixture-device', 'expires_at' => time() + 600, 'next_poll' => time() - 1, 'interval' => 5]);
            Http::preventStrayRequests();
            Http::fake(function ($request) use ($observer, &$seenCommitted, &$blocked) {
                if (str_ends_with($request->url(), '/token')) {
                    return Http::response(['access_token' => 'new-fixture', 'connection_id' => (string) Str::uuid()]);
                }
                if ($request->method() === 'DELETE') {
                    $this->assertSame('Bearer previous-fixture', $request->header('Authorization')[0]);
                    $row = $observer->table('dreamlayer_settings')->where('id', 1)->first();
                    $seenCommitted = Crypt::decryptString($row->credential) === 'new-fixture';
                    try {
                        $observer->table('dreamlayer_settings')->where('id', 1)->update(['authorization_generation' => (string) Str::uuid()]);
                    } catch (QueryException $e) {
                        $blocked = $e->getCode() === '55P03';
                    }

                    return Http::response([], 204);
                }
                throw new RuntimeException('Unexpected request');
            });
            $this->postJson(route('dreamlayer.connect.poll'))->assertOk();
            $this->assertTrue($seenCommitted, 'Revoke must observe the committed new credential from another connection.');
            $this->assertTrue($blocked, 'The current-credential guard must lock competing account changes through revocation.');
            $this->assertSame('new-fixture', Settings::current()->credential);
        } finally {
            DB::purge('reconnect_observer');
            DB::table('dreamlayer_settings')->where('id', 1)->update($original);
            $admin->delete();
            $role->delete();
        }
    }
}
