---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: No install offer, a manifest that 404s, changes that will not show up, and what to check first.
---

Start with **PWA → Preflight**. It fetches the site the way a browser does, and nearly every
problem below shows up there as a failed check with its fix written next to it. The sections that
follow are for what preflight cannot see, or what it found and you want explained.

## The browser never offers to install

Run preflight and look at the six blocking checks first: secure origin, manifest link, manifest
served, display mode, icons, service worker served. Fail any one and no browser will offer an
install, whatever else is right.

If all six pass and there is still no offer:

- **You are on iOS or desktop Safari.** Safari has no install event to intercept. iOS visitors see
  the Share → Add to Home Screen instructions instead, if **Show iOS visitors how** is on.
- **It is already installed.** Chrome does not offer to install an app that is already on the
  device. Check `chrome://apps`, or the device's app list.
- **You dismissed the prompt.** PWA snoozes it for 30 days after a dismissal, and remembers that
  in the browser's local storage. Clear the site's data, or test in a private window.
- **The delay has not passed.** The prompt waits **Delay** seconds (10 by default) after the
  browser offers an install.
- **Chrome's own heuristics.** Chrome sometimes wants a little engagement with a site before it
  offers an install. Click around for a minute.

## The manifest or `sw.js` returns 404

Both are served by Craft through a URL rule, so the request has to reach Craft. A web server rule
that answers static-looking paths itself (`location ~* \.(js|json)$` in nginx is the usual one)
will 404 before Craft ever sees the request. Exclude `/sw.js` and `/manifest.webmanifest` from it,
or let those rules fall through to `index.php` when the file does not exist.

Also check that a real file of the same name is not sitting in your web root from an earlier PWA
setup. A file on disk wins over Craft's route.

`php craft pwa/manifest/dump` prints what Craft would serve, without HTTP in the way, which tells
you whether the problem is the plugin or the path to it.

## The worker is served with the wrong content type

Preflight's **Service worker served** check fails if `sw.js` does not come back as JavaScript.
Browsers refuse to register a worker served as `text/html`, which is what you get when a
catch-all rule returns an error page with a 200. Same cause as the 404 above.

## Icons are grey squares, or missing

- **Listed but not reachable.** Preflight checks that the 192px and 512px icons are actually
  fetchable, not just listed. If they 404, the web root was probably rebuilt by a deploy. Add
  `php craft pwa/icons/generate --force` to the deploy script.
- **The source is not a PNG, JPEG or WebP.** SVG and other formats are refused as an icon source;
  export a PNG of the artwork. The plugin log (`storage/logs/pwa.log`) says so when this happens.
- **`pwa/icons/generate` says the web root could not be resolved.** Set `CRAFT_WEB_ROOT` (or the
  `@webroot` alias) for console commands, so they write where the site serves from.
- **Edges cut off on Android.** Android crops icons to its own shape. PWA insets the maskable icon
  to the safe zone automatically; if your logo still loses its edges, upload pre-padded artwork as
  the **Maskable icon**.

## Changes are not showing up

A registered service worker keeps serving what it stored, which is the whole point of it and also
why template edits seem to vanish in development.

- Pages use **network first**, so they should be fresh whenever the network answers inside the
  timeout. Scripts, styles and images use **stale while revalidate**: the first load after a
  change shows the old copy, the second shows the new one.
- **Invalidate caches** on the flight deck throws away every cache the installed copies hold, on
  their next activation. It works where `allowAdminChanges` is off, because the counter it bumps is
  kept in the database rather than in project config.
- In development, open devtools → Application → Service workers and tick **Update on reload**, or
  **Unregister** the worker there. A hard refresh bypasses the worker for one request only.

Preflight warns when dev mode is on for exactly this reason.

## The installed app opens links in a browser tab

Those links are outside the manifest's **scope**. On a site mounted in a subdirectory (`/de/`, say)
the scope is that subdirectory, and links to the rest of the domain open a tab. That is how
browsers treat scope, and it is what keeps one site's app from swallowing another's pages. If you
want one app across several sites, give them the same scope.

## Something in a frame or embed gets the worker

Injection is skipped for control panel requests, Ajax, live preview, non-HTML responses,
`actions/…` URLs, and any response sent with `Content-Security-Policy: sandbox`. For anything else
you want left alone, add its path to **Settings → General → Never inject into**.

## Push

[Web push](push#when-nothing-arrives) has its own checklist. The short version: run preflight
(it checks the keypair), check **Notification.permission** in the browser, then read the delivery
record. A `401` or `403` from the push service means the VAPID signature was rejected, which points
at a mismatched keypair.

## Scheduled preflight never emails

- It is a Pro feature.
- **Only when something fails** is on by default. A passing site sends nothing.
- The schedule is triggered by control panel traffic. A site nobody logs into will not run it
  unless you add `php craft pwa/preflight/due` to cron. It is safe to call hourly.
- Check Craft's own email settings with **Settings → Email → Test**.

## Getting help

Email [justin@justinholt.com](mailto:justin@justinholt.com) with the output of
`php craft pwa/preflight/run` and, if it is relevant, the lines from `storage/logs/pwa.log`.
