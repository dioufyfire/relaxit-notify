<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Services\ApiKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        Gate::authorize('viewApiKeys', $tenant);

        return Inertia::render('ApiKeys/Index', [
            'tenant' => $tenant->only('id', 'code', 'name'),
            'keys' => ApiKey::where('tenant_id', $tenant->id)->latest('id')->paginate(15)->through(fn (ApiKey $key) => [
                ...$key->displayData(),
                'rotateUrl' => route('api-keys.rotate', [$tenant, $key]),
                'revokeUrl' => route('api-keys.revoke', [$tenant, $key]),
            ]),
            'canManage' => Gate::allows('manageApiKeys', $tenant),
            'storeUrl' => route('api-keys.store', $tenant),
            'checkUrl' => route('api.me'),
        ]);
    }

    public function store(StoreApiKeyRequest $request, Tenant $tenant, ApiKeys $service): JsonResponse
    {
        $result = $service->issue($tenant, $request->user(), $request->validated());

        return response()->json(['key' => $result['key']->displayData(), 'secret' => $result['secret']], 201)->header('Cache-Control', 'no-store, private');
    }

    public function rotate(Request $request, Tenant $tenant, ApiKey $apiKey, ApiKeys $service): JsonResponse
    {
        Gate::authorize('manageApiKeys', $tenant);
        abort_unless($apiKey->tenant_id === $tenant->id, 404);
        $result = $service->rotate($tenant, $apiKey, $request->user());

        return response()->json(['key' => $result['key']->displayData(), 'secret' => $result['secret']], 201)->header('Cache-Control', 'no-store, private');
    }

    public function revoke(Request $request, Tenant $tenant, ApiKey $apiKey, ApiKeys $service): JsonResponse
    {
        Gate::authorize('manageApiKeys', $tenant);
        abort_unless($apiKey->tenant_id === $tenant->id, 404);
        $service->revoke($tenant, $apiKey, $request->user());

        return response()->json(['message' => 'Clé révoquée.'])->header('Cache-Control', 'no-store, private');
    }
}
