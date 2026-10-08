<?php

use App\Http\Controllers\Api\AuthController;
<<<<<<< HEAD
use App\Http\Controllers\Api\BookingController;
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
use App\Http\Controllers\Api\CustomerDashboardController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProfileController;
<<<<<<< HEAD
use App\Http\Controllers\Api\PublicAdController;
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
use App\Http\Controllers\Api\SpacesController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\SpecialRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OwnerSpaceController;
use App\Http\Controllers\Api\OwnerBookingController;
use App\Http\Controllers\Api\OwnerOfferController;
use App\Http\Controllers\Api\OwnerReviewController;
use App\Http\Controllers\Api\OwnerDocumentController;
use App\Http\Controllers\Api\OwnerAdController;
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


Route::middleware('guest:sanctum')->group(function () {
    Route::post('/register/space-owner', [AuthController::class, 'registerSpaceOwnerAccount'])
        ->middleware('throttle:5,1')
        ->name('register.space-owner');
    Route::post('/register/customer', [AuthController::class, 'registerCustomerAccount'])
        ->middleware('throttle:5,1')
        ->name('register.customer');
    Route::post('/login', [AuthController::class, 'loginAccount'])->middleware('throttle:5,1')->name('login');
    Route::post('/forgot-password', [PasswordController::class, 'forgotPassword'])
        ->middleware('throttle:5,1')
        ->name('password.forgot');
    Route::post('/reset-password', [PasswordController::class, 'resetPassword'])
<<<<<<< HEAD
        ->middleware('throttle-verified:reset-password')
        ->name('password.reset');
    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])
        ->middleware('throttle-verified:verify-otp')
        ->name('otp.verify');
    Route::post('/resend-otp', [OtpController::class, 'resendOtp'])
        ->middleware('throttle-verified:resend-otp')
=======
        ->middleware('throttle:5,1')
        ->name('password.reset');
    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])
        ->middleware('throttle:5,1')
        ->name('otp.verify');
    Route::post('/resend-otp', [OtpController::class, 'resendOtp'])
        ->middleware('throttle:3,1')
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
        ->name('otp.resend');
    Route::post('/auth/google', [GoogleAuthController::class, 'loginWithGoogle'])
        ->middleware('throttle:5,1');

    // Admin Auth
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');
});

<<<<<<< HEAD
Route::middleware('guest:sanctum')->group(function () {
    // Public spaces catalogue. Declared OUTSIDE the auth:sanctum group on purpose:
    // these are the endpoints an anonymous visitor hits before signing up, so they
    // must never answer 401. The route names differ from the authenticated
    // /dashboard/spaces alias (already named "spaces" above) — Laravel throws on a
    // duplicate route name, so these are namespaced as spaces.public.*.
    Route::get('/spaces', [SpacesController::class, 'index'])->name('spaces.public.index');
    Route::get('/spaces/{id}', [SpacesController::class, 'show'])
        ->whereNumber('id')
        ->name('spaces.public.show');
});

