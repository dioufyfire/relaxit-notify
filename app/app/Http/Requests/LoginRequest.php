<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1024']];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Indiquez votre adresse email.',
            'email.email' => 'Indiquez une adresse email valide.',
            'password.required' => 'Indiquez votre mot de passe.',
        ];
    }
}
