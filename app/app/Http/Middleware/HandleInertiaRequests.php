<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = $request->attributes->get('currentTenant');

        return [
            ...parent::share($request),
            'auth' => ['user' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->platform_role?->label() ?? ($tenant ? $user->roleIn($tenant)?->label() : 'Accès client'),
            ] : null],
            'currentTenant' => $tenant?->only('id', 'name', 'code'),
            'flash' => ['success' => fn () => $request->session()->get('success')],
            'urls' => [
                'login' => route('login'), 'logout' => route('logout'),
                'dashboard' => route('dashboard'), 'tenants' => route('tenants.index'),
                'tenantCreate' => route('tenants.create'),
                'apiKeys' => $tenant && $user?->can('viewApiKeys', $tenant) ? route('api-keys.index', $tenant) : null,
                'audit' => $user?->platform_role !== null && $user ? route('audit.index') : ($tenant && $user?->can('viewAudit', $tenant) ? route('tenants.audit', $tenant) : null),
            ],
        ];
    }
}
