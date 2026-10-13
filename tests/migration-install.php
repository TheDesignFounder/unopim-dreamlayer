<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Run the real Laravel migrator in disposable PostgreSQL schemas, not inside
// the already-migrated transaction used by the application regression suite.
$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's application exception renderer is intended for Artisan entry
// points. This standalone regression runner must return a failing process code.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});

if (! app()->environment('testing')
    || config('database.default') !== 'pgsql'
    || ! str_ends_with(config('database.connections.pgsql.database'), '_migration_test')
    || getenv('DREAMLAYER_MIGRATION_TEST') !== '1') {
    throw new RuntimeException('Use an explicitly opted-in, dedicated *_migration_test PostgreSQL database.');
}
Http::preventStrayRequests();
$path = dirname(__DIR__).'/database/migrations';
$files = glob($path.'/*.php');
$repair = '2026_10_12_000001_ensure_workflow_backend_column';
$base = $path.'/2026_10_08_000010_create_dreamlayer_tables.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function migrate(array $paths, string $command = 'migrate'): void
{
    $exit = Artisan::call($command, ['--path' => $paths, '--realpath' => true, '--force' => true, '--no-interaction' => true]);
    check($exit === 0, $command.' failed: '.Artisan::output());
}

function fixture(bool $withEndpoint): array
{
    $id = (string) Str::uuid();
    DB::table('dreamlayer_settings')->insert([
        'id'                 => 1, 'enabled' => true, 'credential' => encrypt('fixture-only-not-a-live-key'),
        'credential_version' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
        'connection_id'      => 'preserve-this-installation', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $workflow = [
        'id'                 => $id, 'user_id' => 1, 'conversation_id' => 'migration-fixture',
        'request_id'         => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64),
        'credential_version' => (string) Str::uuid(), 'status' => 'completed',
        'steps'              => '[]', 'result_path' => 'preserve-result.png', 'target' => '{"product_id":42}',
        'created_at'         => now(), 'updated_at' => now(),
    ];
    if ($withEndpoint) {
        $workflow['api_url'] = 'https://preserve-api.example/v1';
    }
    DB::table('dreamlayer_workflows')->insert($workflow);

    return [(array) DB::table('dreamlayer_settings')->first(), (array) DB::table('dreamlayer_workflows')->first()];
}

function snapshot(): array
{
    return [(array) DB::table('dreamlayer_settings')->first(), (array) DB::table('dreamlayer_workflows')->first()];
}

function isolated(string $name, callable $test): void
{
    $schema = 'dl_migration_'.bin2hex(random_bytes(8));
    $oldPath = config('database.connections.pgsql.search_path', 'public');
    DB::statement('CREATE SCHEMA "'.$schema.'"');
    try {
        config(['database.connections.pgsql.search_path' => $schema]);
        DB::purge('pgsql');
        Schema::clearResolvedInstance('db.schema');
        check(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
        $test();
        echo 'PASS: '.$name.PHP_EOL;
    } finally {
        DB::purge('pgsql');
        config(['database.connections.pgsql.search_path' => $oldPath]);
        Schema::clearResolvedInstance('db.schema');
        DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
}

isolated('fresh install, repeated migration, complete rollback and reinstall', function () use ($path, $files) {
    migrate([$path]);
    check(DB::table('migrations')->count() === count($files), 'Not every package migration was applied.');
    check(Schema::hasColumn('dreamlayer_workflows', 'api_url'), 'Fresh install is missing api_url.');
    foreach (['dreamlayer_settings', 'dreamlayer_batches', 'dreamlayer_applications', 'dreamlayer_tool_receipts'] as $table) {
        check(Schema::hasTable($table), 'Missing '.$table);
    }
    $before = fixture(true);
    migrate([$path]);
    check(snapshot() === $before, 'A repeated migrate changed existing records.');
    migrate([$path], 'migrate:rollback');
    check(! Schema::hasTable('dreamlayer_workflows') && ! Schema::hasTable('dreamlayer_settings'), 'Fresh rollback left package tables.');
    check(DB::table('migrations')->count() === 0, 'Fresh rollback left migration records.');
    migrate([$path]);
    check(Schema::hasColumn('dreamlayer_workflows', 'api_url'), 'Reinstall is missing api_url.');
});

isolated('existing installation keeps pinned endpoint, encrypted credential and migration history', function () use ($path, $files, $base, $repair) {
    // Reproduce the historical installed schema: the table existed before the
    // early-named column migration was applied. Keep its original ledger name.
    migrate([$base]);
    migrate(array_values(array_filter($files, fn ($file) => basename($file, '.php') !== $repair)));
    $before = fixture(true);
    $history = DB::table('migrations')->orderBy('id')->get()->toArray();
    migrate([$path]);
    check(snapshot() === $before, 'Upgrade changed a saved endpoint, credential or result.');
    check(DB::table('migrations')->orderBy('id')->limit(count($history))->get()->toArray() == $history, 'Historical migration records changed.');
    migrate([$path], 'migrate:rollback');
    check(snapshot() === $before, 'Rolling back the repair damaged an existing installation.');
    migrate([$path]);
    check(snapshot() === $before, 'Reapplying the repair changed saved data.');
});

isolated('repair adds a missing column without assigning a new backend to old work', function () use ($path, $files, $base, $repair) {
    migrate([$base]);
    migrate(array_values(array_filter($files, fn ($file) => basename($file, '.php') !== $repair)));
    Schema::table('dreamlayer_workflows', fn ($table) => $table->dropColumn('api_url'));
    $before = fixture(false);
    migrate([$path]);
    check(Schema::hasColumn('dreamlayer_workflows', 'api_url'), 'Repair did not add the missing column.');
    $after = snapshot();
    check(array_key_exists('api_url', $after[1]) && $after[1]['api_url'] === null, 'Repair assigned an endpoint to old work.');
    unset($after[1]['api_url']);
    check($after === $before, 'Repair changed unrelated saved values.');
});

echo "All migration scenarios passed. No provider calls were made.\n";
