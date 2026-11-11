<?php

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\MarketplaceAdminController;
use App\Http\Controllers\Api\V1\Admin\ProviderVerificationController;
use App\Http\Controllers\Api\V1\Admin\UserManagementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerMarketplaceController;
use App\Http\Controllers\Api\V1\ProviderAvailabilityController;
use App\Http\Controllers\Api\V1\ProviderMarketplaceController;
use App\Http\Controllers\Api\V1\ProviderOfferController;
use App\Http\Controllers\Api\V1\WaitlistController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/waitlist', [WaitlistController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'neybor-api',
        'timestamp' => now()->toIso8601String(),
    ]))->middleware('throttle:30,1');

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyLink'])
            ->middleware('signed')->name('api.auth.email.verify.link');

        Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
                ->middleware('throttle:6,1');
            Route::post('/email/verify', [AuthController::class, 'verify']);
        });
    });

    Route::prefix('customer')->group(function (): void {
        Route::get('/categories', [CustomerMarketplaceController::class, 'categories']);
        Route::get('/services', [CustomerMarketplaceController::class, 'services']);
        Route::get('/services/{service}', [CustomerMarketplaceController::class, 'showService']);
        Route::get('/providers/{provider}', [CustomerMarketplaceController::class, 'showProvider']);

        Route::middleware(['auth:sanctum', 'account.active', 'role:customer'])->group(function (): void {
            Route::get('/account', fn (Request $request) => response()->json(['data' => $request->user()]));
            Route::get('/bookings', [CustomerMarketplaceController::class, 'bookings']);
            Route::post('/bookings', [CustomerMarketplaceController::class, 'createBooking']);
            Route::get('/bookings/{booking}', [CustomerMarketplaceController::class, 'showBooking']);
            Route::post('/bookings/{booking}/retry-matching', [CustomerMarketplaceController::class, 'retryMatching']);
            Route::post('/bookings/{booking}/cancel', [CustomerMarketplaceController::class, 'cancelBooking']);
            Route::post('/bookings/{booking}/complete', [CustomerMarketplaceController::class, 'completeBooking']);
            Route::post('/bookings/{booking}/review', [CustomerMarketplaceController::class, 'reviewBooking']);
            Route::get('/saved-locations', [CustomerMarketplaceController::class, 'savedLocations']);
            Route::post('/saved-locations', [CustomerMarketplaceController::class, 'createSavedLocation']);
            Route::patch('/saved-locations/{location}', [CustomerMarketplaceController::class, 'updateSavedLocation']);
            Route::delete('/saved-locations/{location}', [CustomerMarketplaceController::class, 'deleteSavedLocation']);
            Route::get('/notifications', [CustomerMarketplaceController::class, 'notifications']);
            Route::post('/notifications/{notification}/read', [CustomerMarketplaceController::class, 'readNotification']);
            Route::post('/push-tokens', [CustomerMarketplaceController::class, 'registerPushToken']);
            Route::patch('/profile', [CustomerMarketplaceController::class, 'updateProfile']);
            Route::get('/support-tickets', [CustomerMarketplaceController::class, 'supportTickets']);
            Route::post('/support-tickets', [CustomerMarketplaceController::class, 'createSupportTicket']);
        });
    });

    Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
        Route::get('/user', fn (Request $request) => $request->user());

        Route::prefix('provider')
            ->middleware('role:service_provider')
            ->group(function (): void {
                Route::get('/account', fn (Request $request) => response()->json([
                    'data' => $request->user(),
                    'capabilities' => [
                        'manage_services' => $request->user()->provider_verification_status
                            === ProviderVerificationStatus::Verified
                            && $request->user()->hasVerifiedEmail(),
                    ],
                ]));
                Route::get('/dashboard', [ProviderMarketplaceController::class, 'dashboard']);
                Route::get('/profile', [ProviderMarketplaceController::class, 'profile']);
                Route::patch('/profile', [ProviderMarketplaceController::class, 'updateProfile']);
                Route::post('/profile/photo', [ProviderMarketplaceController::class, 'uploadProfilePhoto']);
                Route::post('/identity-document', [ProviderMarketplaceController::class, 'uploadIdentityDocument']);
                Route::post('/submit-for-approval', [ProviderMarketplaceController::class, 'submitForApproval']);
                Route::get('/services', [ProviderMarketplaceController::class, 'services']);
                Route::post('/services', [ProviderMarketplaceController::class, 'createService']);
                Route::patch('/services/{service}', [ProviderMarketplaceController::class, 'updateService']);
                Route::delete('/services/{service}', [ProviderMarketplaceController::class, 'deleteService']);
                Route::get('/portfolio', [ProviderMarketplaceController::class, 'portfolio']);
                Route::post('/portfolio', [ProviderMarketplaceController::class, 'addPortfolioItem']);
                Route::delete('/portfolio/{item}', [ProviderMarketplaceController::class, 'deletePortfolioItem']);
                Route::get('/bookings', [ProviderMarketplaceController::class, 'bookings']);
                Route::patch('/bookings/{booking}', [ProviderMarketplaceController::class, 'updateBooking']);
                Route::post('/bookings/{booking}/cancel', [ProviderMarketplaceController::class, 'cancelBooking']);
                Route::get('/notifications', [ProviderMarketplaceController::class, 'notifications']);
                Route::post('/notifications/{notification}/read', [ProviderMarketplaceController::class, 'readNotification']);
                Route::post('/push-tokens', [CustomerMarketplaceController::class, 'registerPushToken']);
                Route::get('/categories', [CustomerMarketplaceController::class, 'categories']);
                Route::get('/operations', fn () => response()->json([
                    'role' => UserRole::ServiceProvider->value,
                    'status' => 'verified',
                ]))->middleware('provider.verified');
                Route::patch('/availability', [ProviderAvailabilityController::class, 'update'])
                    ->middleware('provider.verified');
                Route::get('/offers', [ProviderOfferController::class, 'index'])
                    ->middleware('provider.verified');
                Route::post('/offers/{offer}/respond', [ProviderOfferController::class, 'respond'])
                    ->middleware('provider.verified');
            });

        Route::prefix('admin')
            ->middleware(['role:administrator', 'throttle:120,1'])
            ->group(function (): void {
                Route::get('/overview', [MarketplaceAdminController::class, 'overview']);
                Route::get('/users', [UserManagementController::class, 'index']);
                Route::patch('/users/{user}/role', [UserManagementController::class, 'updateRole']);
                Route::post('/users/{user}/suspend', [UserManagementController::class, 'suspend']);
                Route::post('/users/{user}/unsuspend', [UserManagementController::class, 'unsuspend']);
                Route::get('/providers', [ProviderVerificationController::class, 'index']);
                Route::post('/providers/{user}/verification', [ProviderVerificationController::class, 'decide']);
                Route::get('/providers/{user}/identity-document', [ProviderMarketplaceController::class, 'identityDocument']);
                Route::get('/services', [MarketplaceAdminController::class, 'services']);
                Route::patch('/services/{service}/moderation', [MarketplaceAdminController::class, 'moderateService']);
                Route::get('/bookings', [MarketplaceAdminController::class, 'bookings']);
                Route::get('/reviews', [MarketplaceAdminController::class, 'reviews']);
                Route::delete('/reviews/{review}', [MarketplaceAdminController::class, 'removeReview']);
                Route::get('/categories', [MarketplaceAdminController::class, 'categories']);
                Route::post('/categories', [MarketplaceAdminController::class, 'createCategory']);
                Route::patch('/categories/{category}', [MarketplaceAdminController::class, 'updateCategory']);
                Route::delete('/categories/{category}', [MarketplaceAdminController::class, 'deleteCategory']);
                Route::get('/reports', [MarketplaceAdminController::class, 'reports']);
                Route::get('/settings', [MarketplaceAdminController::class, 'settings']);
                Route::patch('/settings', [MarketplaceAdminController::class, 'updateSettings']);
                Route::get('/waitlist', [MarketplaceAdminController::class, 'waitlist']);
                Route::get('/notifications', [AdminNotificationController::class, 'index']);
                Route::post('/notifications/{notification}/read', [AdminNotificationController::class, 'markRead']);
                Route::get('/audit-logs', [AuditLogController::class, 'index']);
            });
    });
});
