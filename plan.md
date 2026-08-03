# FM Playlist — Quick Wins

## Context

Four targeted improvements, high impact-to-effort ratio:
1. **Duration extraction** — songs, commercials, and sound bytes never call ffprobe on upload, so `duration_seconds` is always null. Breaks queue runtime estimates and the "—" duration display.
2. **Pi online/offline threshold** — 90s cutoff at `PiController::piStatus()` line 132 causes false "offline" flickers when a heartbeat is a few seconds late.
3. **Throttle feedback** — song requests hit `throttle:5,1` but `RequestDialog` has no `onError`; 429s silently disable the button with no message.
4. **Search debounce** — song search fires a full Inertia page reload on every keystroke via `router.get` in `onChange`; causes blank flickers and wasted requests on mobile.

---

## 1. Duration Extraction on Upload

**Create** `app/Support/AudioDuration.php`:
```php
public static function extract(string $absolutePath): ?int
{
    $out = shell_exec('ffprobe -v error -show_entries format=duration'
        . ' -of default=noprint_wrappers=1:nokey=1 '
        . escapeshellarg($absolutePath));
    $s = (float) trim($out ?? '');
    return $s > 0 ? (int) round($s) : null;
}
```

Call after `storeAs` in all three upload methods; get absolute path via `Storage::disk('public')->path($storedPath)`.

**Files:** `app/Support/AudioDuration.php` (new), `SongAdminController::upload()`, `CommercialController::upload()`, `SoundByteController::upload()`

---

## 2. Pi Online/Offline Threshold

Single-line change: `> 90` → `> 120` at `PiController.php:132`.
Also check `BroadcastController.php` for any matching threshold and apply the same change.

---

## 3. Throttle Feedback

In `resources/js/pages/songs.tsx` — `RequestDialog`:
- Add `const [serverError, setServerError] = useState('');`
- Add `onError` to the `post()` call: set `serverError` to `errors?.message ?? 'Too many requests — try again in a moment.'`
- Render `serverError` as an inline error below the submit button
- Change disabled button text to `'Adding...'` while `processing`

---

## 4. Search Debounce

In `resources/js/pages/songs.tsx` — `Songs`:
- Add `useRef` import
- Replace `handleSearch` with a 300ms debounced version using `useRef<ReturnType<typeof setTimeout>>`

---

## Verification
1. Upload WAV song → `duration_seconds` in DB, `duration_formatted` shows in Sounds page (not "—")
2. Upload MP3 commercial → same
3. Request 6 songs fast → dialog shows "Too many requests" on 6th instead of silent fail
4. Type quickly in song search → only one network request fires after 300ms pause
5. Set heartbeat interval to 90s on Pi → admin Pi status stays "online"

---

# FM Playlist — Visual Redesign Plan

## Context

The public site currently looks like a generic Laravel starter kit: white background, zinc palette, small rounded cards, thin buttons. The actual product is a guerrilla FM radio station broadcasting at drive-thrus and vans — people request songs *on their phone while in a car*. The design needs to match that energy: pirate broadcast booth, not SaaS dashboard.

Three constraints shape every decision:
- **Dark only** — no toggle, fully committed aesthetic
- **Mobile while driving** — large tap targets, one-handed, fast interactions
- **2026 techniques** — CSS-native, no extra JS libraries, GPU-accelerated

---

## 2026 Techniques Used

| Technique | Where | Why |
|---|---|---|
| `@view-transition { navigation: auto }` | `app.css` | CSS-only page transitions, zero JS |
| `@property` + animated `radial-gradient` | `app.css` + `home.tsx` | Breathing red glow on hero, GPU-accelerated |
| `scaleY` waveform bars | `now-playing-bar.tsx` | Spectrum-analyzer animation, pure CSS keyframes |
| `touch-action: manipulation` | `app.css` global | Removes 300ms tap delay on mobile |
| `text-wrap: balance` | headings | Better line breaks, CSS-native |
| `env(safe-area-inset-bottom)` | layout, dialog | iPhone home bar safe area |
| Space Grotesk display font | headings, labels | Technical/broadcast character |

---

## Files to Modify (in order)

### 1. `vite.config.ts`
Add Space Grotesk to the `bunny()` fonts array alongside Instrument Sans:
```ts
bunny('Space Grotesk', { weights: [700] }),
```

### 2. `resources/css/app.css`

**a) `@theme` block** — add display font after `--font-sans`:
```css
--font-display:
    'Space Grotesk', ui-sans-serif, system-ui, sans-serif,
    'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
```

