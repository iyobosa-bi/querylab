<?php

use App\Http\Controllers\Lab\CacheLabController;
use App\Http\Controllers\Lab\QueryLabController;
use App\Http\Middleware\EnsureLabEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureLabEnabled::class)->prefix('lab')->name('lab.')->group(function () {
    Route::get('/', [QueryLabController::class, 'index'])->name('index');

    Route::prefix('cache')->name('cache.')->controller(CacheLabController::class)->group(function () {
        Route::get('dashboard', 'dashboard')->name('dashboard');
        Route::post('dashboard/clear', 'clearDashboard')->name('dashboard.clear');

        Route::get('technicians', 'busiestTechnician')->name('technicians');
        Route::post('technicians/flush-all', 'flushAllTechnicians')->name('technicians.flush-all');
        Route::get('technicians/{technician}', 'technician')->whereNumber('technician')->name('technician');
        Route::post('technicians/{technician}/flush', 'flushTechnician')->whereNumber('technician')->name('technician.flush');

        Route::get('otp', 'otp')->name('otp');
        Route::post('otp/send', 'sendOtp')->middleware('throttle:20,1')->name('otp.send');
        Route::post('otp/verify', 'verifyOtp')->middleware('throttle:20,1')->name('otp.verify');
    });

    // Keep last: it matches any single segment.
    Route::get('{slug}', [QueryLabController::class, 'show'])->name('show');
});
