<?php

namespace DreamLayer\UnoPIM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('catalog.products.edit');
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'family' => ['nullable', 'integer'], 'product_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'], 'channel' => ['nullable', 'regex:/^[A-Za-z0-9_-]+$/D'], 'locale' => ['nullable', 'regex:/^[A-Za-z0-9_-]+$/D']];
    }
}