**b) `:root` block** — replace entirely with dark pirate palette (since dark is the only mode, `:root` IS the dark theme):
```css
:root {
    --background: oklch(0.07 0 0);          /* near-black #0a0a0a */
    --foreground: oklch(0.93 0 0);          /* off-white, not blinding */
    --card: oklch(0.10 0 0);               /* slightly lighter than bg */
    --card-foreground: oklch(0.93 0 0);
    --popover: oklch(0.10 0 0);
    --popover-foreground: oklch(0.93 0 0);
    --primary: oklch(0.55 0.24 27);        /* vivid red */
    --primary-foreground: oklch(0.99 0 0);
    --secondary: oklch(0.15 0 0);
    --secondary-foreground: oklch(0.93 0 0);
    --muted: oklch(0.15 0 0);
    --muted-foreground: oklch(0.58 0 0);
    --accent: oklch(0.15 0 0);
    --accent-foreground: oklch(0.93 0 0);
    --destructive: oklch(0.55 0.24 27);
    --destructive-foreground: oklch(0.99 0 0);
    --border: oklch(0.18 0 0);             /* very subtle */
    --input: oklch(0.15 0 0);
    --ring: oklch(0.55 0.24 27);           /* red focus ring */
    --radius: 0.25rem;                      /* almost square — editorial feel */
    --sidebar: oklch(0.10 0 0);
    --sidebar-foreground: oklch(0.93 0 0);
    --sidebar-primary: oklch(0.55 0.24 27);
    --sidebar-primary-foreground: oklch(0.99 0 0);
    --sidebar-accent: oklch(0.15 0 0);
    --sidebar-accent-foreground: oklch(0.93 0 0);
    --sidebar-border: oklch(0.18 0 0);
    --sidebar-ring: oklch(0.55 0.24 27);
}
```
Remove the `.dark` block entirely (no longer needed — `:root` IS the dark theme).

**c) New keyframes + utilities** — add before the end of file:
```css
/* View transitions — page nav crossfade, CSS-only */
@view-transition {
    navigation: auto;
}

/* Animated hero glow — @property for smooth gradient animation */
@property --glow-opacity {
    syntax: '<number>';
    inherits: false;
    initial-value: 0;
}

@keyframes hero-breathe {
    0%, 100% { --glow-opacity: 0.08; }
    50%       { --glow-opacity: 0.18; }
}

/* Waveform bar keyframes — staggered so they never sync */
@keyframes bar-a {
    0%, 100% { transform: scaleY(0.25); }
    45%       { transform: scaleY(1);    }
}
@keyframes bar-b {
    0%, 100% { transform: scaleY(0.6);  }
    30%       { transform: scaleY(0.1); }
    65%       { transform: scaleY(1);   }
}
@keyframes bar-c {
    0%, 100% { transform: scaleY(0.4);  }
    55%       { transform: scaleY(1);   }
    80%       { transform: scaleY(0.15);}
}
@keyframes bar-d {
    0%, 100% { transform: scaleY(0.85); }
    40%       { transform: scaleY(0.2); }
    70%       { transform: scaleY(1);   }
}
@keyframes bar-e {
    0%, 100% { transform: scaleY(0.5);  }
    20%       { transform: scaleY(1);   }
    60%       { transform: scaleY(0.3); }
}

@layer utilities {
    .font-display { font-family: var(--font-display); }

    .animate-bar-a { animation: bar-a 0.85s ease-in-out infinite; }
    .animate-bar-b { animation: bar-b 1.1s  ease-in-out infinite; }
    .animate-bar-c { animation: bar-c 0.75s ease-in-out infinite; }
    .animate-bar-d { animation: bar-d 1.3s  ease-in-out infinite; }
    .animate-bar-e { animation: bar-e 0.95s ease-in-out infinite; }

    /* Remove 300ms tap delay everywhere — critical for driving */
    button, a, [role="button"] {
        touch-action: manipulation;
    }
}
```

**d) `@layer base`** — add `text-wrap: balance` on headings:
```css
@layer base {
    * { @apply border-border; }
    body { @apply bg-background text-foreground; }
    h1, h2, h3 { text-wrap: balance; }
}
```

### 3. `resources/views/app.blade.php`

Force dark class by default (public pages never pass `$appearance`, so they always get dark):
```blade
<html lang="..." class="dark">
```
Remove the `@class` directive and the inline JS appearance script (not needed — dark only).

Update the FOUC inline style:
```html
<style>
    html { background-color: oklch(0.07 0 0); }
</style>
```

### 4. `resources/js/components/public-layout.tsx`

Full replacement. Key changes:
- `bg-background` (inherits from CSS tokens — dark)
- `font-display` on logo + frequency badge
- "96.9" badge inline in header
- Max-width bumped to `max-w-5xl`, padding to `px-6`
- Footer: two-line editorial treatment
- Nav links larger on mobile (`text-base sm:text-sm`)

