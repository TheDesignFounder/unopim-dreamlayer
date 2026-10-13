<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dreamlayer_tool_receipts', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->unsignedInteger('user_id');
            $t->uuid('request_id');
            $t->string('tool');
            $t->string('fingerprint', 64);
            $t->string('status')->default('pending');
            $t->longText('result')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dreamlayer_tool_receipts');
    }
};
