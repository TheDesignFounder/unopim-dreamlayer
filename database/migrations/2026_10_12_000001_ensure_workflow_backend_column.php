<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Run after table creation. Existing installations already have this
        // column: preserve their endpoint values and original migration record.
        if (! Schema::hasColumn('dreamlayer_workflows', 'api_url')) {
            Schema::table('dreamlayer_workflows', fn (Blueprint $table) => $table->string('api_url', 512)->nullable());
        }
    }

    public function down(): void
    {
        // This repair may not have created the column. Removing it here could
        // erase an existing installation's pinned endpoints. A full uninstall
        // still removes the table through the original creation migration.
    }
};
