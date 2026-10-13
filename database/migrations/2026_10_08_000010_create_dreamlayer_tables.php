<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dreamlayer_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->text('credential')->nullable();
            $t->uuid('credential_version')->nullable();
            $t->uuid('installation_id');
            $t->string('connection_id')->nullable();
            $t->timestamps();
        });
        Schema::create('dreamlayer_workflows', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('user_id');
            $t->string('conversation_id', 64);
            $t->uuid('request_id');
            $t->string('fingerprint', 64);
            $t->uuid('credential_version');
            $t->string('status')->default('queued');
            $t->json('steps');
            $t->string('aspect_ratio')->default('1:1');
            $t->string('source_path')->nullable();
            $t->string('result_path')->nullable();
            $t->json('target')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'conversation_id', 'request_id'], 'dreamlayer_request_identity');
            $t->index(['user_id', 'conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dreamlayer_workflows');
        Schema::dropIfExists('dreamlayer_settings');
    }
};
