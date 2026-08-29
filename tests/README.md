# Tests

## Unit suite

Plain PHP — no Craft application, no database, no HTTP. It covers the parts of the plugin that are
deliberately pure.

```sh
ddev start
ddev composer install
ddev exec vendor/bin/phpunit
```

The most important test in the file is `EncryptorTest::testTheRfc8291TestVectorIsReproducedExactly`.
A push payload encrypted incorrectly is still accepted by the push service, still returns 201, and
simply never appears on the device — there is no error anywhere to read. Reproducing the vector
published in RFC 8291 §5 byte for byte is the only way to know the derivation is right.

## Route matcher parity

The route matcher exists twice: in PHP, so the control panel can tell you which rule would claim a
URL, and in JavaScript, because the browser is where the matching actually happens. Two
implementations of one rule is a bug waiting to happen, so both are asserted against one table of
cases in `fixtures/route-cases.json`:

```sh
ddev exec vendor/bin/phpunit --filter RouteTest   # the PHP side
ddev exec node tests/js/route-parity.mjs          # the worker's side
```

The parity test earned its place on its first run: it caught the PHP matcher splitting patterns on
commas where the worker's did not.

## Live verification

Everything that needs a running Craft is exercised against the shared plugin-testing harness rather
than mocked. What was verified there for 5.0.0:

- Install and migrations, on a multi-site install
- `manifest.webmanifest` served as `application/manifest+json`, per site
- `sw.js` served as JavaScript with `no-cache` and `Service-Worker-Allowed`
- Head injection on real templates, and *not* on the control panel or Ajax
- Icon generation from a 1024px source: 41 files, including the maskable pair inset to the safe
  zone and 24 iOS splash screens
- Preflight over HTTP on both sites, and its non-zero exit when a site is not installable
- The control panel screens, the settings save round-tripping into project config, and the route
  tester
- Web push against a real push service: Google accepted the VAPID JWT and the `aes128gcm` body and
  returned 410 for a synthetic subscription, which is the correct answer and confirms the signing
  and encryption were not the problem
- A real browser: the worker registered at root scope, activated, precached the offline page and
  icons, cached a navigation with its expiry stamp, evicted the previous version's caches on
  update, and Chrome offered the install prompt — which means the manifest passed Chrome's own
  installability criteria
