#!/usr/bin/env python3
"""
pi_daemon.py — fmplaylist.com Pi broadcast daemon

All config (broadcast mode, RDS messages, callsign, etc.) is loaded from the
web app via the /api/pi/heartbeat endpoint. The Pi never needs its config
changed locally — everything is controlled from the admin panel.

Usage:
    sudo python3 pi_daemon.py

Config: config.json (only api_key, server_url, freq, pi_code needed locally)
"""

import json
import os
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.request
import urllib.error

SCRIPT_DIR  = os.path.dirname(os.path.realpath(__file__))
CONFIG_PATH = os.path.join(SCRIPT_DIR, 'config.json')
BINARY_PATH = os.path.join(SCRIPT_DIR, 'pi_fm_rds')
CTL_PIPE    = '/tmp/rds_ctl'
RTMP_PORT   = 1935

LOCAL_CONFIG_KEYS = {
    'server_url':            'https://fmplaylist.com',
    'api_key':               '',
    'freq':                  96.9,
    'pi_code':               'C0DE',
    'song_dir':              SCRIPT_DIR,
    'commercial_dir':        os.path.join(SCRIPT_DIR, 'commercials'),
    'sound_byte_dir':        os.path.join(SCRIPT_DIR, 'sound-bytes'),
    'fallback_song':         'FTPA.wav',
    'poll_interval_seconds': 30,
    'verify_ssl':            True,
}

MEDIA_TYPES = {'song', 'commercial', 'sound_byte'}

# Remote config (from web app heartbeat) — overrides local defaults
_remote_cfg: dict = {}

# Subprocess tracking for clean shutdown
_active_procs: list[subprocess.Popen] = []
_stop_live     = threading.Event()
_freq_interrupt = threading.Event()  # set when frequency changes mid-song

# Local config ref — set in main() so send_heartbeat() can update freq
_local: dict = {}


def _shutdown(signum, frame):
    print(f'\n[daemon] signal {signum} — stopping...')
    _stop_live.set()
    for proc in _active_procs:
        try:
            proc.terminate()
        except Exception:
            pass
    time.sleep(1)
    for proc in _active_procs:
        try:
            proc.kill()
        except Exception:
            pass
    sys.exit(0)


signal.signal(signal.SIGTERM, _shutdown)
signal.signal(signal.SIGINT, _shutdown)


# ── Config ────────────────────────────────────────────────────────────────────

def load_local_config() -> dict:
    if os.path.exists(CONFIG_PATH):
        with open(CONFIG_PATH) as f:
            return {**LOCAL_CONFIG_KEYS, **json.load(f)}
    return LOCAL_CONFIG_KEYS.copy()


def save_local_config(cfg: dict) -> None:
    with open(CONFIG_PATH, 'w') as f:
        json.dump({k: cfg[k] for k in LOCAL_CONFIG_KEYS if k in cfg}, f, indent=2)


def merged_cfg(local: dict) -> dict:
    """Merge local config with remote config received from web app."""
    return {**local, **_remote_cfg}


def media_dir(cfg: dict, media_type: str) -> str:
    if media_type == 'commercial':
        return cfg.get('commercial_dir') or os.path.join(cfg['song_dir'], 'commercials')
    if media_type == 'sound_byte':
        return cfg.get('sound_byte_dir') or os.path.join(cfg['song_dir'], 'sound-bytes')
    return cfg['song_dir']


def media_path(cfg: dict, media_type: str, filename: str) -> str:
    return os.path.join(media_dir(cfg, media_type), filename)


def ensure_media_dirs(cfg: dict) -> None:
    os.makedirs(cfg['song_dir'], exist_ok=True)
    os.makedirs(media_dir(cfg, 'commercial'), exist_ok=True)
    os.makedirs(media_dir(cfg, 'sound_byte'), exist_ok=True)


# ── Network ───────────────────────────────────────────────────────────────────

def get_local_ip() -> str:
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect(('8.8.8.8', 80))
            return s.getsockname()[0]
    except Exception:
        return '0.0.0.0'


