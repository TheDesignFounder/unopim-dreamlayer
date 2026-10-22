<?php

namespace DreamLayer\UnoPIM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('catalog.products.edit');
    }

    public function rules(): array
    {
        return ['conversation_id' => ['nullable', 'string', 'max:64'], 'request_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:processing,ready,applied,failed,partial'], 'step' => ['sometimes', 'integer', 'min:0', 'max:1']];
    }
}
