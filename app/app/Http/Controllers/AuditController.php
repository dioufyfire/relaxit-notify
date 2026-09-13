<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request, ?Tenant $tenant = null): Response
    {
        if ($tenant) {
            Gate::authorize('viewAudit', $tenant);
        } else {
            abort_unless($request->user()->is_active && $request->user()->platform_role !== null, 403);
        }
        $query = AuditEvent::with(['actor:id,name', 'tenant:id,name'])->latest('id');
        if ($tenant) {
            $query->where('tenant_id', $tenant->id);
        }

        return Inertia::render('Audit/Index', [
            'tenant' => $tenant?->only('id', 'name', 'code'),
            'events' => $query->paginate(25)->through(fn (AuditEvent $event) => [
                'id' => $event->id, 'action' => $event->action, 'created_at' => $event->created_at,
                'actor' => $event->actor?->name ?? 'Système / visiteur',
                'tenant' => $event->tenant?->name ?? 'Plateforme',
                'metadata' => $event->metadata,
                'ip_address' => $request->user()->platform_role !== null ? $event->ip_address : null,
            ]),
        ]);
    }
}
