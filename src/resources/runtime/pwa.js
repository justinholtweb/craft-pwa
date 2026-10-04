/**
 * PWA — the page-side runtime.
 *
 * Registers the service worker, runs the install prompt, and exposes `window.pwa` for anything a
 * template wants to do itself. Everything it does is optional and everything it does is
 * defensive: this script runs on every page of a live site, and a PWA integration that can break
 * a page is worse than no PWA integration.
 *
 * Its configuration arrives as JSON in a script tag rather than as inline code, so a strict
 * Content-Security-Policy needs no unsafe-inline exception to use it.
 */
(function () {
  'use strict';

  var el = document.getElementById('pwa-config');
  if (!el) return;

  var CONFIG;

  try {
    CONFIG = JSON.parse(el.textContent || '{}');
  } catch (e) {
    return;
  }

  var STORAGE_KEY = 'pwa:prompt-dismissed';
  var deferredPrompt = null;
  var registration = null;

  /* ------------------------------------------------------------------ registration */

  function register() {
    if (!CONFIG.swUrl || !('serviceWorker' in navigator)) return Promise.resolve(null);

    // A service worker needs a secure context. localhost counts, which is why development works
    // and a staging site on plain http does not.
    if (!window.isSecureContext) return Promise.resolve(null);

    return navigator.serviceWorker
      .register(CONFIG.swUrl, { scope: CONFIG.scope || '/' })
      .then(function (reg) {
        registration = reg;

        reg.addEventListener('updatefound', function () {
          var incoming = reg.installing;
          if (!incoming) return;

          incoming.addEventListener('statechange', function () {
            // `controller` being set means this is an update rather than a first install — the
            // distinction matters, because prompting a first-time visitor to reload for an
            // update they have never seen is nonsense.
            if (incoming.state === 'installed' && navigator.serviceWorker.controller) {
              emit('pwa:update-available', { registration: reg, worker: incoming });
            }
          });
        });

        return reg;
      })
      .catch(function () {
        return null;
      });
  }

  /* ------------------------------------------------------------------------ prompt */

  function snoozed() {
    try {
      var until = window.localStorage.getItem(STORAGE_KEY);
      return !!until && Number(until) > Date.now();
    } catch (e) {
      return false;
    }
  }

  function snooze() {
    try {
      var days = CONFIG.snoozeDays || 30;
      window.localStorage.setItem(STORAGE_KEY, String(Date.now() + days * 86400000));
    } catch (e) {
      /* private browsing */
    }
  }

  function standalone() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.matchMedia('(display-mode: fullscreen)').matches ||
      window.navigator.standalone === true
    );
  }

  function isIos() {
    return (
      /iphone|ipad|ipod/i.test(window.navigator.userAgent) ||
      // iPadOS reports as a Mac, and the touch points are the only reliable tell.
      (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1)
    );
  }

  function styles() {
    if (document.getElementById('pwa-styles')) return;

    var css =
      '.pwa-prompt{position:fixed;left:50%;transform:translateX(-50%) translateY(var(--pwa-y,0));z-index:2147483000;' +
      'max-width:26rem;width:calc(100% - 2rem);background:var(--pwa-bg,#fff);color:var(--pwa-fg,#111827);' +
      'border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.22);padding:1rem 1.1rem;display:flex;gap:.9rem;' +
      'align-items:center;font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;opacity:0;' +
      'transition:opacity .28s ease,transform .28s ease}' +
      '.pwa-prompt[data-position="bottom"]{bottom:1rem;--pwa-y:1rem}' +
      '.pwa-prompt[data-position="top"]{top:1rem;--pwa-y:-1rem}' +
      '.pwa-prompt.is-open{opacity:1;--pwa-y:0}' +
      '.pwa-prompt__icon{width:44px;height:44px;border-radius:10px;flex:0 0 auto;object-fit:cover}' +
      '.pwa-prompt__text{flex:1 1 auto;min-width:0}' +
      '.pwa-prompt__title{font-weight:600;margin:0 0 .15rem}' +
      '.pwa-prompt__body{margin:0;opacity:.75;font-size:.9em}' +
      '.pwa-prompt__actions{display:flex;gap:.4rem;flex:0 0 auto;align-items:center}' +
      '.pwa-prompt__btn{font:inherit;font-weight:600;border:0;border-radius:8px;padding:.5rem .85rem;cursor:pointer;' +
      'background:var(--pwa-accent,#111827);color:var(--pwa-accent-fg,#fff)}' +
      '.pwa-prompt__btn--ghost{background:transparent;color:inherit;opacity:.6;font-weight:500;padding:.5rem .5rem}' +
      '.pwa-prompt__close{position:absolute;inset-inline-end:.35rem;inset-block-start:.35rem;border:0;background:0;' +
      'cursor:pointer;font-size:1.1rem;line-height:1;opacity:.5;color:inherit}' +
      '@media (prefers-color-scheme:dark){.pwa-prompt{--pwa-bg:#1f2430;--pwa-fg:#f3f4f6;--pwa-accent:#f3f4f6;--pwa-accent-fg:#111827}}' +
      '@media (prefers-reduced-motion:reduce){.pwa-prompt{transition:none}}';

    var tag = document.createElement('style');
    tag.id = 'pwa-styles';
    tag.textContent = css;
    document.head.appendChild(tag);
  }

  function build(kind) {
    styles();

    var host = document.querySelector('[data-pwa-prompt]');
    var inline = !!host;

    var node = document.createElement('div');
    node.className = 'pwa-prompt';
    node.setAttribute('role', 'dialog');
    node.setAttribute('aria-live', 'polite');
    node.setAttribute('aria-label', CONFIG.promptTitle || 'Install this app');

    if (!inline) {
      node.dataset.position = CONFIG.promptPosition === 'top' ? 'top' : 'bottom';
    } else {
      node.style.position = 'static';
      node.style.transform = 'none';
      node.style.width = '100%';
    }

    if (CONFIG.themeColor) {
      node.style.setProperty('--pwa-accent', CONFIG.themeColor);
      node.style.setProperty('--pwa-accent-fg', contrast(CONFIG.themeColor));
    }

    var icon = CONFIG.icon
      ? '<img class="pwa-prompt__icon" src="' + escapeAttr(CONFIG.icon) + '" alt="" width="44" height="44">'
      : '';

    var body =
      kind === 'ios'
        ? CONFIG.iosBody || 'Tap the Share button, then “Add to Home Screen”.'
        : CONFIG.promptBody || '';

    var actions =
      kind === 'ios'
        ? '<button type="button" class="pwa-prompt__btn" data-pwa-action="dismiss">' + escapeHtml(CONFIG.promptDismissLabel || 'Got it') + '</button>'
        : '<button type="button" class="pwa-prompt__btn" data-pwa-action="accept">' +
          escapeHtml(CONFIG.promptAccept || 'Install') +
          '</button><button type="button" class="pwa-prompt__btn pwa-prompt__btn--ghost" data-pwa-action="dismiss">' +
          escapeHtml(CONFIG.promptDismiss || 'Not now') +
          '</button>';

    node.innerHTML =
      icon +
      '<div class="pwa-prompt__text"><p class="pwa-prompt__title">' +
      escapeHtml(CONFIG.promptTitle || 'Install ' + (CONFIG.appName || 'this app')) +
      '</p>' +
      (body ? '<p class="pwa-prompt__body">' + escapeHtml(body) + '</p>' : '') +
      '</div><div class="pwa-prompt__actions">' +
      actions +
      '</div>';

    (inline ? host : document.body).appendChild(node);

    // One frame between insertion and the class that animates it, or the transition never runs.
    requestAnimationFrame(function () {
      node.classList.add('is-open');
    });

    node.addEventListener('click', function (event) {
      var action = event.target.getAttribute('data-pwa-action');
      if (!action) return;

      if (action === 'accept') accept(node);
      if (action === 'dismiss') dismiss(node);
    });

    report('prompt');

    return node;
  }

  function close(node) {
    node.classList.remove('is-open');
    setTimeout(function () {
      if (node.parentNode) node.parentNode.removeChild(node);
    }, 300);
  }

  function accept(node) {
    close(node);

    if (!deferredPrompt) return;

    deferredPrompt.prompt();

    deferredPrompt.userChoice.then(function (choice) {
      // The browser's own dialog is what decides; ours only decides whether it is offered. An
      // "accepted" here is the last thing we hear before `appinstalled`.
      if (choice && choice.outcome === 'dismissed') {
        snooze();
        report('dismiss');
      }

      deferredPrompt = null;
    });
  }

  function dismiss(node) {
    close(node);
    snooze();
    report('dismiss');
  }

  function maybePrompt() {
    if (!CONFIG.promptEnabled || standalone() || snoozed()) return;

    if (deferredPrompt) {
      setTimeout(function () {
        if (deferredPrompt) build('install');
      }, (CONFIG.promptDelay || 0) * 1000);
      return;
    }

    // Safari has never implemented `beforeinstallprompt`, so on iOS there is nothing to defer and
    // nothing to click: the honest option is instructions, and only for Safari, since Chrome on
    // iOS cannot install to the home screen at all.
    if (CONFIG.iosHint && isIos() && /safari/i.test(navigator.userAgent) && !/crios|fxios|edgios/i.test(navigator.userAgent)) {
      setTimeout(function () {
        build('ios');
      }, (CONFIG.promptDelay || 0) * 1000);
    }
  }

  /* -------------------------------------------------------------------------- push */

  function subscribe() {
    if (!CONFIG.pushEnabled || !CONFIG.vapidPublicKey) {
      return Promise.reject(new Error('Push is not enabled for this site.'));
    }

    if (!('PushManager' in window)) {
      return Promise.reject(new Error('This browser does not support push notifications.'));
    }

    return ready()
      .then(function (reg) {
        // Permission has to be requested from a user gesture or browsers ignore it — that is why
        // this is a function a button calls, not something the script does on load.
        return Notification.requestPermission().then(function (permission) {
          if (permission !== 'granted') throw new Error('Notification permission was not granted.');

          return reg.pushManager.getSubscription().then(function (existing) {
            return (
              existing ||
              reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64ToUint8(CONFIG.vapidPublicKey),
              })
            );
          });
        });
      })
      .then(function (subscription) {
        return post(CONFIG.subscribeUrl, { subscription: subscription.toJSON(), topics: CONFIG.topics || [] }).then(
          function () {
            emit('pwa:subscribed', { subscription: subscription });
            return subscription;
          }
        );
      });
  }

  function unsubscribe() {
    return ready()
      .then(function (reg) {
        return reg.pushManager.getSubscription();
      })
      .then(function (subscription) {
        if (!subscription) return false;

        var endpoint = subscription.endpoint;

        return subscription.unsubscribe().then(function () {
          return post(CONFIG.unsubscribeUrl, { endpoint: endpoint }).then(function () {
            emit('pwa:unsubscribed', {});
            return true;
          });
        });
      });
  }

  function isSubscribed() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return Promise.resolve(false);

    return ready().then(function (reg) {
      return reg.pushManager.getSubscription().then(function (subscription) {
        return !!subscription;
      });
    });
  }

  function ready() {
    return registration ? Promise.resolve(registration) : navigator.serviceWorker.ready;
  }

  /* ------------------------------------------------------------------------ helpers */

  function post(url, data) {
    if (!url) return Promise.resolve(null);

    var headers = { 'Content-Type': 'application/json', Accept: 'application/json' };

    if (CONFIG.csrfName && CONFIG.csrfToken) {
      headers['X-CSRF-Token'] = CONFIG.csrfToken;
    }

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(data),
    }).then(function (response) {
      if (!response.ok) throw new Error('Request failed with status ' + response.status);
      return response.json().catch(function () {
        return null;
      });
    });
  }

  function report(type) {
    if (!CONFIG.eventUrl || !CONFIG.trackEvents) return;

    try {
      var body = JSON.stringify({ type: type, path: location.pathname });

      // `sendBeacon` survives the page being closed, which is exactly when an install event fires.
      if (navigator.sendBeacon) {
        navigator.sendBeacon(CONFIG.eventUrl, new Blob([body], { type: 'application/json' }));
        return;
      }

      fetch(CONFIG.eventUrl, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: body,
      }).catch(function () {});
    } catch (e) {
      /* never worth an error */
    }
  }

  function emit(name, detail) {
    try {
      window.dispatchEvent(new CustomEvent(name, { detail: detail }));
    } catch (e) {
      /* ancient browser */
    }
  }

  function escapeHtml(value) {
    var node = document.createElement('div');
    node.textContent = String(value == null ? '' : value);
    return node.innerHTML;
  }

  function escapeAttr(value) {
    return String(value == null ? '' : value).replace(/"/g, '&quot;');
  }

  /** Black or white, whichever stays readable on the theme colour. */
  function contrast(hex) {
    var value = String(hex).replace('#', '');

    if (value.length === 3) {
      value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
    }

    if (value.length !== 6) return '#fff';

    var r = parseInt(value.slice(0, 2), 16);
    var g = parseInt(value.slice(2, 4), 16);
    var b = parseInt(value.slice(4, 6), 16);

    return (r * 299 + g * 587 + b * 114) / 1000 > 150 ? '#111827' : '#fff';
  }

  function base64ToUint8(value) {
    var padding = new Array((4 - (value.length % 4)) % 4 + 1).join('=');
    var base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(base64);
    var output = new Uint8Array(raw.length);

    for (var i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);

    return output;
  }

  /* --------------------------------------------------------------------------- boot */

  window.addEventListener('beforeinstallprompt', function (event) {
    // Held rather than allowed to fire: the browser's own moment for this is rarely the site's.
    event.preventDefault();
    deferredPrompt = event;
    emit('pwa:installable', {});

    if (document.readyState === 'complete') maybePrompt();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    report('install');
    emit('pwa:installed', {});
  });

  window.pwa = {
    config: CONFIG,
    register: register,
    subscribe: subscribe,
    unsubscribe: unsubscribe,
    isSubscribed: isSubscribed,
    isStandalone: standalone,
    canInstall: function () {
      return !!deferredPrompt;
    },
    showPrompt: function () {
      if (deferredPrompt) return build('install');
      if (isIos()) return build('ios');
      return null;
    },
    update: function () {
      return ready().then(function (reg) {
        return reg.update();
      });
    },
    clearCaches: function () {
      if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'PWA_CLEAR_CACHES' });
      }
    },
  };

  /**
   * Throws away every cache the worker owns after a logout.
   *
   * The server marks the logout response with a short-lived cookie, because by the time a page
   * runs the logout itself was a redirect the page never saw. The caches are reachable from the
   * page directly, so this does not depend on a worker being in control yet.
   */
  function clearAfterLogout() {
    if (document.cookie.indexOf('pwa_clear_caches=1') === -1) return;

    document.cookie = 'pwa_clear_caches=; Max-Age=0; path=/';

    if (!('caches' in window)) return;

    caches.keys().then(function (names) {
      names
        .filter(function (name) {
          return name.indexOf('pwa-') === 0;
        })
        .forEach(function (name) {
          caches.delete(name);
        });
    });
  }

  function boot() {
    clearAfterLogout();
    register();

    if (standalone()) report('launch');

    maybePrompt();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
