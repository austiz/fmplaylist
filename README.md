<p align="center">
  <img src="public/fmplaylist_logo.png" alt="FM Playlist" width="200">
</p>

# FM Playlist

FM Playlist is a Laravel, React, and Raspberry Pi based request-radio system. The web app lets listeners browse a public song catalog, request tracks, watch the queue and estimated wait time, and see now-playing status. The admin console controls the broadcast queue, uploaded media, station IDs, commercials, sound bytes, live modes, RDS text, and the Pi authentication token. A Raspberry Pi polls the web API, plays audio through PiFmRds, and reports its current state back to the app.

This project is intended for authorized, licensed, and venue-approved operation only. RF transmission rules vary by jurisdiction and frequency. Keep deployments compliant with applicable licensing, spectrum, power, antenna, and venue requirements.

## Current Stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13, PHP 8.3+ |
| Frontend | React 19, Inertia.js 3, TypeScript |
| Styling | Tailwind CSS 4, Radix UI primitives, lucide-react |
| Auth | Laravel Fortify, passkeys, two-factor auth |
| Routing helpers | Laravel Wayfinder generated actions/routes |
| Build tooling | Vite 8 |
| Database | SQLite locally by default, MySQL in production |
| Pi runtime | Python 3, ffmpeg, PiFmRds C binary |
| Real-time | Server-Sent Events (`/api/events`) |
| PWA | `manifest.json` + service worker — installable as a home-screen app |

## Repository Layout

```text
.
|-- app/
|   |-- Http/Controllers/
|   |   |-- Api/PiController.php
|   |   |-- Admin/
|   |   |-- PiSetupController.php
|   |   |-- HomeController.php
|   |   |-- QueueController.php
|   |   `-- SongController.php
|   |-- Http/Middleware/AuthenticatePiToken.php
|   |-- Models/
|   `-- Services/
|-- database/
|   |-- migrations/
|   `-- seeders/
|-- PiFmRds/
|   `-- src/
|       |-- pi_daemon.py
|       |-- run.sh
|       |-- config.json
|       |-- pi_fm_rds.c
|       `-- Makefile
|-- resources/js/
|   |-- components/
|   |-- layouts/
|   |-- pages/
|   |   |-- admin/
|   |   |-- auth/
|   |   |-- settings/
|   |   |-- home.tsx
|   |   |-- queue.tsx
|   |   `-- songs.tsx
|   |-- routes/
|   `-- types/
|-- routes/
|   |-- api.php
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

The web app owns the queue, settings, uploaded media metadata, Pi token, and current state. The Pi owns local playback and local media files. The Pi polls the web app instead of receiving inbound commands, which keeps the deployment simple for shared hosting and networks behind NAT.

## Main Features

- Public now-playing page with animated waveform and queue preview.
- Public song browser with search and request form.
- Public full queue page with estimated wait time.
- Admin dashboard with now-playing, queue depth, queue runtime, daily stats, and recent requests with remove option.
- Admin broadcast console for mode changes, RDS text, skip, play-now, force commercial, and force sound byte.
- Admin song library with upload, edit, availability toggle, and Pi delete marking.
- Admin station ID upload and activation.
- Admin commercial upload, rotation order, enable/disable, delete, and inject-now.
- Admin sound byte upload by category, enable/disable, delete, and inject-now.
- Admin settings for callsign, frequency, fallback song, station ID interval, commercial interval, sound byte interval, and fade-in duration.
- Admin Pi token regeneration.
- Admin request history with status filter.
- Pi heartbeat/status indicator across all admin pages.
- Pi setup download — full install script and individual source files served from `/pi/`.
- Authenticated Pi API using a one-time raw token hashed in the database.
- Passkey, password, and two-factor auth support from the Laravel starter kit.

## Public Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/` | Home page with now-playing, queue preview, and public navigation |
| GET | `/songs` | Searchable paginated song catalog |
| POST | `/songs/{song}/request` | Add an available song to the pending queue; throttled at 5 requests/minute |
| GET | `/queue` | Full public pending queue with estimated wait time |
| GET | `/api/events` | SSE stream — now-playing, pi-status, queue-changed events (rate-limited 30/min) |
| GET | `/api/now-playing` | Public JSON now-playing endpoint |
| GET | `/api/pi-status` | Public JSON Pi connectivity/status endpoint |
| GET | `/pi/setup.sh` | Bash setup script — downloads, builds, and installs the Pi daemon |
| GET | `/pi/{file}` | Individual Pi source file download (allowlisted files only) |

## Admin Routes

All admin routes require authentication.

