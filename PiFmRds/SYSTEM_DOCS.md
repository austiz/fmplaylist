# FM Playlist System — Full Documentation

## What This Is

A network of inconspicuous FM broadcast transmitters deployed at Starbucks, McDonald's, and similar venues (with venue agreements), plus van deployments. Each unit broadcasts music on 96.9 MHz to car radios in drive-thrus and on nearby streets. The public visits **fmplaylist.com** on their phone, browses available songs, and requests tracks. Requested songs play in order. Between every 3 songs, a short 5-second anti-corporate station ID callout plays. All licensing is handled — this is a licensed operation.

---

## System Architecture Overview

```
[Public on Phone/Browser]
         │ HTTPS
         ▼
[fmplaylist.com — Namecheap Shared Hosting]
  Laravel 13 + React + Inertia.js
  MySQL database
  - Public: browse songs, request tracks, see now playing
  - Admin: manage songs, upload station IDs, view history, manage Pi token
         │
         │ Pi polls every 30 seconds via HTTP
         ▼
[Raspberry Pi 3 B+] — at venue or in van
  pi_daemon.py running
  Songs stored locally on Pi SD card
  pi_fm_rds broadcasting via GPIO 4
         │
         │ GPIO 4 (pin 7)
         ▼
[LPF — 88-108MHz Bandpass Filter SMA]
         │
         ▼
[5W FM RF Amplifier — 76-108MHz, 12V]
         │
         ▼
[FMUSER GP100 Ground Plane Antenna]
         │
         ▼
[Car Radios on 96.9 MHz — up to ~0.5 mile radius]
```

---

## Hardware

### Complete Bill of Materials

| Item | Model/Spec | Purpose |
|------|-----------|---------|
| Raspberry Pi 3 B+ | BCM2837B0, ARMv8 | Runs PiFmRds + pi_daemon.py |
| 5W FM RF Amplifier | 76-108MHz, 12V input, SMA in/out | Boosts Pi GPIO signal from ~1mW to 5W |
| 88-108MHz Bandpass Filter | SMA connectors, 50 ohm | Removes harmonics before amp |
| FMUSER GP100 Antenna | Ground plane, 1/4 wave, 88-108MHz | Broadcasts signal omnidirectionally |
| FMUSER FT02A Telescopic | 0-7W, portable | Bench testing only |
| SMA Male-Male Jumpers | RG316, 6" (4-pack) | Connects LPF to amp |
| Boobrie SMA Female → BNC Male | RF adapter | Connects amp output to GP100 BNC cable |
| 12V Car Battery | Old/degraded, holds ~11.5V | Power source at night |
| 12V Low Voltage Cutoff | Cuts at 10.8V | Protects battery from deep discharge |
| 12V → 5V USB Converter | 3A minimum | Powers Pi from 12V battery |

### Why Pi 3 B+

PiFmRds uses the Broadcom DMA engine and PWM hardware to generate FM modulation on GPIO 4 (pin 7). It is compatible with:
- Pi 1, 2, 3, 3 B+ ✓
- Pi Zero, Zero 2 ✓
- Pi 4 ✓

**Pi 5 (BCM2712) is NOT compatible** — the DMA/PWM subsystem changed fundamentally. Do not use Pi 5 as the transmitter.

### RF Signal Chain

```
Pi GPIO 4 (pin 7)
    │ ~1mW square wave at 96.9 MHz
    │ via bare wire or SMA pigtail
    ▼
[88-108MHz Bandpass Filter]
    │ Passes 88-108MHz
    │ Cuts harmonics at 290.7 MHz, 484.5 MHz, etc.
    │ Filter sees only ~1mW (Pi output, not amp output)
    ▼
[5W FM RF Amplifier]
    │ 12V powered
    │ Amplifies 1mW → ~5W
    ▼
[SMA → BNC adapter (Boobrie)]
    ▼
[GP100 TNC cable + BNC adapter]
    ▼
[GP100 Ground Plane Antenna]
    Broadcasting 96.9 MHz, ~0.5 mile radius at ground level
```

