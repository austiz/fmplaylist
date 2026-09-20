<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Device presence
    |--------------------------------------------------------------------------
    |
    | The daemon heartbeats every `heartbeat_seconds`. A device counts as online
    | until `online_after_seconds` have passed without one, which must stay well
    | above the heartbeat interval so a single dropped beat does not flap the UI.
    |
    */

    'heartbeat_seconds' => 30,

    'online_after_seconds' => (int) env('FM_ONLINE_AFTER_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | How many pending items autofill tops the queue up to when it runs dry.
    |
    */

    'autofill_target' => (int) env('FM_AUTOFILL_TARGET', 10),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Per-file ceilings in kilobytes, as Laravel's `max:` validation rule wants
    | them. Sound bytes are stings and drops, so they get a tighter cap.
    |
    */

    'uploads' => [
        'song_max_kb' => (int) env('FM_SONG_MAX_KB', 51200),
        'commercial_max_kb' => (int) env('FM_COMMERCIAL_MAX_KB', 51200),
        'sound_byte_max_kb' => (int) env('FM_SOUND_BYTE_MAX_KB', 20480),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public base URL
    |--------------------------------------------------------------------------
    |
    | Baked into setup.sh and the Pi's config.json when the request itself cannot
    | supply a host (artisan commands, queued jobs).
    |
    */

    'base_url' => env('FM_BASE_URL', 'https://fmplaylist.com'),

];
