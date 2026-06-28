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
import math
import os
import select
import signal
import socket
import struct
import subprocess
import sys
import threading
import time
import urllib.request
import urllib.error
import urllib.parse

# Force line-buffered stdout so systemd journal sees output immediately.
sys.stdout.reconfigure(line_buffering=True)

SCRIPT_DIR  = os.path.dirname(os.path.realpath(__file__))
CONFIG_PATH = os.path.join(SCRIPT_DIR, 'config.json')
BINARY_PATH = os.path.join(SCRIPT_DIR, 'pi_fm_rds')
RTMP_PORT   = 1935

# Audio constants — must match what we tell pi_fm_rds via streaming WAV header
SAMPLE_RATE    = 44100
CHANNELS       = 2
BITS           = 16
BYTES_PER_SEC  = SAMPLE_RATE * CHANNELS * (BITS // 8)  # 176400 bytes/sec
CHUNK_SIZE                = 8192   # bytes per read cycle
CROSSFADE_SECS            = 2.0    # overlap at song boundaries
FADE_IN_SECS              = 0.5
FADE_OUT_SECS             = 2.0
PREFETCH_BEFORE_XFADE_SECS = 8.0   # pre-start next song's ffmpeg this far before crossfade

LOCAL_CONFIG_KEYS = {
    'server_url':            'https://fmplaylist.com',
    'api_key':               '',
    'freq':                  96.9,
    'pi_code':               'C0DE',
    'song_dir':              SCRIPT_DIR,
    'commercial_dir':        os.path.join(SCRIPT_DIR, 'commercials'),
    'sound_byte_dir':        os.path.join(SCRIPT_DIR, 'sound-bytes'),
    'fallback_song':         'FTPA.wav',
    'poll_interval_seconds': 5,
    'verify_ssl':            True,
}

MEDIA_TYPES = {'song', 'commercial', 'sound_byte'}

_remote_cfg: dict = {}

# Single persistent FM transmitter — kept alive across all songs
_fm_proc: subprocess.Popen | None = None

_active_procs: list[subprocess.Popen] = []
_stop_live      = threading.Event()
_freq_interrupt = threading.Event()
_skip_event     = threading.Event()   # set by heartbeat when admin presses skip

# Cache audio durations so continuation never blocks on ffprobe mid-stream
_duration_cache: dict[str, float] = {}

_local: dict = {}


def _ts() -> str:
    """Compact timestamp prefix for debug logs."""
    return time.strftime('%H:%M:%S')


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
        print(f'[{_ts()}][hb] status={status} mode={bm} downloads={dl} deletes={rm}')
        _process_pending_downloads(cfg, result)
        _process_pending_deletes(cfg, result)

        if result.get('skip_next'):
            print(f'[{_ts()}][hb] skip_next=true — setting skip event')
            _skip_event.set()

        new_freq = result.get('freq')
        if new_freq is not None and float(new_freq) != float(_local.get('freq', 96.9)):
            print(f'[{_ts()}][hb] FREQ CHANGE {_local["freq"]} → {new_freq} MHz')
            _local['freq'] = float(new_freq)
            save_local_config(_local)
            _freq_interrupt.set()
            _stop_fm()
    else:
        print(f'[{_ts()}][hb] WARNING heartbeat failed (no response)')
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
                # Percent-encode non-ASCII chars (e.g. en-dash in ElevenLabs filenames)
                parts    = urllib.parse.urlsplit(url)
                safe_url = urllib.parse.urlunsplit(
                    parts._replace(path=urllib.parse.quote(parts.path, safe='/:@!$&\'()*+,;='))
                )
                req = urllib.request.Request(safe_url)
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
                    print(f'[{_ts()}][download] ERROR {filename}: {e} — retry in {delay}s')
                    time.sleep(delay)
                    delay *= 2
                else:
                    print(f'[{_ts()}][download] ERROR {filename}: {e} — giving up')


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
    print(f'[{_ts()}][FM] starting transmitter at {cfg["freq"]} MHz  ps="{_ps.strip()}"')
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
        print(f'[{_ts()}][FM] ERROR failed to start: {e}')
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
    if filepath in _duration_cache:
        return _duration_cache[filepath]
    print(f'[{_ts()}][probe] getting duration: {os.path.basename(filepath)}')
    try:
        out = subprocess.check_output(
            ['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
             '-of', 'default=noprint_wrappers=1:nokey=1', filepath],
            stderr=subprocess.DEVNULL, timeout=10,
        )
        dur = max(0.0, float(out.strip()))
        _duration_cache[filepath] = dur
        print(f'[{_ts()}][probe] {os.path.basename(filepath)} = {dur:.2f}s (cached)')
        return dur
    except Exception as e:
        print(f'[{_ts()}][probe] ERROR {os.path.basename(filepath)}: {e}')
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

    def _child_priority():
        try:
            os.nice(-10)   # high priority — decode must keep pace with audio writes
        except Exception:
            pass

    return subprocess.Popen(
        args,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        preexec_fn=_child_priority,
    )


def _cleanup_ffmpeg(proc: 'subprocess.Popen') -> None:
    """Terminate ffmpeg and reap it in a background thread — never blocks audio."""
    try:
        proc.terminate()
    except Exception:
        pass
    if proc in _active_procs:
        _active_procs.remove(proc)

    def _reap() -> None:
        try:
            proc.wait(timeout=5)
        except Exception:
            try:
                proc.kill()
                proc.wait(timeout=2)
            except Exception:
                pass

    threading.Thread(target=_reap, daemon=True).start()


def _fade_out_and_stop(ffmpeg_proc: 'subprocess.Popen', secs: float = 0.3) -> None:
    """Write a short linear fade-out to FM then stop the ffmpeg process."""
    fade_bytes = int(secs * BYTES_PER_SEC)
    written = 0
    while written < fade_bytes and _fm_proc is not None:
        chunk = ffmpeg_proc.stdout.read(CHUNK_SIZE)
        if not chunk:
            break
        t = 1.0 - (written / fade_bytes)
        n = len(chunk) // 2
        if n > 0:
            sa = struct.unpack(f'<{n}h', chunk[:n * 2])
            out = struct.pack(f'<{n}h', *[max(-32768, min(32767, int(s * t))) for s in sa])
            try:
                _fm_proc.stdin.write(out)
            except (BrokenPipeError, OSError):
                break
        written += len(chunk)
    _cleanup_ffmpeg(ffmpeg_proc)


def write_audio_to_fm(
    filepath: str,
    cfg: dict,
    title: str = '',
    artist: str = '',
    fade_in: float = FADE_IN_SECS,
    crossfade_secs: float = CROSSFADE_SECS,
    _pre_started_ffmpeg: 'subprocess.Popen | None' = None,
    _pre_consumed_bytes: int = 0,
) -> 'subprocess.Popen | None':
    """
    Stream filepath to the FM transmitter with crossfade.

    Returns the still-running ffmpeg for the next song when a crossfade was
    started (_pre_started_ffmpeg for the next call), otherwise None.

    _pre_consumed_bytes: bytes already read from _pre_started_ffmpeg during the
    previous crossfade window; reduces body_bytes so Phase 2 gets a full window.

    NEVER calls proc.wait() on the audio thread — cleanup is always async so
    the FM pipe stays filled and the carrier never drops.
    """
    global _fm_proc

    if not os.path.exists(filepath):
        print(f'[{_ts()}][play] ERROR file not found: {filepath}')
        return None

    _freq_interrupt.clear()
    _skip_event.clear()

    if not _ensure_fm_running(cfg, ps=rds_ps(cfg), rt=rds_rt(cfg, title, artist)):
        print(f'[{_ts()}][play] ERROR could not start FM transmitter')
        return None

    # Duration is cached after the first probe — continuation calls are instant.
    duration    = get_audio_duration(filepath)
    body_bytes  = max(0, int((duration - crossfade_secs) * BYTES_PER_SEC) - _pre_consumed_bytes)
    xfade_bytes = int(crossfade_secs * BYTES_PER_SEC)

    continuation = _pre_started_ffmpeg is not None
    ffmpeg_a = _pre_started_ffmpeg or _make_ffmpeg(filepath, fade_in=fade_in)
    if ffmpeg_a not in _active_procs:
        _active_procs.append(ffmpeg_a)

    poll_interval = int(cfg.get('poll_interval_seconds', 5))
    last_hb       = time.time()
    bytes_written = 0

    print(
        f'[{_ts()}][play] "{title}" | dur={duration:.1f}s '
        f'body={body_bytes//BYTES_PER_SEC:.1f}s xfade={crossfade_secs:.1f}s '
        f'{"(continuation)" if continuation else "(new)"}'
    )

    # ── Warmup: write silence while ffmpeg initializes ────────────────────────
    # Prevents DMA underrun (~static) during the 50-200ms ffmpeg startup lag.
    # Continuations skip this — their ffmpeg has been running for 8+ seconds.
    if not continuation and _fm_proc and _fm_proc.poll() is None:
        warmup_deadline = time.monotonic() + 0.4   # max 400ms of silence fill
        while time.monotonic() < warmup_deadline:
            rlist, _, _ = select.select([ffmpeg_a.stdout], [], [], 0.020)
            if rlist:
                break   # ffmpeg has data ready — done
            try:
                _fm_proc.stdin.write(b'\x00' * CHUNK_SIZE)
            except (BrokenPipeError, OSError):
                break

    # ── Prefetch: prepare next song in background during the body phase ───────
    # Fires PREFETCH_BEFORE_XFADE_SECS before the crossfade so next song's
    # ffmpeg is already running (and its first chunks buffered in the kernel pipe)
    # by the time we need to mix.  No API calls happen on the audio thread.
    _prefetch_ready  = threading.Event()
    _prefetch_result: dict = {}   # keys: 'ffmpeg', 'song', 'name'

    def _prefetch_worker() -> None:
        if duration <= crossfade_secs + 2 or _freq_interrupt.is_set() or _skip_event.is_set():
            _prefetch_ready.set()
            return
        q = api_get(cfg, '/api/pi/queue', _retries=1)
        if not (q and q.get('next')):
            _prefetch_ready.set()
            return
        nxt      = q['next']
        nxt_path = os.path.join(cfg['song_dir'], nxt['song']['filename'])
        if not os.path.exists(nxt_path):
            print(f'[{_ts()}][prefetch] not on disk yet: {os.path.basename(nxt_path)}')
            _prefetch_ready.set()
            return
        get_audio_duration(nxt_path)   # cache duration while there's time
        ff = _make_ffmpeg(nxt_path, fade_in=0.0)
        _active_procs.append(ff)
        _prefetch_result['ffmpeg'] = ff
        _prefetch_result['song']   = nxt
        _prefetch_result['name']   = nxt['song'].get('title', os.path.basename(nxt_path))
        print(f'[{_ts()}][prefetch] ready: "{_prefetch_result["name"]}"')
        _prefetch_ready.set()

    # Trigger threshold: 8s before crossfade.  For short songs this is 0 (fires immediately).
    _prefetch_at      = max(0, body_bytes - int(PREFETCH_BEFORE_XFADE_SECS * BYTES_PER_SEC))
    _prefetch_started = False

    # ── Phase 1: body ────────────────────────────────────────────────────────
    while bytes_written < body_bytes:
        if _freq_interrupt.is_set():
            print(f'[{_ts()}][play] freq interrupt mid-song — aborting')
            _cleanup_ffmpeg(ffmpeg_a)
            return None

        if _skip_event.is_set():
            print(f'[{_ts()}][skip] skip event — fading out "{title}"')
            _skip_event.clear()
            _fade_out_and_stop(ffmpeg_a, secs=0.3)
            return None

        to_read = min(CHUNK_SIZE, body_bytes - bytes_written)
        chunk   = ffmpeg_a.stdout.read(to_read)
        if not chunk:
            print(f'[{_ts()}][play] WARNING ffmpeg EOF at {bytes_written/BYTES_PER_SEC:.1f}s '
                  f'(expected {body_bytes/BYTES_PER_SEC:.1f}s) — short file?')
            break

        try:
            _fm_proc.stdin.write(chunk)
        except (BrokenPipeError, OSError) as e:
            print(f'[{_ts()}][FM] ERROR pipe broken during body: {e}')
            _fm_proc = None
            _cleanup_ffmpeg(ffmpeg_a)
            return None

        bytes_written += len(chunk)

        # Spawn prefetch thread when we're PREFETCH_BEFORE_XFADE_SECS from end
        if not _prefetch_started and bytes_written >= _prefetch_at:
            _prefetch_started = True
            secs_left = max(0, (body_bytes - bytes_written) / BYTES_PER_SEC)
            print(f'[{_ts()}][prefetch] triggered — {secs_left:.1f}s before xfade')
            threading.Thread(target=_prefetch_worker, daemon=True).start()

        if time.time() - last_hb > poll_interval:
            threading.Thread(
                target=send_heartbeat, args=(cfg, 'playing', 'normal'), daemon=True,
            ).start()
            last_hb = time.time()

    print(f'[{_ts()}][play] body done ({bytes_written/BYTES_PER_SEC:.1f}s written) — entering crossfade')

    # ── Phase 2: crossfade ───────────────────────────────────────────────────
    # If prefetch didn't trigger during body (very short song), spawn it now.
    if not _prefetch_started:
        threading.Thread(target=_prefetch_worker, daemon=True).start()

    # Non-blocking check — prefetch had PREFETCH_BEFORE_XFADE_SECS to complete.
    # We do NOT wait here; any pause on the audio thread drains the DMA buffer.
    # If it's not ready yet (server was very slow), do a clean solo fade-out.
    _prefetch_ready.wait(timeout=0.0)   # instant poll — set if already done
    next_ffmpeg:    subprocess.Popen | None = _prefetch_result.get('ffmpeg')
    next_song_name: str                     = _prefetch_result.get('name', '')

    if next_ffmpeg:
        print(f'[{_ts()}][xfade] "{title}" → "{next_song_name}" ({crossfade_secs:.1f}s)')
    elif not _prefetch_ready.is_set():
        print(f'[{_ts()}][xfade] prefetch still running — solo fade-out (server slow?)')
    elif duration > crossfade_secs + 2:
        print(f'[{_ts()}][xfade] queue empty — solo fade-out')
    else:
        print(f'[{_ts()}][xfade] skipping crossfade (short song {duration:.1f}s)')

    xfade_written = 0
    while xfade_written < xfade_bytes:
        if _freq_interrupt.is_set() or _skip_event.is_set():
            print(f'[{_ts()}][xfade] interrupted at {xfade_written/BYTES_PER_SEC:.2f}s')
            break

        t     = xfade_written / xfade_bytes
        vol_a = math.cos(t * math.pi / 2)   # 1.0 → 0.0  equal-power
        vol_b = math.sin(t * math.pi / 2)   # 0.0 → 1.0

        size    = min(CHUNK_SIZE, xfade_bytes - xfade_written)
        chunk_a = ffmpeg_a.stdout.read(size) or b''
        chunk_b = (next_ffmpeg.stdout.read(len(chunk_a) or size) if next_ffmpeg else b'') or b''

        if not chunk_a and not chunk_b:
            print(f'[{_ts()}][xfade] both streams EOF at {xfade_written/BYTES_PER_SEC:.2f}s')
            break

        if chunk_a and chunk_b:
            mixed = _mix_pcm(chunk_a, vol_a, chunk_b, vol_b)
        elif chunk_a:
            n     = len(chunk_a) // 2
            sa    = struct.unpack(f'<{n}h', chunk_a[:n * 2])
            mixed = struct.pack(f'<{n}h', *[max(-32768, min(32767, int(s * vol_a))) for s in sa])
        else:
            # chunk_a EOF but chunk_b has data — switch fully to next song
            mixed = chunk_b
        try:
            _fm_proc.stdin.write(mixed)
        except (BrokenPipeError, OSError) as e:
            print(f'[{_ts()}][FM] ERROR pipe broken during crossfade: {e}')
            _fm_proc = None
            break

        xfade_written += len(mixed)

    print(f'[{_ts()}][xfade] complete ({xfade_written/BYTES_PER_SEC:.2f}s mixed)')

    # Async cleanup — NEVER blocks the audio thread
    _cleanup_ffmpeg(ffmpeg_a)

    # If the prefetch thread completed late (after the crossfade), its ffmpeg
    # is now orphaned.  Clean it up so we don't leak processes.
    late_ff = _prefetch_result.get('ffmpeg')
    if late_ff and late_ff is not next_ffmpeg:
        _cleanup_ffmpeg(late_ff)

    if next_ffmpeg and not _freq_interrupt.is_set() and not _skip_event.is_set():
        print(f'[{_ts()}][xfade] handing off to "{next_song_name}"')
        return next_ffmpeg
    if next_ffmpeg:
        print(f'[{_ts()}][xfade] interrupted — discarding next_ffmpeg')
        _cleanup_ffmpeg(next_ffmpeg)
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

    # Raise CPU + I/O priority so the audio pipe write loop is never pre-empted
    # by background downloads, heartbeats, or other system activity.
    try:
        os.nice(-15)
        print('[boot] CPU priority raised (nice=-15)')
    except PermissionError:
        print('[boot] WARNING: could not raise priority (run as root for best audio)')
    try:
        subprocess.run(
            ['ionice', '-c', '1', '-n', '0', '-p', str(os.getpid())],
            check=False, stderr=subprocess.DEVNULL, timeout=2,
        )
        print('[boot] I/O priority set to real-time class')
    except Exception:
        pass

    # Retry until config.json has a valid server_url — handles cases where the
    # file gets overwritten with empty values between service restarts.
    while not str(local.get('server_url', '')).startswith('http'):
        print(f'[boot] ERROR: server_url missing in {CONFIG_PATH}')
        print(f'[boot] Run: sudo tee {CONFIG_PATH} <<\'EOF\'')
        print(f'[boot]   {{"server_url":"https://fmplaylist.com","api_key":"YOUR_TOKEN",...}}')
        print('[boot] Retrying in 15s...')
        time.sleep(15)
        local  = load_local_config()
        _local = local

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
        poll_interval  = int(cfg.get('poll_interval_seconds', 5))
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
        # The previous song pre-started the next via crossfade; CROSSFADE_SECS of
        # audio have already played from the new stream.  Pass _pre_consumed_bytes
        # so body_bytes is computed correctly and Phase 2 still gets a full window.
        if _continued_ffmpeg is not None and _continued_song is not None:
            song     = _continued_song['song']
            filepath = os.path.join(local['song_dir'], song['filename'])
            print(f'[loop] continuing (crossfade): {song["title"]}')
            # Both calls async — ffmpeg is already running, audio must not pause
            threading.Thread(target=send_heartbeat, args=(cfg, 'playing', 'normal'), daemon=True).start()
            threading.Thread(target=api_post, args=(cfg, '/api/pi/now-playing', {
                'type':          'song',
                'queue_item_id': _continued_song.get('queue_item_id'),
                'song_filename': song['filename'],
            }), daemon=True).start()
            next_proc = write_audio_to_fm(
                filepath, cfg,
                title=song['title'], artist=song.get('artist', ''),
                fade_in=0.0,
                _pre_started_ffmpeg=_continued_ffmpeg,
                _pre_consumed_bytes=int(CROSSFADE_SECS * BYTES_PER_SEC),
            )
            _continued_ffmpeg = next_proc
            _continued_song   = next_proc and _peek_next_song(cfg)
            continue

        _continued_ffmpeg = None
        _continued_song   = None

        # ── Queue poll — writes silence to FM pipe while waiting ───────────
        queue_data = _api_get_with_silence(cfg, '/api/pi/queue')

        if not queue_data:
            print('[loop] API unreachable, playing fallback...')
            fallback = os.path.join(local['song_dir'], cfg.get('fallback_song', 'FTPA.wav'))
            get_audio_duration(fallback)
            pre_ff = _make_ffmpeg(fallback, fade_in=FADE_IN_SECS)
            _active_procs.append(pre_ff)
            write_audio_to_fm(fallback, cfg, title=rds_ps(cfg).strip(), _pre_started_ffmpeg=pre_ff)
            continue

        commercial = queue_data.get('commercial')
        if commercial:
            filepath = media_path(local, 'commercial', commercial['filename'])
            print(f'[loop] playing commercial: {commercial["title"]}')
            # Pre-start ffmpeg first so warmup begins while API calls fly async
            get_audio_duration(filepath)
            pre_ff = _make_ffmpeg(filepath, fade_in=FADE_IN_SECS)
            _active_procs.append(pre_ff)
            threading.Thread(target=send_heartbeat, args=(cfg, 'playing', 'normal'), daemon=True).start()
            threading.Thread(target=api_post, args=(cfg, '/api/pi/now-playing',
                {'type': 'commercial', 'item_id': commercial['id']}), daemon=True).start()
            write_audio_to_fm(filepath, cfg, title='Commercial Break',
                               crossfade_secs=0.5, _pre_started_ffmpeg=pre_ff)
            if _freq_interrupt.is_set():
                continue

        sound_byte = queue_data.get('sound_byte')
        if sound_byte:
            filepath = media_path(local, 'sound_byte', sound_byte['filename'])
            print(f'[loop] playing sound byte: {sound_byte["title"]}')
            get_audio_duration(filepath)
            pre_ff = _make_ffmpeg(filepath, fade_in=FADE_IN_SECS)
            _active_procs.append(pre_ff)
            threading.Thread(target=send_heartbeat, args=(cfg, 'playing', 'normal'), daemon=True).start()
            threading.Thread(target=api_post, args=(cfg, '/api/pi/now-playing',
                {'type': 'sound_byte', 'item_id': sound_byte['id']}), daemon=True).start()
            next_proc = write_audio_to_fm(filepath, cfg, title=sound_byte['title'],
                                          crossfade_secs=0.5, _pre_started_ffmpeg=pre_ff)
            if next_proc is not None:
                _continued_ffmpeg = next_proc
                _continued_song   = _peek_next_song(cfg)
            if _freq_interrupt.is_set():
                continue

        next_song = queue_data.get('next')
        if next_song:
            song     = next_song['song']
            filepath = os.path.join(local['song_dir'], song['filename'])
            print(f'[loop] playing: {song["title"]}')
            get_audio_duration(filepath)
            pre_ff = _make_ffmpeg(filepath, fade_in=FADE_IN_SECS)
            _active_procs.append(pre_ff)
            threading.Thread(target=send_heartbeat, args=(cfg, 'playing', 'normal'), daemon=True).start()
            threading.Thread(target=api_post, args=(cfg, '/api/pi/now-playing', {
                'type':          'song',
                'queue_item_id': next_song['queue_item_id'],
                'song_filename': song['filename'],
            }), daemon=True).start()
            next_proc = write_audio_to_fm(
                filepath, cfg,
                title=song['title'], artist=song.get('artist', ''),
                _pre_started_ffmpeg=pre_ff,
            )
            if next_proc is not None:
                _continued_ffmpeg = next_proc
                _continued_song   = _peek_next_song(cfg)
            continue

        # Queue empty — play fallback
        fallback = os.path.join(local['song_dir'], cfg.get('fallback_song', 'FTPA.wav'))
        print('[loop] queue empty, playing fallback')
        get_audio_duration(fallback)
        pre_ff = _make_ffmpeg(fallback, fade_in=FADE_IN_SECS)
        _active_procs.append(pre_ff)
        threading.Thread(target=send_heartbeat, args=(cfg, 'playing', 'normal'), daemon=True).start()
        threading.Thread(target=api_post, args=(cfg, '/api/pi/now-playing', {
            'type':          'song',
            'song_filename': cfg.get('fallback_song', 'FTPA.wav'),
        }), daemon=True).start()
        write_audio_to_fm(fallback, cfg, title=rds_ps(cfg).strip(), _pre_started_ffmpeg=pre_ff)


def _peek_next_song(cfg: dict) -> 'dict | None':
    """Return the full next queue item (including queue_item_id + song), without side effects."""
    data = api_get(cfg, '/api/pi/queue', _retries=1)
    if data and data.get('next'):
        return data['next']
    return None


def _api_get_with_silence(cfg: dict, path: str, _retries: int = 3):
    """
    Call api_get in a background thread while writing PCM silence to the FM
    pipe on the audio thread.  Prevents DMA underrun (static) during the
    queue poll that happens between songs.
    """
    result:   list      = [None]
    done:     threading.Event = threading.Event()

    def _fetch() -> None:
        result[0] = api_get(cfg, path, _retries=_retries)
        done.set()

    threading.Thread(target=_fetch, daemon=True).start()

    silence = b'\x00' * CHUNK_SIZE
    while not done.wait(timeout=0.020):   # poll every 20ms
        if _fm_proc is not None and _fm_proc.poll() is None:
            try:
                _fm_proc.stdin.write(silence)
            except (BrokenPipeError, OSError):
                break

    return result[0]


if __name__ == '__main__':
    main()
