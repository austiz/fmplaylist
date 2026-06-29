#!/usr/bin/env python3
"""
pi_daemon.py — fmplaylist.com Pi broadcast daemon

3-thread producer-consumer architecture eliminates all audio gaps:

  SchedulerThread → _schedule_q → DecoderThread → _ready_q → AudioWriteThread → pi_fm_rds
                                                                    │
                   _now_playing_q ←──────────────────────────────────┘
                         ↓
                   SchedulerThread (POSTs now-playing + heartbeats)

• SchedulerThread : polls server every 5 s, sends heartbeats, handles downloads/deletes
• DecoderThread   : fully decodes songs to PCM bytes 1-3 songs ahead of playback
• AudioWriteThread: ONLY writes bytes to pi_fm_rds — no network, no subprocess creation

Crossfades are pre-mixed in numpy on already-decoded PCM (microsecond operation).
The FM pipe is kept fed at all times: 20 ms silence chunks fill any inter-song gap.
"""

import collections
import dataclasses
import json
import math
import os
import queue
import signal
import socket
import struct
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

sys.stdout.reconfigure(line_buffering=True)

SCRIPT_DIR  = os.path.dirname(os.path.realpath(__file__))
CONFIG_PATH = os.path.join(SCRIPT_DIR, 'config.json')
BINARY_PATH = os.path.join(SCRIPT_DIR, 'pi_fm_rds')
RTMP_PORT   = 1935