def _ssl_ctx(cfg: dict):
    import ssl
    if cfg.get('verify_ssl', True):
        return None
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    return ctx


def api_get(cfg: dict, path: str, params: dict | None = None, _retries: int = 3):
    url = cfg['server_url'].rstrip('/') + path
    if params:
        url += '?' + '&'.join(f'{k}={v}' for k, v in params.items())
    req = urllib.request.Request(
        url,
        headers={'X-Pi-Token': cfg['api_key'], 'Accept': 'application/json'},
    )
    delay = 1
    for attempt in range(_retries):
        try:
            with urllib.request.urlopen(req, timeout=15, context=_ssl_ctx(cfg)) as resp:
                return json.loads(resp.read().decode())
        except Exception as e:
            if attempt < _retries - 1:
                print(f'[API GET {path}] {e} — retry in {delay}s')
                time.sleep(delay)
                delay *= 2
            else:
                print(f'[API GET {path}] {e} — giving up')
    return None


def api_post(cfg: dict, path: str, body: dict, _retries: int = 3):
    url  = cfg['server_url'].rstrip('/') + path
    data = json.dumps(body).encode()
    req  = urllib.request.Request(
        url, data=data,
        headers={
            'X-Pi-Token':   cfg['api_key'],
            'Content-Type': 'application/json',
            'Accept':       'application/json',
        },
        method='POST',
    )
    delay = 1
    for attempt in range(_retries):
        try:
            with urllib.request.urlopen(req, timeout=15, context=_ssl_ctx(cfg)) as resp:
                return json.loads(resp.read().decode())
        except Exception as e:
            if attempt < _retries - 1:
                print(f'[API POST {path}] {e} — retry in {delay}s')
                time.sleep(delay)
                delay *= 2
            else:
                print(f'[API POST {path}] {e} — giving up')
    return None


def send_heartbeat(cfg: dict, status: str, mode: str) -> dict | None:
    result = api_post(cfg, '/api/pi/heartbeat', {
        'status': status,
        'mode':   mode,
        'ip':     get_local_ip(),
    })
    if result:
        global _remote_cfg
        _remote_cfg = result
        bm = result.get('broadcast_mode', 'normal')
        dl = len(result.get('pending_downloads', []))
        rm = len(result.get('pending_deletes', []))
        print(f'[hb] mode={bm} downloads={dl} deletes={rm}')
        _process_pending_downloads(cfg, result)
        _process_pending_deletes(cfg, result)

        # Detect frequency change — interrupt current playback immediately
        new_freq = result.get('freq')
        if new_freq is not None and float(new_freq) != float(_local.get('freq', 96.9)):
            print(f'[hb] FREQ {_local["freq"]} → {new_freq} MHz — interrupting')
            _local['freq'] = float(new_freq)
            save_local_config(_local)
            _freq_interrupt.set()
            for proc in list(_active_procs):
                try:
                    proc.terminate()
                except Exception:
                    pass
    return result


def _process_pending_downloads(cfg: dict, heartbeat: dict) -> None:
    for item in heartbeat.get('pending_downloads', []):
        media_type = item.get('type', 'song')
        item_id = item.get('item_id') or item.get('song_id')
        filename = item['filename']
        url      = item['download_url']

        if media_type not in MEDIA_TYPES or not item_id:
            print(f'[download] skipping invalid item: {item}')
            continue

        os.makedirs(media_dir(cfg, media_type), exist_ok=True)
        dest = media_path(cfg, media_type, filename)

        if os.path.exists(dest):
            print(f'[download] {media_type}:{filename} already exists, confirming')
            api_post(cfg, '/api/pi/confirm-download', {'type': media_type, 'item_id': item_id})
            continue

        print(f'[download] fetching {media_type}:{filename}...')
        delay = 2
        for attempt in range(3):
            try:
                req = urllib.request.Request(url)
                with urllib.request.urlopen(req, timeout=120) as resp:
                    with open(dest, 'wb') as f:
                        f.write(resp.read())
                print(f'[download] saved {filename} ({os.path.getsize(dest) // 1024} KB)')
                api_post(cfg, '/api/pi/confirm-download', {'type': media_type, 'item_id': item_id})
                break
            except Exception as e:
                if os.path.exists(dest):
                    os.remove(dest)  # remove partial file
                if attempt < 2:
                    print(f'[download] failed {filename}: {e} — retry in {delay}s')
                    time.sleep(delay)
                    delay *= 2
                else:
                    print(f'[download] failed {filename}: {e} — giving up')


