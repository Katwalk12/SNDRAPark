#!/usr/bin/env node
/**
 * SNDRA Park driver — reads one command per line on stdin, drives the app
 * in a real Chrome via Playwright, and keeps the session alive between
 * commands. Agent tooling: prints `ok`/`ERR` per line so output is greppable.
 *
 * Usage:  node .claude/skills/run-sndrapark/driver.mjs <<'EOF'
 *         login
 *         shot dashboard
 *         quit
 *         EOF
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createInterface } from 'node:readline';

const HERE = dirname(fileURLToPath(import.meta.url));
const BASE = process.env.SP_BASE || 'http://localhost/sndraPark';
const SHOTS = process.env.SP_SHOTS || resolve(HERE, 'shots');
const USER = process.env.SP_USER || 'smoke.test@sndrapark.local';
const PASS = process.env.SP_PASS || 'Qx7#vRm2!pLz';
const ADMIN_USER = process.env.SP_ADMIN_USER || 'smoke.admin@sndrapark.local';
const ADMIN_PASS = process.env.SP_ADMIN_PASS || 'Qx7#vRm2!pLz';
const BOOTH_PIN = process.env.SP_BOOTH_PIN || '2468';

mkdirSync(SHOTS, { recursive: true });

// headless=new renders the Tailwind/CSS-heavy pages correctly; the app has no
// WebGL, so no GPU flags are needed.
const browser = await chromium.launch({
  channel: 'chrome',
  headless: process.env.SP_HEADED !== '1',
});
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

// Surface page-side failures — the frontend swallows most fetch errors into
// toast popups that a screenshot may not capture.
const logs = [];
page.on('console', (m) => { if (m.type() === 'error') logs.push('[console] ' + m.text()); });
page.on('pageerror', (e) => logs.push('[pageerror] ' + e.message));
page.on('requestfailed', (r) => logs.push('[reqfail] ' + r.url() + ' ' + (r.failure()?.errorText || '')));
// Bare '401 (Unauthorized)' console lines don't name the URL, so log the
// response itself — that's how you find which endpoint is actually refusing.
page.on('response', (r) => { if (r.status() >= 400) logs.push('[http ' + r.status() + '] ' + r.url()); });

const url = (p) => (/^https?:/.test(p) ? p : BASE + (p.startsWith('/') ? p : '/' + p));
const out = (...a) => console.log(...a);

async function settle() {
  // The dashboards fan out several fetches after DOMContentLoaded; networkidle
  // is the only reliable "page is actually populated" signal here.
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
}

const commands = {
  async goto([p]) { await page.goto(url(p), { waitUntil: 'domcontentloaded' }); await settle(); out('ok', page.url()); },

  async login([email = USER, password = PASS]) {
    await page.goto(url('/frontend/pages/login.html'), { waitUntil: 'domcontentloaded' });
    await page.fill('#login-email', email);
    await page.fill('#login-password', password);
    await Promise.all([
      page.waitForURL(/user-dashboard|admin-dashboard|parking-booth/, { timeout: 15000 }),
      page.click('#login-form button[type="submit"]'),
    ]);
    await settle();
    out('ok logged-in', page.url());
  },

  async loginadmin([email = ADMIN_USER, password = ADMIN_PASS]) {
    await page.goto(url('/frontend/pages/admin-login.html'), { waitUntil: 'domcontentloaded' });
    await page.fill('#admin-email', email);
    await page.fill('#admin-password', password);
    await Promise.all([
      page.waitForURL(/admin-dashboard/, { timeout: 15000 }),
      page.click('#admin-login-form button[type="submit"]'),
    ]);
    await settle();
    out('ok admin', page.url());
  },

  // Booth auth is PIN-only: the backend scans every active teller row, so the
  // PIN alone identifies the teller. No email field exists on this form.
  async loginbooth([pin = BOOTH_PIN]) {
    await page.goto(url('/frontend/pages/booth-login.html'), { waitUntil: 'domcontentloaded' });
    await page.fill('#booth-pin', pin);
    await Promise.all([
      page.waitForURL(/parking-booth/, { timeout: 15000 }),
      page.click('#booth-login-form button[type="submit"]'),
    ]);
    await settle();
    out('ok booth', page.url());
  },

  async shot([name = 'shot']) {
    const f = resolve(SHOTS, name.replace(/[^\w.-]/g, '_') + '.png');
    await page.screenshot({ path: f, fullPage: true });
    out('ok', f);
  },

  async click([...sel]) { await page.click(sel.join(' '), { timeout: 10000 }); await settle(); out('ok'); },
  async fill([sel, ...v]) { await page.fill(sel, v.join(' ')); out('ok'); },
  async text([...sel]) { out('ok', (await page.textContent(sel.join(' ')))?.trim().slice(0, 2000)); },
  async count([...sel]) { out('ok', await page.locator(sel.join(' ')).count()); },
  async url() { out('ok', page.url()); },
  async wait([ms = '1000']) { await page.waitForTimeout(Number(ms)); out('ok'); },
  async evaljs([...js]) { out('ok', JSON.stringify(await page.evaluate(js.join(' ')))); },

  // Runs fetch *inside the page*, so it reuses the browser session cookie —
  // this is how you exercise authenticated API routes as the logged-in user.
  async api([method, path, ...body]) {
    const r = await page.evaluate(async ([m, u, b]) => {
      const res = await fetch(u, {
        method: m,
        credentials: 'same-origin',
        headers: b ? { 'Content-Type': 'application/json' } : {},
        body: b || undefined,
      });
      return { status: res.status, body: (await res.text()).slice(0, 1500) };
    }, [method.toUpperCase(), url(path), body.join(' ') || null]);
    out('ok', r.status, r.body);
  },

  async logs() { out('ok', logs.length ? logs.join('\n') : '(none)'); },
  async quit() { await browser.close(); process.exit(0); },
};

for await (const line of createInterface({ input: process.stdin })) {
  const s = line.trim();
  if (!s || s.startsWith('#')) continue;
  const [cmd, ...args] = s.split(/\s+/);
  const fn = commands[cmd];
  if (!fn) { out('ERR unknown command:', cmd, '| known:', Object.keys(commands).join(' ')); continue; }
  try { await fn(args); } catch (e) { out('ERR', cmd, '-', String(e.message).split('\n')[0]); }
}
await browser.close();
