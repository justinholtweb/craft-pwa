---
title: Preflight
slug: preflight
order: 70
summary: Every check preflight makes over HTTP, which ones block an install, and how the score works.
---

# Preflight

Preflight fetches the running site over HTTP the way a browser would and grades what comes back. It
checks the *result*, not the settings that produced it — because the settings always look right
(they are what you just typed) and the manifest is still 404ing because of a rewrite rule.

Run it from **PWA → Preflight**, or `php craft pwa/preflight/run`, which exits non-zero when the
site is not installable. That exit code is the point: put it in your deploy pipeline.

## Blocking checks

Six checks decide whether a browser will offer an install at all. Fail one and nothing else
matters.

| Check | Why it blocks |
|-------|---------------|
| **Secure origin** | A service worker requires HTTPS. localhost counts; a staging site on plain http does not. There is no workaround. |
| **Manifest link in the page** | Without `<link rel="manifest">` the browser never looks. |
| **Manifest served** | The URL must return JSON. A rewrite rule for static files is the usual culprit. |
| **Display mode** | `browser` tells the browser this is a website, not an app. |
| **Icons** | 192px and 512px must be listed *and* reachable. Listed-but-404 is the common failure, and it fails silently: the app installs with a grey square. |
| **Service worker served** | It must respond 200 as JavaScript. A `.js` rewrite rule answers before Craft does. |

## The rest

**Scope covers the start URL** — a start URL outside the scope makes the manifest invalid.

**Start URL** — checked without following redirects. A start URL that 301s works, and every launch
of the installed app pays for it.

**Short name length** — over twelve characters and it is truncated under the home screen icon.

**Maskable icon** — without one, Android puts your icon in a white box.

**Worker scope** — a worker served from a subdirectory can only control that subdirectory,
whatever the manifest says, unless the response carries `Service-Worker-Allowed` — a header many
hosts strip.

**Worker caching** — a worker cached for a day cannot be updated for a day. Browsers cap this at
24 hours by spec; a CDN in front does not.

**Flight plan catch-all** — without a rule matching ordinary pages, most of the site is not handled
by the worker at all.

**Control panel excluded** — a custom flight plan that lets control panel requests be cached is
reported as a failure, not a warning.

**Offline page** and **offline page precached** — a fallback that is not precached is a fallback
that will not be there.

**Precache size** — over thirty URLs and a visitor's first page view is paying for all of them.

**Viewport meta tag** — PWA does not inject this one. It changes how every page of the site is laid
out, and that is not a plugin's decision.

**Dev mode** — a registered worker keeps serving cached responses while you edit templates. This is
the warning that saves an hour.

**VAPID keypair** and **VAPID subject** (Pro, when push is on).

## Scoring

The proportion of checks that passed, with warnings costing half a point, failures a whole one, and
blocking failures three — enough that a site the browser will not install cannot score in the
eighties. It is not a Lighthouse score and does not pretend to be one.

Reports are stored as they read on the day. An audit that re-renders itself against today's check
definitions is not a record of anything.

## Scheduled (Pro)

Runs daily or weekly at an hour you choose and emails a report — by default only when something
failed, because a weekly "all is well" gets filtered within a month and the week it does not arrive
is the week nobody notices.

The schedule is occurrence-based, not elapsed-based: a run that happens late does not push the next
one later, so 3am stays 3am. It is triggered by control panel traffic, so no cron is required —
but `php craft pwa/preflight/due` is there for sites that would rather be explicit, and is safe to
call hourly.
