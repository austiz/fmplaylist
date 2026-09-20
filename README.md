<p align="center">
  <img src="public/fmplaylist_logo.png" alt="FM Playlist" width="200">
</p>

# FM Playlist

FM Playlist is a Laravel, React, and Raspberry Pi based request-radio system. Listeners browse a song catalog, request tracks, watch the queue and its estimated wait, chat, and see what is on air. The admin console runs the broadcast: the queue, the media library, live modes, RDS text, emergency announcements, WiFi, and the transmitters themselves. Each Raspberry Pi polls the web API, plays audio through PiFmRds, and reports its state back.

One install can run **several stations**, and a station can have **several transmitters**. Everything below is scoped to a station unless it says otherwise.

This project is intended for authorized, licensed, and venue-approved operation only. RF transmission rules vary by jurisdiction and frequency. Keep deployments compliant with applicable licensing, spectrum, power, antenna, and venue requirements.

## Documentation

| Document | Covers |
| --- | --- |
| This file | The system: architecture, data model, API, media, devices, security |
| `DEPLOY-NAMECHEAP.md` | Deploying to cPanel/shared hosting, CI secrets, cron |
| `docs/HARDWARE.md` | Bill of materials, RF chain, antenna, power, expected range |
| `docs/CAR-SETUP.md` | Putting a transmitter in a vehicle |
| `PiFmRds/README.md`, `PiFmRds/HOWTO.md` | Upstream PiFmRds, as vendored |

## Current Stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13, PHP 8.4.1+ |
| Frontend | React 19, Inertia.js 3, TypeScript |
| Styling | Tailwind CSS 4, Radix UI primitives, lucide-react |
| Auth | Laravel Fortify, passkeys, two-factor auth |
| Routing helpers | Laravel Wayfinder generated actions/routes |
| Build tooling | Vite 8 |
| Database | SQLite locally by default, MySQL in production |
| Pi runtime | Python 3, ffmpeg, PiFmRds C binary |
| Real-time | Version-cursor polling (`/api/live`) |
| PWA | `manifest.json` + service worker — installable as a home-screen app |

## Repository Layout

```text
.
|-- app/
|   |-- Enums/              MediaType, SettingKey
|   |-- Http/
|   |   |-- Controllers/Admin/    broadcast, sounds, settings, stations, tokens
|   |   |-- Controllers/Api/      PiController, LiveController, ChatController
|   |   |-- Middleware/           AuthenticatePiToken, ResolvePublicStation,
|   |   |                         EnsureActiveStation
|   |   `-- Requests/
|   |-- Models/             MediaAsset, QueueItem, Station, PiToken, PiCommand, ...
|   |-- Models/Concerns/    BelongsToStation
|   |-- Services/           QueueService, DeviceSyncService, MediaUploadService
|   `-- Support/            PiSource, LiveState, StationSettings, CurrentStation
|-- config/fm.php           station tunables (presence, claims, upload caps)
|-- database/
|   |-- factories/
|   |-- migrations/         8 files -- the schema as it is, not as it grew
|   `-- seeders/
|-- public/pi/setup.sh      the installer, served with the host rewritten per request
|-- PiFmRds/                vendored GPLv3 upstream
|   `-- src/                the device payload: pi_daemon.py, wifi_apply.sh, the C
|                           sources and Makefile that setup.sh compiles on the device
|-- docs/
|   |-- HARDWARE.md         bill of materials, RF chain, antenna, power
|   `-- CAR-SETUP.md        in-vehicle install walkthrough
|-- resources/js/
|   |-- components/
|   |-- hooks/use-fm-live.tsx     the single live subscription
|   |-- layouts/
|   |-- lib/live.ts               the /api/live poll client
|   |-- pages/            admin/, auth/, settings/, home, queue, songs, drive
|   `-- types/
|-- routes/
|   |-- api.php
|   |-- console.php         scheduled GC and queue drain
|   |-- settings.php
|   `-- web.php
`-- storage/
```

## System Overview

```text
Public phone/browser
        |
        | HTTPS
        v
Laravel + React web app
        |
        | public pages, admin console, JSON API, Pi file downloads
        v
Database and public storage
        |
        | Pi polls authenticated API with X-Pi-Token
        v
Raspberry Pi daemon
        |
        | ffmpeg -> pi_fm_rds
        v
FM/RDS transmitter chain
```

The web app owns the queue, settings, media metadata, device tokens and current state.
Each Pi owns local playback and its local copies of the files. Devices poll the web app
rather than receiving inbound commands, so nothing has to reach a Pi behind NAT and the
server stays a plain request/response app on shared hosting.

