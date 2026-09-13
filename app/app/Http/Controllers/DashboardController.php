<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $tenant = $request->attributes->get('currentTenant');

        return Inertia::render('Dashboard', [
            'notificationCounts' => $tenant ? Notification::where('tenant_id', $tenant->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') : null,
            'tenantCount' => Tenant::accessibleTo($request->user())->count(),
        ]);
    }
}
