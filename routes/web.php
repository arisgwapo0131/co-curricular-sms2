<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WebAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Laravel 11 + Vue 3 / Inertia.js Architecture)
|--------------------------------------------------------------------------
*/

// Root Entry Point
Route::get('/', function (Request $request) {
    if ($request->wantsJson()) {
        return response()->json([
            'system'  => 'BCP Co-Curricular Management System',
            'stack'   => 'Laravel 11 + Vue 3 / Inertia.js',
            'status'  => 'online',
            'version' => '2.0.0',
        ]);
    }

    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

// Authentication Routes
Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [WebAuthController::class, 'login'])->name('login.post');
Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');

// Authenticated Application Routes (Protected via web auth guard)
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/students', [DashboardController::class, 'students'])->name('students.index');
    Route::get('/clubs', [DashboardController::class, 'clubs'])->name('clubs.index');
    Route::get('/achievements', [DashboardController::class, 'achievements'])->name('achievements.index');
});
