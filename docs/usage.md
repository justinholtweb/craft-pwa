---
title: Usage
slug: usage
order: 30
summary: The flight deck, the install prompt, offline, Twig, the page runtime and the console.
---

## The flight deck

**PWA → Flight deck** is the plugin's dashboard. It answers the question you actually have, which
is "would a browser install this right now?", and then shows what has happened since:

- **Installable**, yes or no, and the latest preflight score
- **Installs** and **Launches** (opens from the home screen rather than a tab), with installs day
  by day
- **Push devices**, on Pro with push switched on
- **What the browser fetches**: the manifest, the service worker, the offline page, the precache
  list and how many times the caches have been invalidated
- **Hit while offline**: pages people asked for with no connection and no stored copy

That last list is worth reading. It is your precache list, written by your visitors.

**Invalidate caches** throws away what every installed copy stored, on its next activation. Use it
when you deployed a change and people are still seeing the old one. Editing the manifest or the
flight plan does this on its own. The button works on environments where `allowAdminChanges` is
off: the counter it bumps lives in the database.

### What is recorded

Six event types: the prompt being shown, dismissed, an install, a launch, an offline hit and a
notification click. Each row holds the type, the path, the site and a platform. There is no user
ID, session, IP address or cookie, because there is nothing an installed app's event needs one
for. Recording is limited to 60 requests a minute from one address, and old rows are pruned by
Craft's garbage collection after **Keep events for** days.

Switch it off with **Settings → General → Record events**.

## Boarding: the install prompt

Chrome, Edge and Samsung Internet decide when a site is installable and fire a
`beforeinstallprompt` event. Left alone, that becomes an icon in the address bar most people never
notice. PWA intercepts it and, after the delay you set, shows its own prompt with your copy. A
dismissal snoozes it for 30 days by default.

Safari has never fired that event, on iOS or macOS. iOS visitors get instructions instead
(**Share → Add to Home Screen**), which is all a site can do there.

To put the prompt somewhere specific rather than pinned to the top or bottom of the window, set
**Position** to *Where you put it* and place it in a template:

```twig
{{ pwa.installPrompt({ class: 'install-banner' }) }}
```

It renders an empty element that stays empty on a device that has already installed the app, or in
a browser that cannot install one.

## Offline

When the service worker installs, it fetches the offline page and the icons, long before anybody
needs them. After that, every page a visitor views is stored in the pages cache. Offline, a page
they have seen comes from the cache. One they have not gets the offline page.

The plugin ships a plain fallback page. Point **Settings → Offline → The offline page** at a page of
your own and it will look like the rest of the site. It must be a page the worker can precache,
so keep it free of anything that needs a session.

How each kind of request is handled is decided by the [flight plan](flight-plan).

## Twig

The `pwa` variable is available in every template.

```twig
{{ pwa.head() }}                {# everything automatic injection would add #}
{{ pwa.manifestLink() }}        {# just the <link rel="manifest"> #}
{{ pwa.installPrompt() }}       {# where an inline prompt goes #}

{{ pwa.manifestUrl() }}
{{ pwa.serviceWorkerUrl() }}
{{ pwa.iconUrl(192) }}          {# any generated size, or null #}
{{ pwa.manifest().getEffectiveName() }}

{% if pwa.isEnabled() %}…{% endif %}
{% if pwa.pushEnabled() %}…{% endif %}   {# Pro and switched on #}
{{ pwa.subscriberCount() }}
```

Every function that reads a manifest takes an optional site ID; it defaults to the current site.

If **Inject automatically** is on and a template already contains a `<link rel="manifest">`,
injection steps aside, so calling `pwa.head()` does not give you two of everything.

## The page runtime

On every page the tags are injected into, `window.pwa` is available:

```js
pwa.canInstall()      // has the browser offered an install?
pwa.showPrompt()      // show the prompt now (the iOS instructions on Safari)
pwa.isStandalone()    // launched from a home screen rather than a tab
pwa.update()          // check for a new worker now
pwa.clearCaches()     // throw away everything the worker stored

// Pro, with push on
pwa.subscribe()       // ask for permission and register the device; call from a click
pwa.unsubscribe()
pwa.isSubscribed()    // Promise<boolean>
```

It fires `pwa:installable`, `pwa:installed`, `pwa:update-available`, `pwa:subscribed` and
`pwa:unsubscribed` on `window`. [Extending](extending) has an example of offering a reload on
`pwa:update-available`.

## Broadcasting (Pro)

**PWA → Broadcast → New broadcast.** Saving and sending are separate steps, and the send button is
armed by typing `SEND`, because a notification cannot be edited or recalled once it has left.
Sends go out in batches on the queue, and every delivery is recorded per device.

[Web push](push) covers subscribing, topics, notify-on-publish and what the delivery numbers mean.

## Console

```sh
php craft pwa/preflight/run                 # all sites; exits non-zero if one is not installable
php craft pwa/preflight/run --site=en       # one site
php craft pwa/preflight/run --strict        # also fail on warnings
php craft pwa/preflight/run --save=0        # don't store the report
php craft pwa/preflight/due                 # Pro: runs only when the schedule says so

php craft pwa/icons/generate                # only sites whose source has changed
php craft pwa/icons/generate --force        # rebuild regardless
php craft pwa/icons/generate --no-splash    # skip the 24 iOS splash screens, the slow part

php craft pwa/manifest/dump --site=en       # the manifest JSON the browser would get
php craft pwa/manifest/worker               # the finished service worker

php craft pwa/broadcast/send --campaign=12 --dry-run=1   # Pro: how many devices, nothing sent
php craft pwa/broadcast/send --campaign=12               # Pro: queue it
php craft pwa/broadcast/due                              # Pro: release scheduled broadcasts
```

`manifest/dump` and `manifest/worker` are for when the site cannot be reached over HTTP (behind
basic auth, in a container, halfway through a deploy), which is exactly when preflight has to skip
half its checks.
