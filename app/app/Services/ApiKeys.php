<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiKeys
{
    public function issue(Tenant $tenant, User $actor, array $data): array
    {
        return DB::transaction(function () use ($tenant, $actor, $data): array {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (ApiKey::where('tenant_id', $tenant->id)->where('application', $data['application'])->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['application' => 'Cette application possède déjà une clé. Utilisez la rotation ou révoquez la clé existante.']);
            }
            $result = $this->generate($tenant, $actor, $data);
            Audit::record('api_key.created', $tenant, $actor, ['key_id' => $result['key']->id, 'application' => $data['application']]);

            return $result;
        });
    }

    public function rotate(Tenant $tenant, ApiKey $key, User $actor): array
    {
        return DB::transaction(function () use ($tenant, $key, $actor): array {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $key = ApiKey::where('tenant_id', $tenant->id)->whereKey($key->id)->lockForUpdate()->firstOrFail();
            abort_unless($key->isUsable(), 409, 'Cette clé ne peut plus être renouvelée. Révoquez-la puis créez une nouvelle clé.');
            $key->revoked_at = now();
            $key->save();
            $result = $this->generate($tenant, $actor, $key->only('name', 'application', 'expires_at'));
            Audit::record('api_key.rotated', $tenant, $actor, ['key_id' => $key->id, 'replacement_key_id' => $result['key']->id, 'application' => $key->application]);

            return $result;
        });
    }

    public function revoke(Tenant $tenant, ApiKey $key, User $actor): void
    {
        DB::transaction(function () use ($tenant, $key, $actor): void {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $key = ApiKey::where('tenant_id', $tenant->id)->whereKey($key->id)->lockForUpdate()->firstOrFail();
            if ($key->revoked_at !== null) {
                return;
            }
            $key->revoked_at = now();
            $key->save();
            Audit::record('api_key.revoked', $tenant, $actor, ['key_id' => $key->id, 'application' => $key->application]);
        });
    }

    private function generate(Tenant $tenant, User $actor, array $data): array
    {
        $key = new ApiKey;
        $key->public_id = (string) Str::ulid();
        $secret = 'rln_'.$key->public_id.'_'.bin2hex(random_bytes(32));
        $key->tenant_id = $tenant->id;
        $key->created_by = $actor->id;
        $key->application = $data['application'];
        $key->name = $data['name'];
        $key->expires_at = $data['expires_at'] ?? null;
        $key->token_hash = hash('sha256', $secret);
        $key->save();

        return ['key' => $key, 'secret' => $secret];
    }
}
