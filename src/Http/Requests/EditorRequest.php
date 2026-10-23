<?php

namespace DreamLayer\UnoPIM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('catalog.products.edit');
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:4000'],
            'operation' => ['required', 'in:image_to_image,background_remove,text_to_image'],
            'selected_result' => ['nullable', 'uuid'], 'retry_result' => ['nullable', 'uuid'],
            'upload_token' => ['nullable', 'string', 'max:8000'],
            'images' => ['present', 'array', 'max:5'], 'images.*' => ['array:token'], 'images.*.token' => ['required', 'string', 'max:8000'],
            'channel' => ['required', 'regex:/^[A-Za-z0-9_-]+$/D'], 'locale' => ['required', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'history' => ['present', 'array', 'max:20'], 'history.*' => ['array:role,content'],
            'history.*.role' => ['required', 'in:user,assistant'], 'history.*.content' => ['required', 'string', 'max:4000'],
        ];
    }
}
