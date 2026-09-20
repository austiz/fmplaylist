<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\LiveController;
use App\Http\Controllers\Api\PiController;
use App\Http\Middleware\AuthenticatePiToken;
use App\Http\Middleware\ResolvePublicStation;
use Illuminate\Support\Facades\Route;

// Public endpoints — no auth. All of them read `?station=`, so the station is
// resolved once by middleware rather than by each controller body.
Route::middleware(ResolvePublicStation::class)->group(function () {
    Route::get('/now-playing', [PiController::class, 'nowPlayingPublic'])->middleware('throttle:60,1');
    // The live transport: every page that shows moving state polls this one endpoint.
    // 30/min covered one SSE connection per tab; a 3s poll needs room for twenty times
    // that, and the response is a few bytes whenever the cursor still matches.
    Route::get('/live', LiveController::class)->middleware('throttle:600,1');
    // Listener chat
    Route::get('/chat', [ChatController::class, 'index']);
    Route::post('/chat', [ChatController::class, 'store'])->middleware('throttle:10,1');
});

// Pi-authenticated endpoints — rate-limited to 120/min (Pi polls every 30s, downloads bursts)
Route::middleware([AuthenticatePiToken::class, 'throttle:120,1'])->prefix('pi')->group(function () {
    Route::get('/queue', [PiController::class, 'queue']);
    Route::post('/now-playing', [PiController::class, 'nowPlaying']);
    Route::post('/sync-library', [PiController::class, 'syncLibrary']);
    Route::get('/config', [PiController::class, 'config']);
    Route::post('/heartbeat', [PiController::class, 'heartbeat']);
    Route::post('/confirm-download', [PiController::class, 'confirmDownload']);
    Route::post('/confirm-delete', [PiController::class, 'confirmDelete']);
    Route::post('/ack-command', [PiController::class, 'ackCommand']);
});