### Why the LPF Goes Before the Amp

The Pi generates a square wave (not a pure sine wave). Square waves contain harmonics:
- Fundamental: 96.9 MHz — your FM signal ✓
- 3rd harmonic: 290.7 MHz — garbage
- 5th harmonic: 484.5 MHz — garbage

The LPF strips harmonics before the amp sees the signal. If you put the LPF after the amp, the amp is already broadcasting harmonics at 5W. Always: **Pi → LPF → Amp → Antenna**.

### Antenna

The FMUSER GP100 is a 1/4 wave ground plane antenna designed for FM broadcast (88-108MHz). It has:
- 1 vertical radiating element
- 4 ground radials (horizontal)
- 50-ohm impedance
- TNC connector (BNC adapter included)
- ~3 dBd gain

At 5W with GP100 on a van roof or building corner: estimated 0.25–0.5 mile radius in urban environments. Range depends heavily on terrain, obstacles, and antenna height.

### Power System

```
[Car Battery 12V, ~11.5V charged]
         │
[12V Low Voltage Cutoff]  ← cuts at 10.8V to protect battery
         │
    ┌────┴──────────────┐
    │                   │
[12V→5V USB]      [5W Amp 12V]
    │                   │ 
[Pi 3 B+]       (amp draws ~800mA at 12V = ~10W)
(Pi draws ~750mA at 5V = ~3.75W)
```

**Total draw:** ~13-14W
**Battery capacity (degraded car battery):** ~180Wh usable (50% DoD from ~360Wh)
**Runtime estimate:** ~12-13 hours

Charge during the day from venue power (12V 2A smart charger or solar). Broadcast at night on battery.

---

## Software — Pi Side

### PiFmRds

PiFmRds is the core FM broadcasting software. It uses the Raspberry Pi's DMA engine to generate FM modulation on GPIO 4 without any additional hardware.

**Source:** `src/pi_fm_rds.c` and supporting files
**Binary:** `src/pi_fm_rds` (compiled on Pi)

**How to compile (on the Pi):**
```bash
cd ~/PiFmRds/src
make
```

**Direct usage:**
```bash
ffmpeg -i song.wav -f wav -ar 44100 -ac 2 - | sudo ./pi_fm_rds \
  -freq 96.9 \
  -pi C0DE \
  -ps "96.9 FM " \
  -rt "Song Title - Artist" \
  -ctl /tmp/rds_ctl \
  -audio -
```

**Key flags:**
| Flag | Description |
|------|-------------|
| `-freq` | Broadcast frequency in MHz (96.9) |
| `-pi` | RDS PI code (4 hex chars, station identifier) |
| `-ps` | Program Service name — 8 chars max, shown as station name on radio |
| `-rt` | Radio Text — scrolling message on radio display, up to 64 chars |
| `-ctl` | Path to control FIFO pipe for runtime updates |
| `-audio -` | Read audio from stdin |
| `-ppm` | Clock error correction (0 usually fine) |

**RDS (Radio Data System):** Metadata broadcast alongside the FM audio. Modern car radios display the station name (PS) and scrolling text (RT). About 95% of cars made after 2000 support RDS.

### run.sh — Simple Loop Mode

`src/run.sh` is a standalone script for simple operation without the web app. It loops a single WAV file indefinitely and rotates custom messages via the RDS RadioText field.

**Configuration via environment variables:**
```bash
FREQ=96.9          # Broadcast frequency
PS_TEXT="96.9 FM " # Station name (8 chars, note trailing space)
PI_CODE="C0DE"     # RDS PI code
MSG_INTERVAL_S=20  # How often to rotate messages (seconds)
SONG="FTPA.wav"    # Audio file to loop
```

**To run:**
```bash
cd /home/pi/PiFmRds/src
./run.sh
# or with custom settings:
FREQ=96.9 PS_TEXT="VIBES   " ./run.sh
```

**To add/change messages,** edit the `MESSAGES` array inside run.sh:
```bash
MESSAGES=(
  "HI VISTA, BE KIND TO YOURSELF"
  "STOP IN AT VISTA CAFE ON MAIN"
  "FREE WIFI AT VISTA LOUNGE"
)
```

