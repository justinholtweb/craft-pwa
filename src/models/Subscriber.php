<?php

namespace justinholtweb\pwa\models;

use craft\base\Model;
use DateTime;

/**
 * One push subscription — one browser profile on one device.
 *
 * Not one person. The same reader on a phone and a laptop is two subscribers, and clearing site
 * data makes a third, which is why the number on the dashboard is labelled "devices" and why
 * unsubscribing has to be idempotent.
 *
 * The three fields that matter are the endpoint (the push service's own URL for this device) and
 * the two keys. `p256dh` is the device's public key, used to derive a shared secret with a
 * throwaway keypair for every message; `auth` is a shared salt. Together they mean the push
 * service relays a payload it cannot read — the encryption is end to end, and the only party that
 * can decrypt is the browser that produced the keys.
 */
class Subscriber extends Model
{
    public ?int $id = null;
    public ?string $uid = null;
    public ?int $siteId = null;

    /** Set when the visitor was logged in at subscribe time. Push does not require an account. */
    public ?int $userId = null;

    public string $endpoint = '';
    public string $p256dh = '';
    public string $auth = '';

    /**
     * Payload encoding the browser advertised.
     *
     * `aes128gcm` (RFC 8188) is what everything current uses. The older `aesgcm` draft is not
     * supported: it is a different header layout for the same crypto, and carrying both doubles
     * the surface of the one part of this plugin where a mistake is silent.
     */
    public string $contentEncoding = 'aes128gcm';

    /** @var string[] */
    public array $topics = [];

    public string $userAgent = '';
    public string $platform = '';

    /** Consecutive delivery failures. Reset on success, and the reason a dead device ages out. */
    public int $failures = 0;

    public ?DateTime $dateLastSeen = null;
    public ?DateTime $dateCreated = null;

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['endpoint', 'p256dh', 'auth'], 'required'],
            [['endpoint'], 'url', 'defaultScheme' => 'https'],
            [['endpoint'], 'string', 'max' => 1000],
        ]);
    }

    /** The push service a subscription belongs to — FCM, Mozilla, WNS — for the CP breakdown. */
    public function getService(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST) ?: '';

        return match (true) {
            str_contains($host, 'googleapis.com') || str_contains($host, 'fcm.') => 'Google',
            str_contains($host, 'mozilla.com') || str_contains($host, 'mozaws.net') => 'Mozilla',
            str_contains($host, 'windows.com') || str_contains($host, 'notify.windows') => 'Microsoft',
            str_contains($host, 'push.apple.com') => 'Apple',
            $host === '' => '—',
            default => $host,
        };
    }
}
