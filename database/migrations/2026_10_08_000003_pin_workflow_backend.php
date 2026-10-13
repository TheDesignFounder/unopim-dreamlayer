<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This historical filename sorts before table creation on a new host.
        // Keep its name for existing migration ledgers; the later repair adds
        // this column after table creation when the early migration must skip it.
        if (! Schema::hasTable('dreamlayer_workflows') || Schema::hasColumn('dreamlayer_workflows', 'api_url')) {
            return;
        }

        Schema::table('dreamlayer_workflows', fn (Blueprint $table) => $table->string('api_url', 512)->nullable());
    }

    public function down(): void
    {
        // During a complete fresh-install rollback, the table is already gone.
        if (! Schema::hasTable('dreamlayer_workflows') || ! Schema::hasColumn('dreamlayer_workflows', 'api_url')) {
            return;
        }

        Schema::table('dreamlayer_workflows', fn (Blueprint $table) => $table->dropColumn('api_url'));
    }
};
