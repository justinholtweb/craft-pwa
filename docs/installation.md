---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and getting from a bare Craft site to an app a browser will offer to install.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- `ext-openssl`, used to sign and encrypt push messages
- GD or Imagick, used to generate the icons from a PNG, JPEG or WebP source. SVG is not accepted
  as an icon source.
- HTTPS in production. A service worker will not register on plain `http://` anywhere except
  `localhost`, and nothing the plugin does can change that.

## Install

```sh
composer require justinholtweb/craft-pwa
php craft plugin/install pwa
```

Or find **PWA** in the Craft Plugin Store and install it from there.

## What works straight away

Installing PWA turns it on. Every site on the install gets a manifest derived from the site's own
name and URL, a service worker at the web root, and the tags that point the browser at both,
injected into `<head>` on every front-end page. Reload the front end and the manifest and worker
are already being served.

The one thing it cannot guess is your icon, and a browser will not offer to install an app without
one.

## Your first installable app

1. Go to **PWA → Manifest**. With more than one site, pick the site first.
2. Choose an **Icon source**: one square PNG, JPEG or WebP image, 512×512 or larger. SVG is not
   accepted: export a PNG of the artwork instead.
3. Check the **Name** and **Short name**. The short name sits under the home-screen icon, and
   Android truncates it past twelve characters.
4. Set the **Theme colour** and **Background colour**. The background colour is what the phone
   shows while the app starts, so it should match your page background.
5. Save. The icon set is generated on save: twelve launcher sizes, a maskable pair, an Apple touch
   icon, favicons and, unless you turn them off, twenty-four iOS splash screens.
6. Go to **PWA → Preflight** and run it.

Preflight fetches the running site over HTTP the way a browser would. If all six blocking checks
pass, Chrome and Edge will offer to install the site. If one fails, the report says which, and
what to do about it. See [Preflight](preflight) for the full list.

## Put preflight in your deploy

```sh
php craft pwa/preflight/run
```

It exits non-zero when the site is not installable, so a deploy that breaks the manifest fails the
pipeline instead of going unnoticed for weeks. Add `--strict` to fail on warnings as well.

If your deploy rebuilds the web root, regenerate the icons as part of it:

```sh
php craft pwa/icons/generate --force
```

Icons are written to `web/pwa/` when the web root is writable, and to `storage/` with a serving
route when it is not. Either way they are derived files, and a fresh web root will not have them.

From the command line the web root is only known if Craft is told where it is. If the command
stops with "The web root could not be resolved", set `CRAFT_WEB_ROOT` (or define the `@webroot`
alias) to the site's public directory, so the icons land where the site serves them from.

## Editions

Lite is free and is a complete installable PWA. Pro is a one-off $79 with a $59/year renewal, and
adds what a site grows into once the app exists.

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$79**, $59/year renewal |
| Per-site manifests, served live | ✅ | ✅ |
| Icon set from one image, incl. maskable and iOS splash screens | ✅ | ✅ |
| Generated service worker with the default flight plan | ✅ | ✅ |
| Offline fallback page, precached | ✅ | ✅ |
| Install prompt, with iOS instructions | ✅ | ✅ |
| Preflight, all checks, in the CP and on the console | ✅ | ✅ |
| Flight deck: installs, launches, offline hits | ✅ | ✅ |
| `pwa` Twig variable and `window.pwa` runtime | ✅ | ✅ |
| **Web push**: subscribers, broadcasts, delivery records, notify on publish | — | ✅ |
| **Custom flight plan**: your own caching rules per route | — | ✅ |
| **Scheduled preflight** with an emailed report | — | ✅ |

Nothing about whether the app installs and works offline is held back for Pro.

## Uninstalling

Uninstalling stops the manifest and the worker being served. It cannot reach into browsers that
already registered the worker: a browser that finds nothing at the worker's URL keeps the copy it
has, and keeps applying its flight plan, until the visitor clears the site's data. With the
default flight plan that is mostly harmless, because pages come from the network first, but it is
worth knowing before you remove PWA from a site that had real traffic.
