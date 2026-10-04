---
title: The flight plan
slug: flight-plan
order: 60
summary: The caching rules the service worker follows: defaults, matching, strategies, buckets and precache.
---

# The flight plan

The flight plan is the ordered list of rules the generated service worker applies to every request.
It is walked top to bottom and **the first rule that matches wins** — so the specific legs go first
and the last rule is expected to catch everything left.

Editing it requires Pro. Lite runs the shipped defaults, which are below.

## The defaults

| # | Rule | Matches | Strategy | Cache |
|---|------|---------|----------|-------|
| 1 | Control panel | path `/admin/*` | Network only | — |
| 2 | Craft actions | path `/actions/*` | Network only | — |
| 3 | Images | request type `image` | Stale while revalidate | Images |
| 4 | Scripts, styles and fonts | request type `script,style,font` | Stale while revalidate | Assets |
| 5 | Pages | path `*` | Network first (3s) | Pages |

The first two are not decoration. A cached control panel serves one author's session shell to the
next; a cached `/actions` request replays a form post out of a cache. Both are put beyond reach
rather than left to a checkbox, and preflight fails the site if a custom flight plan removes them.

If your control panel is not at `/admin`, the defaults follow your `cpTrigger` automatically.

## Matching

| Match on | Means | Example |
|----------|-------|---------|
| Path | The URL path | `/blog/*` |
| File extension | The extension, case-insensitively | `pdf,zip` |
| Request type | The browser's own `request.destination` | `image`, `script`, `style`, `font`, `document` |
| Host | The hostname | `cdn.*` |

Patterns take `*` (spans anything, including slashes) and `?` (one character). A comma separates
alternatives: `/feed,/rss/*` matches either. Everything else is literal — a `.` is a dot, not a
regular expression. A pattern may be at most 200 characters with at most eight `*`, because every
request is tested against every rule.

**Prefer request type over file extension.** The browser knows what it asked for; a `.php` endpoint
returning an image is not hypothetical, and an extension rule gets it wrong.

The control panel's route tester runs the PHP twin of the worker's matcher, so you can ask which
rule would claim a URL before shipping it. The two implementations are held to one shared table of
cases in the test suite — that is what stops them drifting.

## Strategies

**Network first** — try the network, fall back to what is stored. The right default for pages: a
visitor always sees current content, and gets the stored copy when the network does not answer
within the timeout. That timeout is the number that decides whether a bad connection feels like a
slow site or an offline one; three seconds is a reasonable start.

**Stale while revalidate** — serve what is stored immediately, fetch a fresh copy in the
background for next time. The right default for images, scripts, styles and fonts: instant, and
never more than one visit out of date.

**Cache first** — serve what is stored and only go to the network if there is nothing. For content
that is immutable, usually because its URL contains a hash. On anything else it is how a site
serves last month's stylesheet.

**Network only** — never touch the cache. For anything personalised, authenticated or
transactional. Give account pages, carts and anything else that differs for a logged-in visitor a
network-only rule above the catch-all. The worker already refuses to store a response marked
`Cache-Control: private` or `no-store`, and clears its caches after a logout, but a rule says so
before the response is ever fetched.

**Cache only** — never touch the network. Rare; useful for an asset you precached deliberately.

## Buckets and limits

Three caches — pages, assets, images — with their own ceilings, because one shared limit means a
photo gallery evicts the app shell. When a bucket is full the oldest entry goes.

Cached responses are stamped with the time they were stored and refuse to be served past the
lifetime (30 days out of the box). Stale-while-revalidate will still serve an expired copy, because
the refetch is already in flight.

**The pages bucket only ever stores navigations.** A background poll or an XHR that falls through
to the catch-all rule is fetched normally and not kept — otherwise a JSON endpoint with a
cache-busting query string fills the bucket and evicts the pages somebody actually wanted offline.

## Precache

What is fetched before the worker activates. Keep it short: everything here is downloaded on a
visitor's first page view, and an ordinary page visit fetches the shell anyway. What genuinely
belongs here is the offline page — which by definition is never visited before it is needed — and
the icons. Both are included automatically.

The flight deck lists the pages people hit while offline. That list is your precache candidates,
written by your visitors.

## Invalidating

Every cache the worker owns is named after a version number. Changing the manifest, the flight plan
or the precache list bumps it, and the next activation deletes everything from the old one. The
**Invalidate caches** button on the flight deck bumps it by hand — the button for "I deployed a
change and people are still seeing the old thing".
