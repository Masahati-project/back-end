<?php

use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Admin\AdminOverviewController;
use App\Http\Controllers\Api\Admin\AdminUsersController;
use App\Http\Controllers\Api\Admin\AdminSpacesController;
use App\Http\Controllers\Api\Admin\AdminBookingsController;
use App\Http\Controllers\Api\Admin\AdminDisputesController;
use App\Http\Controllers\Api\Admin\AdminReviewsController;
use App\Http\Controllers\Api\Admin\AdminFinancialsController;
use App\Http\Controllers\Api\Admin\AdminInboxController;
use App\Http\Controllers\Api\Admin\AdminBroadcastsController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AdminAuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::patch('/profile', [AdminAuthController::class, 'updateProfile']);
    Route::put('/password', [AdminAuthController::class, 'changePassword']);

    // Settings
    Route::get('/settings', [AdminSettingsController::class, 'index']);
    Route::put('/settings', [AdminSettingsController::class, 'update']);

    // Overview Dashboard
    Route::get('/stats', [AdminOverviewController::class, 'stats']);
    Route::get('/stats/revenue-trend', [AdminOverviewController::class, 'revenueTrend']);
    Route::get('/activities', [AdminOverviewController::class, 'activities']);
    Route::get('/recent-registrations', [AdminOverviewController::class, 'recentRegistrations']);

    // Users
    Route::get('/users', [AdminUsersController::class, 'index']);
    Route::get('/users/stats', [AdminUsersController::class, 'stats']);
    Route::get('/users/export', [AdminUsersController::class, 'export']);
    Route::get('/users/{id}', [AdminUsersController::class, 'show']);
    Route::patch('/users/{id}', [AdminUsersController::class, 'update']);
    Route::patch('/users/{id}/status', [AdminUsersController::class, 'updateStatus']);
    Route::patch('/users/{id}/verify', [AdminUsersController::class, 'verify']);
    Route::delete('/users/{id}', [AdminUsersController::class, 'destroy']);
    Route::post('/users/bulk-status', [AdminUsersController::class, 'bulkStatus']);

    // Spaces
    Route::get('/spaces', [AdminSpacesController::class, 'index']);
    Route::get('/spaces/stats', [AdminSpacesController::class, 'stats']);
    Route::get('/spaces/export', [AdminSpacesController::class, 'export']);
    Route::get('/spaces/{id}', [AdminSpacesController::class, 'show']);
    Route::patch('/spaces/{id}', [AdminSpacesController::class, 'update']);
    Route::patch('/spaces/{id}/status', [AdminSpacesController::class, 'updateStatus']);
    Route::delete('/spaces/{id}', [AdminSpacesController::class, 'destroy']);

    // Bookings
    Route::get('/bookings', [AdminBookingsController::class, 'index']);
    Route::get('/bookings/{ref}', [AdminBookingsController::class, 'show']);
    Route::patch('/bookings/{id}/status', [AdminBookingsController::class, 'updateStatus']);

    // Disputes
    Route::get('/disputes', [AdminDisputesController::class, 'index']);
    Route::get('/disputes/{ref}', [AdminDisputesController::class, 'show']);
    Route::patch('/disputes/{ref}/resolve', [AdminDisputesController::class, 'resolve']);

    // Reviews
    Route::get('/reviews', [AdminReviewsController::class, 'index']);
    Route::get('/reviews/stats', [AdminReviewsController::class, 'stats']);
    Route::patch('/reviews/{id}', [AdminReviewsController::class, 'update']);
    Route::delete('/reviews/{id}', [AdminReviewsController::class, 'destroy']);

    // Financials
    Route::get('/financials/summary', [AdminFinancialsController::class, 'summary']);
    Route::get('/financials/daily', [AdminFinancialsController::class, 'daily']);
    Route::get('/financials/commission-breakdown', [AdminFinancialsController::class, 'commissionBreakdown']);
    Route::get('/financials/transactions', [AdminFinancialsController::class, 'transactions']);
    Route::get('/financials/export', [AdminFinancialsController::class, 'export']);

    // Inbox
    Route::get('/inbox', [AdminInboxController::class, 'index']);
    Route::get('/inbox/unread-count', [AdminInboxController::class, 'unreadCount']);
    Route::get('/inbox/categories', [AdminInboxController::class, 'categories']);
    Route::patch('/inbox/{id}', [AdminInboxController::class, 'update']);
    Route::post('/inbox/bulk', [AdminInboxController::class, 'bulk']);
    Route::post('/inbox/mark-all-read', [AdminInboxController::class, 'markAllRead']);

    // Broadcasts
    Route::get('/broadcasts', [AdminBroadcastsController::class, 'index']);
    Route::get('/broadcasts/audience-counts', [AdminBroadcastsController::class, 'audienceCounts']);
    Route::get('/broadcasts/drafts', [AdminBroadcastsController::class, 'drafts']);
    Route::post('/broadcasts', [AdminBroadcastsController::class, 'send']);
    Route::post('/broadcasts/{id}/resend', [AdminBroadcastsController::class, 'resend']);
    Route::post('/broadcasts/drafts', [AdminBroadcastsController::class, 'createDraft']);
    Route::put('/broadcasts/drafts/{id}', [AdminBroadcastsController::class, 'updateDraft']);
    Route::delete('/broadcasts/drafts/{id}', [AdminBroadcastsController::class, 'deleteDraft']);

    // Notifications
    Route::get('/notifications/unread-count', function() {
        return response()->json([
            'data' => [
                'unread' => \App\Models\InboxNotification::where('read', false)->where('archived', false)->count(),
            ]
        ]);
    });
});
