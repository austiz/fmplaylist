# Deploying to Namecheap Stellar Shared Hosting

CI/CD via GitHub Actions: tests run on every push; if they pass, the app is deployed automatically.

---

## Prerequisites

- **Namecheap plan** — Stellar, Stellar Plus, or Stellar Business (all include SSH)
- **Domain** already pointed at Namecheap nameservers
- **GitHub repo** for the project (public or private)
- **PHP 8.4.1 or newer** — selected in cPanel. 8.3 will not work: Laravel 13 pulls in Symfony 8, which requires 8.4.1. This is the only version number in the project; `composer.json` declares the same floor.

---

## One-Time Server Setup

Do this once over SSH. After this, all future deploys are handled by GitHub Actions automatically.

### 1 — Enable SSH and connect

Namecheap SSH port is **21098** (not 22). Use the `github_deploy` key you'll generate in Step 4 — or authorize any existing key via **cPanel → Security → SSH Access → Manage SSH Keys → Authorize**.

```bash
ssh yourusername@server123.web-hosting.com -p 21098
```

Your home directory is `/home/yourusername/`. The default web root is `public_html/`.

### 2 — Set PHP 8.4

In **cPanel → Software → MultiPHP Manager**, set PHP **8.4** (or 8.5) for your domain's directory.

Confirm in SSH:

```bash
php -v
```

### 3 — Create the MySQL database

In **cPanel → Databases → MySQL Databases**:

1. Create database — e.g. `yourusername_fmplaylist`
2. Create user — e.g. `yourusername_fmuser` with a strong password
3. Add the user to the database with **All Privileges**

Note all three values: database name, username, password.

### 4 — Generate an SSH key on the server

cPanel needs an SSH key to pull from GitHub. Generate one in the browser — no SSH terminal needed yet.

**cPanel → Security → SSH Access → Manage SSH Keys → Generate a New Key**

- Key Name: `github_deploy`
- Key Type: `RSA`
- Key Size: `4096` (or choose `Ed25519` if available)
- Key Password: leave blank (needed for automated pulls)
- Click **Generate Key**

Back on the key list, click **Manage** next to `github_deploy` → **Authorize** (this adds it to `authorized_keys` so you can also SSH with it).

Then click **View/Download** next to the public key (`github_deploy.pub`) and copy the entire contents.

### 5 — Add the key to GitHub

**GitHub repo → Settings → Deploy keys → Add deploy key**

- Title: `Namecheap`
- Key: paste the public key from Step 4
- Allow write access: leave unchecked (read-only is enough to pull)
- Click **Add key**

### 6 — Clone via cPanel Git Version Control

**cPanel → Files → Git Version Control → Create**

| Field | Value |
|---|---|
| Clone URL | `git@github.com:yourusername/fmplaylist.git` |
| Repository Path | `/home/yourusername/fmplaylist` |
| Repository Name | `fmplaylist` |

Click **Create**. cPanel clones the repo using the SSH key from Step 4. Takes 10–30 seconds.

After this, the repo appears in the Git Version Control list. The **Deploy HEAD Commit** button triggers the tasks in [`.cpanel.yml`](.cpanel.yml) — useful for emergency redeploys from the browser without SSH.

> **Note on built assets**: `public/build/` is gitignored so it is not in the repo. The **Deploy HEAD Commit** button runs `composer install` and artisan caches but cannot build frontend assets (no Node.js on shared hosting). Use GitHub Actions (`deploy.yml`) for normal deploys — it builds assets locally and rsyncs them. Use the cPanel button only as a fallback for PHP-only changes (migrations, config updates).

### 7 — The document root

The app's web root is `~/fmplaylist/public/`. The cleanest arrangement is to point the
domain straight at it, in **cPanel → Domains → Document Root**:

```
fmplaylist/public
```

**On the primary domain of a Stellar plan you usually cannot**, because the docroot is
locked to `~/public_html`. That is why the deploy workflow maintains `public_html` as a
thin mirror rather than assuming the docroot moved. Its **Sync public_html** step, which
runs after every deploy:

| What | How |
| --- | --- |
| `public_html/index.php` | Overwritten with a one-line proxy: `<?php require '<app>/public/index.php';` |
| Root-level static files | `favicon.ico`, `manifest.json`, `sw.js`, `robots.txt` … copied from `public/` (top level only) |
| `public_html/build` | Removed and re-copied whole, so a stale Vite manifest can never survive a deploy |
| `public_html/storage` | Symlinked to `<app>/storage/app/public` if it is not already a symlink |

