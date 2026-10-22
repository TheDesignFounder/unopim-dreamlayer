<?php

namespace DreamLayer\UnoPIM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    private mixed $submittedKey = null;

    public function authorize(): bool
    {
        return bouncer()->hasPermission('dreamlayer');
    }

    protected function prepareForValidation(): void
    {
        $this->submittedKey = $this->input('api_key');
        // FormRequest is a clone; scrub the original request too because the
        // exception handler flashes that instance on a validation redirect.
        foreach ([$this, $this->container->make('request')] as $request) {
            $request->request->remove('api_key');
            $request->query->remove('api_key');
            if ($request->isJson()) {
                $request->json()->remove('api_key');
            }
        }
        $this->session()->forget('_old_input.api_key');
    }

    public function validationData(): array
    {
        return parent::validationData() + ['api_key' => $this->submittedKey];
    }

    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'], 'managed_chat' => ['nullable', 'boolean'],
            'save_chat_history' => ['nullable', 'boolean'], 'native_chat_enabled' => ['nullable', 'boolean'],
            'privacy_consent' => ['exclude_unless:managed_chat,1', 'required', 'accepted'],
            'api_key' => ['nullable', 'string', 'max:512'],
        ];
    }
}
