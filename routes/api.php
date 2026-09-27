<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\UserController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\SpaceController;
use App\Http\Controllers\PhoneVerificationController;

// Import Owner Controllers
use App\Http\Controllers\Owner\SpaceController as OwnerSpaceController;
use App\Http\Controllers\Owner\BookingController as OwnerBookingController;
use App\Http\Controllers\Owner\OfferController as OwnerOfferController;
use App\Http\Controllers\Owner\ReviewController as OwnerReviewController;
use App\Http\Controllers\Owner\DocumentController as OwnerDocumentController;
use App\Http\Controllers\Owner\AdController as OwnerAdController;


/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Auth
Route::post('/register', [UserController::class, 'register']);
Route::post('/login', [UserController::class, 'login']);

// Spaces (Public View)
Route::get('/spaces', [SpaceController::class, 'index']);

// Phone Verification
Route::post('/phone/send-code', [PhoneVerificationController::class, 'sendCode']);
Route::post('/phone/verify-code', [PhoneVerificationController::class, 'verifyCode']);

// AI Assistant
Route::prefix('assistant')->group(function () {
    Route::get('/', [AiAssistantController::class, 'index']);
    Route::post('/start', [AiAssistantController::class, 'start']);
    Route::post('/ask', [AiAssistantController::class, 'ask']);
    Route::get('/history/{conversationId}', [AiAssistantController::class, 'history']);
});


/*
|--------------------------------------------------------------------------
| Protected Routes (Authenticated Users)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/upcoming-courses', [DashboardController::class, 'upcomingCourses']);

    // Bookings
    Route::get('/bookings', [BookingController::class, 'index']);

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites/toggle', [FavoriteController::class, 'toggle']);

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile/update', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);

    // Subscription
    Route::get('/subscription/status', [SubscriptionController::class, 'status']);

    // Student Dashboard
    Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);

    // Logout
    Route::post('/logout', [UserController::class, 'logout']);


    /*
    |--------------------------------------------------------------------------
    | Owner Routes (Space Owner Protected)
    |--------------------------------------------------------------------------
    */
    Route::prefix('owner')->middleware('is_owner')->group(function () {

        // Spaces Management
        Route::get('/spaces', [OwnerSpaceController::class, 'index']);
        Route::post('/spaces', [OwnerSpaceController::class, 'store']);
        Route::put('/spaces/{id}', [OwnerSpaceController::class, 'update']);
        Route::patch('/spaces/{id}/active', [OwnerSpaceController::class, 'toggleActive']);
        Route::delete('/spaces/{id}', [OwnerSpaceController::class, 'destroy']);

        // Bookings Management
        Route::get('/bookings', [OwnerBookingController::class, 'index']);
        Route::patch('/bookings/{id}/status', [OwnerBookingController::class, 'updateStatus']);

        // Offers & Reviews
        Route::get('/offers', [OwnerOfferController::class, 'index']);
        Route::get('/reviews', [OwnerReviewController::class, 'index']);

        // Verification Documents
        Route::get('/documents', [OwnerDocumentController::class, 'show']);
        Route::post('/documents', [OwnerDocumentController::class, 'store']);

        // Ads Management
        Route::get('/ads', [OwnerAdController::class, 'index']);
        Route::post('/ads', [OwnerAdController::class, 'store']);
        Route::put('/ads/{id}', [OwnerAdController::class, 'update']);
        Route::delete('/ads/{id}', [OwnerAdController::class, 'destroy']);
        Route::post('/ads/{id}/publish', [OwnerAdController::class, 'publish']);
    });

});