| Route | Page | Purpose |
| --- | --- | --- |
| `/admin` | Dashboard | Now-playing, queue depth, queue runtime, daily stats, recent requests |
| `/admin/broadcast` | Broadcast | Pi status, live mode, RDS, skip, play-now, force commercial/sound byte |
| `/admin/sounds` | Sounds | Songs, commercials, and sound bytes in one tabbed view — upload, edit, toggle, delete |
| `/admin/settings` | Settings | Frequency, callsign, fallback song, playback intervals, fade-in |
| `/admin/tokens` | Pi Token | Regenerate the Pi API token |
| `/admin/history` | History | Paginated request history with status filter |
| `/settings/profile` | Profile | Account profile settings |
| `/settings/security` | Security | Password, passkeys, two-factor auth |
| `/settings/appearance` | Appearance | UI appearance preference |

## Data Model

| Table | Purpose | Important fields |
| --- | --- | --- |
| `users` | Admin users | Fortify auth fields, two-factor fields |
| `passkeys` | WebAuthn/passkey credentials | Managed by Laravel Passkeys |
| `songs` | Requestable song library | `title`, `artist`, `filename`, `available`, `storage_path`, `needs_pi_download`, `pi_delete_requested` |
| `queue_items` | Requests and play history | `song_id`, `requested_by_name`, `position`, `status`, `played_at` |
| `now_playing` | Current broadcast state | Single active row, `song_id`, `queue_item_id`, `type`, `started_at` |
| `station_ids` | Station ID audio | `filename`, `label`, `active`, `file_hash`, `file_size` |
| `commercials` | Rotating ad/commercial inventory | `title`, `filename`, `active`, `rotation_order`, `play_count`, sync flags |
| `sound_bytes` | Drops, jingles, shoutouts, IDs | `title`, `filename`, `category`, `active`, sync flags |
| `settings` | Key/value runtime config | callsign, frequency, intervals, live mode, RDS, forced items |
| `pi_tokens` | Pi API tokens and status | `token_hash`, `last_seen_at`, `pi_status`, `pi_mode`, `pi_ip` |

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

The queue endpoint returns the next eligible item, and the Pi reports the item as now playing before audio starts. When the Pi reports a song, counters for station IDs, commercials, and sound bytes advance. When the Pi reports a station ID, commercial, or sound byte, the relevant counter/force flag resets.

The Pi daemon plays items in this sequence per queue response: commercial (if due/forced) → sound byte (if due/forced) → song or fallback. Each item is reported via `/api/pi/now-playing` with its type and ID before audio starts. Songs and the fallback play with an optional `afade` fade-in set by the `fade_in_duration` setting.

## Pi API

Pi endpoints require:

```http
X-Pi-Token: <raw-token-generated-in-admin>
Accept: application/json
```

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/api/pi/queue` | Returns commercial, sound byte, and next queued song |
| POST | `/api/pi/now-playing` | Pi reports `song`, `commercial`, or `sound_byte` |
| POST | `/api/pi/sync-library` | Pi uploads its local WAV filename list |
| GET | `/api/pi/config` | Returns current runtime config |
| POST | `/api/pi/heartbeat` | Updates Pi status/IP and receives config plus pending sync work |
| POST | `/api/pi/confirm-download` | Confirms a pending media download is now local on the Pi |
| POST | `/api/pi/confirm-delete` | Confirms a pending media delete was removed locally |

Public API endpoints:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/api/now-playing` | Current public now-playing payload |
| GET | `/api/pi-status` | Online/offline/status/mode/IP summary |

### Queue Response Shape

```json
{
  "play_station_id": false,
  "station_id_available": true,
  "commercial": null,
  "sound_byte": null,
  "next": {
    "queue_item_id": 42,
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

### Heartbeat Response Shape

The heartbeat response is also the current remote Pi config:

```json
{
  "broadcast_mode": "normal",
  "live_stream_url": "",
  "live_alsa_device": "hw:1,0",
  "rds_rt_mode": "auto",
  "rds_rt": "",
  "rds_ps": "",
  "station_id_interval": 3,
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
    {
      "type": "commercial",
      "item_id": 3,
      "filename": "old_spot.mp3"
    }
  ]
}
```

`confirm-download` and `confirm-delete` accept `type` plus `item_id`. They also accept the older `song_id` field for legacy song-only clients.

## Broadcast Modes

The admin broadcast page writes settings that the Pi receives on heartbeat.

| Mode | Setting value | Pi behavior |
| --- | --- | --- |
| Normal | `normal` | Poll queue and play station IDs, requests, or fallback; backend also returns commercial/sound byte candidates |
| Phone Stream | `phone_stream` | Wait for RTMP input at `rtmp://<pi-ip>:1935/live` |
| USB Input | `usb_input` | Read live audio from ALSA device, default `hw:1,0` |
| Custom Stream | `custom_stream` | Read an RTMP/HLS/Icecast/HTTP source URL with ffmpeg |