## Stations and Transmitters

One install runs any number of stations, and a station runs any number of transmitters.

**A station** owns its own media library, queue, settings, chat, listener count, saved
WiFi networks and devices. Nothing crosses between them: public pages take
`?station=<slug>` and the admin console drives one active station at a time. Scoping is
a global query scope (`App\Models\Concerns\BelongsToStation`), not a `where` clause
each query has to remember, so a forgotten filter is a scoped query rather than a leak.

**A transmitter** is one row in `pi_tokens` — its own token, its own health, its own
command queue. Two consequences worth knowing:

- The queue hands out *leases*, not readings. Each device is claimed a different item,
  so two transmitters on a station do not broadcast the same song at the same moment.
- Anything aimed at devices — an emergency announcement, an update push — is queued per
  device. It used to be a station-wide flag, which the first Pi to heartbeat consumed
  and cleared.



## Main Features

**Listeners**

- Now-playing page with animated waveform, queue preview, and live listener count.
- Searchable song catalog with a request form; a celebration when your own request hits the air.
- Full queue page with estimated wait time.
- Live chat alongside the stream, seeded and updated on the same poll as everything else.
- Drive Mode (`/drive`) — a large-type, glanceable view for a phone on a dashboard.
- Installable as a home-screen app (`manifest.json` + service worker).

**Operators**

- Multiple stations from one install. Every page, API response, and device is scoped
  to a station; admins switch between them from the station picker.
- Dashboard with now-playing, queue depth, queue runtime, daily stats, and recent requests.
- Broadcast console: mode changes, RDS text, skip, play-now, force commercial,
  force sound byte, and emergency announcement.
- One media library for songs, commercials, and sound bytes — upload, edit, toggle
  availability, and request deletion from the devices that hold a copy.
- Settings for callsign, frequency, fallback song, commercial and sound byte
  intervals, and fade-in duration.
- WiFi management — push a network to a transmitter, keep an ordered list of saved
  networks it can fall back to at boot with no server contact.
- Per-device token management, with health, disk usage, and a per-device command queue.
- Pi status bar across every admin page, on the same transport the listener pages use.

**Devices**

- Authenticated Pi API using a one-time raw token, stored hashed.
- Self-updating daemon: devices diff a signed manifest and download only what changed.
- Several transmitters per station, each leased its own item from the queue.

## Public Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/` | Home page with now-playing, queue preview, and public navigation |
| GET | `/songs` | Searchable paginated song catalog |
| POST | `/songs/{song}/request` | Add an available song to the pending queue; throttled at 5 requests/minute |
| GET | `/queue` | Full public pending queue with estimated wait time |
| GET | `/drive` | Drive Mode — large-type now-playing for a phone on a dashboard |
| GET | `/api/live` | Live state — now-playing, pi-status, queue version, chat. Send back the `v` cursor and an unchanged station answers in a few bytes (rate-limited 600/min) |
| GET | `/api/now-playing` | Public JSON now-playing endpoint |
| GET | `/api/pi-status` | Public JSON Pi connectivity/status endpoint |
| GET | `/api/chat` | Chat backlog (the live poll normally supplies this) |
| POST | `/api/chat` | Post a chat message; throttled at 10/minute |
| GET | `/pi/setup.sh` | Bash setup script — downloads, builds, and installs the Pi daemon |
| GET | `/pi/manifest.json` | Payload manifest the devices diff against |
| GET | `/pi/{file}` | Individual Pi payload file download (allowlisted by name and extension) |
| GET | `/files/{path}` | Uploaded media, served from the public disk |

Every public route accepts `?station=<slug>` and answers for that station. Without it
they answer for the default station.

## Admin Routes

All admin routes require authentication.

| Route | Page | Purpose |
| --- | --- | --- |
| `/admin` | Dashboard | Now-playing, queue depth, queue runtime, daily stats, recent requests |
| `/admin/broadcast` | Broadcast | Pi status, live mode, RDS, skip, play-now, force commercial/sound byte |
| `/admin/sounds` | Sounds | Songs, commercials, and sound bytes in one tabbed view — upload, edit, toggle, delete |
| `/admin/settings` | Settings | Frequency, callsign, fallback song, playback intervals, fade-in, WiFi |
| `/admin/stations` | Stations | Create a station and switch which one the console is driving |
| `/admin/tokens` | Devices | Per-transmitter tokens, health, disk usage, commands |
| `/admin/history` | History | Paginated request history with status filter |
| `/settings/profile` | Profile | Account profile settings |
| `/settings/security` | Security | Password, passkeys, two-factor auth |