SAMPLE_RATE    = 44100
CHANNELS       = 2
BITS           = 16
BYTES_PER_SEC  = SAMPLE_RATE * CHANNELS * (BITS // 8)   # 176 400 bytes/sec
CHUNK_SIZE     = 8192
CROSSFADE_SECS = 2.0
FADE_IN_SECS   = 0.5

# 20 ms of silence at 176 400 B/s — written to FM pipe while waiting for decoded segments
SILENCE_20MS = b'\x00' * 3528

LOCAL_CONFIG_KEYS: dict = {
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
_fm_proc: 'subprocess.Popen | None' = None

_stop_event     = threading.Event()   # set on SIGTERM/SIGINT
_stop_live      = threading.Event()
_freq_interrupt = threading.Event()   # set when freq changes — audio loop returns
_skip_event     = threading.Event()   # set when admin presses skip

_local: dict = {}

# ── Queue engine globals ──────────────────────────────────────────────────────
# AudioSegment objects flow: _schedule_q → decoder → _ready_q → audio thread
_schedule_q:    'queue.Queue[AudioSegment]' = queue.Queue()
_ready_q:       'queue.Queue[AudioSegment]' = queue.Queue(maxsize=3)   # backpressure at 3
_now_playing_q: 'queue.Queue[dict]'         = queue.Queue()

# Dedup keys for items pushed to _schedule_q ('song:123', 'commercial:7', …)
_recently_pushed: collections.deque = collections.deque(maxlen=30)

# Pre-decoded fallback PCM — loaded at boot, available instantly to audio thread
_fallback_pcm: 'bytes | None' = None

# WiFi state — reported back to server via heartbeat so admin can see result
_wifi_applied_ssid: str = ''   # set after successful wifi_setup.sh run
_wifi_failed_ssid:  str = ''   # set after failed run


@dataclasses.dataclass
class AudioSegment:
    filepath:      str
    title:         str
    artist:        str
    queue_item_id: 'int | None'
    media_type:    str              # 'song' | 'commercial' | 'sound_byte' | 'fallback'
    item_id:       'int | None' = None    # commercial / sound_byte DB id
    pcm:           'bytes | None' = None  # set by DecoderThread after decode
    duration_s:    float = 0.0


# ── Utilities ─────────────────────────────────────────────────────────────────

def _ts() -> str:
    return time.strftime('%H:%M:%S')


def _shutdown(signum, frame) -> None:
    print(f'\n[daemon] signal {signum} — stopping...')
    _stop_event.set()
    _stop_fm()
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


def api_get(cfg: dict, path: str, params: 'dict | None' = None, _retries: int = 3):
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


def _get_wifi_info() -> dict:
    """Return current SSID + a list of nearby networks for the admin UI."""
    try:
        import re as _re
        current = ''
        networks: list[dict] = []

        if subprocess.run(['which', 'nmcli'], capture_output=True).returncode == 0:
            # NetworkManager (Bookworm) — cleaner output
            r = subprocess.run(
                ['nmcli', '-t', '-f', 'IN-USE,SSID,SIGNAL,SECURITY', 'device', 'wifi', 'list'],
                capture_output=True, text=True, timeout=10,
            )
            seen: set[str] = set()
            for line in r.stdout.splitlines():
                parts = line.split(':')
                if len(parts) < 4:
                    continue
                in_use, ssid, signal_s, security = parts[0], parts[1], parts[2], ':'.join(parts[3:])
                ssid = ssid.strip()
                if not ssid or ssid in seen:
                    continue
                seen.add(ssid)
                active = in_use.strip() == '*'
                if active:
                    current = ssid
                networks.append({
                    'ssid': ssid,
                    'signal': int(signal_s) if signal_s.isdigit() else 0,
                    'security': security.strip() or 'Open',
                    'active': active,
                })
        else:
            # wpa_supplicant (Bullseye) — parse iwlist output
            r_id = subprocess.run(['iwgetid', '-r', 'wlan0'], capture_output=True, text=True, timeout=3)
            current = r_id.stdout.strip()

            r_scan = subprocess.run(
                ['iwlist', 'wlan0', 'scan'],
                capture_output=True, text=True, timeout=15,
                env={**os.environ, 'LANG': 'C'},
            )
            seen2: set[str] = set()
            for cell in r_scan.stdout.split('Cell ')[1:]:
                m_ssid = _re.search(r'ESSID:"([^"]*)"', cell)
                m_sig  = _re.search(r'Signal level=(-?\d+)', cell)
                m_enc  = _re.search(r'Encryption key:(on|off)', cell)
                ssid = m_ssid.group(1) if m_ssid else ''
                if not ssid or ssid in seen2:
                    continue
                seen2.add(ssid)
                signal_dbm = int(m_sig.group(1)) if m_sig else -100
                # Convert dBm (-30 best, -90 worst) to 0-100 scale
                signal_pct = max(0, min(100, 2 * (signal_dbm + 100)))
                encrypted = m_enc and m_enc.group(1) == 'on'
                networks.append({
                    'ssid': ssid,
                    'signal': signal_pct,
                    'security': 'WPA2' if encrypted else 'Open',
                    'active': ssid == current,
                })

        # Sort: active first, then by signal strength
        networks.sort(key=lambda n: (0 if n['active'] else 1, -n['signal']))
        return {'current': current, 'networks': networks}

    except Exception as exc:
        print(f'[{_ts()}][wifi] scan error: {exc}')
        return {'current': '', 'networks': []}


def _apply_wifi(pending: dict) -> None:
    """Run wifi_setup.sh in a daemon thread; report result via next heartbeat."""
    global _wifi_applied_ssid, _wifi_failed_ssid
    ssid     = pending.get('ssid', '')
    password = pending.get('password', '')
    script   = os.path.join(SCRIPT_DIR, 'wifi_setup.sh')

    if not ssid:
        print(f'[{_ts()}][wifi] pending_wifi has no SSID — skipping')
        return
    if not os.path.exists(script):
        print(f'[{_ts()}][wifi] wifi_setup.sh not found at {script}')
        return

    print(f'[{_ts()}][wifi] applying new network: "{ssid}"')
    try:
        r = subprocess.run(
            ['sudo', 'bash', script, ssid, password],
            capture_output=False, timeout=90,
        )
        if r.returncode == 0:
            print(f'[{_ts()}][wifi] ✓ connected to "{ssid}"')
            _wifi_applied_ssid = ssid
            _wifi_failed_ssid  = ''
        else:
            print(f'[{_ts()}][wifi] ✗ failed to connect to "{ssid}" — rolled back')
            _wifi_failed_ssid  = ssid
            _wifi_applied_ssid = ''
    except Exception as exc:
        print(f'[{_ts()}][wifi] ERROR running wifi_setup.sh: {exc}')
        _wifi_failed_ssid  = ssid
        _wifi_applied_ssid = ''


# SSID being applied right now — prevents re-triggering while script is running
_wifi_pending_ssid: str = ''


def send_heartbeat(cfg: dict, status: str, mode: str) -> 'dict | None':
    global _wifi_applied_ssid, _wifi_failed_ssid, _wifi_pending_ssid

    wifi_info = _get_wifi_info()

    payload: dict = {
        'status':         status,
        'mode':           mode,
        'ip':             get_local_ip(),
        'wifi_ssid':      wifi_info['current'],
        'wifi_networks':  wifi_info['networks'],
    }
    if _wifi_applied_ssid:
        payload['wifi_applied'] = _wifi_applied_ssid
        _wifi_applied_ssid = ''
    if _wifi_failed_ssid:
        payload['wifi_failed'] = _wifi_failed_ssid
        _wifi_failed_ssid = ''

    result = api_post(cfg, '/api/pi/heartbeat', payload)
    if result:
        global _remote_cfg
        _remote_cfg = result
        bm = result.get('broadcast_mode', 'normal')
        dl = len(result.get('pending_downloads', []))
        rm = len(result.get('pending_deletes', []))
        print(f'[{_ts()}][hb] status={status} mode={bm} wifi={wifi_info["current"]!r} downloads={dl} deletes={rm}')
        _process_pending_downloads(cfg, result)
        _process_pending_deletes(cfg, result)

        if result.get('skip_next'):
            print(f'[{_ts()}][hb] skip_next — setting skip event')
            _skip_event.set()

        new_freq = result.get('freq')
        if new_freq is not None and float(new_freq) != float(_local.get('freq', 96.9)):
            print(f'[{_ts()}][hb] FREQ CHANGE {_local["freq"]} → {new_freq} MHz')
            _local['freq'] = float(new_freq)
            save_local_config(_local)
            _freq_interrupt.set()
            _stop_fm()

        pending_wifi = result.get('pending_wifi')
        if pending_wifi and pending_wifi.get('ssid') and pending_wifi['ssid'] != _wifi_pending_ssid:
            _wifi_pending_ssid = pending_wifi['ssid']
            threading.Thread(
                target=_apply_wifi, args=(pending_wifi,), daemon=True,
            ).start()
    else:
        print(f'[{_ts()}][hb] WARNING heartbeat failed')
    return result


def _process_pending_downloads(cfg: dict, heartbeat: dict) -> None:
    for item in heartbeat.get('pending_downloads', []):
        media_type = item.get('type', 'song')
        item_id    = item.get('item_id') or item.get('song_id')
        filename   = item['filename']
        url        = item['download_url']

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
        item_id    = item.get('item_id') or item.get('song_id')
        filename   = item['filename']

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
        print(f'[sync] added={result.get("added",0)} unchanged={result.get("unchanged",0)} '
              f'removed={result.get("removed",0)}')


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
    byte_rate   = SAMPLE_RATE * CHANNELS * (BITS // 8)
    block_align = CHANNELS * (BITS // 8)
    pipe.write(b'RIFF')
    pipe.write(struct.pack('<I', 0xFFFFFFFF))
    pipe.write(b'WAVE')
    pipe.write(b'fmt ')
    pipe.write(struct.pack('<I', 16))
    pipe.write(struct.pack('<HHIIHH', 1, CHANNELS, SAMPLE_RATE, byte_rate, block_align, BITS))
    pipe.write(b'data')
    pipe.write(struct.pack('<I', 0xFFFFFFFF))
    pipe.flush()


def _ensure_fm_running(cfg: dict, ps: str = '', rt: str = '') -> bool:
    global _fm_proc
    if _fm_proc is not None and _fm_proc.poll() is None:
        return True
    _ps = ps or rds_ps(cfg)
    _rt = rt or rds_rt(cfg)
    print(f'[{_ts()}][FM] starting at {cfg["freq"]} MHz  ps="{_ps.strip()}"')
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
        _write_streaming_wav_header(_fm_proc.stdin)
        return True
    except Exception as e:
        print(f'[{_ts()}][FM] ERROR: {e}')
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


# ── PCM helpers ───────────────────────────────────────────────────────────────

def _mix_pcm(chunk_a: bytes, vol_a: float, chunk_b: bytes, vol_b: float) -> bytes:
    """Linear equal-power mix of two s16le PCM chunks. Shorter chunk is zero-padded."""
    max_len = max(len(chunk_a), len(chunk_b)) & ~1
    a = chunk_a.ljust(max_len, b'\x00')[:max_len]
    b = chunk_b.ljust(max_len, b'\x00')[:max_len]
    n = max_len // 2
    try:
        import numpy as np
        sa = np.frombuffer(a, dtype='<i2').astype(np.float32)
        sb = np.frombuffer(b, dtype='<i2').astype(np.float32)
        return np.clip(sa * vol_a + sb * vol_b, -32768, 32767).astype('<i2').tobytes()
    except ImportError:
        sa = struct.unpack(f'<{n}h', a)
        sb = struct.unpack(f'<{n}h', b)
        return struct.pack(f'<{n}h',
                           *[max(-32768, min(32767, int(x * vol_a + y * vol_b)))
                             for x, y in zip(sa, sb)])


def _apply_fade_in(pcm: bytes, fade_bytes: int) -> bytes:
    """Linear fade-in ramp on the first fade_bytes of s16le PCM."""
    fade_bytes = min(fade_bytes & ~1, len(pcm))
    n = fade_bytes // 2
    try:
        import numpy as np
        head = np.frombuffer(pcm[:fade_bytes], dtype='<i2').astype(np.float32)
        ramp = np.linspace(0.0, 1.0, n, dtype=np.float32)
        return np.clip(head * ramp, -32768, 32767).astype('<i2').tobytes() + pcm[fade_bytes:]
    except ImportError:
        samples = list(struct.unpack(f'<{n}h', pcm[:fade_bytes]))
        samples = [max(-32768, min(32767, int(s * (i / n)))) for i, s in enumerate(samples)]
        return struct.pack(f'<{n}h', *samples) + pcm[fade_bytes:]


def _write_fade_out_pcm(pcm_tail: bytes) -> None:
    """Write a linear fade-out of pcm_tail to the FM pipe."""
    n = len(pcm_tail) // 2
    if n == 0 or _fm_proc is None:
        return
    try:
        import numpy as np
        samples = np.frombuffer(pcm_tail[:n * 2], dtype='<i2').astype(np.float32)
        ramp    = np.linspace(1.0, 0.0, n, dtype=np.float32)
        _fm_proc.stdin.write(np.clip(samples * ramp, -32768, 32767).astype('<i2').tobytes())
    except ImportError:
        sa  = struct.unpack(f'<{n}h', pcm_tail[:n * 2])
        out = struct.pack(f'<{n}h',
                          *[max(-32768, min(32767, int(s * (1 - i / n)))) for i, s in enumerate(sa)])
        _fm_proc.stdin.write(out)
    except (BrokenPipeError, OSError):
        pass


# ── Three-Thread Audio Engine ─────────────────────────────────────────────────

def _get_next_decoded(timeout: float = 5.0) -> 'AudioSegment | None':
    """
    Wait up to `timeout` seconds for a decoded segment from _ready_q.
    Writes 20 ms silence chunks while waiting so the DMA buffer never drains.
    """
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            return _ready_q.get(block=True, timeout=0.020)
        except queue.Empty:
            if _fm_proc is not None and _fm_proc.poll() is None:
                try:
                    _fm_proc.stdin.write(SILENCE_20MS)
                except (BrokenPipeError, OSError):
                    break
    return None


def _make_fallback_seg(cfg: dict, local: dict) -> 'AudioSegment | None':
    """Return an AudioSegment backed by the pre-decoded fallback PCM, or None."""
    if _fallback_pcm is None:
        return None
    return AudioSegment(
        filepath      = os.path.join(local['song_dir'], local.get('fallback_song', 'FTPA.wav')),
        title         = rds_ps(cfg).strip(),
        artist        = '',
        queue_item_id = None,
        media_type    = 'fallback',
        pcm           = _fallback_pcm,
        duration_s    = len(_fallback_pcm) / BYTES_PER_SEC,
    )


def _decoder_loop() -> None:
    """
    DecoderThread — runs forever.
    Pulls AudioSegments (metadata only) from _schedule_q, decodes to PCM via
    ffmpeg, pushes fully-decoded segments to _ready_q.
    Never touches the FM pipe. Never makes API calls.
    """
    print(f'[{_ts()}][decoder] started')
    while not _stop_event.is_set():
        try:
            seg: AudioSegment = _schedule_q.get(timeout=2)
        except queue.Empty:
            continue

        if not os.path.exists(seg.filepath):
            print(f'[{_ts()}][decoder] MISSING {os.path.basename(seg.filepath)} — skipping')
            continue

        t0 = time.monotonic()
        print(f'[{_ts()}][decoder] decoding "{seg.title}"')
        try:
            ff = subprocess.Popen(
                ['ffmpeg', '-hide_banner', '-loglevel', 'error',
                 '-i', seg.filepath,
                 '-f', 's16le', '-ar', str(SAMPLE_RATE), '-ac', str(CHANNELS), 'pipe:1'],
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
            )
            seg.pcm = ff.stdout.read()
            ff.wait()
        except Exception as e:
            print(f'[{_ts()}][decoder] ERROR "{seg.title}": {e}')
            continue

        if not seg.pcm:
            print(f'[{_ts()}][decoder] empty PCM for "{seg.title}" — skipping')
            continue

        seg.duration_s = len(seg.pcm) / BYTES_PER_SEC

        if FADE_IN_SECS > 0 and seg.media_type != 'fallback':
            seg.pcm = _apply_fade_in(seg.pcm, int(FADE_IN_SECS * BYTES_PER_SEC))

        elapsed = time.monotonic() - t0
        print(f'[{_ts()}][decoder] ready "{seg.title}" '
              f'{seg.duration_s:.1f}s in {elapsed:.1f}s ({len(seg.pcm) // 1024}KB PCM)')

        _ready_q.put(seg)   # blocks when _ready_q is full — natural backpressure


def _audio_write_loop(cfg: dict, local: dict) -> None:
    """
    AudioWriteThread — runs until _freq_interrupt is set or daemon shuts down.

    Reads pre-decoded PCM from _ready_q and writes continuously to pi_fm_rds.
    Crossfades are pre-mixed in numpy on already-decoded bytes (microseconds).
    Silence fill keeps the DMA buffer fed while waiting for the next segment.

    No network calls. No subprocess creation. No timing-sensitive blocking.
    """
    global _fm_proc

    print(f'[{_ts()}][audio] waiting for first decoded segment...')
    current: 'AudioSegment | None' = _get_next_decoded(timeout=60)

    if current is None:
        current = _make_fallback_seg(cfg, local)
    if current is None:
        print(f'[{_ts()}][audio] nothing decoded after 60s and no fallback — exiting')
        return

    start_offset = 0   # bytes into current.pcm already played (from crossfade head)

    while not _stop_event.is_set() and not _freq_interrupt.is_set():
        if not _ensure_fm_running(cfg,
                                  ps=rds_ps(cfg),
                                  rt=rds_rt(cfg, current.title, current.artist)):
            print(f'[{_ts()}][audio] FM failed to start — retrying in 5s')
            time.sleep(5)
            continue

        xfade_bytes = int(CROSSFADE_SECS * BYTES_PER_SEC)
        if current.duration_s < CROSSFADE_SECS + 1.0:
            xfade_bytes = 0                    # short clips: no crossfade

        pcm        = current.pcm
        xfade_start = max(start_offset, len(pcm) - xfade_bytes)

        print(f'[{_ts()}][audio] → "{current.title}" '
              f'{current.duration_s:.1f}s xfade={xfade_bytes / BYTES_PER_SEC:.1f}s')

        # Notify scheduler of now-playing (it POSTs to server asynchronously)
        _now_playing_q.put_nowait({
            'type':          current.media_type if current.media_type != 'fallback' else 'song',
            'queue_item_id': current.queue_item_id,
            'item_id':       current.item_id,
            'song_filename': os.path.basename(current.filepath)
                             if current.media_type in ('song', 'fallback') else None,
        })

        # ── Body ─────────────────────────────────────────────────────────────
        pos      = start_offset
        pipe_ok  = True
        skipped  = False

        while pos < xfade_start:
            if _freq_interrupt.is_set():
                return
            if _skip_event.is_set():
                _skip_event.clear()
                print(f'[{_ts()}][audio] skip — fading out "{current.title}"')
                _write_fade_out_pcm(pcm[pos : pos + int(0.3 * BYTES_PER_SEC)])
                skipped = True
                break
            end = min(pos + CHUNK_SIZE, xfade_start)
            try:
                _fm_proc.stdin.write(pcm[pos:end])
            except (BrokenPipeError, OSError) as e:
                print(f'[{_ts()}][audio] FM pipe error in body: {e}')
                _fm_proc = None
                pipe_ok  = False
                break
            pos = end

        if _freq_interrupt.is_set():
            return

        if not pipe_ok:
            # FM pipe broke — let _ensure_fm_running restart it next loop
            time.sleep(1)
            start_offset = 0
            continue

        # ── Transition to next segment ────────────────────────────────────────
        # Get next from _ready_q (silence fill while waiting)
        timeout_s = 2.0 if skipped else 5.0
        nxt: 'AudioSegment | None' = _get_next_decoded(timeout=timeout_s)

        if nxt is None:
            # Nothing decoded yet — use fallback as placeholder
            nxt = _make_fallback_seg(cfg, local)

        if nxt is None or xfade_bytes == 0 or skipped:
            # No crossfade: finish tail of current, then move to nxt
            if not skipped and _fm_proc:
                try:
                    _fm_proc.stdin.write(pcm[pos:])
                except (BrokenPipeError, OSError):
                    pass
            if nxt is None:
                # Nothing at all — wait with silence
                print(f'[{_ts()}][audio] queue empty — waiting with silence')
                nxt = _get_next_decoded(timeout=30.0)
                if nxt is None:
                    nxt = _make_fallback_seg(cfg, local)
            if nxt is None:
                time.sleep(1)
                continue
            current = nxt
            start_offset = 0
            continue

        # ── Crossfade ─────────────────────────────────────────────────────────
        # Both segments are pre-decoded — mixing is a fast numpy array operation
        tail_a = pcm[xfade_start : xfade_start + xfade_bytes]
        head_b = nxt.pcm[:len(tail_a)]

        for chunk_start in range(0, len(tail_a), CHUNK_SIZE):
            if _freq_interrupt.is_set() or _skip_event.is_set():
                break
            chunk_end = min(chunk_start + CHUNK_SIZE, len(tail_a))
            t     = chunk_start / max(1, len(tail_a))
            vol_a = math.cos(t * math.pi / 2)   # 1.0 → 0.0
            vol_b = math.sin(t * math.pi / 2)   # 0.0 → 1.0
            mixed = _mix_pcm(tail_a[chunk_start:chunk_end], vol_a,
                             head_b[chunk_start:chunk_end], vol_b)
            try:
                _fm_proc.stdin.write(mixed)
            except (BrokenPipeError, OSError) as e:
                print(f'[{_ts()}][audio] FM pipe error in xfade: {e}')
                _fm_proc = None
                break

        if _freq_interrupt.is_set():
            return

        xfade_used = len(tail_a)
        print(f'[{_ts()}][audio] xfade → "{nxt.title}" '
              f'({xfade_used / BYTES_PER_SEC:.2f}s mixed)')

        # Start next segment from after the crossfade head (already played as vol_b)
        # Use 0 for fallback so it always replays from the beginning
        current      = nxt
        start_offset = 0 if nxt.media_type == 'fallback' else xfade_used


def _scheduler_loop(local: dict) -> None:
    """
    SchedulerThread — runs forever in background.

    Polls the server for upcoming queue items, creates AudioSegment objects,
    and pushes them into _schedule_q so the DecoderThread can pre-decode them.

    Also drains _now_playing_q (events from AudioWriteThread) and sends
    the POST /api/pi/now-playing requests. Sends heartbeats every 30 s.

    Never touches the FM pipe or reads/writes audio data.
    """
    print(f'[{_ts()}][scheduler] started')
    last_hb   = 0.0
    last_sync = time.time()

    while not _stop_event.is_set():
        cfg = merged_cfg(local)

        # ── Now-playing events from audio thread ──────────────────────────────
        while not _now_playing_q.empty():
            try:
                ev = _now_playing_q.get_nowait()
            except queue.Empty:
                break
            body = {k: v for k, v in ev.items() if v is not None}
            threading.Thread(
                target=api_post, args=(cfg, '/api/pi/now-playing', body),
                daemon=True,
            ).start()

        # ── Heartbeat every 30 s ──────────────────────────────────────────────
        if time.time() - last_hb > 30:
            send_heartbeat(cfg, 'playing', 'normal')
            last_hb = time.time()
            local   = load_local_config()   # pick up freq changes saved by heartbeat

        # ── Library sync every hour ───────────────────────────────────────────
        if time.time() - last_sync > 3600:
            threading.Thread(target=sync_library, args=(local,), daemon=True).start()
            last_sync = time.time()

        # ── Skip polling when queue is already healthy ────────────────────────
        if _schedule_q.qsize() + _ready_q.qsize() >= 4:
            time.sleep(2)
            continue

        # ── Poll server for upcoming items ────────────────────────────────────
        queue_data = api_get(cfg, '/api/pi/queue?lookahead=3')
        if not queue_data:
            print(f'[{_ts()}][scheduler] API unreachable — retrying in 5s')
            time.sleep(5)
            continue

        candidates: list[tuple[str, dict]] = []
        if queue_data.get('commercial'):
            candidates.append(('commercial', queue_data['commercial']))
        if queue_data.get('sound_byte'):
            candidates.append(('sound_byte', queue_data['sound_byte']))
        if queue_data.get('next'):
            candidates.append(('song', queue_data['next']))
        for upcoming_item in queue_data.get('upcoming', []):
            candidates.append(('song', upcoming_item))

        pushed_any = False
        for media_type, item in candidates:
            if media_type == 'commercial':
                key = f'commercial:{item["id"]}'
                seg = AudioSegment(
                    filepath      = media_path(local, 'commercial', item['filename']),
                    title         = item['title'],
                    artist        = '',
                    queue_item_id = None,
                    media_type    = 'commercial',
                    item_id       = item['id'],
                )
            elif media_type == 'sound_byte':
                key = f'sound_byte:{item["id"]}'
                seg = AudioSegment(
                    filepath      = media_path(local, 'sound_byte', item['filename']),
                    title         = item['title'],
                    artist        = '',
                    queue_item_id = None,
                    media_type    = 'sound_byte',
                    item_id       = item['id'],
                )
            else:   # song
                key  = f'song:{item["queue_item_id"]}'
                song = item['song']
                seg  = AudioSegment(
                    filepath      = os.path.join(local['song_dir'], song['filename']),
                    title         = song['title'],
                    artist        = song.get('artist', ''),
                    queue_item_id = item['queue_item_id'],
                    media_type    = 'song',
                )

            if key in _recently_pushed:
                continue

            _recently_pushed.append(key)
            _schedule_q.put(seg)
            pushed_any = True
            print(f'[{_ts()}][scheduler] queued for decode: "{seg.title}" ({media_type})')

        if not pushed_any and not queue_data.get('next'):
            print(f'[{_ts()}][scheduler] server queue empty')

        time.sleep(5)


# ── Live Stream ───────────────────────────────────────────────────────────────

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

    if not _ensure_fm_running(cfg, ps=ps, rt=rt):
        ffmpeg.terminate()
        ffmpeg.wait()
        return

    def _watch() -> None:
        while not _stop_live.is_set():
            time.sleep(30)
            send_heartbeat(cfg, 'live', mode)
            if _remote_cfg.get('broadcast_mode', 'normal') not in (
                    'phone_stream', 'usb_input', 'custom_stream'):
                print('[live] mode changed — stopping live')
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
    _stop_live.clear()


# ── Boot ──────────────────────────────────────────────────────────────────────

def _preload_fallback(local: dict) -> None:
    """
    Decode the fallback song to _fallback_pcm at startup.
    WAV files complete in ~1 s (essentially file I/O).
    Stored in memory so the audio thread can play it instantly when queue is empty.
    """
    global _fallback_pcm
    path = os.path.join(local['song_dir'], local.get('fallback_song', 'FTPA.wav'))
    if not os.path.exists(path):
        print(f'[boot] WARNING: fallback not found: {path}')
        return
    print(f'[boot] pre-decoding fallback: {os.path.basename(path)}')
    try:
        ff = subprocess.Popen(
            ['ffmpeg', '-hide_banner', '-loglevel', 'error',
             '-i', path,
             '-f', 's16le', '-ar', str(SAMPLE_RATE), '-ac', str(CHANNELS), 'pipe:1'],
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        )
        _fallback_pcm = ff.stdout.read()
        ff.wait()
        dur = len(_fallback_pcm) / BYTES_PER_SEC
        print(f'[boot] fallback ready: {len(_fallback_pcm) // 1024}KB ({dur:.1f}s)')
    except Exception as e:
        print(f'[boot] ERROR pre-decoding fallback: {e}')


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

    while not str(local.get('server_url', '')).startswith('http'):
        print(f'[boot] ERROR: server_url missing in {CONFIG_PATH} — retrying in 15s')
        time.sleep(15)
        local  = load_local_config()
        _local = local

    print(f'[boot] server={local["server_url"]} freq={local["freq"]}MHz')

    _preload_fallback(local)    # fast for WAV (~1s); blocks until done
    sync_library(local)
    send_heartbeat(local, 'idle', 'normal')

    # Start permanent background threads
    threading.Thread(
        target=_scheduler_loop, args=(local,), daemon=True, name='Scheduler',
    ).start()
    threading.Thread(
        target=_decoder_loop, daemon=True, name='Decoder',
    ).start()

    print('[boot] entering playback loop')

    while not _stop_event.is_set():
        cfg            = merged_cfg(local)
        broadcast_mode = _remote_cfg.get('broadcast_mode', 'normal')

        if broadcast_mode in ('phone_stream', 'usb_input', 'custom_stream'):
            _freq_interrupt.set()   # stops audio loop if running
            play_live_stream(cfg, broadcast_mode, _remote_cfg.get('live_stream_url', ''))
            send_heartbeat(cfg, 'idle', 'normal')
            _freq_interrupt.clear()
            continue

        _freq_interrupt.clear()

        # Runs until _freq_interrupt is set (freq change) or _stop_event (shutdown)
        _audio_write_loop(cfg, local)

        if _freq_interrupt.is_set():
            local  = load_local_config()
            _local = local
            print(f'[main] freq changed to {local["freq"]} MHz — restarting audio')

        time.sleep(0.5)


if __name__ == '__main__':
    main()
