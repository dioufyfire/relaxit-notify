<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Tenant;
use App\Services\MetaWhatsApp;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MetaWhatsApp $provider): Response
    {
        $tenant = $request->attributes->get('currentTenant');
        $since = now()->subHours(24);
        $operations = null;
        if ($tenant) {
            $query = Notification::where('tenant_id', $tenant->id);
            $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $operations = [
                'received24h' => (clone $query)->where('created_at', '>=', $since)->count(),
                'delivered24h' => (clone $query)->where('created_at', '>=', $since)->whereIn('status', ['delivered', 'read'])->count(),
                'attempts24h' => (clone $query)->where('send_started_at', '>=', $since)->count(),
                'counts' => $counts,
                'overdue' => (clone $query)->whereIn('status', ['pending', 'scheduled', 'queued', 'sending', 'submitted', 'sent'])
                    ->whereRaw('COALESCE(schedule_at, created_at) <= ?', [now()->subHour()])->count(),
                'issues' => (clone $query)->whereIn('status', ['failed', 'blocked', 'delivery_unknown'])->latest('updated_at')->latest('id')->limit(10)->get()
                    ->map(fn (Notification $notification) => [
                        'id' => $notification->public_id,
                        'template' => $notification->template,
                        'status' => $notification->status,
                        'error_code' => $notification->error_code,
                        'updated_at' => $notification->updated_at->toISOString(),
                        'url' => route('notifications.index', ['tenant' => $tenant, 'reference' => $notification->public_id]),
                    ]),
                'links' => collect(['failed', 'blocked', 'delivery_unknown', 'awaiting_provider'])->mapWithKeys(fn (string $status) => [$status => route('notifications.index', ['tenant' => $tenant, 'status' => $status])]),
            ];
        }
        $quota = null;
        if ($request->user()->platform_role !== null) {
            $rawLimit = config('meta_whatsapp.max_attempts_per_24h', 250);
            $limit = (is_int($rawLimit) || is_string($rawLimit)) ? filter_var($rawLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 250]]) : false;
            $used = $provider->attemptsInLast24Hours();
            $quota = ['used' => $used, 'limit' => $limit === false ? null : $limit, 'remaining' => $limit === false ? null : max(0, $limit - $used)];
        }

        return Inertia::render('Dashboard', [
            'whatsappPilotEnabled' => (bool) config('meta_whatsapp.enabled'),
            'whatsappMode' => config('meta_whatsapp.mode', 'pilot'),
            'notificationCounts' => $operations['counts'] ?? null,
            'tenantCount' => Tenant::accessibleTo($request->user())->count(),
            'operations' => $operations,
            'quota' => $quota,
            'refreshedAt' => now()->toISOString(),
        ]);
    }
}
