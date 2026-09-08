
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


/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Register
Route::post('/register', [
    UserController::class,
    'register'
]);

// Login
Route::post('/login', [
    UserController::class,
    'login'
]);

// Spaces

Route::get('/spaces', [
    SpaceController::class,
    'index'
]);


/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Dashboard
    Route::get('/dashboard/stats', [
        DashboardController::class,
        'stats'
    ]);
    Route::post('/spaces', [SpaceController::class, 'store']);

    Route::get('/dashboard/upcoming-courses', [
        DashboardController::class,
        'upcomingCourses'
    ]);


    // Bookings
    Route::get('/bookings', [
        BookingController::class,
        'index'
    ]);


    // Favorites
    Route::get('/favorites', [
        FavoriteController::class,
        'index'
    ]);

    Route::post('/favorites/toggle', [
        FavoriteController::class,
        'toggle'
    ]);


    // Profile
    Route::get('/profile', [
        ProfileController::class,
        'show'
    ]);

    Route::put('/profile/update', [
        ProfileController::class,
        'update'
    ]);

    Route::post('/profile/change-password', [
        ProfileController::class,
        'changePassword'
    ]);


    // Subscription
    Route::get('/subscription/status', [
        SubscriptionController::class,
        'status'
    ]);


    // Student Dashboard
    Route::get('/student/dashboard', [
        StudentDashboardController::class,
        'index'
    ]);


    // Logout
    Route::post('/logout', [
        UserController::class,
        'logout'
    ]);
});
Route::prefix('assistant')->group(function () {
    Route::get('/', [AiAssistantController::class, 'index']);
    Route::post('/start', [AiAssistantController::class, 'start']);
    Route::post('/ask', [AiAssistantController::class, 'ask']);
    Route::get('/history/{conversationId}', [AiAssistantController::class, 'history']);
});
/*
|--------------------------------------------------------------------------
| Phone Verification
|--------------------------------------------------------------------------
*/

Route::post('/phone/send-code', [
    PhoneVerificationController::class,
    'sendCode'
]);

Route::post('/phone/verify-code', [
    PhoneVerificationController::class,
    'verifyCode'
]);


 