The console always drives exactly one station at a time — the active one, held in the
session and enforced by `EnsureActiveStation`. `/admin/stations/switch` changes it.

## Data Model

| Table | Purpose | Important fields |
| --- | --- | --- |
| `stations` | One broadcast identity — its own media, queue, settings and devices | `name`, `slug`, `is_default` |
| `users` | Admin users | Fortify auth fields, two-factor fields |
| `passkeys` | WebAuthn/passkey credentials | Managed by Laravel Passkeys |
| `media_assets` | Songs, commercials and sound bytes — one table, one lifecycle | `type`, `station_id`, `title`, `artist`, `filename`, `storage_path`, `duration_seconds`, `active`, `category`, `rds_ps`, `rotation_order`, `play_count`, `last_played_at`, `needs_pi_download`, `pi_delete_requested` |
| `queue_items` | Requests and play history | `station_id`, `media_asset_id`, `requested_by_name`, `position`, `status`, `played_at`, `claimed_by_pi_token_id`, `claimed_at` |
| `now_playing` | What is on air, one row per station | `station_id` (unique), `media_asset_id`, `queue_item_id`, `type`, `started_at` |
| `settings` | Per-station key/value config | `station_id`, `key`, `value`, unique on the pair |
| `chat_messages` | Listener chat | `station_id`, `name`, `message` |
| `pi_tokens` | One row per transmitter | `station_id`, `token_hash`, `last_seen_at`, `pi_status`, `pi_mode`, `pi_ip`, `pi_daemon_hash`, `pi_fm_running`, `pi_queue_depth`, `disk_free_bytes` |
| `pi_commands` | Work addressed to one device, not to a station | `pi_token_id`, `command`, `payload`, `status` |
| `device_downloads` | What each device reports holding | `pi_token_id`, `media_asset_id` |
| `wifi_networks` | Saved networks a device falls back to at boot | `station_id`, `ssid`, `password`, `priority` |

`media_assets` replaced three near-identical tables (`songs`, `commercials`,
`sound_bytes`) plus a fourth (`station_ids`) that nothing read. They differed only in
*scheduling policy* — which now lives in `QueueService` and `MediaType`, not in the
schema. See `app/Enums/MediaType.php`.

Everything with a `station_id` is scoped automatically by the `BelongsToStation`
concern's global scope, so a query has to opt *out* of scoping rather than remember to
opt in.

Queue statuses:

```text
pending -> playing -> played
                  \-> skipped
```

## Backend Playback Priority

The Pi asks `/api/pi/queue` what to play next. The backend queue service decides in this order:

1. Forced commercial from the broadcast console.
2. Scheduled commercial if `commercial_interval` is enabled and due.
3. Forced sound byte from the broadcast console.
4. Scheduled random sound byte if `sound_byte_interval` is enabled and due.
5. Next pending requested song.
6. Local fallback song from settings.

The Pi plays one queue response in that sequence — commercial, then sound byte, then the
song or the fallback — and reports each item to `/api/pi/now-playing` with its type and id
*before* audio starts, so counters and force flags settle even if playback then fails.
Reporting a song advances the commercial and sound byte counters; reporting a commercial
or sound byte resets its own counter and clears its force flag.

Songs and the fallback play with an optional `afade` fade-in, set by `fade_in_duration`.

**With more than one transmitter on a station**, step 5 hands out a *lease* rather than a
reading. The item returned to a device is claimed under a row lock for
`fm.queue_claim_seconds` (default 300) and renewed on every poll, so a second transmitter
is handed the item after it instead of the same one. A device that dies mid-song stops
blocking its stand-in once the lease lapses. An unattributed read — the admin queue
preview — takes no lease.

## Pi API

Every device endpoint requires:

```http
X-Pi-Token: <raw-token-shown-once-in-admin>
Accept: application/json
```