### pi_daemon.py — Full Network Mode

`src/pi_daemon.py` is the production daemon. It replaces run.sh when connected to fmplaylist.com. Instead of looping one song, it polls the web app API for song requests from the public and plays them in order.

**How it works:**

```
1. Startup
   ├── Load config.json
   ├── Ensure commercials/ and sound-bytes/ subdirs exist under song_dir
   ├── POST /api/pi/heartbeat    (receive config + pending downloads/deletes)
   ├── POST /api/pi/sync-library (tells web app what songs are on the Pi)
   ├── GET  /api/pi/station-id   (downloads station ID callout if updated)
   └── Enter main playback loop

2. Main loop (repeats after every item finishes)
   ├── POST /api/pi/heartbeat   (every 30 s — receive config, download/delete queue)
   ├── _process_pending_downloads() — download any new songs/commercials/sound bytes
   ├── _process_pending_deletes()  — delete any flagged files
   ├── GET /api/pi/queue
   │   ├── play_station_id=true?  → POST now-playing(station_id), play station_id.wav
   │   ├── commercial returned?   → POST now-playing(commercial), play from commercials/
   │   ├── sound_byte returned?   → POST now-playing(sound_byte), play from sound-bytes/
   │   ├── next song exists?      → POST now-playing(song), play with fade-in
   │   └── queue empty?           → play fallback (FTPA.wav) with fade-in
   └── Every 1 hour: re-sync song library

Songs and fallback play with ffmpeg fade-in (duration from fade_in_duration setting).
Commercials and sound bytes do NOT use fade-in.
```

**To run:**
```bash
cd /home/pi/PiFmRds/src
sudo python3 pi_daemon.py
```

**To run as a systemd service** (auto-start on boot):
```ini
# /etc/systemd/system/fmplaylist.service
[Unit]
Description=FM Playlist Daemon
After=network.target

[Service]
ExecStart=/usr/bin/python3 /home/pi/PiFmRds/src/pi_daemon.py
WorkingDirectory=/home/pi/PiFmRds/src
Restart=always
RestartSec=10
User=root

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable fmplaylist
sudo systemctl start fmplaylist
```

### config.json

```json
{
  "server_url": "https://fmplaylist.com",
  "api_key": "paste-raw-token-from-admin-panel",
  "freq": 96.9,
  "pi_code": "C0DE",
  "song_dir": "/home/pi/PiFmRds/src",
  "commercial_dir": "/home/pi/PiFmRds/src/commercials",
  "sound_byte_dir": "/home/pi/PiFmRds/src/sound-bytes",
  "fallback_song": "FTPA.wav",
  "local_station_id_path": "/home/pi/PiFmRds/src/station_id.wav",
  "local_station_id_hash": "",
  "poll_interval_seconds": 30
}
```

**`api_key`:** Generate this in the web admin panel (Admin → Pi Token → Generate). Paste the raw token here. The web app stores only a SHA-256 hash — the raw token is shown only once.

**`commercial_dir` / `sound_byte_dir`:** Subdirectories where the daemon stores commercial and sound byte files. Created automatically on first run.

**`local_station_id_hash`:** Written automatically by the daemon after downloading a new station ID. Do not edit manually.

**`poll_interval_seconds`:** How often the daemon sends a heartbeat (default 30). Controls how quickly the Pi picks up new songs, force-play commands, and config changes.

---

## Software — Web App

