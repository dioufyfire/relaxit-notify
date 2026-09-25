<?php

use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\NotificationApiController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/v1/me', function (Request $request) {
    return response()->json([
        'tenant' => $request->attributes->get('apiTenant')->only('id', 'code', 'name'),
        'application' => $request->attributes->get('apiKey')->application,
    ]);
})->middleware(['throttle:api-entry', AuthenticateApiKey::class])->name('api.me');

Route::middleware(['throttle:api-entry', AuthenticateApiKey::class])->group(function () {
    Route::post('/v1/notifications', [NotificationApiController::class, 'store'])->name('api.notifications.store');
    Route::get('/v1/notifications/{id}', [NotificationApiController::class, 'show'])->whereUlid('id')->name('api.notifications.show');
});

Route::get('/webhooks/whatsapp', [MetaWebhookController::class, 'verify'])->name('meta.webhook.verify');
Route::post('/webhooks/whatsapp', [MetaWebhookController::class, 'receive'])->name('meta.webhook.receive');
