<?php

use App\Http\Controllers\Api\AchievementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StudentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Sanctum & JWT Token Authenticated REST API Routes
|
*/

// Public Authentication Endpoints (Rate Limited for brute-force defense)
Route::middleware('throttle:6,1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
});

// Protected Endpoints (Requires Sanctum Bearer Token)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/profile', [AuthController::class, 'profile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Student Resource APIs
    Route::apiResource('students', StudentController::class);

    // Achievements & Malware Security Scanned Upload API
    Route::get('/achievements', [AchievementController::class, 'index']);
    Route::post('/achievements', [AchievementController::class, 'store']);
});
