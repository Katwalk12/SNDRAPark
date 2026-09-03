---
name: run-sndrapark
description: Build, run, screenshot, and drive the SNDRA Park parking-reservation app (PHP/XAMPP + vanilla-JS frontend). Use when asked to run, start, launch, smoke-test, screenshot, or verify a change in the app, or to log in as a member, admin, or booth teller and exercise the reservation flow.
---

# Run SNDRA Park

PHP 8.2 app served by XAMPP Apache out of `c:\xampp\htdocs\sndraPark`, with a
vanilla-JS frontend (no bundler, no `package.json` at the repo root). There is
no build step and no test suite — you verify changes by **driving the running
app**.

The driver is `.claude/skills/run-sndrapark/driver.mjs`: a stdin-command REPL
that steers real Chrome through Playwright, keeps one session alive across
commands, and can call the app's APIs *from inside the page* so they inherit
the session cookie. All paths below are relative to the repo root.

## Prerequisites

Apache and MySQL must already be running (XAMPP Control Panel, or
`c:\xampp\apache_start.bat` / `c:\xampp\mysql_start.bat`). Confirm:

```bash
netstat -ano | grep LISTENING | grep -E ":80 |:3306 "
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/sndraPark/frontend/pages/index.html   # 200
```

One-time driver install. `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` matters — the
driver uses the **system Chrome** (`channel: 'chrome'`), so skipping the
bundled-browser download saves a ~150MB fetch:

```bash
cd .claude/skills/run-sndrapark && PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install
```

## Setup: test accounts

`seed.php` creates three clearly-namespaced accounts. It is idempotent and
keyed on identifier, so it never touches the real accounts already in this
database:

```bash
/c/xampp/php/php .claude/skills/run-sndrapark/seed.php
```

| Role | Credentials |
|---|---|
| member | `smoke.test@sndrapark.local` / `Qx7#vRm2!pLz` |
| admin | `smoke.admin@sndrapark.local` / `Qx7#vRm2!pLz` |
| booth teller | PIN `2468` |

If the member account is missing, `seed.php` prints the exact `curl` that
registers it (registration must go through the API so the vehicle row is
created too).

## Run (agent path)

Pipe commands to the driver, one per line. Every line prints `ok …` or `ERR …`:

```bash
node .claude/skills/run-sndrapark/driver.mjs <<'EOF'
login
shot user-dashboard
api GET /backend/user/get_reservations.php
logs
quit
EOF
```

Screenshots land in `.claude/skills/run-sndrapark/shots/<name>.png` (full-page).
**Open the PNG and look at it** — the frontend reports most failures as toasts,
not exceptions.

| Command | Effect |
|---|---|
| `login [email] [pass]` | member login → waits for `user-dashboard.html` |
| `loginadmin [email] [pass]` | admin login → waits for `admin-dashboard.html` |
| `loginbooth [pin]` | booth PIN login → waits for `parking-booth.html` |
| `goto <path>` | navigate (path relative to app base) |
| `shot <name>` | full-page screenshot |
| `click <sel>` / `fill <sel> <val>` | interact |
| `text <sel>` / `count <sel>` | read DOM |
| `evaljs <js>` | evaluate in page, prints JSON |
| `api <METHOD> <path> [json]` | `fetch` **inside the page** — inherits session cookie |
| `logs` | console errors, page errors, and every HTTP >=400 with its URL |
| `wait <ms>` / `url` / `quit` | |

Overridable via env: `SP_BASE`, `SP_USER`, `SP_PASS`, `SP_ADMIN_USER`,
`SP_ADMIN_PASS`, `SP_BOOTH_PIN`, `SP_SHOTS`, `SP_HEADED=1` (visible browser).

### The full cross-role flow

This is the app's core loop — member reserves, booth scans the barcode. Both
halves verified end to end:

```bash
node .claude/skills/run-sndrapark/driver.mjs <<'EOF'
login
click button.slot-card.available
fill #reservation-time-in 23:30
click #reservation-form button[type="submit"]
wait 2500
shot reservation-confirmed
api GET /backend/user/get_reservations.php
quit
EOF
```

Take the `barcode` from that response, then scan it as the booth:

```bash
node .claude/skills/run-sndrapark/driver.mjs <<'EOF'
loginbooth
api POST /backend/parking-booth/scan.php {"action":"scan","barcode":"SP-LG-L2-02VH0DE1"}
quit
EOF
```

The reservation flips `Reserved` to `Parked` and `actual_time_in` is recorded.

### Cleaning up after a run

A smoke run leaves a reservation behind and marks its slot `Occupied`. Reset
just the smoke data (real reservations and slots untouched):

```bash
/c/xampp/php/php .claude/skills/run-sndrapark/seed.php --clean
```

## Direct invocation (no browser)

For API-only work, `curl` with a cookie jar. `login` and `register` are the
only CSRF-exempt routes, so this works without a token:

```bash
curl -s -c /tmp/sp.jar -X POST http://localhost/sndraPark/backend/api/v1/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"smoke.test@sndrapark.local","password":"Qx7#vRm2!pLz"}'
curl -s -b /tmp/sp.jar http://localhost/sndraPark/backend/api/v1/session
```

