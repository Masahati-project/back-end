<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\UserController;
use App\Http\Controllers\Api\StudentDashboardController;


Route::middleware('auth:sanctum')->group(function () {

  

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [
        UserController::class,
        'logout'
    ]);

});


Route::post('/register', [
    UserController::class,
    'register'
]);

Route::post('/login', [
    UserController::class,
    'login'
]);

    Route::middleware('auth:sanctum')->group(function () {
    Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);
});



 
