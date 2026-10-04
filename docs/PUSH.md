---
title: Web push
slug: push
order: 80
summary: Self-hosted push notifications: keys, subscribing, broadcasting, and what the delivery numbers mean.
---

# Web push

Push is the one thing in this plugin that reaches somebody who is not looking at the site. It is a
Pro feature, and it is sent from this Craft install directly to browsers — no third-party service,
no per-message pricing, no SDK.

## How it works

Three parties. The **browser** creates a subscription: an endpoint URL at its own push service
(Google, Mozilla, Microsoft, Apple) and a keypair. The **push service** relays messages to the
device. **Craft** signs each message with a VAPID keypair that identifies the site, and encrypts
the payload with a key derived from the browser's own — so the push service delivers something it
cannot read.

That end-to-end encryption is why nothing here is optional or approximate. A payload encrypted
incorrectly is still accepted by the push service, still returns 201, and simply never appears on
the device. There is no error to read. PWA's implementation is checked against the test vector
published in RFC 8291 §5, byte for byte, in the test suite.

## Turning it on

**Settings → Push → Enabled**, and set a contact address — a `mailto:` or `https://` URL that push
services use to reach a human when a sender misbehaves. Some of them reject messages without one.

The VAPID keypair is generated the first time it is needed and stored in the database. It is
deliberately not in project config: a private key in project config is a private key in git, and
therefore on every laptop that ever cloned the repo. To supply your own, set `PWA_VAPID_PUBLIC_KEY`
and `PWA_VAPID_PRIVATE_KEY` (raw base64url or PEM); environment variables win over stored keys.

**Rotating the keypair orphans every existing subscription.** Each browser's subscription is bound
to the public key it was created with, and every device has to opt in again from a site you do not
control. There is no recovery, which is why the control panel makes you type it out.

## Subscribing

Push needs a user gesture — browsers ignore a permission request that did not come from one. So it
is a button:

```twig
{% if pwa.pushEnabled() %}
  <button type="button" id="notify">Notify me about new articles</button>
{% endif %}
```

```js
document.getElementById('notify').addEventListener('click', () => {
  pwa.subscribe()
     .then(() => console.log('subscribed'))
     .catch(error => console.warn(error.message));
});
```

`pwa.isSubscribed()` resolves to a boolean, and `pwa.unsubscribe()` reverses it. The
`pwa:subscribed` and `pwa:unsubscribed` events fire on `window`.

To segment, subscribe with topics — a broadcast with topics reaches only devices that hold one of
them, and a broadcast with none reaches everybody. A subscription keeps up to 20 topics, each up to
64 letters, numbers, `_`, `.`, `:` or `-`; anything else is dropped.

### Which subscriptions are accepted

The subscribe endpoint is anonymous and can't carry a CSRF token (the service worker resubscribes
on its own when an endpoint rotates), and every broadcast POSTs to the endpoint a subscription
names. So PWA only accepts endpoints on the push services browsers use — FCM (Chrome, Edge, Opera,
Samsung), Mozilla's autopush (Firefox), WNS and Apple's push service — over HTTPS on the default
port. Anything else is refused at subscribe and, if it somehow got into the table, removed rather
than sent to. Sends never follow a redirect.

A browser whose push service isn't on that list can be allowed in `config/pwa.php`:

```php
return [
    'extraPushHosts' => ['push.example.com', '*.push.example.net'],
];
```

Subscribing and unsubscribing are limited to 10 requests a minute from one address, and the event
recorder to 60; past that they answer `429`. The address is the one the connection came from:
`X-Forwarded-For` and similar headers are only believed when Craft's `trustedHosts` names your
proxies (its default of `any` does not count). IPv6 addresses are limited per /64.

Two more ceilings apply to *new* devices only, so a known device refreshing its subscription is
never refused: at most `pushNewPerMinute` (300) across the whole site, and at most
`pushMaxSubscribers` (250,000) rows in total. A subscription whose keys are not a valid P-256 key
and 16-byte secret is refused outright.

## Broadcasting

**PWA → Broadcast → New broadcast.** Write it, save it, and send it as a separate, deliberate step:
a notification cannot be edited, recalled or deleted once it has left, and every interface that
treats save and send as one action eventually sends a draft. The send button is armed by typing
`SEND`.

Sending is done in batches on the queue — a thousand subscribers is a thousand HTTPS round trips to
four different push services, and doing that in a web request means a timeout with half the list
notified and no record of which half. Each job sends a batch and queues the next.

**Notify on publish** raises a broadcast the first time an entry in a chosen section goes live.
Drafts, revisions, propagations and resaves are ignored — Craft fires a save for all of those, and
only one of them is news. One campaign per entry, ever.

## What the numbers mean

A subscriber is **one browser profile on one device**, not one person. The same reader on a phone
and a laptop is two; clearing site data makes a third.

Delivery outcomes:

- **delivered** — the push service accepted it. It is not a read receipt, and there is no such thing.
- **gone** — the push service returned 404 or 410. That device is retired and the row is deleted;
  retrying is pointless forever. The delivery record survives it.
- **failed** — a timeout, a 429, a 5xx. The device is fine; the failure counter goes up and the
  next broadcast tries again. After three consecutive failures it is dropped.

Removing a device in the control panel stops us sending to it. It does not unsubscribe the browser
— only the browser can do that, from the site.

## Payload limits

About 4KB *after* encryption. Keep the title under forty characters (Android truncates, iOS wraps
once) and the body short. A collapse tag makes a new notification replace an undelivered one on the
same subject — right for anything that supersedes itself, wrong for anything that does not, where
the second article of the day silently eats the first.

## When nothing arrives

Run preflight; it checks the keypair. Then, in order: is push enabled in settings; did the browser
actually grant permission (`Notification.permission`); is the device still in the subscriber list;
and what does the delivery record say. A `gone` means the subscription was retired — usually the
browser cleared site data — or that its endpoint isn't on a known push service (see *Which
subscriptions are accepted*). A 401 or 403 means the VAPID signature was rejected, which points at a
mismatched keypair rather than at the message.
