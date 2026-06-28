# FM Playlist Pi — Setup Guide

Everything you need to go from a blank Pi to a live FM broadcast connected to fmplaylist.com.

---

## What You Need

- Raspberry Pi 3 B+ (NOT Pi 5 — incompatible hardware)
- MicroSD card (8GB+)
- iPhone with Personal Hotspot (or other WiFi)
- The hardware signal chain: Pi → LPF → 5W amp → GP100 antenna
- fmplaylist.com deployed and accessible

---

## Part 1 — Flash the SD Card

1. Download **Raspberry Pi Imager**: https://www.raspberrypi.com/software/
2. Insert your SD card
3. Choose OS: **Raspberry Pi OS Lite (64-bit)** — no desktop needed
4. Before writing, click the gear icon and set:
   - Hostname: `pirate` (or anything)
   - Enable SSH: yes, use password authentication
   - Username: `pi`
   - Password: something you'll remember
   - **Do NOT set WiFi here** — we use our own config file
5. Write the image

---

## Part 2 — WiFi Config (edit from Windows)

After flashing, the SD card will show up in File Explorer as two drives. Open the small FAT one (called **bootfs** or **boot**).

Copy [wifi.conf](wifi.conf) into the **root** of that partition and edit it:

```
SSID="Austin's iPhone"
PASSWORD="your_hotspot_password"
COUNTRY="US"
```

> **Finding your iPhone SSID:** Settings → General → About → Name  
> **Finding your hotspot password:** Settings → Personal Hotspot → Wi-Fi Password

Save, eject the SD card, insert into the Pi.

---

## Part 3 — First Boot

1. Turn on your iPhone hotspot **before** powering the Pi
2. Power on the Pi (USB-C or USB micro, 5V 3A)
3. Wait ~60 seconds for it to boot and connect
4. Find the Pi's IP address:
   - iPhone hotspot shows connected devices under "Personal Hotspot"
   - Or scan with a network app like "Fing"
5. SSH into the Pi:
   ```
   ssh pi@192.168.x.x
   ```

---

## Part 4 — Copy Files to Pi

On your Windows machine, open PowerShell in the fmplaylist project folder:

```powershell
# Copy the entire PiFmRds directory to the Pi
scp -r PiFmRds pi@192.168.x.x:/home/pi/
```

> Replace `192.168.x.x` with your Pi's actual IP address.

---

## Part 5 — Apply WiFi Config on the Pi

SSH into the Pi and run the WiFi setup script (this saves the hotspot so it auto-connects on every boot):

```bash
sudo bash ~/PiFmRds/src/wifi_setup.sh
```

---

## Part 6 — Install Dependencies

```bash
sudo apt update
sudo apt install -y ffmpeg build-essential
```

---

## Part 7 — Compile pi_fm_rds

The FM transmitter binary must be compiled on the Pi itself:

```bash
cd ~/PiFmRds/src
make
```

You should see `pi_fm_rds` appear in the directory. Test it (no audio, just signal):

```bash
sudo ./pi_fm_rds -freq 96.9 -ps "TEST    " -rt "HELLO WORLD" -audio /dev/zero
```

Tune a phone or radio to 96.9 — you should see the station name `TEST` appear within 10 feet (wire antenna only at this point).

Press Ctrl+C to stop.

---

## Part 8 — Add Songs

Place WAV files (44.1kHz stereo) in `/home/pi/PiFmRds/src/`. Keep `FTPA.wav` — it's the fallback.

```bash
# Example: copy songs from your Windows machine
scp "C:\Users\austi\Music\song.wav" pi@192.168.x.x:/home/pi/PiFmRds/src/
```

---

## Part 9 — Connect to fmplaylist.com

### 9a. Generate the Pi API token

On the web app (once deployed):
1. Log in to the admin panel
2. Go to **Admin → Pi Token → Generate Token**
3. Copy the raw token — **it's only shown once**

### 9b. Paste the token into config.json

On the Pi:

```bash
nano ~/PiFmRds/src/config.json
```

Change `api_key` to your token:

```json
{
  "server_url": "https://fmplaylist.com",
  "api_key": "PASTE_TOKEN_HERE",
  "freq": 96.9,
  "callsign": "96.9 FM ",
  ...
}
```

Save with Ctrl+O, exit with Ctrl+X.

---

## Part 10 — Start the Daemon

```bash
cd ~/PiFmRds/src
sudo python3 pi_daemon.py
```

On first run it will:
1. Sync your song list to fmplaylist.com (songs appear in the web library)
2. Start broadcasting the fallback song on 96.9 MHz
3. Poll the API every 30 seconds for song requests

Visit fmplaylist.com on your phone. Request a song. It should play within 30 seconds.

---

## Part 11 — Auto-Start on Boot (systemd)

So the Pi broadcasts automatically whenever it powers on:

