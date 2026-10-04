---
title: Configuration
slug: configuration
order: 20
summary: The manifest, every setting, the config file, environment variables and permissions.
---

PWA keeps its configuration in two places. The **manifest** has its own screen, one per site.
Everything else is under **PWA → Settings**, in four panes: General, Offline, Install prompt and
Push. All of it is stored in project config, so it deploys with the rest of your site.

Where `allowAdminChanges` is off, the manifest, flight plan and settings screens are read-only, as
Craft's own settings are: the values are shown, nothing can be saved, and a note says why. The
actions that write the database rather than project config keep working there: **Invalidate
caches**, **Regenerate icons**, and generating, verifying or rotating the VAPID keypair.

## The manifest

**PWA → Manifest**, per site. Two sites on one install are two apps as far as a browser is
concerned, with different scopes and start URLs and usually different names, so there is no shared
manifest.

| Field | Default | Notes |
|---|---|---|
| Name | the site name | Shown in the install dialog and the app switcher |
| Short name | the name | Under the home-screen icon. Android truncates past twelve characters |
| Description | — | |
| Start URL | `/` | Relative to the site's base URL. Where a launch from the home screen lands. A full URL must be on the site's own origin |
| Scope | the site's base path | Everything outside it opens in a browser tab. A site at `/de/` has a scope of `/de/`. Same origin only |
| Display | Standalone | *Browser* means an ordinary tab, and nothing will offer to install it |
| Orientation | Any | |
| App ID | the scope | The app's stable identity, so changing the start URL does not create a second installed app. Same origin only |
| Theme colour | `#111827` | The title bar and task switcher |
| Background colour | `#ffffff` | The launch screen. Match your page background |
| Icon source | — | One square PNG, JPEG or WebP image, 512px or larger. Everything else is generated from it. SVG is not accepted |
| Maskable icon | — | Optional pre-padded artwork for Android's adaptive icons. Without it, the source is inset to the 80% safe zone automatically |

Images are stored by UID rather than element ID, so a manifest synced from development points at
the same asset in production.

## General

| Setting | Default | Why you would change it |
|---|---|---|
| Enabled | on | Off means no manifest, no worker, no injected tags |
| Manifest path | `manifest.webmanifest` | Only if something else already answers at that path |
| Service worker | on | Off serves a manifest with no worker. The site will not be installable |
| Service worker path | `sw.js` | Keep it at the web root: a worker can only control the directory it is served from |
| Inject automatically | on | Off, and you place the tags yourself with `{{ pwa.head() }}` |
| Never inject into | — | Path patterns, `*` allowed, for pages that should never get a worker attached |
| iOS status bar | Default | *Black translucent* draws the page under the status bar and clips layouts not designed for it |
| Generate iOS splash screens | on | Twenty-four images. Off if you do not care about the iOS launch screen |
| Record events | on | Installs, launches and offline hits for the flight deck |
| Keep events for | 90 days | |

## Offline

| Setting | Default | Notes |
|---|---|---|
| The offline page (URI) | the shipped fallback | Per site. Point it at a page of your own so the fallback looks like the site |
| Precache the essentials | on | The offline page and the manifest icons, fetched when the worker installs |
| Also precache | — | Extra URLs fetched on install. Keep the list short |
| Pages / Assets / Images | 60 / 120 / 80 | How many responses each cache bucket holds before the oldest goes |
| Keep cached responses for | 30 days | After this a cached response is refetched rather than served |

The caching rules themselves are the [flight plan](flight-plan).

## Install prompt

| Setting | Default | Notes |
|---|---|---|
| Show the prompt | on | Off leaves installation to the browser's own menu |
| Title, Body | the manifest name | The prompt's copy |
| Accept button, Dismiss button | Install, Not now | |
| Position | Bottom of the window | *Where you put it* renders nothing until a template calls `{{ pwa.installPrompt() }}` |
| Delay | 10 seconds | Time on the page before the prompt appears, once the browser has offered an install |
| Snooze after dismissal | 30 days | |
| Show iOS visitors how | on | Safari never offers an install event, so iOS visitors get Share → Add to Home Screen instructions instead |

## Push (Pro)

| Setting | Default | Notes |
|---|---|---|
| Enabled | off | Whether visitors can subscribe |
| Contact address | — | A `mailto:` or `https://` URL for the VAPID subject. Some push services reject messages without one |
| Default icon | the 192px icon | |
| Badge | — | The monochrome silhouette Android shows in the status bar |
| Notify when an entry is published | off | With sections chosen, the first publish of an entry raises a broadcast |
| Devices per queue job | 100 | |
| Drop a device after | 3 failures | A 404 or 410 drops it immediately |
| Most devices | 250,000 | New subscriptions are refused once the list is this long. `0` means no limit. Setting: `pushMaxSubscribers` |
| New devices per minute | 300 | Across the whole site, on top of the per-address limit. Setting: `pushNewPerMinute` |
| Keep delivery records for | 30 days | |

The same pane holds **Scheduled preflight**: daily or weekly, the hour (and weekday) it runs, who
the report is emailed to, and whether to email only when something fails. That is on by default.

See [Web push](push) for how subscribing and broadcasting work.

## The config file

Any setting can be fixed in `config/pwa.php`, which takes precedence over what is saved in the
control panel:

```php
<?php

return [
    'injectExclude' => ['/print/*', '/embed/*'],
    'promptDelay' => 20,
    'cacheLifetimeDays' => 14,
    'preflightRecipients' => ['ops@example.com'],
];
```

Two settings exist only in the config file:

| Setting | Default | |
|---|---|---|
| `extraPushHosts` | `[]` | Push-service hosts to accept beyond the browsers' own. `*.example.com` matches subdomains. Config only, because the server POSTs to them |
| `logLevel` | `info` | Verbosity of `storage/logs/pwa.log` |

Retention for preflight reports is `auditRetentionDays`, 180 by default.

## Environment variables

| Variable | |
|---|---|
| `PWA_VAPID_PUBLIC_KEY` | Supply your own VAPID keypair, as raw base64url or PEM. |
| `PWA_VAPID_PRIVATE_KEY` | Both must be set. They win over the stored keys. |

Without them the keypair is generated on first use and stored in the database, never in project
config. A private key in project config ends up in git.

## Permissions

- **View the flight deck and preflight reports** (`pwa:view`)
- **Manage the manifest and flight plan** (`pwa:manage`)
- **Send push notifications** (`pwa:broadcast`, Pro). It is a separate permission because a
  broadcast reaches people who are not on the site and cannot be recalled.

Settings are admin-only.
