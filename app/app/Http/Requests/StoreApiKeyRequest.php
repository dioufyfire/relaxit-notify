<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageApiKeys', $this->route('tenant'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'application' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_-]*\z/'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'tenant_id' => ['missing'], 'token_hash' => ['missing'], 'secret' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Donnez un nom à cette connexion.',
            'application.required' => 'Indiquez un identifiant d’application.',
            'application.regex' => 'Utilisez des lettres minuscules, chiffres, tirets ou underscores, en commençant par une lettre.',
            'expires_at.after' => 'La date d’expiration doit être postérieure à aujourd’hui.',
        ];
    }
}
