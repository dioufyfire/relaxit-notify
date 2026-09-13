<?php

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/tenants/{tenant}/audit', [AuditController::class, 'index'])->name('tenants.audit');
    Route::get('/tenants/{tenant}/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('/tenants/{tenant}/api-keys', [ApiKeyController::class, 'store'])->middleware('throttle:30,1')->name('api-keys.store');
    Route::post('/tenants/{tenant}/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->middleware('throttle:30,1')->name('api-keys.rotate');
    Route::delete('/tenants/{tenant}/api-keys/{apiKey}', [ApiKeyController::class, 'revoke'])->name('api-keys.revoke');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('/tenants/{tenant}/select', [TenantController::class, 'select'])->name('tenants.select');
});
