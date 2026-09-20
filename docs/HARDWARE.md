# Hardware

Everything about the physical side of a transmitter: what to buy, how to wire it, what
range to expect, and what a driver actually sees on their radio.

The software side lives in the root `README.md`; deployment lives in
`DEPLOY-NAMECHEAP.md`; putting one of these in a vehicle is walked through in
`docs/CAR-SETUP.md`.

Frequencies below are written as 96.9 MHz because that is what this station runs on.
The frequency is a setting (`/admin/settings`), not a property of the hardware — the
whole chain covers the 88–108 MHz band.

> Transmitting on the FM broadcast band is licensed in most jurisdictions, and a 5 W amp
> is well past any unlicensed allowance. Operate only where you are authorized to.

## Bill of Materials

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

### Grounding the GPIO 4 → LPF Pigtail

GPIO 4's RF output is unbalanced (single-ended) — the bare signal wire alone isn't enough. The LPF's SMA input expects a proper 50-ohm coax reference: center pin = signal, outer shell = ground. If you build your own bare-wire-to-SMA pigtail, wire both:

- **Signal:** GPIO 4 (pin 7) → SMA center pin
- **Ground:** a GND pin → SMA outer shell/shield — **pin 6 is GND and sits directly next to pin 7**, so it's the natural choice:

```
 5  [GPIO3]  [GND ]  6   ← ground return, adjacent to pin 7
 7  [GPIO4]  [GPIO14] 8   ← signal
```

Leaving the SMA shield floating (relying on the Pi's board/PSU to "find" ground) still often produces a signal, but worse: a poorer impedance match into the filter, more stray radiation from the wire itself before the filter can strip it, and a noisier result overall. Keep both the signal and ground wires short and run them close together — a long, separated ground return acts as its own small loop antenna and adds noise instead of removing it.

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

- **PS** (station name): The `rds_ps` setting if set, otherwise the `callsign`, truncated to 8 chars
- **RT** (RadioText): Set by pi_daemon.py to the current song title and artist
- **CT** (Clock Time): PiFmRds automatically sends the Pi's system clock once per minute

RDS works on ~95% of cars made after 2000. Phones with FM radio apps also show RDS.

---

## Troubleshooting the RF chain

### No signal at all

1. Check `sudo` — pi_fm_rds requires root for DMA access
2. Check GPIO 4 wire is connected to LPF SMA input
3. Check amp has 12V power (LED on amp board should be on)
4. Check you're tuned to exactly 96.9 MHz (not 96.8 or 97.0)
5. Run `sudo ./pi_fm_rds -freq 96.9 -ps "TEST" -rt "HELLO" -audio /dev/zero` — no audio needed to test signal

### Signal is there but noisy, or the range is far short of the table

1. Check the pigtail ground — a floating SMA shield still transmits, just badly. See
   **Grounding the GPIO 4 → LPF Pigtail**.
2. Check the filter is *before* the amp, not after it.
3. Check the antenna is vertical and its radials are clear of metal.
4. Height beats power. Raising the antenna a few metres does more than any change to the
   chain below it.

### The carrier is up but there is no audio

That is a software problem, not an RF one — the daemon starts `pi_fm_rds` whether or not
it has something to play. Check `sudo systemctl status fmplaylist` and the README's
troubleshooting section.

