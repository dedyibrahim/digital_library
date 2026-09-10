<?php

use App\Http\Controllers\Api\DesktopController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/desktop')->group(function (): void {
    Route::post('login', [DesktopController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::get('me', [DesktopController::class, 'me']);
        Route::post('logout', [DesktopController::class, 'logout']);
        Route::get('files', [DesktopController::class, 'index']);
        Route::post('files', [DesktopController::class, 'store']);
        Route::get('files/{book}/content', [DesktopController::class, 'content']);
    });
});
