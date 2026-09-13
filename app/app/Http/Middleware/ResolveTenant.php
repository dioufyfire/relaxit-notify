<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Inertia::clearHistory();

            return redirect()->route('login');
        }

        $tenant = null;
        if ($user && $request->session()->has('tenant_id')) {
            $tenant = Tenant::accessibleTo($user)->find($request->session()->get('tenant_id'));
            if (! $tenant) {
                $request->session()->forget('tenant_id');
            }
        }
        $request->attributes->set('currentTenant', $tenant);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
