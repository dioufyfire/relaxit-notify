<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('apiTenant') !== null;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'regex:/\A\+[1-9][0-9]{7,14}\z/'],
            'channel' => ['required', 'in:whatsapp'],
            'template' => ['required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9_]*\z/'],
            'variables' => ['present', 'array', 'max:30'],
            'variables.*' => ['required', 'string', 'max:1000'],
            'schedule_at' => ['nullable', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/'],
            'external_reference' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $header = $this->header('Idempotency-Key');
            if (! is_string($header) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $header)) {
                $validator->errors()->add('idempotency_key', 'L’en-tête Idempotency-Key est requis (1 à 128 caractères : lettres, chiffres, point, tiret, deux-points ou underscore).');
            }
            $allowed = array_keys($this->rules());
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Champ non accepté.');
            }
            foreach (array_keys((array) $this->input('variables', [])) as $name) {
                if (! preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', (string) $name)) {
                    $validator->errors()->add('variables', 'Les noms de variables doivent être des identifiants simples.');
                }
            }
        }];
    }
}
