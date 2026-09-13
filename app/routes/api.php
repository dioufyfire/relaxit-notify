<?php

use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/v1/me', function (Request $request) {
    return response()->json([
        'tenant' => $request->attributes->get('apiTenant')->only('id', 'code', 'name'),
        'application' => $request->attributes->get('apiKey')->application,
    ]);
})->middleware(['throttle:api-entry', AuthenticateApiKey::class])->name('api.me');
