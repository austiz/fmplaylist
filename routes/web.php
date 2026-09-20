<?php

use App\Enums\MediaType;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HistoryController;
use App\Http\Controllers\Admin\MediaAssetController;
use App\Http\Controllers\Admin\QueueAdminController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SoundsController;
use App\Http\Controllers\Admin\StationController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PiSetupController;
use App\Http\Controllers\PublicFileController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\SongController;
use App\Http\Middleware\EnsureActiveStation;
use Illuminate\Support\Facades\Route;

// Pi setup download routes
Route::get('/pi/setup.sh', [PiSetupController::class, 'setup'])->name('pi.setup');
// Payload manifest — the Pi diffs this against its local files and downloads
// only what changed. Must be registered before the /pi/{filename} catch-all.
Route::get('/pi/manifest.json', [PiSetupController::class, 'manifest'])->name('pi.manifest');

Route::get('/files/{path}', PublicFileController::class)
    ->name('files.show')
    ->where('path', '.*');
Route::get('/pi/{filename}', [PiSetupController::class, 'file'])->name('pi.file')
    ->where('filename', '[a-zA-Z0-9_\-\.]+');

// Public
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/songs', [SongController::class, 'index'])->name('songs.index');
Route::post('/songs/{song}/request', [SongController::class, 'request'])
    ->name('songs.request')
    ->middleware('throttle:5,1');
Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');
Route::get('/drive', [HomeController::class, 'drive'])->name('drive');

// Admin (dashboard alias for Wayfinder compatibility)
Route::middleware(['auth'])->get('/dashboard', fn () => redirect('/admin'))->name('dashboard');

Route::middleware(['auth', EnsureActiveStation::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/stations', [StationController::class, 'index'])->name('stations');
    Route::post('/stations', [StationController::class, 'store'])->name('stations.store');
    Route::post('/stations/switch', [StationController::class, 'switch'])->name('stations.switch');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/broadcast', [BroadcastController::class, 'index'])->name('broadcast');
    Route::post('/broadcast/mode', [BroadcastController::class, 'setMode'])->name('broadcast.mode');
    Route::post('/broadcast/rds', [BroadcastController::class, 'updateRds'])->name('broadcast.rds');
    Route::post('/broadcast/skip', [BroadcastController::class, 'skip'])->name('broadcast.skip');
    Route::post('/broadcast/play-now', [BroadcastController::class, 'playNow'])->name('broadcast.play-now');
    Route::post('/broadcast/force-commercial', [BroadcastController::class, 'forceCommercial'])->name('broadcast.force-commercial');
    Route::post('/broadcast/force-sound-byte', [BroadcastController::class, 'forceSoundByte'])->name('broadcast.force-sound-byte');
    Route::post('/broadcast/emergency', [BroadcastController::class, 'emergency'])->name('broadcast.emergency');
    Route::get('/sounds', [SoundsController::class, 'index'])->name('sounds');
    // Songs, commercials and sound bytes are one table behind one controller; the
    // URLs and route names stay as they were, and `defaults('type', …)` tells the
    // controller which of the three it is serving.
    foreach ([
        'songs' => MediaType::Song,
        'commercials' => MediaType::Commercial,
        'sound-bytes' => MediaType::SoundByte,
    ] as $segment => $mediaType) {
        Route::post("/{$segment}/upload", [MediaAssetController::class, 'upload'])
            ->defaults('type', $mediaType->value)->name("{$segment}.upload");
        Route::patch("/{$segment}/{mediaAsset}", [MediaAssetController::class, 'update'])
            ->defaults('type', $mediaType->value)->name("{$segment}.update");
        Route::patch("/{$segment}/{mediaAsset}/toggle", [MediaAssetController::class, 'toggle'])
            ->defaults('type', $mediaType->value)->name("{$segment}.toggle");
        Route::delete("/{$segment}/{mediaAsset}", [MediaAssetController::class, 'destroy'])
            ->defaults('type', $mediaType->value)->name("{$segment}.destroy");
    }
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/wifi', [SettingsController::class, 'connectWifi'])->name('settings.wifi');
    Route::post('/settings/wifi/networks', [SettingsController::class, 'storeWifiNetwork'])->name('settings.wifi.store');
    Route::post('/settings/wifi/networks/reorder', [SettingsController::class, 'reorderWifiNetworks'])->name('settings.wifi.reorder');
    Route::delete('/settings/wifi/networks/{wifiNetwork}', [SettingsController::class, 'destroyWifiNetwork'])->name('settings.wifi.destroy');
    Route::post('/pi/update', [SettingsController::class, 'pushDaemonUpdate'])->name('pi.update');
    Route::get('/tokens', [TokenController::class, 'index'])->name('tokens');
    Route::post('/tokens', [TokenController::class, 'store'])->name('tokens.store');
    Route::patch('/tokens/{token}', [TokenController::class, 'update'])->name('tokens.update');
    Route::post('/tokens/{token}/regenerate', [TokenController::class, 'regenerate'])->name('tokens.regenerate');
    Route::delete('/tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');
    Route::post('/tokens/{token}/command', [TokenController::class, 'dispatchCommand'])->name('tokens.command');
    Route::get('/history', [HistoryController::class, 'index'])->name('history');
    Route::delete('/queue/{queueItem}', [QueueAdminController::class, 'destroy'])->name('queue.destroy');
});

require __DIR__.'/settings.php';
