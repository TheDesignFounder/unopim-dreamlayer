<?php

namespace DreamLayer\UnoPIM\Services;

use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Workflow;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keep editable user wording separate from the model's source-routing context. */
class RetryInstruction
{
    private const PREFIX = '/^The supplied image is @(Asset-[1-9][0-9]{0,12})\. Apply instructions for this image only\. /u';

    public static function reference(Workflow $workflow): ?string
    {
        $step = $workflow->steps[0] ?? [];
        if (array_key_exists('source_reference', $step)) {
            return is_string($step['source_reference']) && preg_match('/^Asset-[1-9][0-9]{0,12}$/D', $step['source_reference']) ? $step['source_reference'] : null;
        }
        // Older batch jobs stored this context only in the model instruction.
        return preg_match(self::PREFIX, $step['instruction'] ?? '', $match) ? $match[1] : null;
    }

    public static function display(Workflow $workflow): string
    {
        $step = $workflow->steps[0] ?? [];
        if (isset($step['user_instruction'])) {
            return $step['user_instruction'];
        }
        $request = $workflow->batch_id
            ? Batch::whereKey($workflow->batch_id)->where('user_id', $workflow->user_id)->where('conversation_id', $workflow->conversation_id)->value('request_id')
            : $workflow->request_id;
        $saved = DB::table('dreamlayer_editor_history')->where('user_id', $workflow->user_id)
            ->where('conversation_id', $workflow->conversation_id)->where('request_id', $request)->value('messages');
        if ($saved) {
            $messages = json_decode(Crypt::decryptString($saved), true);
            if (is_string($messages[0]['content'] ?? null)) {
                return $messages[0]['content'];
            }
        }
        // When old unsaved wording is unavailable, reuse the clean instruction.
        return preg_replace(self::PREFIX, '', $step['instruction'] ?? '', 1);
    }

    public static function scope(string $instruction, string $reference): string
    {
        $prefix = 'The supplied image is @'.$reference.'. Apply instructions for this image only. ';
        $limit = 4000 - mb_strlen($prefix);
        if (mb_strlen($instruction) > $limit) {
            throw ValidationException::withMessages(['message' => trans('validation.max.string', ['attribute' => 'message', 'max' => $limit])]);
        }

        return $prefix.$instruction;
    }
}
