<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_chat_turns', function (Blueprint $t) {
            $t->longText('system_prompt')->nullable();
            $t->string('input_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dreamlayer_chat_turns', fn (Blueprint $t) => $t->dropColumn(['system_prompt', 'input_fingerprint']));
    }
};
