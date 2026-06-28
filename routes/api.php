<?php

use App\Http\Controllers\Api\PiController;
use App\Http\Controllers\Api\SseController;
use App\Http\Middleware\AuthenticatePiToken;
use Illuminate\Support\Facades\Route;

// Public endpoints — no auth
Route::get('/now-playing', [PiController::class, 'nowPlayingPublic']);
Route::get('/pi-status', [PiController::class, 'piStatus']);
// SSE: one persistent connection per browser tab; 30/min covers reconnects and page reloads
Route::get('/events', [SseController::class, 'stream'])->middleware('throttle:30,1');

// Pi-authenticated endpoints — rate-limited to 120/min (Pi polls every 30s, downloads bursts)
Route::middleware([AuthenticatePiToken::class, 'throttle:120,1'])->prefix('pi')->group(function () {
    Route::get('/queue', [PiController::class, 'queue']);
    Route::post('/now-playing', [PiController::class, 'nowPlaying']);
    Route::post('/sync-library', [PiController::class, 'syncLibrary']);
    Route::get('/config', [PiController::class, 'config']);
    Route::post('/heartbeat', [PiController::class, 'heartbeat']);
    Route::post('/confirm-download', [PiController::class, 'confirmDownload']);
    Route::post('/confirm-delete', [PiController::class, 'confirmDelete']);
});