The token identifies the *device*, and the device's row carries its station — so no Pi
request names a station, and none can reach another one's queue. Rate-limited to
120/minute, which covers the 30-second heartbeat plus download bursts.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/pi/heartbeat` | Report status, health, disk and WiFi; receive config, pending sync work and queued commands |
| GET | `/api/pi/config` | The same config payload, without reporting anything |
| GET | `/api/pi/queue` | Lease the next item, plus any commercial or sound byte that is due |
| POST | `/api/pi/now-playing` | Report `song`, `commercial` or `sound_byte` as it starts |
| POST | `/api/pi/sync-library` | Report the local WAV filenames the device holds |
| POST | `/api/pi/confirm-download` | A pending download is now on disk |
| POST | `/api/pi/confirm-delete` | A pending delete is gone from disk |
| POST | `/api/pi/ack-command` | Report the outcome of a command handed out on heartbeat |

There is **no** station-id endpoint. Station identification is a sound byte with
`category = id`, scheduled like any other.

### Queue response

```json
{
  "commercial": null,
  "sound_byte": null,
  "next": {
    "queue_item_id": 42,
    "requested_by_name": "Sam",
    "song": {
      "id": 7,
      "title": "Midnight Radio",
      "artist": "The Underground",
      "filename": "midnight_radio.wav",
      "duration_seconds": 213
    }
  }
}
```

`?lookahead=n` (max 5) adds an `upcoming` array starting *after* the leased item, so a
device can pre-download without racing its own claim.

### Heartbeat response

The heartbeat response is the device's current config, plus anything addressed to it:

```json
{
  "freq": 96.9,
  "broadcast_mode": "normal",
  "live_stream_url": "",
  "live_alsa_device": "hw:1,0",
  "rds_rt_mode": "auto",
  "rds_rt": "",
  "rds_ps": "",
  "callsign": "96.9 FM",
  "fallback_song": "FTPA.wav",
  "fade_in_duration": 0.5,
  "pending_downloads": [
    {
      "type": "song",
      "item_id": 12,
      "filename": "example_1710000000.wav",
      "title": "Example",
      "download_url": "/storage/songs/example_1710000000.wav"
    }
  ],
  "pending_deletes": [
    { "type": "commercial", "item_id": 3, "filename": "old_spot.mp3" }
  ],
  "pending_wifi": null,
  "wifi_profiles": [],
  "wifi_profiles_rev": "",
  "skip_next": false,
  "commands": [
    { "id": 9, "command": "emergency", "payload": "severe_weather.wav" }
  ]
}
```

### Device commands

`commands` is how anything addressed to a *device* reaches it. Each is marked sent when
handed out and acked with its outcome through `/api/pi/ack-command`.

| Command | Effect | Disruptive |
| --- | --- | --- |
| `update` | Fetch the current payload and restart into it | yes |
| `rollback` | Restore the previous payload | yes |
| `rebuild` | Recompile `pi_fm_rds` | yes |
| `restart_daemon` | Restart the systemd service | yes |
| `reboot` | Reboot the device | yes |
| `fm_stop` / `fm_start` | Take the carrier down / bring it back | `fm_stop` |
| `fetch_logs` | Upload recent daemon logs | no |
| `emergency` | Play the announcement named in `payload` immediately | yes |

`update`, `rollback` and the rest are aimed at one device from `/admin/tokens`.
`emergency` is the exception — the Broadcast page raises it for the whole station, which
queues one command per device. It used to be a station-wide *setting*, cleared by
whichever Pi heartbeated first, so the second transmitter on a station stayed on the
music through an emergency. That is why this queue exists.

`confirm-download` and `confirm-delete` take `type` plus `item_id`. They still accept the
older `song_id` field so a daemon build predating the media unification keeps working.

## Broadcast Modes

The admin broadcast page writes settings that the Pi receives on heartbeat.

| Mode | Setting value | Pi behavior |
| --- | --- | --- |
| Normal | `normal` | Poll the queue and play the leased request, or the fallback; the response also carries any commercial or sound byte that is due |
| Phone Stream | `phone_stream` | Wait for RTMP input at `rtmp://<pi-ip>:1935/live` |
| USB Input | `usb_input` | Read live audio from ALSA device, default `hw:1,0` |
| Custom Stream | `custom_stream` | Read an RTMP/HLS/Icecast/HTTP source URL with ffmpeg |

RDS settings:

- `rds_ps`: optional 8-character Program Service override. If blank, the Pi uses the callsign.
- `rds_rt_mode=auto`: RadioText uses the current song title and artist.
- `rds_rt_mode=custom`: RadioText uses the configured custom string, capped at 64 characters.

**Emergency** is not a mode. It skips every pending request, raises an `emergency`
command on each transmitter on the station, and sets `skip_next` so whatever is playing
is cut short. The announcement itself is an uploaded file named by the
`emergency_announcement` setting.

## Local Development

Requirements:

- PHP 8.4.1+ (Laravel 13 pulls in Symfony 8, which requires it)
- Composer
- Node.js and npm
- SQLite for the default local database, or MySQL if you change `.env`

Setup:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

For SQLite local development:

```bash
mkdir -p database
touch database/database.sqlite
php artisan migrate --seed
```

On Windows PowerShell:

```powershell
New-Item -ItemType File -Force database/database.sqlite
php artisan migrate --seed
```

