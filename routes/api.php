<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SpacesController;
use Illuminate\Support\Facades\Route;


Route::middleware('guest:sanctum')->group(function () {
    Route::post('/register/space-owner', [AuthController::class, 'registerSpaceOwnerAccount'])->name('register.space-owner');
    Route::post('/register/customer', [AuthController::class, 'registerCustomerAccount'])->name('register.customer');
    Route::post('/login', [AuthController::class, 'loginAccount'])->middleware('throttle:5,1')->name('login');
    Route::post('/forgot-password', [PasswordController::class, 'forgotPassword'])->name('password.forgot');
    Route::post('/reset-password', [PasswordController::class, 'resetPassword'])->name('password.reset');
    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])->name('otp.verify');
    Route::post('/resend-otp', [OtpController::class, 'resendOtp'])->name('otp.resend');
    Route::post('/auth/google', [GoogleAuthController::class, 'loginWithGoogle']);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [ProfileController::class, 'profile'])->name('profile');
    Route::patch('/owner/profile', [ProfileController::class, 'updateOwnerProfile'])->name('owner.profile.update');
    Route::patch('/customer/profile', [ProfileController::class, 'updateCustomerProfile'])->name('customer.profile.update');
    Route::patch('/profile/picture', [ProfileController::class, 'updateProfilePicture'])->name('profile.picture.update');
    Route::post('/change-pass', [ProfileController::class, 'changePassword'])->name('password.change');
    Route::post('/logout', [AuthController::class, 'logoutAccount'])->name('user.logout');
    Route::delete('/delete-user', [AuthController::class, 'deleteAccount'])->name('user.delete');

    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
    Route::get('/dashboard/upcoming-booking', [DashboardController::class, 'upcomingBooking'])->name('dashboard.upcoming.booking');
    Route::get('/dashboard/bookings', [DashboardController::class, 'bookings'])->name('dashboard.booking');
    Route::get('/dashboard/favorites', [DashboardController::class, 'favoriteSpaces'])->name('favorite.spaces');
    Route::post('/dashboard/favorites/toggle', [DashboardController::class, 'toggle'])->name('favorite.toggle');
    Route::get('/dashboard/spaces', [SpacesController::class, 'index'])->name('spaces');

});
