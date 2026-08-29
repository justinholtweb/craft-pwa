/**
 * PWA — generated service worker.
 *
 * This file is rendered by the plugin, not edited: everything variable about it arrives in the
 * CONFIG object below, which is written from the flight plan in the control panel. If you want it
 * to behave differently, change the flight plan.
 *
 * Three rules govern everything here, and they are the ones that keep a service worker from
 * turning into a support ticket:
 *
 *   1. Only GET requests to this origin are ever touched. Anything else — a form post, a
 *      cross-origin font, a range request for a video — goes straight to the network untouched.
 *   2. Nothing is served from a cache belonging to an older version. Caches are named with the
 *      version, and activation deletes every name that is not current, so a deploy cannot leave a
 *      visitor on last week's shell.
 *   3. A cached response is stamped with the time it was stored, and refuses to be served past
 *      its lifetime. A cache with no expiry is not a cache, it is a copy.
 */

const CONFIG = /*__PWA_CONFIG__*/ {};

const VERSION = CONFIG.version || 1;
const PREFIX = 'pwa';
const STAMP_HEADER = 'x-pwa-cached';

const CACHES = {
  precache: `${PREFIX}-precache-v${VERSION}`,
  pages: `${PREFIX}-pages-v${VERSION}`,
  assets: `${PREFIX}-assets-v${VERSION}`,
  images: `${PREFIX}-images-v${VERSION}`,
};

const LIMITS = CONFIG.limits || { pages: 60, assets: 120, images: 80 };
const LIFETIME = (CONFIG.lifetimeDays || 30) * 86400 * 1000;

/* ------------------------------------------------------------------------- install */

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const urls = (CONFIG.precache || []).filter(Boolean);

      if (urls.length) {
        const cache = await caches.open(CACHES.precache);

        // One at a time rather than cache.addAll(): addAll is atomic, so a single 404 in the
        // precache list throws away the entire install and the worker never activates. A missing
        // shell asset should degrade, not brick.
        await Promise.all(
          urls.map(async (url) => {
            try {
              const response = await fetch(url, { cache: 'reload', credentials: 'same-origin' });

              if (response.ok) {
                await cache.put(url, stamp(response));
              }
            } catch (e) {
              /* offline at install time, or the URL is gone. Neither is fatal. */
            }
          })
        );
      }

      if (CONFIG.skipWaiting) {
        await self.skipWaiting();
      }
    })()
  );
});

/* ------------------------------------------------------------------------ activate */

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keep = Object.values(CACHES);
      const names = await caches.keys();

      await Promise.all(
        names
          .filter((name) => name.startsWith(`${PREFIX}-`) && !keep.includes(name))
          .map((name) => caches.delete(name))
      );

      // Lets a network-first navigation start fetching before the fetch handler runs, which is
      // most of the latency a service worker otherwise adds to a first navigation.
      if (self.registration.navigationPreload) {
        try {
          await self.registration.navigationPreload.enable();
        } catch (e) {
          /* not supported here */
        }
      }

      await self.clients.claim();
    })()
  );
});

/* --------------------------------------------------------------------------- fetch */

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) return;
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  // Range requests are served in pieces; a cache that answers one with a whole file breaks media
  // playback in a way that is very hard to trace back to here.
  if (request.headers.has('range')) return;

  const route = matchRoute(url, request);

  if (!route || route.strategy === 'networkOnly') return;

  event.respondWith(handle(event, request, route));
});

function matchRoute(url, request) {
  const path = url.pathname;
  const destination = request.destination || '';
  const routes = CONFIG.routes || [];

  for (const route of routes) {
    const patterns = route.patterns || [];
    let hit = false;

    if (route.match === 'path') {
      hit = patterns.some((pattern) => globMatches(pattern, path));
    } else if (route.match === 'extension') {
      const ext = (path.split('.').pop() || '').toLowerCase();
      hit = path.includes('.') && patterns.includes(ext);
    } else if (route.match === 'destination') {
      hit = patterns.includes(destination.toLowerCase());
    } else if (route.match === 'host') {
      hit = patterns.some((pattern) => globMatches(pattern, url.hostname));
    }

    if (hit) return route;
  }

  return null;
}

