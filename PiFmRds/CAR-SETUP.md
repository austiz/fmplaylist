# Car Setup — Raspberry Pi

For a vehicle install: phone hotspot for WiFi, USB car charger for power, wire antenna on GPIO 4.

Works on both Pi 3 Model B+ and Pi Zero 2 WH. The 3B+ is easier to set up (Ethernet port, full USB-A ports, bigger board to handle). The Zero 2 WH is easier to hide.

---

## What you need

| Item | Pi 3B+ | Pi Zero 2 WH |
|---|---|---|
| Board | Pi 3 Model B+ | Pi Zero 2 WH |
| Micro SD card | 16 GB+ Class 10 / A1 | same |
| Car USB charger | **5V 3A** (the 3B+ is hungry) | 5V 2.5A |
| Cable | Micro USB | Micro USB — **PWR IN port** (corner), not OTG |
| 25 cm wire | FM antenna on GPIO 4 | same |
| Velcro / tape | Mount behind dash | same — or sun visor |

---

## Step 1 — Flash the SD card

Download [Raspberry Pi Imager](https://www.raspberrypi.com/software/).

- **OS**: Raspberry Pi OS Lite (64-bit) — no desktop needed
- **Storage**: your SD card

Click **Next → Edit Settings** before writing:

**General tab:**
- Hostname: `fm-pi` (or whatever you like)
- Username: `pi`
- Password: something you'll remember
- **WiFi: your phone hotspot** (SSID + password)
  - Wireless LAN country: US (or your country)

**Services tab:**
- Enable SSH ✓
- Use password authentication

Write the card.

---

## Step 2 — First boot

1. Insert SD card into Pi
2. Power it from a regular USB plug (not the car yet — you need SSH access)
3. **Pi 3B+**: plug in an Ethernet cable — skip the hotspot entirely for initial setup
   **Zero 2 WH**: turn on your phone hotspot
4. Wait ~60 seconds for boot

SSH in:

```bash
ssh pi@fm-pi.local
# or: ssh pi@<IP address>
```

For the 3B+, `fm-pi.local` works reliably over Ethernet. If the hotspot route, find the IP in your phone's connected-devices list.

---

## Step 3 — Add WiFi networks

```bash
# Phone hotspot (needed in the car):
sudo nmcli dev wifi connect "YourHotspotSSID" password "YourHotspotPassword"

# Home WiFi (for at-home maintenance):
sudo nmcli dev wifi connect "YourHomeSSID" password "YourHomePassword"
```

Pi connects to whichever is available — no reboot needed when you switch.

---

## Step 3b — Raspberry Pi Connect (recommended)

Raspberry Pi Connect gives you a browser-based terminal to the Pi from **any network** — no SSH client, no IP address, no port forwarding. Useful for checking logs or updating config while the Pi is in the car on your phone hotspot and you're at home on a different network.

```bash
sudo apt install -y rpi-connect-lite
rpi-connect signin
```

It prints a URL — open it on any device, sign in with a free [Raspberry Pi ID](https://id.raspberrypi.com), and the Pi appears at **connect.raspberrypi.com**.

Click **Remote shell** → instant terminal.

Enable it to start automatically:

```bash
systemctl --user enable rpi-connect
```

After this, whenever the Pi is online (hotspot or home WiFi), you can reach it at connect.raspberrypi.com from a browser anywhere.

---

## Step 4 — Run the setup script

Go to **Admin → Pi Token → Generate Token** first. After generating, the admin page shows a ready-to-run command with the token already embedded:

```bash
curl -fsSL https://yourdomain.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
```

Copy that command from the admin page and paste it into the Pi. It installs ffmpeg, builds pi_fm_rds, writes `config.json` with the token injected, and creates the systemd service. Takes 3–5 minutes on a Zero 2 W.

No `nano` needed — `config.json` is fully configured by the script.

---

## Step 5 — Optional: set freq and pi_code

`freq` is pushed from the server on every heartbeat (set it in Admin → Broadcast Settings). You only need to edit `config.json` locally if you want to override `pi_code` (the 4-char RDS PI identifier):

```bash
sudo nano /home/pi/PiFmRds/src/config.json
```

Change `pi_code` to something unique (e.g. your initials + 2 digits). Everything else is managed from the admin panel.

---

## Step 6 — Antenna wire

Cut a **25 cm** wire (bare wire or strip one end). Attach it to **pin 7** on the GPIO header — that's **GPIO 4**, the FM output.

```
Pin layout (40-pin header, looking down at the board):
 1  [ 3.3V ]  [ 5V   ]  2
 3  [ GPIO2]  [ 5V   ]  4
 5  [ GPIO3]  [ GND  ]  6
 7  [ GPIO4]  [ GPIO14] 8   ← antenna here (pin 7)
```

Same pin on both the 3B+ and Zero 2 WH — GPIO 4 / physical pin 7.

Wrap the bare end a few turns around pin 7. A loose connection is fine — it doesn't carry current, just emits RF.

In a car, 25 cm is enough. The car body acts as a ground plane and the signal fills the cabin easily.

---

## Step 7 — Start the service

```bash
sudo systemctl start fmplaylist
sudo systemctl status fmplaylist
```

You should see `Active: active (running)`. Check the admin broadcast page — Pi status should go **Connected** within 30 seconds.

Enable auto-start on boot (already done by setup.sh, but verify):

```bash
sudo systemctl is-enabled fmplaylist
# should print: enabled
```

---

## Step 8 — Move to the car

1. Shut down cleanly: `sudo shutdown now`
2. Wait for the green LED to stop blinking (10–15 sec)
3. Move the Pi to the car
4. Plug USB-A charger into cigarette lighter / 12V port
5. Connect the micro USB power cable to the Pi's **PWR IN** port (the one closest to the corner of the board)

The Pi boots automatically when power is applied. No button press needed.

Turn on your phone hotspot before starting the car — the Pi will connect within ~30 seconds of boot and start broadcasting.

---

## Mounting

**Pi 3B+** — larger, so: glove box, under the seat, or zip-tied to the back of the center console. Easy access for the Ethernet port when parked at home.

**Sun visor** — works better for the Zero 2 WH. Route the antenna wire toward the windshield.

**Behind the dash** — tape near the head unit if you have clearance. Keep the antenna wire away from the engine harness to avoid interference.

No mounting position is wrong for short-range in-car broadcast — the signal only needs to reach the head unit 30–60 cm away.

---

## Hotspot tips

- **iPhone**: Personal Hotspot → "Maximise Compatibility" ON — keeps the 2.4 GHz band active which the Zero 2 W requires (no 5 GHz)
- **Android**: Hotspot → 2.4 GHz band preferred (some phones default to 5 GHz which the Zero 2 W can't use)
- The Pi reconnects automatically if you turn hotspot off and back on — no reboot needed
- If the hotspot isn't on when the Pi boots, the daemon retries every 30s once it connects

---

## Checking logs from your phone

**With Raspberry Pi Connect (easiest):** open connect.raspberrypi.com in any browser → Remote shell. No app needed.

**With SSH app:** Termius (iOS/Android) → connect to `fm-pi.local` or the Pi's IP.

Either way:

```bash
sudo journalctl -u fmplaylist -f
```

Live log — confirms songs are playing and heartbeats are succeeding.

---

## Checklist

- [ ] SD card flashed with Pi OS Lite 64-bit, SSH + hotspot pre-configured
- [ ] First boot: SSH works, home WiFi added as second network
- [ ] `setup.sh` ran successfully (pi_fm_rds binary exists)
- [ ] `config.json` has correct `server_url`, `api_key`, `freq`
- [ ] Antenna wire on GPIO pin 7 (GPIO 4)
- [ ] `sudo systemctl start fmplaylist` — admin shows Pi as Connected
- [ ] Pi moved to car, boots and connects automatically on power
- [ ] Head unit tuned to your frequency — audio plays
