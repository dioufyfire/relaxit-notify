<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        Gate::authorize('view', $tenant);
        $filters = $request->validate(['status' => ['nullable', Rule::in(Notification::STATUSES)]]);

        return Inertia::render('Notifications/Index', [
            'whatsappPilotEnabled' => (bool) config('meta_whatsapp.enabled'),
            'tenant' => $tenant->only('id', 'name', 'code'),
            'filters' => ['status' => $filters['status'] ?? ''],
            'indexUrl' => route('notifications.index', $tenant),
            'notifications' => Notification::where('tenant_id', $tenant->id)
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->latest('id')->paginate(20)->withQueryString()->through(fn (Notification $notification) => [
                    ...$notification->publicData(),
                    'recipient_masked' => '•••• '.substr($notification->recipient, -4),
                ]),
        ]);
    }
}
