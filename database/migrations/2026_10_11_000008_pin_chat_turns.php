<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dreamlayer_chat_turns', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id');
            $t->uuid('request_id');
            $t->uuid('credential_version');
            $t->string('api_url', 512);
            $t->timestamps();
            $t->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dreamlayer_chat_turns');
    }
};
