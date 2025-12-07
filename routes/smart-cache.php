<?php

use Illuminate\Support\Facades\Route;
use SmartCache\Http\Controllers\StatisticsController;

/*
|--------------------------------------------------------------------------
| SmartCache Routes
|--------------------------------------------------------------------------
|
| These routes provide access to the SmartCache dashboard and API endpoints.
| They are registered with the prefix and middleware configured in the
| smart-cache config file.
|
*/

Route::get('/', [StatisticsController::class, 'dashboard'])->name('smart-cache.dashboard');
Route::get('/statistics', [StatisticsController::class, 'index'])->name('smart-cache.statistics');
Route::get('/health', [StatisticsController::class, 'health'])->name('smart-cache.health');
Route::get('/keys', [StatisticsController::class, 'keys'])->name('smart-cache.keys');
Route::get('/commands', [StatisticsController::class, 'commands'])->name('smart-cache.commands');

