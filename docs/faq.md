---
title: FAQ
slug: faq
order: 50
summary: Common questions about turning a Craft site into an installable progressive web app.
---

## What does "installable" actually mean?

The browser offers to put the site on the home screen or in the app launcher, and when it is
opened from there it runs in its own window, without the address bar, like an app. Chrome, Edge and
Samsung Internet decide that a site qualifies by checking the manifest, the icons and the service
worker. PWA produces all three and [preflight](preflight) checks them the way the browser will.

## Do I have to change my templates?

No. The manifest link, theme colour, iOS tags and the script that registers the worker are
injected into `<head>` on every front-end page. If you would rather place them yourself, turn off
**Inject automatically** and add `{{ pwa.head() }}` to your layout.

## Does it work on iPhone?

Yes, with Safari's limits. iOS installs web apps from **Share → Add to Home Screen**, and never
fires the event that lets a site offer an install button, so PWA shows iOS visitors those
instructions instead. The manifest, icons, splash screens and offline support all work. Web push
works on iOS 16.4 and later, but only for an app that has been added to the home screen.

## Is it free?

Lite is, and it is the whole installable app: manifest, icons, service worker, offline page,
install prompt and every preflight check. Pro is a one-off $79 with a $59/year renewal, and adds
web push, a flight plan you write yourself, and scheduled preflight with an emailed report.

## What is the difference between Lite and Pro?

Lite is everything a browser needs to install the site and keep it working offline, with the
default caching rules. Pro adds the parts a site grows into: push notifications sent from your own
server, your own caching rules per route, and a preflight check that runs on a schedule and emails
somebody when a deploy breaks the manifest. See [Installation](installation#editions) for the full
table.

## What happens when the renewal lapses?

Pro keeps working. Renewals buy updates, the way every Craft plugin licence does.

## Do I need a third-party push service?

No. PWA sends push messages from your Craft install directly to the browsers' own push services
(Google, Mozilla, Microsoft, Apple). There is no OneSignal account, SDK or per-message fee. The
payload is encrypted end to end, so the push service relays a message it cannot read.

## Will the service worker cache my control panel?

No. The default flight plan sends `/admin/*` (or whatever your `cpTrigger` is) and `/actions/*`
straight to the network, and preflight fails a custom flight plan that would let either be cached.

## Will visitors see stale pages after I deploy?

Pages are network first, so a visitor with a connection gets the current page. Scripts, styles and
images are stale while revalidate, so they are at most one visit behind. Changing the manifest,
flight plan or precache list changes the cache version on its own (it includes a hash of the
worker's configuration), and **Invalidate caches** on the flight deck does it by hand.

## Can a logged-in page end up in the offline cache?

Not if the server says it is private. The worker never stores a response sent with
`Cache-Control: private` or `no-store`, which is what Craft sends for logged-in requests. When a
visitor logs out, the response tells the browser to clear its HTTP cache, and the page runtime
throws away the worker's caches on the next page. Pages whose content depends on who is logged in
belong in a **network only** rule of their own.

## Does it work with multi-site?

Yes, and it treats each site as its own app, with its own manifest, name, scope, start URL, icons
and offline page. Two sites on one install are two apps to a browser.

## Does it track my visitors?

It records installs, launches, prompt shows and dismissals, offline hits and notification clicks so
the flight deck has numbers on it. The rows hold no user ID, IP address, session or cookie, and you
can turn recording off in **Settings → General**.

## Can I run preflight in CI?

Yes. `php craft pwa/preflight/run` exits non-zero when a site is not installable, and `--strict`
makes it fail on warnings as well. That is what it was built for.

## Is the service worker generated code I can't read?

No. The worker's logic is one fixed file that ships with the plugin. Only a JSON block of
configuration is generated into it, so it reads and debugs normally in browser devtools.
`php craft pwa/manifest/worker` prints the finished file.

## Which versions are supported?

Craft CMS 5.3+ and PHP 8.2+, with `ext-openssl` and GD or Imagick.
