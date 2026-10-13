<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_workflows', function (Blueprint $table) {
            $table->unsignedSmallInteger('error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dreamlayer_workflows', function (Blueprint $table) {
            $table->dropColumn('error_code');
        });
    }
};