```tsx
export function PublicLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b border-border sticky top-0 z-40 bg-background/95 backdrop-blur-sm">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                    <Link href="/" className="flex items-center gap-3 group">
                        <span className="font-display text-xl font-bold tracking-tight text-red-500 transition-colors group-hover:text-red-400">FM</span>
                        <span className="font-display text-xl font-bold tracking-tight text-foreground transition-colors group-hover:text-foreground/70">PLAYLIST</span>
                        <span className="ml-1 border border-red-500/40 bg-red-500/10 px-1.5 py-0.5 font-sans text-[10px] font-bold uppercase tracking-widest text-red-500">
                            96.9
                        </span>
                    </Link>
                    <nav className="flex items-center gap-6 text-base sm:text-sm font-medium text-muted-foreground">
                        <Link href="/" className="hover:text-foreground transition-colors py-2">Home</Link>
                        <Link href="/songs" className="hover:text-foreground transition-colors py-2">Songs</Link>
                        <Link href="/queue" className="hover:text-foreground transition-colors py-2">Queue</Link>
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-6 py-10">
                {children}
            </main>
            <footer className="border-t border-border py-8 text-center" style={{ paddingBottom: 'max(2rem, env(safe-area-inset-bottom))' }}>
                <p className="font-display text-xs font-bold uppercase tracking-[0.3em] text-muted-foreground">96.9 FM</p>
                <p className="mt-1 text-xs text-muted-foreground/50">Fuck corporate media · Request a song · Hear it live</p>
            </footer>
        </div>
    );
}
```

Note: `sticky top-0 backdrop-blur-sm` makes the header stay visible while scrolling — critical for one-handed driving use.

### 5. `resources/js/components/now-playing-bar.tsx`

Replace pulsing dot with 5-bar waveform. `origin-bottom` so bars animate upward from baseline.

**Playing state:**
```tsx
<div className="flex items-center gap-4 border border-border bg-card px-5 py-4">
    {/* Waveform — 5 bars, all different animation keyframes */}
    <div className="flex h-5 shrink-0 items-end gap-[3px]">
        {['animate-bar-a','animate-bar-b','animate-bar-c','animate-bar-d','animate-bar-e'].map((cls, i) => (
            <span key={i} className={`inline-block w-[3px] origin-bottom rounded-full bg-red-500 h-full ${cls}`} />
        ))}
    </div>
    <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-foreground">{data.song.title}</p>
        {data.song.artist && <p className="truncate text-xs text-muted-foreground">{data.song.artist}</p>}
    </div>
    <span className="ml-auto shrink-0 font-display text-xs font-bold uppercase tracking-widest text-red-500">On Air</span>
</div>
```

**Idle state:**
```tsx
<div className="flex items-center gap-3 border border-dashed border-border bg-card px-5 py-4 text-sm text-muted-foreground">
    <div className="flex h-5 shrink-0 items-end gap-[3px]">
        {[40, 65, 30, 80, 50].map((h, i) => (
            <span key={i} className="inline-block w-[3px] rounded-full bg-muted-foreground/20" style={{ height: `${h}%` }} />
        ))}
    </div>
    Nothing playing right now
</div>
```

### 6. `resources/js/pages/home.tsx`

Key changes:
- `@property` breathing glow: a `<div>` with `hero-glow` class sits absolutely behind the hero text
- Hero splits frequency "96.9" as a design element vs. part of a sentence
- Queue rows: row height `py-4` (was `py-3`), position numbers in red

**Hero section:**
```tsx
<div className="relative space-y-3 border-b border-border pb-10 overflow-hidden">
    {/* Breathing red glow — @property CSS animation */}
    <div
        className="pointer-events-none absolute -top-20 left-1/2 -translate-x-1/2 h-64 w-96 rounded-full blur-3xl"
        style={{
            background: `radial-gradient(ellipse, oklch(0.55 0.24 27 / var(--glow-opacity, 0.1)), transparent)`,
            animation: 'hero-breathe 4s ease-in-out infinite',
        }}
    />
    <p className="relative font-display text-xs font-bold uppercase tracking-[0.25em] text-red-500">
        On Air · 96.9 FM
    </p>
    <h1 className="relative font-display text-4xl font-bold leading-tight text-foreground sm:text-5xl">
        Your station.<br />Your songs.
    </h1>
    <p className="relative max-w-sm text-base text-muted-foreground">
        No algorithms. No ads. No bullshit. Request a track, hear it live on 96.9.
    </p>
</div>
```

**Queue preview rows** — increase to `py-4` and make position number red:
```tsx
<div key={item.id} className="flex items-center gap-4 px-5 py-4">
    <span className="font-display w-6 shrink-0 text-center text-sm font-bold tabular-nums text-red-500/60">
        {item.position}
    </span>
    {/* rest unchanged */}
</div>
```