=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [ProfileController::class, 'profile'])->name('profile');
    Route::patch('/owner/profile', [ProfileController::class, 'updateOwnerProfile'])->name('owner.profile.update');
    Route::patch('/customer/profile', [ProfileController::class, 'updateCustomerProfile'])->name('customer.profile.update');
    Route::patch('/profile/picture', [ProfileController::class, 'updateProfilePicture'])->name('profile.picture.update');
    Route::post('/uploadPicture', [ProfileController::class, 'uploadPicture']);
    Route::post('/change-pass', [ProfileController::class, 'changePassword'])->name('password.change');
    Route::post('/logout', [AuthController::class, 'logoutAccount'])->name('user.logout');
    Route::delete('/delete-user', [AuthController::class, 'deleteAccount'])->name('user.delete');

    Route::get('/dashboard/stats', [CustomerDashboardController::class, 'stats'])->name('dashboard.stats');
    Route::get('/dashboard/upcoming-booking', [CustomerDashboardController::class, 'upcomingBooking'])->name('dashboard.upcoming.booking');
    Route::get('/dashboard/bookings', [CustomerDashboardController::class, 'bookings'])->name('dashboard.booking');
    Route::get('/dashboard/favorites', [CustomerDashboardController::class, 'favoriteSpaces'])->name('favorite.spaces');
    Route::post('/dashboard/favorites/toggle', [CustomerDashboardController::class, 'toggle'])->name('favorite.toggle');
    Route::get('/dashboard/spaces', [SpacesController::class, 'index'])->name('spaces');

    // Special Requests Routes
    // The literal /open route must be declared before /{requestId}, otherwise the
    // {requestId} segment swallows "open". whereNumber() makes that impossible
    // regardless of declaration order.
    Route::get('/special-requests', [SpecialRequestController::class, 'index'])->name('special-requests.index');
    Route::post('/special-requests', [SpecialRequestController::class, 'store'])->name('special-requests.store');
    Route::get('/special-requests/open', [SpecialRequestController::class, 'open'])->name('special-requests.open');
    Route::post('/special-requests/{requestId}/offers', [SpecialRequestController::class, 'storeOffer'])
        ->whereNumber('requestId')
        ->name('special-requests.offers.store');
    Route::get('/special-requests/{requestId}', [SpecialRequestController::class, 'show'])
        ->whereNumber('requestId')
        ->name('special-requests.show');
    Route::post('/special-requests/{requestId}/offers/{offerId}/accept', [SpecialRequestController::class, 'acceptOffer'])
        ->whereNumber('requestId')->whereNumber('offerId')
        ->name('special-requests.accept-offer');
    Route::post('/special-requests/{requestId}/offers/{offerId}/reject', [SpecialRequestController::class, 'rejectOffer'])
        ->whereNumber('requestId')->whereNumber('offerId')
        ->name('special-requests.reject-offer');
    Route::post('/special-requests/{requestId}/close', [SpecialRequestController::class, 'closeRequest'])
        ->whereNumber('requestId')
        ->name('special-requests.close');

    // Notifications Routes
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::match(['post', 'patch'], '/notifications/read', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.mark-all-as-read');

<<<<<<< HEAD
    // Customer Bookings Routes
    Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
    // PATCH, not DELETE: the agreed single cancel route, so a cancellation
    // reason can be carried in a body later. whereNumber keeps a non-numeric
    // id off the lookup.
    Route::patch('/bookings/{id}/cancel', [BookingController::class, 'cancel'])
        ->whereNumber('id')
        ->name('bookings.cancel');

    // Public Ads Feed — accessible to customer and space_owner roles
    Route::get('/ads/open', [PublicAdController::class, 'open'])->name('ads.open');

=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
    // Owner Routes (Space Owner Dashboard)
    Route::middleware('auth:sanctum')->prefix('owner')->group(function () {
        // Spaces
        Route::get('/spaces', [OwnerSpaceController::class, 'index'])->name('owner.spaces.index');
        Route::post('/spaces', [OwnerSpaceController::class, 'store'])->name('owner.spaces.store');
<<<<<<< HEAD
        // Literal routes MUST come before parameterised routes they shadow.
        // whereNumber() on the params below makes shadowing structurally impossible
        // regardless of declaration order.
        Route::get('/spaces/open', [OwnerSpaceController::class, 'open'])->name('owner.spaces.open');
        Route::get('/spaces/stats', [OwnerSpaceController::class, 'stats'])->name('owner.spaces.stats');
        Route::put('/spaces/{space}', [OwnerSpaceController::class, 'update'])
            ->whereNumber('space')
            ->name('owner.spaces.update');
        Route::patch('/spaces/{space}/active', [OwnerSpaceController::class, 'toggleActive'])
            ->whereNumber('space')
            ->name('owner.spaces.toggle-active');
        Route::delete('/spaces/{space}', [OwnerSpaceController::class, 'destroy'])
            ->whereNumber('space')
            ->name('owner.spaces.destroy');

        // Bookings
        Route::get('/bookings', [OwnerBookingController::class, 'index'])->name('owner.bookings.index');
        Route::patch('/bookings/{booking}/status', [OwnerBookingController::class, 'updateStatus'])
            ->whereNumber('booking')
            ->name('owner.bookings.update-status');
=======
        Route::put('/spaces/{space}', [OwnerSpaceController::class, 'update'])->name('owner.spaces.update');
        Route::patch('/spaces/{space}/active', [OwnerSpaceController::class, 'toggleActive'])->name('owner.spaces.toggle-active');
        Route::delete('/spaces/{space}', [OwnerSpaceController::class, 'destroy'])->name('owner.spaces.destroy');

        // Bookings
        Route::get('/bookings', [OwnerBookingController::class, 'index'])->name('owner.bookings.index');
        Route::patch('/bookings/{booking}/status', [OwnerBookingController::class, 'updateStatus'])->name('owner.bookings.update-status');
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687

        // Offers
        Route::get('/offers', [OwnerOfferController::class, 'index'])->name('owner.offers.index');

        // Reviews
        Route::get('/reviews', [OwnerReviewController::class, 'index'])->name('owner.reviews.index');

        // Documents
        Route::get('/documents', [OwnerDocumentController::class, 'index'])->name('owner.documents.index');
        Route::post('/documents', [OwnerDocumentController::class, 'store'])->name('owner.documents.store');

        // Ads
        Route::get('/ads', [OwnerAdController::class, 'index'])->name('owner.ads.index');
        Route::post('/ads', [OwnerAdController::class, 'store'])->name('owner.ads.store');
<<<<<<< HEAD
        // Literal routes before parameterised ones; whereNumber guards on params.
        Route::get('/ads/open', [OwnerAdController::class, 'open'])->name('owner.ads.open');
        Route::get('/ads/published', [OwnerAdController::class, 'published'])->name('owner.ads.published');
        Route::get('/ads/stats', [OwnerAdController::class, 'stats'])->name('owner.ads.stats');
        Route::put('/ads/{ad}', [OwnerAdController::class, 'update'])
            ->whereNumber('ad')
            ->name('owner.ads.update');
        Route::delete('/ads/{ad}', [OwnerAdController::class, 'destroy'])
            ->whereNumber('ad')
            ->name('owner.ads.destroy');
        Route::post('/ads/{ad}/publish', [OwnerAdController::class, 'publish'])
            ->whereNumber('ad')
            ->name('owner.ads.publish');
=======
        Route::put('/ads/{ad}', [OwnerAdController::class, 'update'])->name('owner.ads.update');
        Route::delete('/ads/{ad}', [OwnerAdController::class, 'destroy'])->name('owner.ads.destroy');
        Route::post('/ads/{ad}/publish', [OwnerAdController::class, 'publish'])->name('owner.ads.publish');
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687
    });

    // Admin Routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::patch('/profile', [AdminAuthController::class, 'updateProfile']);
        Route::put('/password', [AdminAuthController::class, 'changePassword']);
<<<<<<< HEAD
        Route::post('/profile/picture', [AdminAuthController::class, 'updateProfilePicture']);
=======
>>>>>>> 70ab341a93cda185b5426b47c12600dcb3d90687

        Route::get('/settings', [AdminSettingsController::class, 'index']);
        Route::put('/settings', [AdminSettingsController::class, 'update']);

        Route::get('/stats', [AdminOverviewController::class, 'stats']);
        Route::get('/stats/revenue-trend', [AdminOverviewController::class, 'revenueTrend']);
        Route::get('/activities', [AdminOverviewController::class, 'activities']);
        Route::get('/recent-registrations', [AdminOverviewController::class, 'recentRegistrations']);

        Route::get('/users', [AdminUsersController::class, 'index']);
        Route::get('/users/stats', [AdminUsersController::class, 'stats']);
        Route::get('/users/export', [AdminUsersController::class, 'export']);
        Route::get('/users/{id}', [AdminUsersController::class, 'show']);
        Route::patch('/users/{id}', [AdminUsersController::class, 'update']);
        Route::patch('/users/{id}/status', [AdminUsersController::class, 'updateStatus']);
        Route::patch('/users/{id}/verify', [AdminUsersController::class, 'verify']);
        Route::delete('/users/{id}', [AdminUsersController::class, 'destroy']);
        Route::post('/users/bulk-status', [AdminUsersController::class, 'bulkStatus']);

        Route::get('/spaces', [AdminSpacesController::class, 'index']);
        Route::get('/spaces/stats', [AdminSpacesController::class, 'stats']);
        Route::get('/spaces/export', [AdminSpacesController::class, 'export']);
        Route::get('/spaces/{id}', [AdminSpacesController::class, 'show']);
        Route::patch('/spaces/{id}', [AdminSpacesController::class, 'update']);
        Route::patch('/spaces/{id}/status', [AdminSpacesController::class, 'updateStatus']);
        Route::delete('/spaces/{id}', [AdminSpacesController::class, 'destroy']);

        Route::get('/bookings', [AdminBookingsController::class, 'index']);
        Route::get('/bookings/{ref}', [AdminBookingsController::class, 'show']);
        Route::patch('/bookings/{id}/status', [AdminBookingsController::class, 'updateStatus']);

        Route::get('/disputes', [AdminDisputesController::class, 'index']);
        Route::get('/disputes/{ref}', [AdminDisputesController::class, 'show']);
        Route::patch('/disputes/{ref}/resolve', [AdminDisputesController::class, 'resolve']);

        Route::get('/reviews', [AdminReviewsController::class, 'index']);
        Route::get('/reviews/stats', [AdminReviewsController::class, 'stats']);
        Route::patch('/reviews/{id}', [AdminReviewsController::class, 'update']);
        Route::delete('/reviews/{id}', [AdminReviewsController::class, 'destroy']);

        Route::get('/financials/summary', [AdminFinancialsController::class, 'summary']);
        Route::get('/financials/daily', [AdminFinancialsController::class, 'daily']);
        Route::get('/financials/commission-breakdown', [AdminFinancialsController::class, 'commissionBreakdown']);
        Route::get('/financials/transactions', [AdminFinancialsController::class, 'transactions']);
        Route::get('/financials/export', [AdminFinancialsController::class, 'export']);

        Route::get('/inbox', [AdminInboxController::class, 'index']);
        Route::get('/inbox/unread-count', [AdminInboxController::class, 'unreadCount']);
        Route::get('/inbox/categories', [AdminInboxController::class, 'categories']);
        Route::patch('/inbox/{id}', [AdminInboxController::class, 'update']);
        Route::post('/inbox/bulk', [AdminInboxController::class, 'bulk']);
        Route::post('/inbox/mark-all-read', [AdminInboxController::class, 'markAllRead']);

        Route::get('/broadcasts', [AdminBroadcastsController::class, 'index']);
        Route::get('/broadcasts/audience-counts', [AdminBroadcastsController::class, 'audienceCounts']);
        Route::get('/broadcasts/drafts', [AdminBroadcastsController::class, 'drafts']);
        Route::post('/broadcasts', [AdminBroadcastsController::class, 'send']);
        Route::post('/broadcasts/{id}/resend', [AdminBroadcastsController::class, 'resend']);
        Route::post('/broadcasts/drafts', [AdminBroadcastsController::class, 'createDraft']);
        Route::put('/broadcasts/drafts/{id}', [AdminBroadcastsController::class, 'updateDraft']);
        Route::delete('/broadcasts/drafts/{id}', [AdminBroadcastsController::class, 'deleteDraft']);

        Route::get('/notifications/unread-count', function() {
            return response()->json([
                'data' => [
                    'unread' => \App\Models\InboxNotification::where('read', false)->where('archived', false)->count(),
                ]
            ]);
        });
    });
});

Route::post('/assistant/chat', [ChatController::class, 'sendMessage'])
        ->middleware('throttle:10,1');

