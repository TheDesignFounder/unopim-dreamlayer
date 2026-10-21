<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_batches', function (Blueprint $table) {
            // Existing batches retain their upstream identity and serial execution.
            $table->string('dispatch_mode')->default('upstream');
            $table->unsignedTinyInteger('concurrency')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('dreamlayer_batches', fn (Blueprint $table) => $table->dropColumn(['dispatch_mode', 'concurrency']));
    }
};