**Location:** `C:\Users\austi\Herd\fmplaylist\`
**URL:** https://fmplaylist.com
**Stack:** Laravel 13, PHP 8.5, React 19, Inertia.js v2, Tailwind CSS v4, MySQL

### Public Pages

| URL | Page | What it does |
|-----|------|-------------|
| `/` | Home | Now playing display (polls every 10s), queue preview (next 5), link to song browser |
| `/songs` | Songs | Searchable song library, request dialog per song |
| `/queue` | Queue | Full queue list, auto-refreshes every 15s |

### Admin Pages (login required)

| URL | Page | What it does |
|-----|------|-------------|
| `/admin` | Dashboard | Now playing, queue depth, today's stats, recent requests |
| `/admin/broadcast` | Broadcast | Pi live status, broadcast mode (normal/phone/USB/stream), RDS editor, skip, play-now, commercial injection, sound byte injection |
| `/admin/songs` | Songs | Upload WAV songs from web, full library with Pi download/delete status |
| `/admin/commercials` | Commercials | Upload ad spots (WAV/MP3/OGG), set rotation order, enable/disable, force play |
| `/admin/sound-bytes` | Sound Bytes | Upload jingles/shoutouts/drops/IDs, categorize, enable/disable, force play |
| `/admin/station-ids` | Station IDs | Upload WAV callouts, set one active |
| `/admin/settings` | Settings | Callsign, fallback song, station ID/commercial/sound byte intervals, fade-in duration |
| `/admin/tokens` | Pi Token | Generate/regenerate the Pi API key |
| `/admin/history` | History | All song requests with status filter |

**Default admin login (after seeding):**
```
Email: admin@fmplaylist.com
Password: set via `php artisan tinker` → `User::first()->update(['password' => bcrypt('yourpassword')])`
```

### Pi API Endpoints

All Pi API endpoints require the `X-Pi-Token: {raw_token}` header.

| Method | URL | What it does |
|--------|-----|-------------|
| `POST` | `/api/pi/heartbeat` | Pi sends status, receives config + pending downloads/deletes |
| `GET` | `/api/pi/queue` | Returns next song to play + station ID flag + any due commercial/sound byte |
| `POST` | `/api/pi/now-playing` | Pi reports what it's currently playing (song, station_id, commercial, or sound_byte) |
| `POST` | `/api/pi/sync-library` | Pi pushes its list of song files + sizes |
| `GET` | `/api/pi/station-id` | Pi checks if station ID has changed, gets download URL if so |
| `POST` | `/api/pi/confirm-download` | Pi confirms it downloaded a song/commercial/sound_byte |
| `POST` | `/api/pi/confirm-delete` | Pi confirms it deleted a song/commercial/sound_byte |
| `GET` | `/api/now-playing` | Public endpoint — no auth — polled by browser every 10s |
| `GET` | `/api/pi-status` | Public endpoint — no auth — returns Pi online/mode status |

#### GET /api/pi/queue — Response Example

```json
{
  "play_station_id": false,
  "station_id_available": true,
  "commercial": {
    "id": 3,
    "title": "Midnight Diner Ad",
    "filename": "midnight_diner.mp3",
    "duration_seconds": 30
  },
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

**Playback order when all fields are populated:** station ID → commercial → sound byte → song.

- `play_station_id: true` — Pi plays the callout file first, then loops back.
- `commercial` — Pi plays it before the song. Null if none is due.
- `sound_byte` — Pi plays it between the commercial and the song. Null if none is due.
- `next.song` — The queued song. Null when queue is empty (Pi plays fallback).

Config (callsign, intervals, fade duration) is returned by the heartbeat endpoint, not queue.

### Database Schema

| Table | Key Columns | Purpose |
|-------|-------------|---------|
| `songs` | id, title, artist, filename, duration_seconds, file_size, available, storage_path, needs_pi_download, pi_delete_requested | Song library — synced from Pi OR web-uploaded |
| `queue_items` | id, song_id, requested_by_name, position, status, played_at | Public song requests + play history |
| `now_playing` | id=1 singleton, song_id, type (song/station_id/commercial/sound_byte), started_at | Current broadcast state |
| `station_ids` | id, filename, label, active, file_hash, file_size | Station ID callout WAV files |
| `commercials` | id, title, filename, storage_path, duration_seconds, file_size, active, rotation_order, play_count, needs_pi_download, pi_delete_requested | Ad spots — rotated sequentially by rotation_order |
| `sound_bytes` | id, title, filename, storage_path, category (jingle/shoutout/drop/id), duration_seconds, file_size, active, needs_pi_download, pi_delete_requested | Radio drops/jingles — played randomly between songs |
| `settings` | key (PK), value | All settings: callsign, intervals, broadcast_mode, rds_rt/rds_ps, force flags, counters, etc. |
| `pi_tokens` | id, token_hash, label, last_seen_at, pi_status, pi_mode, pi_ip | Pi API auth + live status tracking |

**`now_playing` is a singleton** — the code always uses `id=1`. It is upserted, never has multiple rows.

**Queue status values:** `pending → playing → played` or `pending → playing → skipped`.

**Commercials vs. sound bytes:** Commercials rotate sequentially (by `rotation_order`). Sound bytes are selected randomly. The admin can force-play a specific item by ID via Admin → Broadcast.

**Queue status flow:**
```
[created] → pending → playing → played
                    ↘ skipped
```

### How Playback Counters Work

All three automatic-playback intervals use the same counter-in-settings pattern:

| Counter setting | Interval setting | Reset trigger |
|-----------------|-----------------|---------------|
| `songs_played_since_last_station_id` | `station_id_interval` | Pi reports `type=station_id` |
| `songs_since_last_commercial` | `commercial_interval` | Pi reports `type=commercial` |
| `songs_since_last_sound_byte` | `sound_byte_interval` | Pi reports `type=sound_byte` |

Every time the Pi reports `type=song`, all three counters increment by one. When a counter reaches its interval value, the next queue poll returns that item. Setting an interval to `0` disables automatic scheduling for that item type.

Counters are stored in the `settings` table, so they persist through Pi reboots — the Pi never tracks them locally.

**Force play:** The admin can bypass intervals by setting `force_commercial_id` or `force_sound_byte_id` in the settings table (via Admin → Broadcast → Play Now). The force flag is cleared when the Pi reports the item as played.

---

## Deployment — Namecheap cPanel

### Initial Setup

1. **Upload files** to `/home/USERNAME/fmplaylist/` — NOT inside `public_html`
2. **Set document root** in cPanel → Domains → Edit → Document Root → `/home/USERNAME/fmplaylist/public`
3. **PHP version** in cPanel → MultiPHP Manager → set to 8.5 (or highest available)
4. **Create database** in cPanel → MySQL Databases → create DB + user + grant all privileges

### Environment Configuration

Edit `/home/USERNAME/fmplaylist/.env`:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fmplaylist.com
APP_KEY=base64:...  # generated by php artisan key:generate

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=USERNAME_fmplaylist
DB_USERNAME=USERNAME_fmuser
DB_PASSWORD=your_db_password

QUEUE_CONNECTION=sync
SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=public
```

### Build Locally, Upload Assets

```bash
# On your Windows machine
cd C:\Users\austi\Herd\fmplaylist
composer install --no-dev --optimize-autoloader
npm install
npm run build
# Upload the entire fmplaylist/ directory to Namecheap
```

### Post-Deploy Commands

Run via cPanel → Terminal (or SSH if available):
```bash
cd ~/fmplaylist
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### cPanel Cron Job

cPanel → Cron Jobs → Add:
```
Command: /usr/local/bin/php /home/USERNAME/fmplaylist/artisan schedule:run >> /dev/null 2>&1
Frequency: Every Minute (select * * * * *)
```

This runs the Laravel scheduler which prunes old queue history weekly.

### PHP Upload Limits (.user.ini)

The file `/home/USERNAME/fmplaylist/.user.ini` is already in the project:
```ini
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 256M
max_execution_time = 120
```
This allows uploading station ID WAV files up to 50MB via the admin panel.

---

## First Run — End to End

### Step 1: Deploy the Web App

Follow the Namecheap deployment steps above. After `db:seed`, visit https://fmplaylist.com/login and log in as `admin@fmplaylist.com`.

### Step 2: Generate Pi API Token

Admin → Pi Token → Generate Token → **copy the raw token immediately** (shown only once).

### Step 3: Configure the Pi

On the Pi, edit `/home/pi/PiFmRds/src/config.json`:
```json
{
  "server_url": "https://fmplaylist.com",
  "api_key": "PASTE_RAW_TOKEN_HERE",
  "freq": 96.9,
  ...
}
```

### Step 4: Put Songs on the Pi

Place WAV files in `/home/pi/PiFmRds/src/`. Any WAV at 44.1kHz stereo works. FTPA.wav is the fallback — keep it there.

### Step 5: Start the Daemon

```bash
cd /home/pi/PiFmRds/src
sudo python3 pi_daemon.py
```

On first run it will:
1. Sync the song list to fmplaylist.com (songs appear in the web library)
2. Check for a station ID to download (none yet, that's fine)
3. Start broadcasting FTPA.wav on 96.9 MHz (fallback, queue is empty)

### Step 6: Upload a Station ID

Admin → Station IDs → Upload → select a WAV file (5 seconds, anti-corporate callout) → give it a label → Upload → Set Active.

Within 5 minutes the Pi polls for station ID updates and downloads it automatically. From then on it plays after every 3 songs.

### Step 7: Go Public

Share fmplaylist.com. People request songs. Songs play in order. Station ID every 3 songs. Now playing updates live on the website.

---

## Testing Without the Full Setup

### Test Broadcast (no amp, no antenna)

Plug a 30cm wire into GPIO 4 (pin 7) of the Pi. Run:
```bash
cd /home/pi/PiFmRds/src
ffmpeg -i FTPA.wav -f wav -ar 44100 -ac 2 - | sudo ./pi_fm_rds -freq 96.9 -ps "96.9 FM" -audio -
```
Tune a phone or radio to 96.9 MHz. Should hear audio within ~10 feet.

### Test With Amp + FT02A Telescopic

Connect: Pi GPIO 4 → LPF → amp → FT02A antenna. Power Pi via USB, amp via 12V. Should hear clearly in the same room and adjacent rooms.

### Test Queue (curl)

Generate a token in admin panel, then:
```bash
# Check queue from Pi's perspective
curl -H "X-Pi-Token: your_raw_token" https://fmplaylist.com/api/pi/queue

# Check now playing (public)
curl https://fmplaylist.com/api/now-playing
```

### Test Library Sync

```bash
curl -X POST https://fmplaylist.com/api/pi/sync-library \
  -H "X-Pi-Token: your_raw_token" \
  -H "Content-Type: application/json" \
  -d '{"songs": [{"filename": "FTPA.wav", "file_size": 47000000}]}'
```

---

## File Structure Reference

### Pi (Raspberry Pi)
```
/home/pi/PiFmRds/
├── src/
│   ├── pi_fm_rds           # compiled binary
│   ├── pi_daemon.py        # production daemon (polls fmplaylist.com)
│   ├── run.sh              # simple loop mode (no web app needed)
│   ├── config.json         # daemon config (api_key goes here)
│   ├── FTPA.wav            # fallback/loop audio
│   ├── station_id.wav      # downloaded from web app automatically
│   ├── *.wav               # songs (synced to web app library)
│   ├── commercials/        # downloaded ad spots (WAV/MP3/OGG)
│   └── sound-bytes/        # downloaded jingles/drops (WAV/MP3/OGG)
```

### Web App (Namecheap / local dev)
```
C:\Users\austi\Herd\fmplaylist\
├── app/
│   ├── Http/Controllers/
│   │   ├── HomeController.php
│   │   ├── SongController.php
│   │   ├── QueueController.php
│   │   ├── Admin/
│   │   │   ├── BroadcastController.php    # broadcast mode, force play, skip
│   │   │   ├── CommercialController.php   # upload/delete commercials
│   │   │   ├── SoundByteController.php    # upload/delete sound bytes
│   │   │   ├── SongAdminController.php
│   │   │   ├── StationIdController.php
│   │   │   ├── SettingsController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── TokenController.php
│   │   │   └── HistoryController.php
│   │   └── Api/PiController.php
│   ├── Http/Middleware/AuthenticatePiToken.php
│   ├── Models/              # Song, Commercial, SoundByte, QueueItem, NowPlaying,
│   │                        # StationId, Setting, PiToken
│   └── Services/            # QueueService, StationIdService
├── resources/js/
│   ├── types/fm.ts          # TypeScript interfaces for all models
│   ├── pages/               # home.tsx, songs.tsx, queue.tsx
│   │   └── admin/           # dashboard, broadcast, songs, commercials,
│   │                        # sound-bytes, station-ids, settings, tokens, history
│   └── components/          # now-playing-bar.tsx, public-layout.tsx, admin-layout.tsx
├── routes/
│   ├── web.php              # public + admin routes (~33 routes)
│   └── api.php              # Pi API + public now-playing/pi-status JSON
├── database/migrations/     # 9 custom migrations
├── storage/app/public/      # uploaded songs, commercials, sound bytes (web-sourced)
├── .user.ini                # cPanel PHP upload limits
└── public/.htaccess         # cPanel URL rewriting
```

---

## Troubleshooting

### Pi not broadcasting / no signal

1. Check `sudo` — pi_fm_rds requires root for DMA access
2. Check GPIO 4 wire is connected to LPF SMA input
3. Check amp has 12V power (LED on amp board should be on)
4. Check you're tuned to exactly 96.9 MHz (not 96.8 or 97.0)
5. Run `sudo ./pi_fm_rds -freq 96.9 -ps "TEST" -rt "HELLO" -audio /dev/zero` — no audio needed to test signal

### Pi daemon not connecting to web app

1. Check `api_key` in config.json matches the token shown in Admin → Pi Token
2. Check `server_url` has no trailing slash and uses https://
3. Test manually: `curl -H "X-Pi-Token: your_key" https://fmplaylist.com/api/pi/queue`
4. Check Pi has internet: `ping fmplaylist.com`

### Songs not appearing in web library

Songs only appear after the Pi runs `pi_daemon.py` at least once (it syncs the library on startup). If already running: songs sync every hour, or restart the daemon.

### Station ID not playing on Pi

1. Check Admin → Station IDs — is one marked "Active"?
2. Check Admin → Settings — what is `station_id_interval` set to?
3. The Pi checks every 5 minutes — may need to wait or restart daemon
4. Check the WAV file is at 44.1kHz and can be played by ffmpeg

### Queue not advancing

Songs advance when the Pi reports `now-playing`. If the daemon crashes mid-song, the queue item stays as "playing." Fix: in Admin → History, this shows as a stuck "playing" item — it clears itself when the Pi next calls `now-playing`.

### Website 500 error after deploy

1. Check `.env` has correct DB credentials
2. Run `php artisan config:clear && php artisan config:cache`
3. Check `storage/` and `bootstrap/cache/` are writable: `chmod -R 775 storage bootstrap/cache`
4. Check `QUEUE_CONNECTION=sync` (not redis/database — shared hosting)

---

## Frequency and Range Summary

| Setup | Estimated Range |
|-------|----------------|
| Pi GPIO wire only (no amp) | 10–30 feet |
| Pi + 5W amp + FT02A telescopic (bench) | 50–300 feet |
| Pi + 5W amp + GP100 ground level | 0.25–0.5 mile |
| Pi + 5W amp + GP100 on van roof | 0.5–1 mile |
| Pi + 5W amp + GP100 on overpass | 1–3 miles |

Urban range is limited by buildings and terrain. Open highway range is much greater.

---

## RDS Display on Car Radios

What drivers see on their radio display:

```
┌──────────────────────┐
│ 96.9 FM              │  ← PS (Program Service) — 8 chars
│ Midnight Radio - The │  ← RT (RadioText) — scrolling, 64 chars
└──────────────────────┘
```

- **PS** (station name): Set by `callsign` setting in Admin → Settings, truncated to 8 chars
- **RT** (RadioText): Set by pi_daemon.py to the current song title and artist
- **CT** (Clock Time): PiFmRds automatically sends the Pi's system clock once per minute

RDS works on ~95% of cars made after 2000. Phones with FM radio apps also show RDS.
