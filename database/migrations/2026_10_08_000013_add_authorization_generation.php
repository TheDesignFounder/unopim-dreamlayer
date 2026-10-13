<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_settings', function (Blueprint $table) {
            $table->uuid('authorization_generation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dreamlayer_settings', function (Blueprint $table) {
            $table->dropColumn('authorization_generation');
        });
    }
};