def _process_pending_deletes(cfg: dict, heartbeat: dict) -> None:
    for item in heartbeat.get('pending_deletes', []):
        media_type = item.get('type', 'song')
        item_id = item.get('item_id') or item.get('song_id')
        filename = item['filename']

        if media_type not in MEDIA_TYPES or not item_id:
            print(f'[delete] skipping invalid item: {item}')
            continue

        path = media_path(cfg, media_type, filename)

        if os.path.exists(path):
            try:
                os.remove(path)
                print(f'[delete] removed {media_type}:{filename}')
            except Exception as e:
                print(f'[delete] failed to remove {filename}: {e}')
                continue

        api_post(cfg, '/api/pi/confirm-delete', {'type': media_type, 'item_id': item_id})


# ── Library ───────────────────────────────────────────────────────────────────

def sync_library(cfg: dict) -> None:
    song_dir = cfg['song_dir']
    songs = [
        {'filename': f, 'file_size': os.path.getsize(os.path.join(song_dir, f))}
        for f in os.listdir(song_dir)
        if os.path.isfile(os.path.join(song_dir, f)) and f.lower().endswith('.wav')
    ]
    result = api_post(cfg, '/api/pi/sync-library', {'songs': songs})
    if result:
        print(f'[sync] added={result.get("added", 0)} unchanged={result.get("unchanged", 0)} removed={result.get("removed", 0)}')


# ── FM Playback ───────────────────────────────────────────────────────────────

# Skip sudo when already root (e.g. systemd service with User=root)
_PI_CMD = [BINARY_PATH] if os.geteuid() == 0 else ['sudo', BINARY_PATH]

def make_ctl_pipe() -> None:
    if not os.path.exists(CTL_PIPE):
        os.mkfifo(CTL_PIPE)


def rds_ps(cfg: dict) -> str:
    """Exactly 8 chars: custom rds_ps → callsign → frequency-based default."""
    default = f'{cfg.get("freq", 96.9)} FM'
    ps = _remote_cfg.get('rds_ps') or cfg.get('callsign') or default
    return ps.ljust(8)[:8]


def rds_rt(cfg: dict, song_title: str = '', song_artist: str = '') -> str:
    """RadioText: custom message or auto song title."""
    if _remote_cfg.get('rds_rt_mode') == 'custom':
        return (_remote_cfg.get('rds_rt') or '').strip()[:64]
    if song_title and song_artist:
        return f'{song_title} - {song_artist}'[:64]
    return song_title[:64]


def play_file(cfg: dict, filepath: str, title: str = '', artist: str = '', fade_in: bool = False, rds_ps_override: str = '') -> None:
    if not os.path.exists(filepath):
        print(f'[play] file not found: {filepath}')
        return

    _freq_interrupt.clear()

    ffmpeg_args = [
        'ffmpeg', '-hide_banner', '-loglevel', 'error',
        '-i', filepath,
    ]
    fade_duration = float(cfg.get('fade_in_duration', 0.0) or 0.0)
    if fade_in and fade_duration > 0:
        ffmpeg_args += ['-af', f'afade=t=in:st=0:d={fade_duration}']
    ffmpeg_args += ['-f', 'wav', '-ar', '44100', '-ac', '2', '-sample_fmt', 's16', '-']

    ffmpeg = subprocess.Popen(ffmpeg_args, stdout=subprocess.PIPE)
    _active_procs.append(ffmpeg)

    rds = subprocess.Popen(
        _PI_CMD + [
         '-freq', str(cfg['freq']),
         '-pi',   cfg.get('pi_code', 'C0DE'),
         '-audio', '-',
         '-ps',   (rds_ps_override.ljust(8)[:8] if rds_ps_override else rds_ps(cfg)),
         '-rt',   rds_rt(cfg, title, artist)],
        stdin=ffmpeg.stdout,
    )
    _active_procs.append(rds)

    if ffmpeg.stdout:
        ffmpeg.stdout.close()

    # Background thread: fire one heartbeat mid-song to catch frequency changes
    poll_interval = int(cfg.get('poll_interval_seconds', 30))
    def _freq_watcher():
        time.sleep(poll_interval)
        if rds.poll() is None:
            send_heartbeat(cfg, 'playing', 'normal')

    threading.Thread(target=_freq_watcher, daemon=True).start()

    # Poll instead of blocking — allows _freq_interrupt to cut playback short
    while rds.poll() is None and not _freq_interrupt.is_set():
        time.sleep(1)

    if _freq_interrupt.is_set():
        ffmpeg.terminate()
        rds.terminate()

    rds.wait()
    ffmpeg.wait()
    _active_procs.clear()


