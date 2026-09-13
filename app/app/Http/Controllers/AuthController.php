<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt([...$request->validated(), 'is_active' => true])) {
            Audit::record('auth.failed');
            throw ValidationException::withMessages(['email' => 'Adresse email ou mot de passe incorrect.']);
        }

        Audit::record('auth.login', actor: $request->user());
        $request->session()->regenerate();
        $request->session()->forget('tenant_id');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Audit::record('auth.logout', actor: $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return to_route('login');
    }
}