```bash
sudo nano /etc/systemd/system/fmplaylist.service
```

Paste:

```ini
[Unit]
Description=FM Playlist Daemon
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 /home/pi/PiFmRds/src/pi_daemon.py
WorkingDirectory=/home/pi/PiFmRds/src
Restart=always
RestartSec=10
User=root

[Install]
WantedBy=multi-user.target
```

Save, then enable:

```bash
sudo systemctl daemon-reload
sudo systemctl enable fmplaylist
sudo systemctl start fmplaylist
```

Check it's running:

```bash
sudo systemctl status fmplaylist
```

From now on: plug in the Pi, hotspot auto-connects, daemon auto-starts, broadcasting begins within ~60 seconds.

---

## Part 12 — Upload a Station ID

Short anti-corporate callout that plays every N songs (configured in Admin → Settings):

1. Record a WAV file (5 seconds, 44.1kHz stereo, say whatever you want)
2. Admin → Station IDs → Upload → give it a label → Upload → Set Active
3. The Pi downloads it automatically within one heartbeat cycle (~30 seconds)

---

## Part 13 — Upload Commercials and Sound Bytes

**Commercials** (ad spots, 15–60 seconds):
1. Admin → Commercials → Upload → select WAV/MP3/OGG (up to 50 MB)
2. Set a title and rotation order
3. Enable in Admin → Settings → "Songs between commercials" (0 = disabled)
4. The Pi downloads the file automatically. Admin → Broadcast → Play Commercial to force one immediately.

**Sound Bytes** (jingles, drops, shoutouts — 2–10 seconds):
1. Admin → Sound Bytes → Upload → select WAV/MP3/OGG (up to 20 MB)
2. Set a title and category (jingle / shoutout / drop / id)
3. Enable in Admin → Settings → "Songs between sound bytes" (0 = disabled)
4. Admin → Broadcast → Play Sound Byte to force one immediately.

Both types are downloaded to the Pi automatically. Commercials rotate sequentially by rotation order. Sound bytes are selected at random.

---

## Quick Reference

| Task | Command |
|------|---------|
| SSH into Pi | `ssh pi@192.168.x.x` |
| Start daemon manually | `sudo python3 ~/PiFmRds/src/pi_daemon.py` |
| Check service status | `sudo systemctl status fmplaylist` |
| View live logs | `sudo journalctl -u fmplaylist -f` |
| Restart service | `sudo systemctl restart fmplaylist` |
| Standalone loop (no web app) | `cd ~/PiFmRds/src && ./run.sh` |
| Test signal only (no audio) | `sudo ./pi_fm_rds -freq 96.9 -ps "TEST    " -rt "HELLO" -audio /dev/zero` |
| Check WiFi connection | `ip addr show wlan0` |
| Re-apply WiFi config | `sudo bash ~/PiFmRds/src/wifi_setup.sh` |
| Copy new songs to Pi | `scp song.wav pi@192.168.x.x:/home/pi/PiFmRds/src/` |

---

## Troubleshooting

**Pi not connecting to hotspot**
- Make sure hotspot is on before the Pi boots
- Re-run `sudo bash ~/PiFmRds/src/wifi_setup.sh`
- Check SSID matches exactly: `nmcli connection show` or `cat /etc/wpa_supplicant/wpa_supplicant.conf`

**Daemon won't start — api_key error**
- Check `config.json` has the token pasted in (no extra spaces or quotes around the value)
- Regenerate the token in Admin → Pi Token if you lost it

**No FM signal**
- Confirm `pi_fm_rds` binary exists: `ls -la ~/PiFmRds/src/pi_fm_rds`
- Must run as root: `sudo python3 pi_daemon.py`
- Check the wire/antenna is connected to GPIO pin 7 (physical pin, not GPIO number)

**Songs not appearing on website**
- Daemon syncs library on boot and every hour — restart the service to force a sync
- File must be `.wav` extension (lowercase)

**Queue not advancing**
- If daemon crashed mid-song, a queue item stays stuck as "playing"
- It clears itself automatically when the daemon next reports now-playing
- Restart service: `sudo systemctl restart fmplaylist`

**Commercials or sound bytes not playing**
- Check Admin → Settings — is the interval set to a non-zero value?
- Check Admin → Commercials (or Sound Bytes) — is at least one item marked active?
- The Pi must have downloaded the file first — check Admin → Commercials and look at the download status
- Use Admin → Broadcast → Play Commercial (or Sound Byte) to force one immediately for testing

**Commercials/sound bytes not downloading to Pi**
- The Pi polls on every heartbeat (~30 seconds) — wait one cycle or restart the daemon
- Check the Pi has internet access and the `needs_pi_download` flag is true in the admin table
- Song, commercial, and sound byte directories are created automatically: check `/home/pi/PiFmRds/src/commercials/` and `/home/pi/PiFmRds/src/sound-bytes/`
