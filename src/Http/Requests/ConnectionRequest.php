<?php

namespace DreamLayer\UnoPIM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('dreamlayer');
    }

    public function rules(): array
    {
        return ['automatic' => ['nullable', 'boolean'], 'regenerate' => ['nullable', 'boolean'],
            'included_chat' => ['nullable', 'boolean'],
            'privacy_consent' => [Rule::excludeIf(fn () => ! $this->boolean('included_chat')), 'required', 'accepted'],
            'forget_local' => ['nullable', 'boolean'], 'attempt_id' => ['nullable', 'string', 'max:128']];
    }
}
