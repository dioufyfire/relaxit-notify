<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/\Arln_([0-9A-HJKMNP-TV-Z]{26})_[a-f0-9]{64}\z/', $token, $parts)) {
            return $this->unauthorized();
        }
        $key = ApiKey::with('tenant')->where('public_id', $parts[1])->first();
        if (! $key || ! hash_equals($key->token_hash, hash('sha256', $token)) || ! $key->isUsable() || ! $key->tenant?->is_active) {
            return $this->unauthorized();
        }

        $limit = 'api-tenant:'.$key->tenant_id;
        if (RateLimiter::tooManyAttempts($limit, config('api.requests_per_minute'))) {
            return response()->json(['message' => 'Limite de requêtes atteinte.'], 429)
                ->header('Retry-After', RateLimiter::availableIn($limit))->header('Cache-Control', 'no-store');
        }
        RateLimiter::hit($limit, 60);
        $request->attributes->set('apiTenant', $key->tenant);
        $request->attributes->set('apiKey', $key);
        $key->last_used_at = now();
        $key->save();
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Clé API absente ou invalide.'], 401)
            ->header('WWW-Authenticate', 'Bearer')->header('Cache-Control', 'no-store');
    }
}