def play_live_stream(cfg: dict, mode: str, url: str = '') -> None:
    """
    Receive audio and broadcast live.

    mode='phone_stream' — listen for RTMP on port 1935 (Larix Broadcaster)
    mode='usb_input'    — read from ALSA USB device
    mode='custom_stream'— read from any URL ffmpeg supports
    """
    ps = rds_ps(cfg)
    rt = rds_rt(cfg, 'LIVE BROADCAST')

    if mode == 'phone_stream':
        print(f'[live] waiting for RTMP at port {RTMP_PORT}')
        src_args = ['-listen', '1', '-i', f'rtmp://0.0.0.0:{RTMP_PORT}/live']
    elif mode == 'usb_input':
        device = _remote_cfg.get('live_alsa_device', 'hw:1,0')
        print(f'[live] reading from ALSA device {device}')
        src_args = ['-f', 'alsa', '-i', device]
    elif mode == 'custom_stream' and url:
        print(f'[live] streaming from {url}')
        src_args = ['-i', url]
    else:
        print(f'[live] unknown mode or missing URL: {mode}')
        return

    ffmpeg = subprocess.Popen(
        ['ffmpeg', '-hide_banner', '-loglevel', 'error']
        + src_args
        + ['-f', 'wav', '-ar', '44100', '-ac', '2', '-sample_fmt', 's16', '-'],
        stdout=subprocess.PIPE,
    )
    _active_procs.append(ffmpeg)

    rds_proc = subprocess.Popen(
        _PI_CMD + [
         '-freq', str(cfg['freq']),
         '-pi',   cfg.get('pi_code', 'C0DE'),
         '-audio', '-',
         '-ps',   ps,
         '-rt',   rt],
        stdin=ffmpeg.stdout,
    )
    _active_procs.append(rds_proc)

    if ffmpeg.stdout:
        ffmpeg.stdout.close()

    # Poll for mode changes every 30s; exit if admin switches back to normal
    def _watch():
        while not _stop_live.is_set():
            time.sleep(30)
            result = send_heartbeat(cfg, 'live', mode)
            new_mode = _remote_cfg.get('broadcast_mode', 'normal')
            if new_mode not in ('phone_stream', 'usb_input', 'custom_stream'):
                print(f'[live] mode changed to {new_mode} — stopping live')
                _stop_live.set()

    watcher = threading.Thread(target=_watch, daemon=True)
    watcher.start()

    while rds_proc.poll() is None and not _stop_live.is_set():
        time.sleep(1)

    ffmpeg.terminate()
    rds_proc.terminate()
    ffmpeg.wait()
    rds_proc.wait()
    _active_procs.clear()
    _stop_live.clear()


# ── Main Loop ─────────────────────────────────────────────────────────────────

