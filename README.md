# PWA

Turn a Craft site into an app people can install.

A progressive web app is three artifacts, and a browser refuses to install one if any of them is
wrong: a **manifest** describing the app, a **service worker** deciding what happens when the
network does not answer, and a set of **icons** at the sizes each platform insists on. PWA produces
all three from what Craft already knows — and then, because "correct" is not something you can tell
by looking, fetches them back over HTTP and tells you plainly what is wrong.

Install it, pick one square image, and reload the front end. That is the whole setup.

## What it does

**Manifest.** Per site, because two sites on one Craft install are two apps as far as a browser is
concerned. Name, short name, scope, start URL, display mode, colours, shortcuts, screenshots —
served live from a route, so it can never be stale or half-deployed.

**Icons.** One square source image in, everything out: the twelve sizes launchers ask for, the
maskable pair inset to the 80% safe zone so Android does not shave the edges off your logo, an
Apple touch icon flattened onto your background colour, favicons, and twenty-four iOS splash
screens — because Safari is the one platform that will not derive a launch screen for itself.

**Service worker.** Generated from a flight plan you can read: an ordered list of rules, first
match wins. Pages from the network with a cached fallback, assets and images served from cache and
refreshed behind you, the control panel never touched. Per-bucket eviction limits and a real expiry,
because a cache with no expiry is not a cache, it is a copy.

**Offline.** A fallback page precached at install time — long before anybody needs it — and every
page a visitor has already seen, still there when the connection is not.

**Install prompt.** Browsers hide their install button in a menu nobody opens. PWA intercepts the
offer and asks at a moment you choose, with copy you write, snoozed for a month if it is dismissed.
iOS visitors get the "Share → Add to Home Screen" instructions instead, because Safari has never
implemented the event there is nothing else to do about.

**Preflight.** The part that stops this being guesswork. It fetches the running site the way a
browser would and grades what it finds — is the manifest reachable, is the worker being served as
JavaScript, does the start URL redirect, is the 512px icon actually there. Six checks are marked
*blocking*: fail one and no browser will offer to install, whatever else is right. Every finding
carries the one thing to do about it.

**Web push (Pro).** Sent from this Craft install straight to browsers — no third party, no
per-message pricing. VAPID keypair generated for you, payloads encrypted end to end so the push
service relays something it cannot read, delivery recorded per device, dead endpoints dropped
automatically.

## Requirements

Craft CMS 5.3+, PHP 8.2+, `ext-openssl` (for push), and GD or Imagick (for icons). The icon source
must be a PNG, JPEG or WebP image; SVG is not accepted.

## Installing

```sh
composer require justinholtweb/craft-pwa
php craft plugin/install pwa
```

Then: **PWA → Manifest**, choose a square image of 512×512 or larger, save. Reload the front end.
Run **Preflight**.

## Editions

**Lite** is a complete, installable PWA: manifest, icons, service worker, offline page, install
prompt, and the full preflight check. Nothing about *whether the app works* is held back.

**Pro** ($79, then $59/year for updates) adds what a site grows into:

- **Web push** — subscribers, broadcasts, delivery reports, notify-on-publish
- **A flight plan you write yourself** — your own caching rules per route
- **Scheduled preflight** — runs on a schedule and emails somebody when a deploy breaks it

## Templates

Everything is injected into `<head>` automatically. Turn that off in **Settings → General** and
place it yourself instead:

```twig
{{ pwa.head() }}                {# the whole block #}
{{ pwa.manifestLink() }}        {# just the manifest link #}
{{ pwa.installPrompt() }}       {# where the prompt should appear, with position: "where you put it" #}
```

Reading the state:

```twig
{{ pwa.manifestUrl() }}
{{ pwa.serviceWorkerUrl() }}
{{ pwa.iconUrl(192) }}
{% if pwa.pushEnabled() %}…{% endif %}
{{ pwa.subscriberCount() }}
```

And on the page, `window.pwa`:

```js
pwa.canInstall()      // has the browser offered an install?
pwa.showPrompt()      // show ours now
pwa.subscribe()       // ask for notification permission and register the device (from a click)
pwa.unsubscribe()
pwa.isSubscribed()
pwa.isStandalone()    // launched from a home screen rather than a tab
pwa.update()          // check for a new worker
pwa.clearCaches()
```

Events on `window`: `pwa:installable`, `pwa:installed`, `pwa:update-available`, `pwa:subscribed`,
`pwa:unsubscribed`.

## Console

```sh
php craft pwa/preflight/run              # exits non-zero if the site is not installable
php craft pwa/preflight/run --strict     # …or if anything at all is warning
php craft pwa/preflight/due              # for cron: runs only when the schedule says so
php craft pwa/icons/generate --force     # for deploy scripts that rebuild the web root
php craft pwa/manifest/dump              # what the browser would be served
php craft pwa/manifest/worker
php craft pwa/broadcast/send --campaign=12 --dry-run=1
php craft pwa/broadcast/due              # release scheduled broadcasts
```

`pwa/preflight/run` in CI is the point of the whole check: a deploy that quietly breaks the
manifest is invisible from the outside for weeks.

## Push keys

The VAPID keypair is generated on first use and stored in the database — deliberately not in
project config, because a private key in project config is a private key in git and therefore on
every laptop that ever cloned the repo. To supply your own (migrating from another stack, sharing
with a native app), set both:

```
PWA_VAPID_PUBLIC_KEY="BEl…"
PWA_VAPID_PRIVATE_KEY="lPx…"     # raw base64url or PEM
```

Rotating the pair orphans every existing subscription. The control panel says so and makes you
type it out.

## Documentation

Full documentation is at [justinholt.com/plugins/craft-pwa/docs](https://justinholt.com/plugins/craft-pwa/docs).
The same pages live in [`docs/`](docs):

- [docs/installation.md](docs/installation.md) — requirements, install, editions
- [docs/configuration.md](docs/configuration.md) — the manifest, every setting, the config file
- [docs/usage.md](docs/usage.md) — the flight deck, the install prompt, Twig, the runtime, the console
- [docs/troubleshooting.md](docs/troubleshooting.md) — no install offer, 404s, stale pages
- [docs/faq.md](docs/faq.md)
- [docs/FLIGHT-PLAN.md](docs/FLIGHT-PLAN.md) — caching strategies, and which one to reach for
- [docs/PUSH.md](docs/PUSH.md) — subscribing, broadcasting, and what can go wrong
- [docs/PREFLIGHT.md](docs/PREFLIGHT.md) — every check, and what each failure means
- [docs/EXTENDING.md](docs/EXTENDING.md) — events and hooks

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Lite is free, with no licence key needed; Pro is a
paid edition, bought through the Craft Plugin Store.
