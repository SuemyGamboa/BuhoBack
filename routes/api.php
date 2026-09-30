<?php

use App\Http\Controllers\Api\ActivityCatalogController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\StripeBillingController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Middleware\EnsurePaidAdmin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/subjects', [SubjectController::class, 'publicIndex']);

Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ValidateCsrfToken::class,
])->group(function (): void {
    Route::get('/csrf-token', [AdminAuthController::class, 'csrfToken']);
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/admin/register', [AdminAuthController::class, 'register'])->middleware('throttle:3,1');
    Route::post('/admin/logout', [AdminAuthController::class, 'logout']);

    Route::prefix('admin')->middleware('supabase.admin')->group(function (): void {
        Route::get('/session', [AdminAuthController::class, 'session']);
        Route::post('/billing/checkout', [StripeBillingController::class, 'checkout']);
        Route::post('/billing/portal', [StripeBillingController::class, 'portal']);
        Route::get('/achievements', [ActivityCatalogController::class, 'achievements']);
        Route::get('/rewards', [ActivityCatalogController::class, 'rewards']);

        Route::get('/subjects', [SubjectController::class, 'index']);
        Route::post('/subjects', [SubjectController::class, 'store'])->middleware(EnsurePaidAdmin::class);
        Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->middleware(EnsurePaidAdmin::class);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->middleware(EnsurePaidAdmin::class);
        Route::post('/activities/cover', [ActivityController::class, 'uploadCover'])->middleware(EnsurePaidAdmin::class);
        Route::post('/activities/media', [ActivityController::class, 'uploadMedia'])->middleware(EnsurePaidAdmin::class);
        Route::post('/subjects/{subject}/activities', [ActivityController::class, 'store'])->middleware(EnsurePaidAdmin::class);
        Route::put('/activities/{activity}', [ActivityController::class, 'update'])->middleware(EnsurePaidAdmin::class);
        Route::delete('/activities/{activity}', [ActivityController::class, 'destroy'])->middleware(EnsurePaidAdmin::class);
    });
});
