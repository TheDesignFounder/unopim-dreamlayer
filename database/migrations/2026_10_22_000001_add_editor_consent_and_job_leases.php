<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dreamlayer_settings', function (Blueprint $t) {
            $t->boolean('save_chat_history')->default(false);
            $t->boolean('native_chat_enabled')->default(false);
            $t->unsignedInteger('privacy_consent_version')->default(0);
        });
        // Existing connections retain image access. Broader chat processing now
        // requires a fresh, explicit choice after reviewing the data disclosure.
        DB::table('dreamlayer_settings')->update(['managed_chat' => false]);
        foreach (['dreamlayer_workflows', 'dreamlayer_batches'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('lease_token')->nullable();
                $t->timestamp('lease_expires_at')->nullable();
                $t->timestamp('poll_deadline')->nullable();
            });
        }
        Schema::table('dreamlayer_applications', fn (Blueprint $t) => $t->index(['batch_id', 'product_id', 'destination'], 'dreamlayer_apply_destination'));
        Schema::create('dreamlayer_editor_history', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id');
            $t->uuid('conversation_id');
            $t->uuid('request_id');
            $t->text('messages');
            $t->timestamps();
            $t->unique(['user_id', 'conversation_id', 'request_id'], 'dreamlayer_editor_turn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dreamlayer_editor_history');
        Schema::table('dreamlayer_applications', fn (Blueprint $t) => $t->dropIndex('dreamlayer_apply_destination'));
        foreach (['dreamlayer_workflows', 'dreamlayer_batches'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['lease_token', 'lease_expires_at', 'poll_deadline']));
        }
        Schema::table('dreamlayer_settings', fn (Blueprint $t) => $t->dropColumn(['save_chat_history', 'native_chat_enabled', 'privacy_consent_version']));
    }
};
