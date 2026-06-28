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
import struct
import subprocess
import sys
import threading
import time
import urllib.request
import urllib.error

SCRIPT_DIR  = os.path.dirname(os.path.realpath(__file__))
CONFIG_PATH = os.path.join(SCRIPT_DIR, 'config.json')
BINARY_PATH = os.path.join(SCRIPT_DIR, 'pi_fm_rds')
RTMP_PORT   = 1935

# Audio constants — must match what we tell pi_fm_rds via streaming WAV header
SAMPLE_RATE    = 44100
CHANNELS       = 2
BITS           = 16
BYTES_PER_SEC  = SAMPLE_RATE * CHANNELS * (BITS // 8)  # 176400 bytes/sec
CHUNK_SIZE     = 8192   # bytes per read cycle
CROSSFADE_SECS = 2.0    # overlap at song boundaries
FADE_IN_SECS   = 0.5
FADE_OUT_SECS  = 2.0

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

_remote_cfg: dict = {}

# Single persistent FM transmitter — kept alive across all songs
_fm_proc: subprocess.Popen | None = None

_active_procs: list[subprocess.Popen] = []
_stop_live      = threading.Event()
_freq_interrupt = threading.Event()

_local: dict = {}


def _shutdown(signum, frame):
    print(f'\n[daemon] signal {signum} — stopping...')
    _stop_live.set()
    _stop_fm()
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

        new_freq = result.get('freq')
        if new_freq is not None and float(new_freq) != float(_local.get('freq', 96.9)):
            print(f'[hb] FREQ {_local["freq"]} → {new_freq} MHz — restarting transmitter')
            _local['freq'] = float(new_freq)
            save_local_config(_local)
            _freq_interrupt.set()
            _stop_fm()
    return result


def _process_pending_downloads(cfg: dict, heartbeat: dict) -> None:
    for item in heartbeat.get('pending_downloads', []):
        media_type = item.get('type', 'song')
        item_id = item.get('item_id') or item.get('song_id')
        filename = item['filename']
        url      = item['download_url']

        if media_type not in MEDIA_TYPES or not item_id:
            continue

        os.makedirs(media_dir(cfg, media_type), exist_ok=True)
        dest = media_path(cfg, media_type, filename)

        if os.path.exists(dest):
            print(f'[download] {filename} already exists, confirming')
            api_post(cfg, '/api/pi/confirm-download', {'type': media_type, 'item_id': item_id})
            continue

        print(f'[download] fetching {media_type}:{filename}...')
        delay = 2
        for attempt in range(3):
            try:
                req = urllib.request.Request(url)
                with urllib.request.urlopen(req, timeout=120, context=_ssl_ctx(cfg)) as resp:
                    with open(dest, 'wb') as f:
                        f.write(resp.read())
                print(f'[download] saved {filename} ({os.path.getsize(dest) // 1024} KB)')
                api_post(cfg, '/api/pi/confirm-download', {'type': media_type, 'item_id': item_id})
                break
            except Exception as e:
                if os.path.exists(dest):
                    os.remove(dest)
                if attempt < 2:
                    print(f'[download] {filename}: {e} — retry in {delay}s')
                    time.sleep(delay)
                    delay *= 2
                else:
                    print(f'[download] {filename}: {e} — giving up')


def _process_pending_deletes(cfg: dict, heartbeat: dict) -> None:
    for item in heartbeat.get('pending_deletes', []):
        media_type = item.get('type', 'song')
        item_id = item.get('item_id') or item.get('song_id')
        filename = item['filename']

        if media_type not in MEDIA_TYPES or not item_id:
            continue

        path = media_path(cfg, media_type, filename)
        if os.path.exists(path):
            try:
                os.remove(path)
                print(f'[delete] removed {filename}')
            except Exception as e:
                print(f'[delete] failed {filename}: {e}')
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


# ── FM Transmitter ────────────────────────────────────────────────────────────

_PI_CMD = [BINARY_PATH] if os.geteuid() == 0 else ['sudo', BINARY_PATH]


def rds_ps(cfg: dict) -> str:
    default = f'{cfg.get("freq", 96.9)} FM'
    ps = _remote_cfg.get('rds_ps') or cfg.get('callsign') or default
    return ps.ljust(8)[:8]


def rds_rt(cfg: dict, song_title: str = '', song_artist: str = '') -> str:
    if _remote_cfg.get('rds_rt_mode') == 'custom':
        return (_remote_cfg.get('rds_rt') or '').strip()[:64]
    if song_title and song_artist:
        return f'{song_title} - {song_artist}'[:64]
    return song_title[:64]


def _write_streaming_wav_header(pipe) -> None:
    """
    Write a WAV header whose sizes are 0xFFFFFFFF ("unknown / streaming").
    libsndfile will keep reading raw PCM samples indefinitely after this header,
    so we can pipe multiple songs without ever restarting pi_fm_rds.
    """
    byte_rate   = SAMPLE_RATE * CHANNELS * (BITS // 8)
    block_align = CHANNELS * (BITS // 8)
    pipe.write(b'RIFF')
    pipe.write(struct.pack('<I', 0xFFFFFFFF))   # total RIFF size: unknown
    pipe.write(b'WAVE')
    pipe.write(b'fmt ')
    pipe.write(struct.pack('<I', 16))            # fmt chunk size
    pipe.write(struct.pack('<HHIIHH',
        1,            # PCM
        CHANNELS,
        SAMPLE_RATE,
        byte_rate,
        block_align,
        BITS,
    ))
    pipe.write(b'data')
    pipe.write(struct.pack('<I', 0xFFFFFFFF))   # data size: unknown
    pipe.flush()


def _ensure_fm_running(cfg: dict, ps: str = '', rt: str = '') -> bool:
    global _fm_proc
    if _fm_proc is not None and _fm_proc.poll() is None:
        return True

    _ps = ps or rds_ps(cfg)
    _rt = rt or rds_rt(cfg)
    print(f'[FM] starting transmitter at {cfg["freq"]} MHz  ps="{_ps.strip()}"')
    try:
        _fm_proc = subprocess.Popen(
            _PI_CMD + [
                '-freq', str(cfg['freq']),
                '-pi',   cfg.get('pi_code', 'C0DE'),
                '-audio', '-',
                '-ps',   _ps,
                '-rt',   _rt,
            ],
            stdin=subprocess.PIPE,
        )
        # Send streaming WAV header once — libsndfile reads raw PCM after this
        _write_streaming_wav_header(_fm_proc.stdin)
        return True
    except Exception as e:
        print(f'[FM] failed to start: {e}')
        _fm_proc = None
        return False


def _stop_fm() -> None:
    global _fm_proc
    if _fm_proc is None:
        return
    try:
        _fm_proc.stdin.close()
    except Exception:
        pass
    try:
        _fm_proc.terminate()
        _fm_proc.wait(timeout=3)
    except Exception:
        try:
            _fm_proc.kill()
        except Exception:
            pass
    _fm_proc = None


def get_audio_duration(filepath: str) -> float:
    try:
        out = subprocess.check_output(
            ['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
             '-of', 'default=noprint_wrappers=1:nokey=1', filepath],
            stderr=subprocess.DEVNULL, timeout=10,
        )
        return max(0.0, float(out.strip()))
    except Exception:
        return 0.0


def _mix_pcm(chunk_a: bytes, vol_a: float, chunk_b: bytes, vol_b: float) -> bytes:
    """
    Linear mix of two raw s16le PCM chunks at given volumes.
    Shorter chunk is zero-padded. Result is clamped to int16 range.
    """
    max_len = max(len(chunk_a), len(chunk_b)) & ~1  # keep 16-bit aligned
    a = chunk_a.ljust(max_len, b'\x00')[:max_len]
    b = chunk_b.ljust(max_len, b'\x00')[:max_len]
    n = max_len // 2
    try:
        import numpy as np
        sa = np.frombuffer(a, dtype='<i2').astype(np.float32)
        sb = np.frombuffer(b, dtype='<i2').astype(np.float32)
        mixed = np.clip(sa * vol_a + sb * vol_b, -32768, 32767).astype('<i2')
        return mixed.tobytes()
    except ImportError:
        sa = struct.unpack(f'<{n}h', a)
        sb = struct.unpack(f'<{n}h', b)
        mixed = [max(-32768, min(32767, int(x * vol_a + y * vol_b))) for x, y in zip(sa, sb)]
        return struct.pack(f'<{n}h', *mixed)


def _make_ffmpeg(filepath: str, fade_in: float = 0.0) -> subprocess.Popen:
    """
    Start ffmpeg for filepath, outputting raw s16le PCM at 44100 Hz stereo.
    The streaming WAV header is already written to pi_fm_rds stdin, so we
    emit headerless PCM only (-f s16le).
    """
    af_parts = []
    if fade_in > 0:
        af_parts.append(f'afade=t=in:st=0:d={fade_in}')
    args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-i', filepath]
    if af_parts:
        args += ['-af', ','.join(af_parts)]
    args += ['-f', 's16le', '-ar', str(SAMPLE_RATE), '-ac', str(CHANNELS), 'pipe:1']
    return subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)


def write_audio_to_fm(
    filepath: str,
    cfg: dict,
    title: str = '',
    artist: str = '',
    fade_in: float = FADE_IN_SECS,
    crossfade_secs: float = CROSSFADE_SECS,
    _pre_started_ffmpeg: 'subprocess.Popen | None' = None,
) -> 'subprocess.Popen | None':
    """
    Stream filepath to the FM transmitter.

    - One pi_fm_rds process is reused across all songs (no restarts → no static).
    - Applies fade-in at start.
    - Near the end, fetches next queue item and crossfades (overlap) into it.
    - Returns the still-running ffmpeg process for the next song so the caller
      can continue it without restarting (None if no crossfade happened).
    """
    global _fm_proc

    if not os.path.exists(filepath):
        print(f'[play] file not found: {filepath}')
        return None

    _freq_interrupt.clear()

    if not _ensure_fm_running(cfg, ps=rds_ps(cfg), rt=rds_rt(cfg, title, artist)):
        return None

    duration      = get_audio_duration(filepath)
    # Bytes before we start the crossfade countdown
    body_bytes    = max(0, int((duration - crossfade_secs) * BYTES_PER_SEC))
    # After body_bytes, we apply fade-out on A while fading in B
    xfade_bytes   = int(crossfade_secs * BYTES_PER_SEC)

    ffmpeg_a = _pre_started_ffmpeg or _make_ffmpeg(filepath, fade_in=fade_in)
    if ffmpeg_a not in _active_procs:
        _active_procs.append(ffmpeg_a)

    poll_interval = int(cfg.get('poll_interval_seconds', 30))
    last_hb       = time.time()
    bytes_written = 0

    # ── Phase 1: main body (before crossfade window) ──────────────────────────
    while bytes_written < body_bytes:
        if _freq_interrupt.is_set():
            ffmpeg_a.terminate()
            ffmpeg_a.wait()
            if ffmpeg_a in _active_procs:
                _active_procs.remove(ffmpeg_a)
            return None

        to_read = min(CHUNK_SIZE, body_bytes - bytes_written)
        chunk   = ffmpeg_a.stdout.read(to_read)
        if not chunk:
            break  # song shorter than expected

        try:
            _fm_proc.stdin.write(chunk)
        except (BrokenPipeError, OSError):
            print('[play] FM pipe broken — transmitter will restart next track')
            _fm_proc = None
            ffmpeg_a.terminate()
            ffmpeg_a.wait()
            if ffmpeg_a in _active_procs:
                _active_procs.remove(ffmpeg_a)
            return None

        bytes_written += len(chunk)

        if time.time() - last_hb > poll_interval:
            threading.Thread(
                target=send_heartbeat, args=(cfg, 'playing', 'normal'), daemon=True,
            ).start()
            last_hb = time.time()

    # ── Phase 2: crossfade window ─────────────────────────────────────────────
    # Fetch next song for crossfade (non-blocking — we have crossfade_secs to fill)
    next_ffmpeg: subprocess.Popen | None = None
    next_song_path = ''
    if duration > crossfade_secs + 2 and not _freq_interrupt.is_set():
        next_queue = api_get(cfg, '/api/pi/queue', _retries=1)
        if next_queue:
            _process_pending_downloads(cfg, next_queue)
            _process_pending_deletes(cfg, next_queue)
            nxt = next_queue.get('next')
            if nxt:
                next_song_path = os.path.join(cfg['song_dir'], nxt['song']['filename'])
                if os.path.exists(next_song_path):
                    next_ffmpeg = _make_ffmpeg(next_song_path, fade_in=0.0)
                    _active_procs.append(next_ffmpeg)

    xfade_written = 0
    while xfade_written < xfade_bytes:
        if _freq_interrupt.is_set():
            break

        t     = xfade_written / xfade_bytes          # 0 → 1
        vol_a = max(0.0, 1.0 - t)
        vol_b = min(1.0, t)

        size    = min(CHUNK_SIZE, xfade_bytes - xfade_written)
        chunk_a = ffmpeg_a.stdout.read(size) or b''
        chunk_b = (next_ffmpeg.stdout.read(len(chunk_a) or size) if next_ffmpeg else b'') or b''

        if not chunk_a and not chunk_b:
            break

        if next_ffmpeg and chunk_b:
            mixed = _mix_pcm(chunk_a, vol_a, chunk_b, vol_b)
        elif chunk_a:
            # No next song — just fade out song A
            n = len(chunk_a) // 2
            sa = struct.unpack(f'<{n}h', chunk_a)
            mixed = struct.pack(f'<{n}h', *[max(-32768, min(32767, int(s * vol_a))) for s in sa])
        else:
            break

        try:
            _fm_proc.stdin.write(mixed)
        except (BrokenPipeError, OSError):
            _fm_proc = None
            break

        xfade_written += len(mixed)

    ffmpeg_a.terminate()
    ffmpeg_a.wait()
    if ffmpeg_a in _active_procs:
        _active_procs.remove(ffmpeg_a)

    if next_ffmpeg and not _freq_interrupt.is_set():
        return next_ffmpeg   # caller continues playing next song from here
    if next_ffmpeg:
        next_ffmpeg.terminate()
        next_ffmpeg.wait()
        if next_ffmpeg in _active_procs:
            _active_procs.remove(next_ffmpeg)
    return None


def play_live_stream(cfg: dict, mode: str, url: str = '') -> None:
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
        + ['-f', 's16le', '-ar', str(SAMPLE_RATE), '-ac', str(CHANNELS), 'pipe:1'],
        stdout=subprocess.PIPE,
    )
    _active_procs.append(ffmpeg)

    if not _ensure_fm_running(cfg, ps=ps, rt=rt):
        ffmpeg.terminate()
        ffmpeg.wait()
        _active_procs.remove(ffmpeg)
        return

    def _watch():
        while not _stop_live.is_set():
            time.sleep(30)
            send_heartbeat(cfg, 'live', mode)
            new_mode = _remote_cfg.get('broadcast_mode', 'normal')
            if new_mode not in ('phone_stream', 'usb_input', 'custom_stream'):
                print(f'[live] mode changed to {new_mode} — stopping live')
                _stop_live.set()

    threading.Thread(target=_watch, daemon=True).start()

    while ffmpeg.poll() is None and not _stop_live.is_set():
        chunk = ffmpeg.stdout.read(CHUNK_SIZE)
        if not chunk:
            break
        if _fm_proc is None or _fm_proc.poll() is not None:
            break
        try:
            _fm_proc.stdin.write(chunk)
        except (BrokenPipeError, OSError):
            break

    ffmpeg.terminate()
    ffmpeg.wait()
    if ffmpeg in _active_procs:
        _active_procs.remove(ffmpeg)
    _stop_live.clear()


# ── Main Loop ─────────────────────────────────────────────────────────────────

def main() -> None:
    global _local
    _local = load_local_config()
    local  = _local
    ensure_media_dirs(local)

    if not local['api_key']:
        print('ERROR: api_key not set in config.json. Generate one in Admin → Pi Token.')
        sys.exit(1)

    if not os.path.exists(BINARY_PATH):
        print(f'ERROR: pi_fm_rds binary not found. Run "make" in {SCRIPT_DIR}')
        sys.exit(1)

    print(f'[boot] server={local["server_url"]} freq={local["freq"]}MHz')
    sync_library(local)
    send_heartbeat(local, 'idle', 'normal')

    last_sync        = time.time()
    last_config_poll = time.time()

    # _continued_ffmpeg: when write_audio_to_fm returns a running ffmpeg for the
    # next song (crossfade was started), we pass it back so the song continues
    # seamlessly without restart.
    _continued_ffmpeg: subprocess.Popen | None = None
    _continued_song:   dict | None             = None

    print('[boot] entering playback loop')

    while True:
        cfg            = merged_cfg(local)
        poll_interval  = int(cfg.get('poll_interval_seconds', 30))
        broadcast_mode = _remote_cfg.get('broadcast_mode', 'normal')

        # ── Live mode ──────────────────────────────────────────────────────
        if broadcast_mode in ('phone_stream', 'usb_input', 'custom_stream'):
            _continued_ffmpeg = None
            _continued_song   = None
            api_post(cfg, '/api/pi/now-playing', {'type': 'song', 'song_filename': ''})
            play_live_stream(cfg, broadcast_mode, url=_remote_cfg.get('live_stream_url', ''))
            send_heartbeat(cfg, 'idle', 'normal')
            continue

        # ── Periodic tasks ─────────────────────────────────────────────────
        if time.time() - last_config_poll > poll_interval:
            send_heartbeat(cfg, 'idle', 'normal')
            last_config_poll = time.time()

        if time.time() - last_sync > 3600:
            sync_library(local)
            last_sync = time.time()

        # ── Crossfade continuation ─────────────────────────────────────────
        # If the previous song already started the next via crossfade, continue it.
        if _continued_ffmpeg is not None and _continued_song is not None:
            song     = _continued_song
            filepath = os.path.join(local['song_dir'], song['filename'])
            print(f'[loop] continuing (crossfade): {song["title"]}')
            send_heartbeat(cfg, 'playing', 'normal')
            api_post(cfg, '/api/pi/now-playing', {
                'type':          'song',
                'song_filename': song['filename'],
            })
            next_proc = write_audio_to_fm(
                filepath, cfg,
                title=song['title'], artist=song.get('artist', ''),
                fade_in=0.0,  # already faded in during crossfade
                _pre_started_ffmpeg=_continued_ffmpeg,
            )
            _continued_ffmpeg = next_proc
            _continued_song   = next_proc and _peek_next_song(cfg)
            continue

        _continued_ffmpeg = None
        _continued_song   = None

        # ── Queue poll ─────────────────────────────────────────────────────
        queue_data = api_get(cfg, '/api/pi/queue')

        if not queue_data:
            print('[loop] API unreachable, playing fallback...')
            fallback = os.path.join(local['song_dir'], cfg.get('fallback_song', 'FTPA.wav'))
            write_audio_to_fm(fallback, cfg, title=rds_ps(cfg).strip())
            continue

        commercial = queue_data.get('commercial')
        if commercial:
            filepath = media_path(local, 'commercial', commercial['filename'])
            print(f'[loop] playing commercial: {commercial["title"]}')
            send_heartbeat(cfg, 'playing', 'normal')
            api_post(cfg, '/api/pi/now-playing', {'type': 'commercial', 'item_id': commercial['id']})
            write_audio_to_fm(filepath, cfg, title='Commercial Break',
                               crossfade_secs=0.5)
            if _freq_interrupt.is_set():
                continue

        sound_byte = queue_data.get('sound_byte')
        if sound_byte:
            filepath = media_path(local, 'sound_byte', sound_byte['filename'])
            print(f'[loop] playing sound byte: {sound_byte["title"]}')
            send_heartbeat(cfg, 'playing', 'normal')
            api_post(cfg, '/api/pi/now-playing', {'type': 'sound_byte', 'item_id': sound_byte['id']})
            write_audio_to_fm(filepath, cfg, title=sound_byte['title'],
                               crossfade_secs=0.5)
            if _freq_interrupt.is_set():
                continue

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
            next_proc = write_audio_to_fm(
                filepath, cfg,
                title=song['title'], artist=song.get('artist', ''),
            )
            if next_proc is not None:
                # Crossfade started into a next song — peek what it is so we can
                # mark now-playing correctly on the next loop iteration
                _continued_ffmpeg = next_proc
                _continued_song   = _peek_next_song(cfg)
            continue

        # Queue empty — play fallback
        fallback = os.path.join(local['song_dir'], cfg.get('fallback_song', 'FTPA.wav'))
        print('[loop] queue empty, playing fallback')
        send_heartbeat(cfg, 'playing', 'normal')
        api_post(cfg, '/api/pi/now-playing', {
            'type':          'song',
            'song_filename': cfg.get('fallback_song', 'FTPA.wav'),
        })
        write_audio_to_fm(fallback, cfg, title=rds_ps(cfg).strip())


def _peek_next_song(cfg: dict) -> 'dict | None':
    """Return the song dict for what's next in the queue, without side effects."""
    data = api_get(cfg, '/api/pi/queue', _retries=1)
    if data and data.get('next'):
        return data['next']['song']
    return None


if __name__ == '__main__':
    main()
