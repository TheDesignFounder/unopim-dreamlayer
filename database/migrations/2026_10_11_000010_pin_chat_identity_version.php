<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_chat_turns', fn (Blueprint $t) => $t->unsignedSmallInteger('identity_version')->default(1));
    }

    public function down(): void
    {
        Schema::table('dreamlayer_chat_turns', fn (Blueprint $t) => $t->dropColumn('identity_version'));
    }
};