def main() -> None:
    global _local
    _local = load_local_config()
    local = _local
    ensure_media_dirs(local)

    if not local['api_key']:
        print('ERROR: api_key not set in config.json. Generate one in Admin → Pi Token.')
        sys.exit(1)

    if not os.path.exists(BINARY_PATH):
        print(f'ERROR: pi_fm_rds binary not found. Run "make" in {SCRIPT_DIR}')
        sys.exit(1)

    print(f'[boot] server={local["server_url"]} freq={local["freq"]}MHz')

    print('[boot] syncing song library...')
    sync_library(local)

    print('[boot] sending initial heartbeat...')
    send_heartbeat(local, 'idle', 'normal')

    last_sync        = time.time()
    last_config_poll = time.time()

    print('[boot] entering playback loop')

    while True:
        cfg          = merged_cfg(local)
        poll_interval = int(cfg.get('poll_interval_seconds', 30))
        broadcast_mode = _remote_cfg.get('broadcast_mode', 'normal')

        # ── Live mode ──────────────────────────────────────────────────────
        if broadcast_mode in ('phone_stream', 'usb_input', 'custom_stream'):
            api_post(cfg, '/api/pi/now-playing', {'type': 'song', 'song_filename': ''})
            play_live_stream(
                cfg, broadcast_mode,
                url=_remote_cfg.get('live_stream_url', ''),
            )
            # After live ends, send heartbeat and fall through to normal queue
            send_heartbeat(cfg, 'idle', 'normal')
            continue

        # ── Periodic tasks ─────────────────────────────────────────────────
        if time.time() - last_config_poll > poll_interval:
            send_heartbeat(cfg, 'idle', 'normal')
            last_config_poll = time.time()

        if time.time() - last_sync > 3600:
            sync_library(local)
            last_sync = time.time()

        # ── Queue poll ─────────────────────────────────────────────────────
        queue_data = api_get(cfg, '/api/pi/queue')

        if not queue_data:
            print(f'[loop] API unreachable, playing fallback...')
            fallback = os.path.join(local['song_dir'], cfg.get('fallback_song', 'FTPA.wav'))
            play_file(cfg, fallback, title=rds_ps(cfg).strip())
            continue

        commercial = queue_data.get('commercial')
        if commercial:
            filepath = media_path(local, 'commercial', commercial['filename'])
            print(f'[loop] playing commercial: {commercial["title"]}')
            send_heartbeat(cfg, 'playing', 'normal')
            api_post(cfg, '/api/pi/now-playing', {
                'type': 'commercial',
                'item_id': commercial['id'],
            })
            play_file(cfg, filepath, title='Commercial Break')
            if _freq_interrupt.is_set():
                continue  # restart loop — cfg will pick up new freq

        sound_byte = queue_data.get('sound_byte')
        if sound_byte:
            filepath = media_path(local, 'sound_byte', sound_byte['filename'])
            print(f'[loop] playing sound byte: {sound_byte["title"]}')
            send_heartbeat(cfg, 'playing', 'normal')
            api_post(cfg, '/api/pi/now-playing', {
                'type': 'sound_byte',
                'item_id': sound_byte['id'],
            })
            play_file(cfg, filepath, title=sound_byte['title'], rds_ps_override=sound_byte.get('rds_ps') or '')
            if _freq_interrupt.is_set():
                continue  # restart loop — cfg will pick up new freq

        # Play requested song
        next_song = queue_data.get('next')
        if next_song:
            song     = next_song['song']
            filepath = os.path.join(local['song_dir'], song['filename'])
            print(f'[loop] playing: {song["title"]}')
            send_heartbeat(cfg, 'playing', 'normal')
            api_post(cfg, '/api/pi/now-playing', {
                'type':          'song',
                'queue_item_id': next_song['queue_item_id'],
                'song_filename': song['filename'],
            })
            play_file(cfg, filepath, title=song['title'], artist=song.get('artist', ''), fade_in=True)
            continue

        # Queue empty — play fallback
        fallback = os.path.join(local['song_dir'], cfg.get('fallback_song', 'FTPA.wav'))
        print(f'[loop] queue empty, playing fallback')
        send_heartbeat(cfg, 'playing', 'normal')
        api_post(cfg, '/api/pi/now-playing', {
            'type':          'song',
            'song_filename': cfg.get('fallback_song', 'FTPA.wav'),
        })
        play_file(cfg, fallback, title=rds_ps(cfg).strip(), fade_in=True)


if __name__ == '__main__':
    main()