Two things follow from this that are easy to get wrong:

- **Root-level static files are copied, not synced.** A file you delete from `public/`
  stays in `public_html` until you remove it by hand. Only `build/` is replaced wholesale.
- **If you *do* move the document root to `fmplaylist/public`**, the sync step becomes
  harmless but pointless, and `public_html` is then a second copy of the site that
  nothing serves. Pick one and know which you picked.

The `.htaccess` redirect approach is a third option and is worse than both — it rewrites
every request through an extra pass and breaks path handling for uploads.

### 8 — Configure the environment

```bash
cp .env.example .env
nano .env
```

Minimum values to set:

```env
APP_NAME="FM Playlist"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_KEY=                          # filled by artisan below

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=yourusername_fmplaylist
DB_USERNAME=yourusername_fmuser
DB_PASSWORD=yourpassword

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

QUEUE_CONNECTION=sync
CACHE_STORE=database
FILESYSTEM_DISK=public
```

Generate the app key:

```bash
php artisan key:generate
```

### 9 — Install PHP dependencies and run setup

```bash
/usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 10 — Set upload limits

Create `~/fmplaylist/public/.user.ini` for PHP file upload settings:

```ini
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 256M
max_execution_time = 120
```

Or set these in **cPanel → Software → PHP Configuration → Options**.

### 11 — Set directory permissions

```bash
chmod -R 755 ~/fmplaylist/storage
chmod -R 755 ~/fmplaylist/bootstrap/cache
```

### 12 — Verify

Visit `https://yourdomain.com` — you should see the FM Playlist home page.

Log in at `/login` and visit `/admin/tokens` to generate the Pi token.

---

## GitHub Actions CI/CD Setup

After initial server setup, every `git push` to `main`:
1. Runs the full test suite
2. Builds frontend assets (Vite)
3. Deploys to the server only if tests pass

### Required GitHub Secrets

Go to **GitHub repo → Settings → Secrets and variables → Actions → New repository secret** and add:

| Secret name | Value |
|---|---|
| `DEPLOY_HOST` | Your Namecheap server hostname (e.g. `server123.web-hosting.com`) |
| `DEPLOY_USER` | Your cPanel username |
| `DEPLOY_KEY` | Private key for `github_deploy` — download it from **cPanel → SSH Access → Manage SSH Keys → View/Download** (the private key file, not the `.pub`) |
| `DEPLOY_PASSPHRASE` | Passphrase for that key. Leave empty if the key has none. |
| `DEPLOY_PORT` | SSH port. Namecheap shared hosting uses `21098`, not `22`. |
| `DEPLOY_PATH` | Absolute path on server, e.g. `/home/yourusername/fmplaylist`. The deploy refuses to run if this is empty or `/`. |

> Use a **GitHub environment** named `production` for the deploy job (created automatically in the workflow). You can add environment protection rules (e.g. require approval) in **Settings → Environments**.

### How it works

The workflow is at [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml).

**On every push to `main`:**

```
push to main
    │
    ├─► test job
    │       npm ci + composer install
    │       php artisan test
    │
    └─► deploy job (only if tests pass)
            │
            ├─ npm ci && npm run build       ← builds public/build/ locally
            ├─ SSH: git pull + composer install
            ├─ rsync: upload public/build/   ← gitignored, must be sent separately
            └─ SSH: migrate + config:cache + route:cache + view:cache
```

`public/build/` is gitignored (Vite hash-named files), so it is built in GitHub Actions and uploaded via rsync after the code pull.

### First deploy via Actions

1. Add all four secrets (above)
2. Push any commit to `main`:
   ```bash
   git add .
   git commit -m "deploy"
   git push origin main
   ```
3. Watch **Actions** tab — should go green in ~2 minutes

---

## The three deploy paths

There are three ways code reaches the server, and they do **not** do the same things.
Knowing which one you just used explains most "it worked locally" failures.