Start the full development stack:

```bash
composer run dev
```

That starts `php artisan serve`, `php artisan queue:listen --tries=1`, and `npm run dev`.

Build production assets:

```bash
npm run build
```

## Useful Development Commands

```bash
composer run lint
composer run lint:check
npm run lint
npm run lint:check
npm run format
npm run format:check
npm run types:check
php artisan test
composer run test
composer run ci:check
```

`composer run test` clears config, checks PHP formatting, runs PHPStan, and runs the Laravel test suite. `composer run ci:check` also checks the frontend lint, format, and TypeScript status.

## Admin Bootstrap

The seeder creates:

```text
Name: Admin
Email: admin@fmplaylist.com
```

Set a usable password after seeding:

```bash
php artisan tinker
```

```php
\App\Models\User::where('email', 'admin@fmplaylist.com')
    ->first()
    ->update(['password' => bcrypt('change-this-password')]);
```

Then sign in at `/login`.

## Pi Setup

Pi requirements: Raspberry Pi 3 B+ or newer, Raspberry Pi OS, network access to the web app, and an authorized RF output chain.

### One-line install

Create the device in `/admin/tokens` first and copy its token — it is shown once. Then
SSH into the Pi and pass it to the installer:

```bash
curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
```

The token is **required**; without it the script exits and prints this usage. It
downloads the payload, verifies every file against `/pi/manifest.json`, installs
dependencies (Python 3, ffmpeg, libsndfile, build tools), compiles `pi_fm_rds`, writes
`config.json`, and registers the systemd service.

The install lives in `$HOME/PiFmRds/src` for the user running `sudo` — usually
`/home/pi/PiFmRds/src`, but set `FMPLAYLIST_USER` if the account is named something else.

### Configure

Almost nothing needs configuring on the device. `api_key` comes from the install
command, and frequency, callsign, RDS, intervals and broadcast mode all come from
`/admin/settings` on the next heartbeat (~30 s). Re-running the installer preserves
whatever the server has pushed, so an update never knocks a station back to defaults.

The one local value is the RDS PI code:

```bash
sudo nano "$HOME/PiFmRds/src/config.json"    # "pi_code": "C0DE"
```

### Start

```bash
sudo systemctl start fmplaylist
sudo systemctl status fmplaylist
```

The Admin status bar goes green within one poll cycle (~30 s).

### Update

Normally you don't. The admin device page shows an update as available when a Pi
reports a payload hash that differs from the server's, and the `update` command applies
it in place with a rollback on failure.

To reinstall by hand, re-run the installer with the same token:

```bash
curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
sudo systemctl restart fmplaylist
```

### Download individual files

```
https://fmplaylist.com/pi/manifest.json     # names, sizes and sha256 of the payload
https://fmplaylist.com/pi/pi_daemon.py
https://fmplaylist.com/pi/pi_fm_rds.c
https://fmplaylist.com/pi/Makefile
https://fmplaylist.com/pi/FTPA.wav
```

Only files on the manifest can be downloaded — it is an allowlist by name and
extension, so a stray file left in the source directory is not served and does not
auto-deploy to every transmitter on the next update.

## Pi Daemon Flow

On startup:

1. Load local `config.json`.
2. Require `api_key`.
3. Require the compiled `pi_fm_rds` binary.
4. Report the local WAV library to `/api/pi/sync-library`.
5. Send the first heartbeat.
6. Enter the playback loop.

During normal playback:

1. Heartbeat every 30 s; receive config, pending downloads and deletes, WiFi profiles,
   `skip_next`, and any commands addressed to this device.
2. Reconcile media — download what is pending, delete what is flagged, confirm each.
3. Run any commands, and ack each with its result.
4. Re-report the local library once per hour.
5. Poll `/api/pi/queue`, which leases it one item.
6. Play commercial (if due or forced) → sound byte (if due or forced) → the leased song,
   or the fallback if the queue is empty.
7. Report each item to `/api/pi/now-playing` with its `type` and `item_id` *before* audio
   starts, so counters and force flags settle even if playback then fails.

During live modes:

1. Admin changes `broadcast_mode` in the broadcast console.
2. The next heartbeat carries the new mode.
3. The Pi starts ffmpeg on the selected live input.
4. It keeps heartbeating while live.
5. Switching back to normal stops the live process and resumes queue playback.

## Media Management

Songs, commercials and sound bytes are rows in one `media_assets` table with a `type`
discriminator, administered from the single `/admin/sounds` page. They share one upload
path, one duration probe, one per-device download ledger and one delete protocol. What
differs is per-type and declared in `app/Enums/MediaType.php`:

