/**
 * SNDRA Park service worker.
 *
 * Drivers open this on a phone, in a car park, often on one bar of signal.
 * The shell is cached so the dashboard still opens, and the last successful
 * reservation response is kept so the barcode is readable with no connection
 * at all -- which is exactly the moment it is needed at the gate.
 *
 * Everything else is network-first: slot availability that is minutes stale
 * would send somebody to a slot that is already taken.
 */

const VERSION = "sndra-park-v1";
const SHELL_CACHE = `${VERSION}-shell`;
const DATA_CACHE = `${VERSION}-data`;

const SHELL_ASSETS = [
  "./frontend/pages/user-dashboard.html",
  "./frontend/pages/login.html",
  "./frontend/css/user-dashboard.css",
  "./frontend/css/login.css",
  "./frontend/js/user-dashboard.js",
  "./frontend/js/auth.js",
  "./assets/js/runtime-config.js",
  "./assets/images/favicon.png",
  "./assets/images/brand-mark.png",
  "./manifest.webmanifest"
];

// The one response worth reading offline: it carries the active barcode.
const OFFLINE_READABLE = /\/backend\/user\/get_reservations\.php/;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      // addAll fails the whole install if any single file 404s, so each asset
      // is added on its own and a missing one is simply skipped.
      .then((cache) => Promise.all(
        SHELL_ASSETS.map((asset) => cache.add(asset).catch(() => null))
      ))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => !key.startsWith(VERSION)).map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;

  if (request.method !== "GET") {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  if (OFFLINE_READABLE.test(url.pathname)) {
    event.respondWith(networkFirst(request, DATA_CACHE));
    return;
  }

  // Never serve a stale answer for anything else the backend says.
  if (url.pathname.includes("/backend/")) {
    return;
  }

  event.respondWith(networkFirst(request, SHELL_CACHE));
});

async function networkFirst(request, cacheName) {
  try {
    const response = await fetch(request);

    if (response && response.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, response.clone());
    }

    return response;
  } catch (error) {
    const cached = await caches.match(request);

    if (cached) {
      return cached;
    }

    throw error;
  }
}