/**
 * Glob matching, kept deliberately identical to the PHP side.
 *
 * `*` spans anything including slashes, `?` is one character, everything else is literal. The
 * control panel's route tester runs the PHP twin of this function, and the pair is covered by one
 * shared table of cases — two implementations that drift are worse than one that is wrong.
 */
function globMatches(pattern, subject) {
  const escaped = pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp('^' + escaped.replace(/\*/g, '.*').replace(/\?/g, '.') + '$', 'i');

  return regex.test(subject);
}

async function handle(event, request, route) {
  const cacheName = CACHES[route.cache] || CACHES.pages;

  try {
    switch (route.strategy) {
      case 'cacheFirst':
        return await cacheFirst(request, cacheName, route);
      case 'staleWhileRevalidate':
        return await staleWhileRevalidate(request, cacheName, route);
      case 'cacheOnly':
        return (await caches.match(request)) || (await fallback(request, route));
      case 'networkFirst':
      default:
        return await networkFirst(event, request, cacheName, route);
    }
  } catch (e) {
    return fallback(request, route);
  }
}

async function networkFirst(event, request, cacheName, route) {
  const timeout = (route.timeout || 0) * 1000;

  try {
    const preload = event.preloadResponse ? await event.preloadResponse : null;
    const response = preload || (await withTimeout(fetch(request), timeout));

    if (response && response.ok) {
      await put(cacheName, request, response.clone(), route);
    }

    if (response) return response;
  } catch (e) {
    /* fall through to the cache */
  }

  const cached = await fresh(request);

  return cached || fallback(request, route);
}

async function cacheFirst(request, cacheName, route) {
  const cached = await fresh(request);

  if (cached) return cached;

  const response = await fetch(request);

  if (response && response.ok) {
    await put(cacheName, request, response.clone(), route);
  }

  return response;
}

async function staleWhileRevalidate(request, cacheName, route) {
  const cached = await caches.match(request);

  const network = fetch(request)
    .then(async (response) => {
      if (response && response.ok) {
        await put(cacheName, request, response.clone(), route);
      }
      return response;
    })
    .catch(() => null);

  // The stale copy is served even past its lifetime here, because the refetch is already in
  // flight: the visitor gets something immediately and the next visit gets the new one.
  return cached || (await network) || fallback(request, route);
}

/** A cached response, but only if it is still within its lifetime. */
async function fresh(request) {
  const cached = await caches.match(request);

  if (!cached) return null;
  if (!LIFETIME) return cached;

  const stamped = cached.headers.get(STAMP_HEADER);

  if (stamped && Date.now() - Number(stamped) > LIFETIME) return null;

  return cached;
}

async function put(cacheName, request, response, route) {
  // A partial or opaque response cached as if it were whole is the classic way to serve a broken
  // page from a cache that looks healthy.
  if (!response || response.status !== 200 || response.type === 'opaque') return;

  // The pages bucket holds pages — things a navigation asked for. Without this line, any
  // background poll that falls through to the catch-all rule lands in it: a JSON endpoint fetched
  // every few seconds with a cache-busting query string will fill the bucket, and the eviction
  // ceiling then throws away the real pages to make room for URLs nobody will ever request twice.
  if (route.cache === 'pages' && request.mode !== 'navigate') return;

  const cache = await caches.open(cacheName);
  await cache.put(request, stamp(response));
  await trim(cache, LIMITS[route.cache]);
}

