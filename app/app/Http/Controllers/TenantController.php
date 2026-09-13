<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Tenants/Index', [
            'tenants' => Tenant::accessibleTo($request->user())->orderBy('name')->paginate(12)
                ->through(fn (Tenant $tenant) => [
                    ...$tenant->only('id', 'code', 'name', 'address'),
                    'url' => route('tenants.show', $tenant),
                    'selectUrl' => route('tenants.select', $tenant),
                ]),
            'canCreate' => $request->user()->can('create', Tenant::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Tenant::class);

        return Inertia::render('Tenants/Form', ['tenant' => null, 'canUpdate' => true, 'saveUrl' => route('tenants.store')]);
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $tenant = Tenant::create($request->validated());

        return to_route('tenants.show', $tenant)->with('success', 'Client créé.');
    }

    public function show(Tenant $tenant): Response
    {
        Gate::authorize('view', $tenant);

        return Inertia::render('Tenants/Form', [
            'tenant' => $tenant->only('id', 'code', 'name', 'email', 'phone', 'address'),
            'canUpdate' => Gate::allows('update', $tenant),
            'saveUrl' => route('tenants.update', $tenant),
            'selectUrl' => route('tenants.select', $tenant),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update($request->validated());

        return to_route('tenants.show', $tenant)->with('success', 'Coordonnées enregistrées.');
    }

    public function select(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('view', $tenant);
        $request->session()->put('tenant_id', $tenant->id);
        $request->session()->regenerate();

        return to_route('dashboard')->with('success', 'Client sélectionné : '.$tenant->name);
    }
}
