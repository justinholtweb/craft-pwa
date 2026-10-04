---
title: Extending PWA
slug: extending
order: 90
summary: Placing the tags by hand, the page runtime, worker messages, services and permissions.
---

# Extending PWA

## Placing the tags yourself

Turn off **Settings → General → Inject automatically** and put them where you want:

```twig
{{ pwa.head() }}
```

That emits exactly what injection would have — the same code path, so the two can never disagree.
`{{ pwa.manifestLink() }}` is the manifest link alone, for a layout that assembles its own head.

Injection is skipped automatically for control panel requests, Ajax, live preview, non-HTML
responses, and any page that already has a `<link rel="manifest">` — so a template that calls
`pwa.head()` while injection is still on does not get two.

It is also skipped for documents that aren't pages the site rendered: anything answering an
`actions/…` URL, and any response sent with `Content-Security-Policy: sandbox` — the signal for a
document meant to run in a frame, cut off from the site, such as an embed proxy. A plugin or
module serving its own frame documents can rely on that header to keep the service worker
registration out of them.

## The runtime

`window.pwa` is available on every page the runtime loads on:

| Method | Returns |
|--------|---------|
| `pwa.canInstall()` | whether the browser has offered an install |
| `pwa.showPrompt()` | shows the prompt now (the iOS instructions on Safari) |
| `pwa.isStandalone()` | launched from a home screen rather than a tab |
| `pwa.subscribe()` | Promise — asks for permission and registers the device. Call from a click. |
| `pwa.unsubscribe()` | Promise |
| `pwa.isSubscribed()` | Promise&lt;boolean&gt; |
| `pwa.update()` | Promise — check for a new worker now |
| `pwa.clearCaches()` | throws away everything the worker stored |
| `pwa.config` | the configuration the page was rendered with |

Window events: `pwa:installable`, `pwa:installed`, `pwa:update-available`, `pwa:subscribed`,
`pwa:unsubscribed`.

`pwa:update-available` is the useful one — it fires only for an *update*, never a first install, so
you can offer a reload without confusing a first-time visitor:

```js
window.addEventListener('pwa:update-available', (event) => {
  showBanner('A new version is ready.', () => {
    event.detail.worker.postMessage({ type: 'PWA_SKIP_WAITING' });
    location.reload();
  });
});
```

## The service worker

The worker's logic ships with the plugin as a fixed file and only its configuration is generated —
so it is reviewable, debuggable in browser devtools, and identical on every site. If you want it to
behave differently, change the flight plan.

It accepts two messages:

```js
navigator.serviceWorker.controller.postMessage({ type: 'PWA_SKIP_WAITING' });
navigator.serviceWorker.controller.postMessage({ type: 'PWA_CLEAR_CACHES' });
```

## Services

Everything is reachable from PHP:

```php
use justinholtweb\pwa\Plugin;

$plugin = Plugin::getInstance();

$plugin->manifests->build($site);            // the manifest as an array
$plugin->manifests->json($site);
$plugin->serviceWorker->render($site);       // the finished worker
$plugin->serviceWorker->invalidate();        // throw away every installed cache (a DB counter)
$plugin->icons->generate($manifest);         // rebuild the icon set
$plugin->preflight->run($site);              // returns an Audit
$plugin->events->topOfflinePaths();          // precache candidates
$plugin->push->getSubscribers($siteId);
$plugin->campaigns->broadcast($campaign);    // queues it
```

## Sending a broadcast from code

```php
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\Plugin;

$campaign = new Campaign([
    'title' => 'Gate change',
    'body' => 'Now boarding at gate 14.',
    'url' => '/departures',
    'topics' => ['flights'],
]);

$plugin = Plugin::getInstance();

if ($plugin->campaigns->save($campaign)) {
    $plugin->campaigns->broadcast($campaign);
}
```

`broadcast()` counts the audience, records it, and puts the send on the queue. It returns
immediately.

## Permissions

- `pwa:view` — the flight deck and preflight reports
- `pwa:manage` — the manifest and the flight plan
- `pwa:broadcast` — sending push notifications, which is its own permission because it reaches
  people who are not on the site