| Type | Accepts | Cap | Stored under | Scheduling |
| --- | --- | --- | --- | --- |
| Song | wav, mp3 | 50 MB | `songs/` | From the queue, by listener request or autofill |
| Commercial | wav, mp3, ogg | 50 MB | `commercials/` | Every `commercial_interval` songs, in `rotation_order` |
| Sound byte | wav, mp3, ogg | 20 MB | `soundbytes/` | Every `sound_byte_interval` songs, chosen at random |

Sound byte categories are `jingle`, `shoutout`, `drop` and `id`. Station identification
is a sound byte with `category = id` — there is no separate station-ID mechanism.

The caps are `FM_SONG_MAX_KB`, `FM_COMMERCIAL_MAX_KB` and `FM_SOUND_BYTE_MAX_KB` in
`config/fm.php`. Songs exclude ogg because the transmitter's player never handled it.

The shared lifecycle:

- Public requests can only target a song with `active = true`.
- A new upload is marked `needs_pi_download`, and appears in every device's
  `pending_downloads` until each confirms it with `/api/pi/confirm-download`.
- Duration is probed by a **queued job**, not during the upload request — which is why
  the scheduler has to be running (see `routes/console.php`).
- Deleting an asset no device holds removes it immediately. If a device holds a copy,
  `pi_delete_requested` is set, the device removes its local file and confirms with
  `/api/pi/confirm-delete`, and the row goes once every holder has confirmed.
- Assets discovered by a device's library report may have no `storage_path` — those are
  local-only files on that Pi.

## Production Deployment

`DEPLOY-NAMECHEAP.md` is the full walkthrough for the cPanel/shared-hosting target this
project actually deploys to, including the GitHub Actions secrets and the `public_html`
layout. What follows is the shape of it.

The app runs on conventional PHP hosting as long as the document root points at
`public/` and the required extensions are available. **A cron entry is not optional** —
see `routes/console.php`. Uploads probe their duration in a queued job, stale device
commands expire on a schedule, and `QUEUE_CONNECTION=database` has no worker without it.

Typical production `.env` values:

```env
APP_NAME="FM Playlist"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fmplaylist.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_user
DB_PASSWORD=your_password

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=file
FILESYSTEM_DISK=public
```

`CACHE_STORE=file` rather than `database`: the live poll reads cached state on every
request, and on shared hosting the local filesystem beats a round trip to MySQL for it.

`QUEUE_CONNECTION=database` rather than `sync`: `sync` would put `ffprobe` back inside
the upload request, which is what it was moved out of.

Deployment commands:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If using cPanel or shared hosting:

- Upload the project outside `public_html` when possible.
- Point the domain document root to `<project>/public`.
- Ensure `storage/` and `bootstrap/cache/` are writable.
- Run `php artisan storage:link` so uploaded media is available from `/storage/...`.
- Increase upload limits if needed for media files.

Example `.user.ini`:

```ini
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 256M
max_execution_time = 120
```

## End-to-End First Run

1. Install, migrate and seed the web app. The `stations` migration creates the default
   station, so the queue, settings and public site work from the first request.
2. Set the admin password (see **Admin Bootstrap**) and sign in at `/login`.
3. *(Optional)* Create more stations at `/admin/stations`. The console drives one at a
   time; the picker switches it.
4. Open `/admin/tokens`, add a device for this station, and copy its token — shown once.
5. SSH into the Pi and install, passing that token:
   ```bash
   curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
   ```
6. Set `pi_code` in `$HOME/PiFmRds/src/config.json` if you want something other than
   `C0DE`. Everything else comes from the server.
7. Start the daemon:
   ```bash
   sudo systemctl start fmplaylist
   ```
8. Within ~30 s the admin status bar turns green and the device appears online.
9. Set frequency and callsign in `/admin/settings`.
10. Upload songs, commercials and sound bytes from `/admin/sounds`. Durations fill in
    once the scheduler runs the queued job — confirm cron is configured.
11. Request a song from `/songs` and watch `/admin/broadcast`, `/queue` and `/api/live`
    as the Pi picks it up.
12. Add a second transmitter to the same station if you have one. It gets its own token
    and is leased its own item from the queue.

## Troubleshooting

### Admin Pi status stays offline

- Confirm the Pi is running (`sudo systemctl status fmplaylist`).
- Confirm `config.json` has the correct `api_key` and `server_url`.
- Confirm the device is on the station you are looking at — `/admin/tokens` lists it.
- Confirm the Pi can reach the site over HTTPS.
- Check the Laravel logs for `401` responses from Pi endpoints.
- Check that `last_seen_at` is updating in `pi_tokens`.