RDS settings:

- `rds_ps`: optional 8-character Program Service override. If blank, the Pi uses the callsign.
- `rds_rt_mode=auto`: RadioText uses the current song title and artist.
- `rds_rt_mode=custom`: RadioText uses the configured custom string, capped at 64 characters.

## Local Development

Requirements:

- PHP 8.3+
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

SSH into the Pi and run:

```bash
curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash
```

This downloads all source files from fmplaylist.com, installs dependencies (Python 3, ffmpeg, libsndfile, build tools), compiles `pi_fm_rds`, writes a `config.json` template, and registers the systemd service.

### Configure

```bash
sudo nano /home/pi/PiFmRds/src/config.json
```

Three values to set:

| Key | Value |
| --- | --- |
| `api_key` | Raw token from Admin → Pi Token → Regenerate (shown once — copy it immediately) |
| `freq` | Broadcast frequency in MHz, e.g. `96.9` |
| `pi_code` | 4-character RDS PI code, e.g. `C0DE` |

Everything else (callsign, RDS messages, intervals, broadcast mode) is controlled remotely from the admin panel and applied on the next heartbeat (~30 s).

### Start

```bash
sudo systemctl start fmplaylist
sudo systemctl status fmplaylist
```

The Admin status bar goes green within one poll cycle (~30 s).

### Update

Re-run setup to pull the latest daemon and rebuild:

```bash
curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash
sudo systemctl restart fmplaylist
```

### Download individual files

```
https://fmplaylist.com/pi/pi_daemon.py
https://fmplaylist.com/pi/pi_fm_rds.c
https://fmplaylist.com/pi/Makefile
https://fmplaylist.com/pi/run.sh
https://fmplaylist.com/pi/FTPA.wav
```

## Pi Daemon Flow

On startup:

1. Load local `config.json`.
2. Require `api_key`.
3. Require compiled `pi_fm_rds` binary.
4. Sync local WAV library to `/api/pi/sync-library`.
5. Check/download active station ID.
6. Send initial heartbeat.
7. Enter playback loop.

During normal playback:

1. Send periodic heartbeat and receive remote config.
2. Process pending downloads and deletes (songs, commercials, and sound bytes) from the heartbeat response.
3. Check station ID changes.
4. Sync local library once per hour.
5. Poll `/api/pi/queue`.
6. In sequence: play station ID (if due) → commercial (if due or forced) → sound byte (if due or forced) → queued song or fallback.
7. Report `/api/pi/now-playing` with `type` and `item_id` before each item starts so counters and force flags reset correctly.

During live modes:

1. Admin changes `broadcast_mode` in the broadcast console.
2. Pi heartbeat receives the new mode.
3. Pi starts ffmpeg with the selected live input.
4. Pi continues heartbeating while live.
5. When admin switches back to normal, the live process stops and normal queue playback resumes.

## Standalone Pi Mode

`/home/pi/PiFmRds/src/run.sh` loops one local audio file and rotates RDS RadioText messages without the Laravel app. Useful for bench tests or local PiFmRds verification.

```bash
cd /home/pi/PiFmRds/src
./run.sh
```

Environment overrides:

```bash
FREQ=96.9 \
PS_TEXT="96.9 FM " \
PI_CODE="C0DE" \
MSG_INTERVAL_S=20 \
SONG="FTPA.wav" \
./run.sh
```

## Media Management

### Songs

- Public requests can only target `songs.available = true`.
- Songs uploaded through admin are stored on the public disk under `songs/`.
- New uploaded songs are marked `needs_pi_download = true`.
- The Pi heartbeat response includes pending song downloads.
- Once the Pi downloads a file, it calls `/api/pi/confirm-download`.
- Deleting a downloaded song marks `pi_delete_requested = true`; the Pi deletes the local file and confirms with `/api/pi/confirm-delete`.
- Songs discovered by Pi library sync may not have a `storage_path`; these are local-only Pi files.

### Station IDs

- Station IDs are uploaded as WAV files.
- Only one station ID should be active at a time.
- The Pi checks `/api/pi/station-id` with its local hash.
- If the hash differs, the Pi downloads the active station ID and stores the new local hash.
- Station ID interval is controlled by `station_id_interval` in settings.

### Commercials

