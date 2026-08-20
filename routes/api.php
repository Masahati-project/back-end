<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;


Route::middleware('guest')->group(function () {
    Route::post('/register/space-owner', [AuthController::class, 'registerSpaceOwnerAccount']);
    Route::post('/register/customer', [AuthController::class, 'registerCustomerAccount']);
    Route::post('/login', [AuthController::class, 'loginAccount']);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user-details', [AuthController::class, 'accountdetails']);

    Route::get('/logout', [AuthController::class, 'logoutAccount']);
    Route::delete('/delete-user', [AuthController::class, 'deleteAccount']);
});
