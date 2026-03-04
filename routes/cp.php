<?php

use Illuminate\Support\Facades\Route;
use TakepartMedia\Vidiq\Http\Controllers\AssetsController;

Route::prefix('vidiq')->group(function () {
    Route::get('assets', [AssetsController::class, 'thumbnails'])->name('vidiq.assets');
    Route::get('player-url', [AssetsController::class, 'playerUrl'])->name('vidiq.player-url');
});