### Pi token rejected

- Tokens are stored as SHA-256 hashes; the raw token cannot be recovered.
- Regenerate the token at `/admin/tokens`.
- Paste the new raw token into `$HOME/PiFmRds/src/config.json`, or re-run the installer with it.
- Restart: `sudo systemctl restart fmplaylist`.

### Uploaded media does not appear on the Pi

- Confirm `FILESYSTEM_DISK=public` in `.env`.
- Run `php artisan storage:link`.
- Confirm uploaded media has a valid public `/storage/...` URL.
- Confirm the Pi heartbeat is succeeding (status bar green).
- Confirm the media row has `needs_pi_download = true`.

### Queue does not advance

- The web app advances queue state when the Pi reports `/api/pi/now-playing`.
- Check the Pi daemon logs around the song transition.
- Check whether a queue item is stuck in `playing`.
- Use `/admin/broadcast` to skip the current item if needed.
- Pending items can also be removed individually from the Admin Dashboard.

### Station identification does not play

Station IDs are sound bytes with `category = id`; there is no separate mechanism.

- Confirm at least one sound byte exists, is active, and has `category = id`.
- Confirm `sound_byte_interval` is greater than zero in `/admin/settings`.
- Confirm the device downloaded it — `/admin/tokens` shows per-device sync state.
- Confirm the Pi reports `type=sound_byte` after playback so the counter resets.

Sound bytes are picked at random from the active set, so an `id` competes with jingles
and drops. Give it its own interval by keeping the other categories inactive, or upload
it as a commercial, which rotates in a fixed order instead.

### The queue is full but nothing is claimed

With more than one transmitter on a station, each item is leased for
`fm.queue_claim_seconds` (default 300). A device that vanished mid-song holds its lease
until it lapses. Either wait it out, lower `FM_QUEUE_CLAIM_SECONDS`, or delete the
device's token — the claim is released by `nullOnDelete`.

### Live mode does not start

- Confirm the Pi is online in `/admin/broadcast`.
- For phone stream mode, use the `rtmp://<pi-ip>:1935/live` URL shown on the broadcast page.
- Confirm the phone and Pi can reach each other on the same network.
- For USB input, confirm the ALSA device with `arecord -l` on the Pi.
- For custom stream, test the URL with `ffmpeg -i <url>` on the Pi.

### An emergency reached only one transmitter

This was true before emergencies moved onto the per-device command queue. If it still
happens, the devices are on different stations — `emergency` is raised for the active
station only. Check `station_id` on each row in `/admin/tokens`.

### Durations stay blank, uploads never reach devices

The scheduler is not running. Duration probing is a queued job and `QUEUE_CONNECTION`
is `database`, so with no cron the jobs table just fills up. See
`DEPLOY-NAMECHEAP.md` → "Cron is required".

### Website 500 after deploy

- Check `.env` database credentials.
- Run `php artisan config:clear`.
- Run `php artisan migrate --force`.
- Ensure `storage/` and `bootstrap/cache/` are writable.
- Confirm built assets exist under `public/build`.
- Check `storage/logs/laravel.log`.

## Security

### Production checklist

| Variable | Required value | Why |
|---|---|---|
| `APP_ENV` | `production` | Disables Ignition error pages and debug output |
| `APP_DEBUG` | `false` | Never expose stack traces to browsers |
| `SESSION_SECURE_COOKIE` | `true` | Prevents session cookie transmission over plain HTTP |
| `SESSION_SAME_SITE` | `lax` | Mitigates CSRF on cross-site navigations |
| `APP_URL` | `https://your-domain.com` | Used in Pi setup script and storage URLs |

### HTTP security headers

Applied globally to all web and API responses via `SecurityHeaders` middleware:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` — prevents MIME-type sniffing attacks |
| `X-Frame-Options` | `DENY` — blocks clickjacking via iframes |
| `Referrer-Policy` | `strict-origin-when-cross-origin` — limits URL leakage in Referer headers |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` — disables unused browser APIs |

### Authentication

- **Admin panel** — Laravel Fortify with password + optional passkey + optional two-factor auth
- **Pi API** — SHA-256 hashed token in `X-Pi-Token` header; raw token never stored in the database
- **Token regeneration** — wrapped in a database transaction (delete + create are atomic); logged at `INFO` level
- **Failed Pi auth** — logged at `WARNING` level with IP and endpoint for monitoring

### Rate limiting

