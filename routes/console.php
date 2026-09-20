<?php

use App\Models\PiCommand;
use App\Services\DeviceSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Housekeeping. Both of these used to run inside the 30-second device poll, where
 * they were pure overhead on the transmitter's own request path. Neither is part of
 * the sync protocol — they repair state the protocol can no longer reach.
 *
 * If no cron is configured, `PiCommand::expireStale()` still runs when an admin opens
 * the devices page, so the UI self-heals; the orphan purge simply waits.
 */
Schedule::call(fn () => PiCommand::expireStale())
    ->everyFifteenMinutes()
    ->name('pi-commands:expire-stale')
    ->withoutOverlapping();

Schedule::call(fn (DeviceSyncService $sync) => $sync->purgeOrphanedDeleteRequests())
    ->hourly()
    ->name('media:purge-orphaned-deletes')
    ->withoutOverlapping();
