<?php

namespace App\Http\Requests;

use App\Models\Tenant;

class StoreTenantRequest extends UpdateTenantRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Tenant::class);
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'code' => ['required', 'string', 'max:64', 'regex:/\A[A-Z][A-Z0-9_]*\z/', 'unique:tenants,code'],
        ]);
    }
}
