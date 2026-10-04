<?php

namespace justinholtweb\pwa\helpers;

use Craft;

/**
 * Per-minute request budgets for PWA's anonymous routes: push subscriptions and the event recorder.
 */
abstract class RateLimit
{
    /**
     * Whether this client may make another request to `$bucket` this minute.
     *
     * The count is read and written under a mutex: without it, requests sent in parallel all read
     * the same number and none is ever refused. A busy lock refuses rather than queues — a worker
     * held up waiting cannot serve anyone.
     */
    public static function allow(string $bucket, int $perMinute): bool
    {
        return self::consume(sprintf('pwa:rate:%s:%s:%d', $bucket, sha1(self::client()), intdiv(time(), 60)), $perMinute);
    }

    /**
     * Whether `$bucket` may be used again this minute by anybody at all.
     *
     * The ceiling over every client together, for the one thing worth bounding globally: new rows
     * in a table the public can write to. A per-client limit does nothing against many clients.
     */
    public static function allowGlobal(string $bucket, int $perMinute): bool
    {
        return self::consume(sprintf('pwa:rate:%s:all:%d', $bucket, intdiv(time(), 60)), $perMinute);
    }

    /**
     * Who is asking, for rate-limiting purposes.
     *
     * The connecting address, not `getUserIP()`: Craft reads that from `Client-IP`,
     * `X-Forwarded-For` and their relatives without asking who sent them, so a client that changes
     * the header on every request gets a fresh budget every time. The forwarded address is only
     * believed when the site has said which proxies to trust — Craft's default of "any" is not
     * saying so.
     */
    public static function client(): string
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return 'console';
        }

        $trusted = array_values(array_filter((array)$request->trustedHosts));
        $trustsProxies = $trusted !== [] && !in_array('any', $trusted, true);

        return self::key((string)$request->getRemoteIP(), $trustsProxies ? $request->getUserIP() : null);
    }

    /**
     * The budget key for an address. Pure, so it can be tested.
     *
     * IPv6 is grouped by /64. One subscriber line is routinely handed a whole /64, so keying on
     * the full address gives every client 2^64 budgets to rotate through.
     */
    public static function key(string $remoteIp, ?string $forwardedIp = null): string
    {
        $ip = $forwardedIp !== null && $forwardedIp !== '' ? $forwardedIp : $remoteIp;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed !== false) {
                return bin2hex(substr($packed, 0, 8)) . '::/64';
            }
        }

        return $ip !== '' ? $ip : 'unknown';
    }

    private static function consume(string $key, int $limit): bool
    {
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 2)) {
            return false;
        }

        try {
            $count = (int)$cache->get($key);

            if ($count >= $limit) {
                return false;
            }

            $cache->set($key, $count + 1, 120);

            return true;
        } finally {
            $mutex->release($key);
        }
    }
}
