<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dreamlayer_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('user_id');
            $t->string('conversation_id', 64);
            $t->uuid('request_id');
            $t->string('fingerprint', 64);
            $t->uuid('credential_version');
            $t->string('api_url', 512);
            $t->uuid('upstream_id')->nullable();
            $t->json('children');
            $t->string('status')->default('queued');
            $t->string('error')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'conversation_id', 'request_id'], 'dreamlayer_batch_identity');
        });
        Schema::table('dreamlayer_workflows', fn (Blueprint $t) => $t->uuid('batch_id')->nullable()->index());
        Schema::create('dreamlayer_applications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('batch_id');
            $t->uuid('workflow_id')->unique();
            $t->unsignedInteger('product_id');
            $t->string('destination');
            $t->unsignedInteger('slot');
            $t->string('before_version', 64);
            $t->string('after_version', 64);
            $t->timestamps();
        });
        Schema::table('dreamlayer_settings', function (Blueprint $t) {
            $t->boolean('managed_chat')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dreamlayer_settings', fn (Blueprint $t) => $t->dropColumn('managed_chat'));
        Schema::dropIfExists('dreamlayer_applications');
        Schema::table('dreamlayer_workflows', fn (Blueprint $t) => $t->dropColumn('batch_id'));
        Schema::dropIfExists('dreamlayer_batches');
    }
};