| Endpoint | Limit | Rationale |
|---|---|---|
| `POST /songs/{song}/request` | 5 / IP / min | Prevents queue spam from a single device |
| `POST /api/chat` | 10 / IP / min | Chat is public and unauthenticated |
| `GET /api/now-playing`, `GET /api/pi-status` | 60 / IP / min | Legacy read endpoints; the live poll supersedes both |
| `GET /api/live` | 600 / IP / min | Room for a 3s poll across a handful of tabs; the responses are tiny |
| Pi API (`/api/pi/*`) | 120 / IP / min | Well above the 2/min normal heartbeat; blocks brute-force of token auth |
| Auth routes | 6 / IP / min | Laravel Fortify default |

### Input validation

- All form inputs pass Laravel's request validation before reaching the database or filesystem
- File uploads: `mimes` (extension + MIME sniff) + maximum size enforced server-side
- Files stored with server-generated names; user-supplied names are never used on disk
- `AudioDuration::extract()` wraps the file path in `escapeshellarg()`; the path itself is server-controlled (not from user input)
- History `filter` parameter is allowlisted to `['all', 'pending', 'playing', 'played', 'skipped']`
- All database queries use Eloquent's parameterized bindings — no raw SQL with user input

### CSRF

All web form submissions include a Laravel CSRF token (enforced by `VerifyCsrfToken` middleware). API routes use token authentication instead and are exempt from CSRF.

### XSS

React escapes all rendered values by default. The two uses of `dangerouslySetInnerHTML` in pagination components render Laravel's own paginator output — page numbers (cast to integers) and `«`/`»` HTML entities — which are not user-controlled.

### Pi token security

The raw token is displayed **once** immediately after generation and is not recoverable. If compromised:

1. Go to `/admin/tokens` → Regenerate Token
2. Copy the new raw token immediately
3. Edit `/home/pi/PiFmRds/src/config.json` and paste the new `api_key`
4. `sudo systemctl restart fmplaylist`

The old token is invalidated the instant Regenerate is clicked.

### What is intentionally public (read-only)

| Path | Notes |
|---|---|
| `GET /api/live` | Read-only; rate-limited; serves one station's public state |
| `GET /api/now-playing` | Current song only; no user data |
| `GET /api/pi-status` | Online/offline indicator; no credentials |
| `GET /api/chat` | Listener chat for one station; no user data beyond the name given |
| `GET /pi/setup.sh` | Bootstrap script; fetches only what `/pi/manifest.json` lists |
| `GET /pi/manifest.json` | Names, sizes and sha256 of the payload — no secrets |
| `GET /pi/{file}` | Payload files only, allowlisted by name *and* extension, so a stray file dropped in the source directory is neither served nor deployed |
| `GET /files/{path}` | Uploaded media, contained to the public disk by `realpath()` |

### What a compromised admin account can do

- Change broadcast mode, RDS text, and frequency
- Queue, skip, or inject songs/commercials/sound bytes
- Upload audio files (stored outside PHP execution scope)
- Regenerate the Pi token (Pi stops playing until reconfigured)

A compromised admin account **cannot**:

- Execute arbitrary code on the server (no `eval`, no dynamic includes, `AudioDuration` uses `escapeshellarg`)
- Access other users' data (single-user system)
- Read or write the Pi filesystem directly

---

## Known Implementation Notes

- The `confirm-download` and `confirm-delete` Pi endpoints accept `type` + `item_id`. The older `song_id` field is also accepted for daemon builds predating the media unification.
- `/pi/setup.sh` bakes the requesting host in as `server_url`, so a device always points back to the server it was installed from. `FM_BASE_URL` is the fallback for artisan commands and queued jobs, which have no request to read.
- The seeder creates only the admin user. Station defaults are not seeded: the default station comes from the `stations` migration, and every setting's default lives on `App\Enums\SettingKey`, so an unset key reads its default rather than needing a row.
- `commercial_interval` and `sound_byte_interval` default to `0` (disabled). Enable them in `/admin/settings` once media is uploaded.
- Queue wait time on the public queue page is estimated from the durations of pending items, so it is only as good as the duration backfill.
- `now_playing` holds one row per station, enforced by a unique constraint — it is the de-facto primary key.

## License

`PiFmRds/` is vendored from [PiFmRds](https://github.com/ChristopheJacquet/PiFmRds) and
is **GPLv3** — see `PiFmRds/LICENSE`. Its C sources are served to devices over HTTP and
compiled there, so any distribution of this project distributes GPLv3 code.

The application code around it currently declares no license. Until a root `LICENSE`
exists, treat this repository as all rights reserved.
 _