| | `deploy.yml` (push to `main`) | `.cpanel.yml` (**Deploy HEAD Commit** button) | By hand over SSH |
|---|---|---|---|
| Runs tests first | yes | no | no |
| Builds `public/build` | yes, in Actions, then rsyncs it | **no** — no Node.js on the server | only if you build and upload it yourself |
| `composer install --no-dev` | yes | yes | yours to run |
| `migrate --force` | yes | yes | yours to run |
| `optimize:clear` before re-caching | yes | yes | yours to run |
| `storage:link` | yes | yes | yours to run |
| Maintenance mode (`down`/`up`) | yes | no | no |
| Syncs `public_html` | yes | **no** | **no** |

**Use `deploy.yml` — push to `main` — for everything normal.** It is the only path that
builds frontend assets and the only one that updates `public_html`.

**The cPanel button is a PHP-only fallback.** Because it cannot build assets and does not
sync `public_html`, deploying a commit that touched anything under `resources/js` leaves
the server running new PHP against the *previous* build. The Vite manifest and the asset
filenames disagree, and the site renders blank. Recover by re-running the Actions
workflow (**Actions → Deploy → Run workflow**), which rebuilds and re-syncs.

Safe uses for the button: migrations, config changes, backend-only fixes — and only when
Actions is unavailable.

---

## Shared Hosting Limitations

### `ffprobe` / audio duration extraction

`shell_exec` and `ffprobe` are **often disabled** on shared hosting. To check:

```bash
php -r "echo shell_exec('which ffprobe');"
```

If blank, duration extraction silently returns `null` — the app still works, songs just show `—` for duration instead of a timestamp. Run the backfill if you later gain access:

```bash
php artisan media:backfill-durations
```

If you need durations and `shell_exec` is blocked, contact Namecheap support to enable it, or upgrade to a VPS.

### The realtime transport is an ordinary poll

Browsers watch a station through `GET /api/live`, polled every few seconds. Nothing is
held open, so Apache's 60–300 second request timeout is irrelevant and a room full of
listeners cannot pin a PHP worker each — which matters here, because the transmitter's
own API shares that worker pool.

### Cron is required

**Set up the scheduler before you go live.** In **cPanel → Cron Jobs**, run this every
minute:

```bash
php /home/yourusername/fmplaylist/artisan schedule:run
```

It drives everything that is not part of a request:

| Scheduled | Why it matters |
|---|---|
| `queue:work --stop-when-empty` | Audio durations are extracted off the request. Without cron, an upload's duration stays `null` until you run `media:backfill-durations` by hand. |
| `media:backfill-durations` | Catches anything the job missed. |
| Stale-command and delete-request clean-up | Used to run inside the Pi's 30-second heartbeat. |

### Queue workers

Shared hosting won't keep a worker alive, so `QUEUE_CONNECTION=database` plus the
scheduled `queue:work --stop-when-empty` above stands in for one: each cron tick drains
whatever is waiting and exits. Jobs run within a minute of being queued rather than
instantly, which is fine for everything currently queued.

---

## Maintenance

### Manual redeploy without a code change

```bash
cd ~/fmplaylist
git pull origin main
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### View logs

```bash
tail -f ~/fmplaylist/storage/logs/laravel.log
```

### Clear caches

```bash
cd ~/fmplaylist
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Backfill audio durations (if ffprobe becomes available)

```bash
cd ~/fmplaylist
php artisan media:backfill-durations
```

### Update Pi source files

The Pi downloads source files from `/pi/*.` These are served directly from `PiFmRds/src/` and update automatically when you deploy — no extra step needed.

---

## Checklist

- [ ] PHP 8.4.1+ set in MultiPHP Manager
- [ ] MySQL database + user created
- [ ] SSH key generated in cPanel → added to GitHub Deploy Keys
- [ ] Repo cloned via cPanel Git Version Control
- [ ] Document root decided — either `fmplaylist/public`, or left at `public_html` and synced by CI
- [ ] `.env` configured (`APP_KEY`, DB, `SESSION_SECURE_COOKIE=true`)
- [ ] `php artisan storage:link` run
- [ ] `public/.user.ini` upload limits set
- [ ] Storage + cache directories writable (`chmod 755`)
- [ ] All 6 GitHub Secrets added (`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KEY`, `DEPLOY_PASSPHRASE`, `DEPLOY_PORT`, `DEPLOY_PATH`)
- [ ] First push to `main` — Actions goes green
- [ ] `/login` works, `/admin/tokens` accessible
- [ ] Pi token generated and installed on Pi
