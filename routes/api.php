<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerDashboardController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProfileController;
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
        ->middleware('throttle:5,1')
        ->name('password.reset');
    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])
        ->middleware('throttle:5,1')
        ->name('otp.verify');
    Route::post('/resend-otp', [OtpController::class, 'resendOtp'])
        ->middleware('throttle:3,1')
        ->name('otp.resend');
    Route::post('/auth/google', [GoogleAuthController::class, 'loginWithGoogle'])
        ->middleware('throttle:5,1');
});


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
    Route::get('/special-requests', [SpecialRequestController::class, 'index'])->name('special-requests.index');
    Route::post('/special-requests', [SpecialRequestController::class, 'store'])->name('special-requests.store');
    Route::get('/special-requests/{requestId}', [SpecialRequestController::class, 'show'])->name('special-requests.show');
    Route::post('/special-requests/{requestId}/offers/{offerId}/accept', [SpecialRequestController::class, 'acceptOffer'])->name('special-requests.accept-offer');
    Route::post('/special-requests/{requestId}/offers/{offerId}/reject', [SpecialRequestController::class, 'rejectOffer'])->name('special-requests.reject-offer');
    Route::post('/special-requests/{requestId}/close', [SpecialRequestController::class, 'closeRequest'])->name('special-requests.close');

    // Notifications Routes
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');

    // Owner Routes (Space Owner Dashboard)
    Route::middleware('auth:sanctum')->prefix('owner')->group(function () {
        // Spaces
        Route::get('/spaces', [OwnerSpaceController::class, 'index'])->name('owner.spaces.index');
        Route::post('/spaces', [OwnerSpaceController::class, 'store'])->name('owner.spaces.store');
        Route::put('/spaces/{space}', [OwnerSpaceController::class, 'update'])->name('owner.spaces.update');
        Route::patch('/spaces/{space}/active', [OwnerSpaceController::class, 'toggleActive'])->name('owner.spaces.toggle-active');
        Route::delete('/spaces/{space}', [OwnerSpaceController::class, 'destroy'])->name('owner.spaces.destroy');

        // Bookings
        Route::get('/bookings', [OwnerBookingController::class, 'index'])->name('owner.bookings.index');
        Route::patch('/bookings/{booking}/status', [OwnerBookingController::class, 'updateStatus'])->name('owner.bookings.update-status');

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
        Route::put('/ads/{ad}', [OwnerAdController::class, 'update'])->name('owner.ads.update');
        Route::delete('/ads/{ad}', [OwnerAdController::class, 'destroy'])->name('owner.ads.destroy');
        Route::post('/ads/{ad}/publish', [OwnerAdController::class, 'publish'])->name('owner.ads.publish');
    });

});

Route::post('/assistant/chat', [ChatController::class, 'sendMessage'])
        ->middleware('throttle:10,1');
