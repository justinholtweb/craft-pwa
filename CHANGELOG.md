# Release Notes for PWA

## 5.0.0 - 2026-10-03

Initial release. Version 5.0.0 rather than 1.0.0 to match the Craft major it supports, in line with
the rest of the family.

### Added

- Per-site web app manifests, served live rather than written to disk
- Icon generation from one square source: twelve sizes, a maskable pair inset to the safe zone, an
  Apple touch icon flattened onto the background colour, favicons, and iOS splash screens
- A generated service worker with an ordered flight plan — network first, cache first, stale while
  revalidate, network only, cache only — per-bucket eviction limits and response expiry
- Offline fallback page, precached when the worker installs
- An install prompt that waits for the browser to offer one, with iOS instructions where it never will
- Preflight: twenty checks made over HTTP against the running site, six of them marked blocking,
  each carrying its own remediation
- Automatic injection of the manifest link, iOS meta tags, splash links and the page runtime, with
  per-path exclusions and Twig functions for placing them by hand
- Event recording — installs, launches, offline hits — with no identifiers of any kind, rate
  limited per address
- Injection that leaves documents other than site pages alone: action responses, and anything
  served with a `sandbox` Content-Security-Policy, such as an embed proxy's frames
- Manifests and the flight plan in project config, with their images stored by UID so they
  survive a sync, and the manifest, flight plan and settings screens read-only where admin changes
  are off. The cache-invalidation counter is a database row, so **Invalidate caches** works there too
- The worker never stores a response marked `private` or `no-store`, and a logout clears the
  browser's HTTP cache and the worker's own caches
- `{{ pwa.head() }}` and the rest of `craft.pwa` as a `pwa` template global
- Web push (Pro): VAPID keypair, RFC 8291 payload encryption with no third-party library,
  subscribers, broadcasts on a queue, delivery ledger, notify on publish. Subscriptions are only
  accepted on the browsers' own push services (plus any in `extraPushHosts`), sends never follow
  redirects, subscribing is rate limited by connecting address (forwarded headers only behind
  configured `trustedHosts`), new devices are capped per minute and in total, malformed keys are
  refused, and topics are capped
- Scheduled preflight with an emailed report (Pro)
- Console commands for preflight, icons, manifest and broadcasts