- Commercial uploads accept WAV, MP3, and OGG up to 50 MB via `/admin/commercials`.
- Stored on the public disk under `commercials/` and downloaded to `commercials/` on the Pi.
- Active commercials rotate sequentially by `rotation_order` and track `play_count`.
- Scheduled automatically every `commercial_interval` songs, or forced immediately from `/admin/broadcast`.
- Deleting a commercial that the Pi hasn't downloaded yet removes it immediately. If the Pi already has it, `pi_delete_requested` is set and Pi cleans up on next heartbeat.

### Sound Bytes

- Sound byte uploads accept WAV, MP3, and OGG up to 20 MB via `/admin/sound-bytes`.
- Stored on the public disk under `soundbytes/` and downloaded to `sound-bytes/` on the Pi.
- Categories are `jingle`, `shoutout`, `drop`, and `id`.
- Scheduled automatically every `sound_byte_interval` songs (random selection from active items), or forced immediately from `/admin/broadcast`.
- Same delete pattern as commercials — immediate if not on Pi yet, flagged otherwise.

## Production Deployment

The app is designed to work on conventional PHP hosting as long as the document root points to `public/` and required PHP extensions are available.

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
QUEUE_CONNECTION=sync
CACHE_STORE=database
FILESYSTEM_DISK=public
```

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

1. Install and migrate the web app.
2. Seed the database.
3. Set the admin password.
4. Sign in at `/login`.
5. Open `/admin/tokens` and regenerate the Pi token.
6. Copy the raw token immediately — it is only shown once.
7. SSH into the Pi and run:
   ```bash
   curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash
   ```
8. Edit `/home/pi/PiFmRds/src/config.json` — paste `api_key`, set `freq` and `pi_code`.
9. Start the daemon:
   ```bash
   sudo systemctl start fmplaylist
   ```
10. Confirm the admin status bar shows the Pi as connected.
11. Upload songs, commercials, and sound bytes from `/admin/sounds`.
12. Request a public song from `/songs`.
13. Watch `/admin/broadcast`, `/queue`, and `/api/now-playing` while the Pi advances.

## Troubleshooting

### Admin Pi status stays offline

- Confirm the Pi is running (`sudo systemctl status fmplaylist`).
- Confirm `config.json` has the correct `api_key` and `server_url`.
- Confirm the Pi can reach the site over HTTPS.
- Check the Laravel logs for `401` responses from Pi endpoints.
- Check that `last_seen_at` is updating in `pi_tokens`.

### Pi token rejected

- Tokens are stored as SHA-256 hashes; the raw token cannot be recovered.
- Regenerate the token at `/admin/tokens`.
- Paste the new raw token into `/home/pi/PiFmRds/src/config.json`.
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

### Station ID does not play

- Confirm a station ID exists and is marked active.
- Confirm `station_id_interval` is greater than zero in settings.
- Confirm the Pi downloaded the station ID file.
- Confirm the Pi reports `type=station_id` after playback so the counter resets.

### Live mode does not start

- Confirm the Pi is online in `/admin/broadcast`.
- For phone stream mode, use the `rtmp://<pi-ip>:1935/live` URL shown on the broadcast page.
- Confirm the phone and Pi can reach each other on the same network.
- For USB input, confirm the ALSA device with `arecord -l` on the Pi.
- For custom stream, test the URL with `ffmpeg -i <url>` on the Pi.

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
| `GET /api/events` (SSE) | 30 / IP / min | Covers normal reconnects; blocks SSE exhaustion attacks |
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
| `GET /api/events` | SSE stream; read-only; rate-limited |
| `GET /api/now-playing` | Current song only; no user data |
| `GET /api/pi-status` | Online/offline indicator; no credentials |
| `GET /pi/setup.sh` | Bootstrap script; references only the 18 allowlisted source files |
| `GET /pi/{file}` | Source files from a strict allowlist — no dynamic paths accepted |

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

- The `confirm-download` and `confirm-delete` Pi endpoints accept `type` + `item_id`. The older `song_id` field is also accepted for legacy daemon builds.
- The Pi setup script at `/pi/setup.sh` uses `config('app.url')` as `server_url` in the generated `config.json`, so the Pi always points back to the server it was downloaded from.
- The seeder creates only the base admin user and a few default settings. Settings not yet touched through the admin UI are read with defaults and created on first save.
- `commercial_interval` and `sound_byte_interval` default to `0` (disabled). Enable them in `/admin/settings` once you have media uploaded.
- Queue wait time shown on the public queue page is estimated from song durations of pending items.

## License

The Laravel starter kit portions and the project application code follow the license declared in `composer.json`. PiFmRds includes its own license under `PiFmRds/LICENSE`.
 _