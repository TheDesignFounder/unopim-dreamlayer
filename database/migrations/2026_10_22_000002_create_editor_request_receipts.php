<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dreamlayer_editor_requests', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id');
            $t->uuid('request_id');
            $t->string('fingerprint', 64);
            $t->uuid('credential_version');
            $t->string('api_url', 512);
            $t->boolean('assistance');
            $t->text('instruction')->nullable();
            $t->text('result')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_id'], 'dreamlayer_editor_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dreamlayer_editor_requests');
    }
};