Query the database straight through PHP's PDO (no `mysql` client is on PATH):

```bash
/c/xampp/php/php -r 'foreach((new PDO("mysql:host=127.0.0.1;dbname=sndrapark_db","root",""))->query("SELECT id,parking_slot,status FROM reservations ORDER BY id DESC LIMIT 5", PDO::FETCH_ASSOC) as $r) echo json_encode($r)."\n";'
```

## Run (human path)

Start Apache + MySQL in the XAMPP Control Panel and open
<http://localhost/sndraPark/frontend/pages/index.html>. That is the entry
point — **not** the site root (see Gotchas).

## Gotchas

- **The repo root 403s.** `.htaccess` sets `DirectoryIndex index.php` and
  `Options -Indexes`, but there is no `index.php` at the root, so
  `http://localhost/sndraPark/` returns 403 Forbidden. Always enter at
  `/frontend/pages/index.html`. The directory is `sndraPark` but Windows paths
  are case-insensitive, so `/sndrapark/` also resolves.
- **The registration API takes camelCase, the database uses snake_case.**
  Posting `vehicle_type`/`plate_number` fails with
  `Missing required fields: vehicleType, plateNumber` (422). Required:
  `email`, `password`, `vehicleType`, `plateNumber`.
- **`GET /backend/api/v1/reservations` always returns `[]` for reservations
  made through the UI.** `Reservation::findByUserId` does
  `INNER JOIN parking_slots p ON p.id = r.parking_slot_id`, but
  `submit_reservation.php` stores the slot as *text* (`parking_slot = 'L2'`)
  and leaves `parking_slot_id` NULL — so the join drops every row. The
  dashboard uses `/backend/user/get_reservations.php`; so should you. Confirm
  a reservation exists by querying the table directly, not via the v1 route.
- **Rate limiting silently never blocks on the v1 API.**
  `backend/api/v1/index.php` never requires `backend/config/app.php` (the only
  place that calls `date_default_timezone_set('Asia/Manila')`), so it runs on
  `php.ini`'s `Europe/Berlin` while MySQL is on system time — a 6-hour skew.
  `rate_limit_attempts.window_end` is written in the past, so every window
  reads as expired. 14 recorded failed logins never produced the documented
  429 (the limit is 5 per 15 min). Don't rely on the limiter firing, and don't
  trust `window_start`/`window_end` timestamps written by that path.
- **`build-tailwind.bat` is broken — do not run it.** `tailwind.input.css`
  `@apply`s a palette (`bg-obsidian`, `text-mist`, `bg-ember`,
  `bg-premium-grid`) that `tailwind.config.js` never defines, so the build dies
  with "The `bg-obsidian` class does not exist". The committed
  `assets/css/tailwind.css` is stale but working, and only `index.html` loads
  it — every other page ships hand-written CSS from `frontend/css/`. Editing
  Tailwind classes will not change anything until that palette mismatch is
  fixed.
- **The landing page 404s on `assets/assets/images/carousel*.jpg` and that is
  cosmetic.** The stale `tailwind.css` consumes `var(--slide-image)`, and
  Chrome resolves the relative `url()` in the substituted custom property
  against *that* stylesheet (`/sndraPark/assets/css/`), doubling the `assets/`
  segment. The `<img>` fallback resolves correctly and renders, so the carousel
  still looks right — ignore these 404s in `logs`.
- **A 401 on `backend/parking-booth/session.php` before login is normal.**
  `booth-login.html` probes the session on load while unauthenticated. After
  `loginbooth` the same endpoint returns 200.
- **Booth login is PIN-only.** There is no username field; the backend
  `password_verify`s the submitted PIN against *every* active row in
  `booth_teller_accounts`. A duplicate PIN would authenticate as whichever
  teller matches first, so keep the seeded `2468` unique.
- **Sessions expire after 1800s (30 min).** A driver run left idle past that
  will start returning 401s mid-script.

## Troubleshooting

- `CssSyntaxError: … bg-obsidian class does not exist` — you ran
  `build-tailwind.bat`. Don't; see Gotchas. The app does not need it.
- `Cannot delete or update a parent row … fk_payments_reservation` — deleting
  reservations requires clearing `payments` (and `parking_transactions`) first.
  `seed.php --clean` already does this in the right order.
- `ERR login - page.waitForURL: Timeout` — the credentials were rejected and
  the page stayed put. Re-run `seed.php` (it repairs the member password and
  clears `failed_login_attempts` / `login_locked_until`), then retry.
- Driver exits with `browserType.launch: Executable doesn't exist` — the
  `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` install left no bundled browser and
  system Chrome wasn't found. Chrome is expected at
  `C:\Program Files\Google\Chrome\Application\chrome.exe`.
- API calls return `{"authenticated":false}` — `curl` needs `-b`/`-c` on a
  cookie jar; the driver's `api` command has the session automatically because
  it runs `fetch` inside the page.
