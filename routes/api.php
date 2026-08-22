<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;


Route::middleware('guest:sanctum')->group(function () {
    Route::post('/register/space-owner', [AuthController::class, 'registerSpaceOwnerAccount'])->name('register.space-owner');
    Route::post('/register/customer', [AuthController::class, 'registerCustomerAccount'])->name('register.customer');
    Route::post('/login', [AuthController::class, 'loginAccount'])->middleware('throttle:5,1')->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
    Route::post('/send-otp', [AuthController::class, 'sendOtp'])->name('otp.send');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->name('otp.verify');
});


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user-details', [AuthController::class, 'accountDetails'])->name('user.details');

    Route::post('/logout', [AuthController::class, 'logoutAccount'])->name('user.logout');
    Route::delete('/delete-user', [AuthController::class, 'deleteAccount'])->name('user.delete');
});
