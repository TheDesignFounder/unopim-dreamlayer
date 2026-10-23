<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dreamlayer_editor_threads', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->uuid('conversation_id');
            $table->text('title')->nullable(); // Encrypted, like the messages.
            $table->boolean('bookmarked')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'conversation_id'], 'dl_editor_thread_owner');
        });
        // Existing opt-in records become readable without changing their contents.
        DB::table('dreamlayer_editor_history')->select('user_id', 'conversation_id')
            ->selectRaw('MIN(created_at) AS first_at, MAX(updated_at) AS last_at')
            ->groupBy('user_id', 'conversation_id')->orderBy('user_id')->get()->each(function ($row) {
                DB::table('dreamlayer_editor_threads')->insert([
                    'user_id' => $row->user_id, 'conversation_id' => $row->conversation_id,
                    'created_at' => $row->first_at, 'updated_at' => $row->last_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('dreamlayer_editor_threads');
    }
};