/** Records when a response was stored, since the Cache API keeps no metadata of its own. */
function stamp(response) {
  const headers = new Headers(response.headers);
  headers.set(STAMP_HEADER, String(Date.now()));

  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

async function trim(cache, limit) {
  if (!limit) return;

  const keys = await cache.keys();

  // keys() is in insertion order, so the front of the list is the oldest thing in the bucket.
  for (let i = 0; i < keys.length - limit; i++) {
    await cache.delete(keys[i]);
  }
}

async function fallback(request, route) {
  if (route && route.fallback !== false && request.mode === 'navigate' && CONFIG.offlineUrl) {
    const offline = (await caches.match(CONFIG.offlineUrl)) || (await caches.match(new Request(CONFIG.offlineUrl)));

    if (offline) {
      report('offline', new URL(request.url).pathname);
      return offline;
    }
  }

  if (request.mode === 'navigate') {
    report('offline', new URL(request.url).pathname);

    return new Response(
      '<!doctype html><meta charset="utf-8"><title>Offline</title>' +
        '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;padding:2rem;text-align:center}</style>' +
        '<h1>You are offline</h1><p>This page has not been visited before, so there is no copy stored on this device.</p>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }

  return Response.error();
}

function withTimeout(promise, ms) {
  if (!ms) return promise;

  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('timeout')), ms);

    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error) => {
        clearTimeout(timer);
        reject(error);
      }
    );
  });
}

/* ---------------------------------------------------------------------------- push */

self.addEventListener('push', (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = { title: (event.data && event.data.text()) || '' };
  }

  const title = payload.title || CONFIG.appName || '';

  if (!title) return;

  event.waitUntil(
    self.registration.showNotification(title, {
      body: payload.body || '',
      icon: payload.icon || CONFIG.pushIcon || undefined,
      badge: payload.badge || CONFIG.pushBadge || undefined,
      tag: payload.tag || undefined,
      requireInteraction: !!payload.requireInteraction,
      data: { url: payload.url || CONFIG.startUrl || '/', campaign: payload.campaign || null },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;

  event.waitUntil(
    (async () => {
      report('notification-click', new URL(target).pathname);

      const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

      // Focusing an open tab beats opening a second one showing the same thing.
      for (const client of clientList) {
        if (client.url === target && 'focus' in client) {
          return client.focus();
        }
      }

      if (self.clients.openWindow) {
        return self.clients.openWindow(target);
      }
    })()
  );
});

self.addEventListener('pushsubscriptionchange', (event) => {
  // The push service can rotate a subscription without the page being open. Re-subscribing here
  // and telling the server is the difference between a device that keeps working and one that
  // silently stops receiving anything.
  event.waitUntil(
    (async () => {
      if (!CONFIG.vapidPublicKey || !CONFIG.subscribeUrl) return;

      try {
        const subscription = await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: base64ToUint8(CONFIG.vapidPublicKey),
        });

        await fetch(CONFIG.subscribeUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ subscription: subscription.toJSON(), previous: event.oldSubscription ? event.oldSubscription.endpoint : null }),
        });
      } catch (e) {
        /* nothing useful to do from here */
      }
    })()
  );
});

function base64ToUint8(value) {
  const padding = '='.repeat((4 - (value.length % 4)) % 4);
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = self.atob(base64);
  const output = new Uint8Array(raw.length);

  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);

  return output;
}

/* ------------------------------------------------------------------------ messages */

self.addEventListener('message', (event) => {
  const data = event.data || {};

  if (data.type === 'PWA_SKIP_WAITING') {
    self.skipWaiting();
  }

  if (data.type === 'PWA_CLEAR_CACHES') {
    event.waitUntil(
      (async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((n) => n.startsWith(`${PREFIX}-`)).map((n) => caches.delete(n)));
      })()
    );
  }
});

/** Fire-and-forget telemetry. Never awaited, never allowed to affect a response. */
function report(type, path) {
  if (!CONFIG.eventUrl) return;

  try {
    fetch(CONFIG.eventUrl, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type, path }),
    }).catch(() => {});
  } catch (e) {
    /* telemetry is never worth an error */
  }
}
