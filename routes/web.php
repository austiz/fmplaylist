<?php

use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\CommercialController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\QueueAdminController;
use App\Http\Controllers\Admin\HistoryController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SongAdminController;
use App\Http\Controllers\Admin\SoundByteController;
use App\Http\Controllers\Admin\SoundsController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PiSetupController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\SongController;
use Illuminate\Support\Facades\Route;

// Pi setup download routes
Route::get('/pi/setup.sh', [PiSetupController::class, 'setup'])->name('pi.setup');

// Serve public storage files — symlinks unreliable on LiteSpeed shared hosting.
// /files/ prefix avoids conflict with Laravel's built-in storage.local route at /storage/.
Route::get('/files/{path}', function (string $path) {
    abort_if(str_contains($path, '..'), 403);
    $file = storage_path('app/public/' . $path);
    abort_unless(file_exists($file) && is_file($file), 404);
    return response()->file($file);
})->where('path', '.*');
Route::get('/pi/{filename}', [PiSetupController::class, 'file'])->name('pi.file')
    ->where('filename', '[a-zA-Z0-9_\-\.]+');

// Public
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/songs', [SongController::class, 'index'])->name('songs.index');
Route::post('/songs/{song}/request', [SongController::class, 'request'])
    ->name('songs.request')
    ->middleware('throttle:5,1');
Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');

// Admin (dashboard alias for Wayfinder compatibility)
Route::middleware(['auth'])->get('/dashboard', fn () => redirect('/admin'))->name('dashboard');

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/broadcast', [BroadcastController::class, 'index'])->name('broadcast');
    Route::post('/broadcast/mode', [BroadcastController::class, 'setMode'])->name('broadcast.mode');
    Route::post('/broadcast/rds', [BroadcastController::class, 'updateRds'])->name('broadcast.rds');
    Route::post('/broadcast/skip', [BroadcastController::class, 'skip'])->name('broadcast.skip');
    Route::post('/broadcast/play-now', [BroadcastController::class, 'playNow'])->name('broadcast.play-now');
    Route::post('/broadcast/force-commercial', [BroadcastController::class, 'forceCommercial'])->name('broadcast.force-commercial');
    Route::post('/broadcast/force-sound-byte', [BroadcastController::class, 'forceSoundByte'])->name('broadcast.force-sound-byte');
    Route::get('/sounds', [SoundsController::class, 'index'])->name('sounds');
    Route::post('/songs/upload', [SongAdminController::class, 'upload'])->name('songs.upload');
    Route::patch('/songs/{song}', [SongAdminController::class, 'update'])->name('songs.update');
    Route::patch('/songs/{song}/toggle', [SongAdminController::class, 'toggleAvailable'])->name('songs.toggle');
    Route::delete('/songs/{song}', [SongAdminController::class, 'destroy'])->name('songs.destroy');
    Route::post('/commercials/upload', [CommercialController::class, 'upload'])->name('commercials.upload');
    Route::patch('/commercials/{commercial}', [CommercialController::class, 'update'])->name('commercials.update');
    Route::patch('/commercials/{commercial}/toggle', [CommercialController::class, 'toggleActive'])->name('commercials.toggle');
    Route::delete('/commercials/{commercial}', [CommercialController::class, 'destroy'])->name('commercials.destroy');
    Route::post('/sound-bytes/upload', [SoundByteController::class, 'upload'])->name('sound-bytes.upload');
    Route::patch('/sound-bytes/{soundByte}', [SoundByteController::class, 'update'])->name('sound-bytes.update');
    Route::patch('/sound-bytes/{soundByte}/toggle', [SoundByteController::class, 'toggleActive'])->name('sound-bytes.toggle');
    Route::delete('/sound-bytes/{soundByte}', [SoundByteController::class, 'destroy'])->name('sound-bytes.destroy');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/wifi', [SettingsController::class, 'connectWifi'])->name('settings.wifi');
    Route::get('/tokens', [TokenController::class, 'index'])->name('tokens');
    Route::post('/tokens/regenerate', [TokenController::class, 'regenerate'])->name('tokens.regenerate');
    Route::get('/history', [HistoryController::class, 'index'])->name('history');
    Route::delete('/queue/{queueItem}', [QueueAdminController::class, 'destroy'])->name('queue.destroy');
});

require __DIR__.'/settings.php';