**Flash message** — remove rounded-lg, use editorial border treatment:
```tsx
<div className="border-l-2 border-green-500 bg-green-500/10 px-4 py-3 text-sm text-green-400">
    {flash.success}
</div>
```

### 7. `resources/js/pages/songs.tsx`

Two major changes: (1) bigger tap targets for driving, (2) mobile bottom-sheet dialog.

**Song rows** — change from `py-3` to `py-4`, make entire row feel tappable:
```tsx
<div
    key={song.id}
    className="group flex min-h-[64px] cursor-pointer items-center gap-4 px-5 py-4 transition-colors hover:bg-card active:bg-card"
    onClick={() => setRequesting(song)}
>
    <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-foreground">{song.title}</p>
        {song.artist && <p className="truncate text-xs text-muted-foreground">{song.artist}</p>}
    </div>
    {song.duration_formatted && (
        <span className="shrink-0 text-xs tabular-nums text-muted-foreground/50">{song.duration_formatted}</span>
    )}
    <span className="shrink-0 border border-red-500/40 px-3 py-2 text-xs font-bold uppercase tracking-wider text-red-500 transition-colors group-hover:bg-red-500 group-hover:text-foreground">
        Request
    </span>
</div>
```

Note: making the entire `<div>` clickable (via `onClick`) gives the full-row tap target that driving needs. The "Request" label on the right is now visual affordance, not the only tappable area.

**RequestDialog** — bottom sheet on mobile via `className` override on `DialogContent`:
```tsx
<DialogContent className="
    sm:max-w-sm
    max-sm:fixed max-sm:bottom-0 max-sm:left-0 max-sm:right-0 max-sm:top-auto
    max-sm:translate-x-0 max-sm:translate-y-0
    max-sm:rounded-t-2xl max-sm:rounded-b-none max-sm:max-w-none
    max-sm:border-t max-sm:border-x max-sm:border-b-0
"
style={{ paddingBottom: 'env(safe-area-inset-bottom, 1.5rem)' }}
>
```

**Inside the dialog** — make inputs and buttons full-width, tall, mobile-friendly:
```tsx
<Input
    id="name"
    className="h-14 text-base"   /* tall input, larger text — easy to tap and type */
    ...
/>
<Button type="submit" className="h-14 w-full bg-red-600 hover:bg-red-700 text-white font-display font-bold uppercase tracking-wide">
    Add to Queue
</Button>
<Button type="button" variant="outline" className="h-12 w-full" onClick={onClose}>Cancel</Button>
```

### 8. `resources/js/pages/queue.tsx`

Position numbers get the editorial treatment — large, red, tabular:
```tsx
<span className="font-display w-10 shrink-0 text-center text-2xl font-bold tabular-nums text-red-600">
    {item.position}
</span>
```

Row height increases to `py-4` (was `py-3`) for driving tap targets.

Empty state gets a large typographic dash:
```tsx
<div className="py-20 text-center">
    <p className="font-display text-6xl font-bold text-border">—</p>
    <p className="mt-4 text-sm text-muted-foreground">
        Queue is empty.{' '}
        <Link href="/songs" className="text-red-500 hover:underline">Request a song.</Link>
    </p>
</div>
```

### 9. `resources/js/components/admin-layout.tsx`

Swap hardcoded zinc colors for CSS token classes (they'll inherit the dark palette automatically):
- `bg-zinc-50 dark:bg-zinc-950` → `bg-background`
- `bg-white dark:bg-zinc-900` → `bg-card`  
- `border-zinc-200 dark:border-zinc-800` → `border-border`
- `text-zinc-900 dark:text-white` → `text-foreground`
- `text-zinc-600 dark:text-zinc-400` → `text-muted-foreground`

Same token swap pattern applies to `admin/dashboard.tsx` (the stat cards use `bg-white dark:bg-zinc-900`).

---

## Verification

1. Run `npm run dev` after `vite.config.ts` change — confirm Space Grotesk loads (check Network tab for font file)
2. Check `font-display` Tailwind utility is generated (inspect heading element)
3. Navigate between pages — should see a crossfade view transition (Chrome/Edge; silent fallback in Firefox)
4. On the home page, the hero background should have a slow breathing red glow every ~4 seconds
5. When a song is playing, the NowPlayingBar shows 5 animating bars (not synced with each other)
6. On mobile (375px), song rows are at least 64px tall, entire row tappable
7. On mobile, tapping "Request" on songs page slides up a bottom sheet (not a centered modal)
8. Dialog "Add to Queue" button is full-width, 56px tall — easy to tap one-handed
9. Sticky header stays visible while scrolling the song list
10. No white flash on page load (inline `<style>` in blade sets dark background)
