<?php

use Illuminate\Support\Facades\Route;
use TakepartMedia\Vidiq\Http\Controllers\VidiQAssetsController;

Route::prefix('vidiq')->group(function () {
    Route::get('assets', [VidiQAssetsController::class, 'thumbnails'])->name('vidiq.assets');
    Route::get('player-url', [VidiQAssetsController::class, 'playerUrl'])->name('vidiq.player-url');
});
