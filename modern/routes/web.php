<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SheetController;
use App\Livewire\ImportManager;
use App\Livewire\MappingGrid;
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

    Route::get('/sheets', [SheetController::class, 'sourceIndex'])->name('sheets.index');
    Route::get('/domains', [SheetController::class, 'domainIndex'])->name('domains.index');
    Route::get('/sheets/{sheet}', MappingGrid::class)->name('sheets.show');
    Route::get('/import', ImportManager::class)->name('import');
});
