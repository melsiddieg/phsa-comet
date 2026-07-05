<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// ── Auth ────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::get('/auth/redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
    Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');
    Route::post('/auth/local', [AuthController::class, 'loginLocal'])->name('auth.local');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')->name('logout');

// ── App ─────────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::view('/', 'home')->name('home');
});
