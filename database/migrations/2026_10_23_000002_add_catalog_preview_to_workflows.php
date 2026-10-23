<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_workflows', fn (Blueprint $table) => $table->json('catalog_preview')->nullable());
    }

    public function down(): void
    {
        Schema::table('dreamlayer_workflows', fn (Blueprint $table) => $table->dropColumn('catalog_preview'));
    }
};
