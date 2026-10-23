<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EditorHistory
{
    public function assertAvailable(int $user, string $conversation): void
    {
        abort_if(DB::table('dreamlayer_editor_threads')->where('user_id', $user)
            ->where('conversation_id', $conversation)->whereNotNull('deleted_at')->exists(),
            409, trans('dreamlayer::studio.deleted-thread'));
    }

    public function record(int $user, string $conversation, string $request, string $message, string $reply): void
    {
        DB::transaction(function () use ($user, $conversation, $request, $message, $reply) {
            $settings = Settings::whereKey(1)->lockForUpdate()->firstOrFail();
            if (! $settings->save_chat_history) {
                return;
            }
            $identity = ['user_id' => $user, 'conversation_id' => $conversation];
            $thread = DB::table('dreamlayer_editor_threads')->where($identity)->first();
            // Deleting a thread while a request runs must not resurrect its messages.
            if ($thread && $thread->deleted_at) {
                return;
            }
            if (! $thread) {
                DB::table('dreamlayer_editor_threads')->insert($identity + [
                    'title' => Crypt::encryptString(Str::limit($message, 90)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('dreamlayer_editor_threads')->where($identity)->update(['updated_at' => now()]);
            }
            DB::table('dreamlayer_editor_history')->updateOrInsert($identity + ['request_id' => $request], [
                'messages' => Crypt::encryptString(json_encode([['role' => 'user', 'content' => $message], ['role' => 'assistant', 'content' => $reply]])),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
