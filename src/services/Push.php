<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\pwa\helpers\RateLimit;
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\models\Subscriber;
use justinholtweb\pwa\Plugin;
use justinholtweb\pwa\push\Encryptor;
use justinholtweb\pwa\records\KeyRecord;
use justinholtweb\pwa\records\SubscriberRecord;
use Throwable;

/**
 * The push side: keys, devices, and getting one message to one device.
 *
 * Everything about push is asymmetric. Subscribing is cheap, public and happens thousands of
 * times; sending is expensive, authenticated and happens once per device per message. The two
 * halves therefore have very different failure handling — a subscribe that fails is retried by the
 * browser, while a send that fails has to decide, right there, whether the device is temporarily
 * unreachable or permanently gone.
 *
 * That decision is the reason this service exists rather than a helper: a push list that never
 * removes dead endpoints spends the rest of its life pushing to browsers that were uninstalled.
 */
class Push extends Component
{
    /**
     * The push services browsers actually use: Chrome, Edge-on-Chromium, Opera and Samsung
     * (FCM), Firefox (Mozilla autopush), legacy Edge (WNS) and Safari (Apple).
     *
     * A subscription's endpoint comes from an anonymous, CSRF-exempt request, and every broadcast
     * POSTs to it from the server. Before the allow-list, anything starting `https://` was taken —
     * an internal host, or a public one redirecting to cloud metadata — and the first 500 bytes of
     * its answer were kept in the delivery log for anyone with the broadcast permission to read.
     */
    public const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        'web.push.apple.com',
        '*.push.apple.com',
    ];

    /** The most topics one subscription may carry, and the shape of each. */
    public const MAX_TOPICS = 20;
    private const TOPIC_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /**
     * Whether an endpoint is on a push service this plugin will send to: https, the default port,
     * no credentials, a host name rather than an address, and a host on the allow-list.
     */
    public static function isPushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        // An address is never a push service, whatever `extraPushHosts` says: listing one there
        // would hand the server's network position to anybody able to subscribe.
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $extra = Plugin::getInstance()?->getSettings()->extraPushHosts ?? [];

        foreach (array_merge(self::PUSH_HOSTS, $extra) as $allowed) {
            $allowed = strtolower(trim((string)$allowed));

            if ($allowed === $host) {
                return true;
            }

            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)) && strlen($host) > strlen($allowed) - 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Topics as a browser sent them, kept to a bounded list of short names.
     *
     * @return string[]
     */
    public static function cleanTopics(array $topics): array
    {
        $clean = [];

        foreach ($topics as $topic) {
            if (is_string($topic) && preg_match(self::TOPIC_PATTERN, $topic)) {
                $clean[$topic] = $topic;
            }
        }

        return array_slice(array_values($clean), 0, self::MAX_TOPICS);
    }

    /**
     * Whether a subscription's two keys are the shape RFC 8291 says they are.
     *
     * `p256dh` is an uncompressed P-256 point — 65 bytes, starting 0x04 — and `auth` a 16-byte
     * secret, both base64url. Anything else cannot be encrypted to, and storing it only means a
     * row that fails at every send.
     */
    public static function validKeys(string $p256dh, string $auth): bool
    {
        $decode = static function(string $value): ?string {
            if ($value === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
                return null;
            }

            $raw = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

            return $raw === false ? null : $raw;
        };

        $key = $decode($p256dh);
        $secret = $decode($auth);

        return $key !== null && strlen($key) === 65 && $key[0] === "\x04"
            && $secret !== null && strlen($secret) === 16;
    }

    /** How long a push service should hold a message for a device that is offline. Four weeks. */
    private const TTL = 2419200;

    private ?array $keys = null;

    // ------------------------------------------------------------------------------ keys

    /**
     * The VAPID public key, generated on first use.
     *
     * Generated rather than configured because there is nothing a human can usefully choose here,
     * and every minute somebody spends running `openssl ecparam` at a shell is a minute spent on
     * the least interesting part of this.
     */
    public function getPublicKey(): string
    {
        return $this->getKeys()['publicKey'];
    }

    public function getPrivateKey(): string
    {
        return $this->getKeys()['privateKey'];
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function getKeys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        // An env-supplied pair wins, so a site that already has VAPID keys — from a previous
        // stack, or shared with a native app — keeps its existing subscriptions working.
        $envPublic = trim((string)App::env('PWA_VAPID_PUBLIC_KEY'));
        $envPrivate = trim((string)App::env('PWA_VAPID_PRIVATE_KEY'));

        if ($envPublic !== '' && $envPrivate !== '') {
            $private = str_contains($envPrivate, 'BEGIN')
                ? $envPrivate
                : Encryptor::importPrivateKey($envPublic, $envPrivate);

            return $this->keys = ['publicKey' => $envPublic, 'privateKey' => $private];
        }

        $record = KeyRecord::find()->one();

        if ($record === null) {
            return $this->keys = $this->generateKeys();
        }

        /** @var KeyRecord $record */
        return $this->keys = [
            'publicKey' => (string)$record->publicKey,
            'privateKey' => (string)$record->privateKey,
        ];
    }

    /**
     * Replaces the keypair, orphaning every existing subscription.
     *
     * Every subscription a browser holds is bound to the public key it was created with, so after
     * this every device on the list will refuse messages until it resubscribes. The CP says so
     * plainly and asks twice; nothing about it is recoverable afterwards.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public function rotateKeys(bool $dropSubscribers = true): array
    {
        KeyRecord::deleteAll();
        $this->keys = null;

        if ($dropSubscribers) {
            SubscriberRecord::deleteAll();
        }

        Plugin::info('VAPID keypair rotated' . ($dropSubscribers ? ' and subscribers cleared.' : '.'));

        return $this->generateKeys();
    }

    /** @return array{publicKey: string, privateKey: string} */
    private function generateKeys(): array
    {
        $keys = Encryptor::generateKeys();

        $record = new KeyRecord();
        $record->publicKey = $keys['publicKey'];
        $record->privateKey = $keys['privateKey'];
        $record->save(false);

        Plugin::info('Generated a VAPID keypair.');

        return $this->keys = $keys;
    }

    // ----------------------------------------------------------------------- subscriptions

    /**
     * Records a subscription, or refreshes the one this browser already had.
     *
     * The endpoint is the identity. A browser that resubscribes — because permissions were reset,
     * because the push service rotated it, because the worker updated — presents a new endpoint
     * and the old one simply stops being written to. Matching on the endpoint hash makes the whole
     * operation idempotent, which matters because the client retries it on every page load it
     * cannot confirm.
     *
     * @param array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}} $subscription
     */
    public function subscribe(array $subscription, ?int $userId = null, ?int $siteId = null, array $topics = []): ?Subscriber
    {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $p256dh = trim((string)($subscription['keys']['p256dh'] ?? ''));
        $auth = trim((string)($subscription['keys']['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return null;
        }

        if (strlen($endpoint) > 2048 || !self::isPushEndpoint($endpoint) || !self::validKeys($p256dh, $auth)) {
            return null;
        }

        $hash = hash('sha256', $endpoint);
        $record = SubscriberRecord::findOne(['endpointHash' => $hash]);

        if ($record === null) {
            // Only a *new* device is capped. A known one refreshing its row costs nothing and
            // must keep working when the list is full, or every resubscribe after an endpoint
            // rotation would be refused.
            $settings = Plugin::getInstance()->getSettings();

            if ($settings->pushMaxSubscribers > 0 && $this->countSubscribers() >= $settings->pushMaxSubscribers) {
                Plugin::warning('A subscription was refused: the subscriber table is at its ceiling of ' . $settings->pushMaxSubscribers . '.');
                return null;
            }

            if (!RateLimit::allowGlobal('push-new', $settings->pushNewPerMinute)) {
                Plugin::warning('A subscription was refused: more than ' . $settings->pushNewPerMinute . ' new devices this minute.');
                return null;
            }

            $record = new SubscriberRecord();
        }

        $record->siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $record->userId = $userId;
        $record->endpoint = $endpoint;
        $record->endpointHash = $hash;
        $record->p256dh = $p256dh;
        $record->auth = $auth;
        $record->contentEncoding = 'aes128gcm';
        $record->topics = Json::encode(self::cleanTopics($topics));
        $record->userAgent = substr((string)Craft::$app->getRequest()->getUserAgent(), 0, 500);
        $record->failures = 0;
        $record->dateLastSeen = Db::prepareDateForDb(new DateTime());

        if (!$record->save()) {
            Plugin::error('Could not save a push subscription: ' . Json::encode($record->getErrors()));
            return null;
        }

        return $this->toModel($record);
    }

    /** Forgets a device. Returns whether there was anything to forget. */
    public function unsubscribe(string $endpoint): bool
    {
        return SubscriberRecord::deleteAll(['endpointHash' => hash('sha256', trim($endpoint))]) > 0;
    }

    /**
     * Subscribers in ID order, from after a given ID.
     *
     * Keyset rather than offset: a broadcast deletes devices as it walks the list, and an offset
     * into a shrinking table skips whoever slid back into the gap.
     *
     * @return Subscriber[]
     */
    public function getSubscribers(?int $siteId = null, array $topics = [], int $afterId = 0, ?int $limit = null): array
    {
        return $this->subscriberPage($siteId, $topics, $afterId, $limit)['subscribers'];
    }

    /**
     * One page of the list, and where it ended.
     *
     * `walked` and `lastId` describe the rows read, before topic filtering — so a page whose rows
     * all subscribed to other topics is an empty page, not the end of the list.
     *
     * @return array{subscribers: Subscriber[], walked: int, lastId: int}
     */
    public function subscriberPage(?int $siteId, array $topics, int $afterId, ?int $limit): array
    {
        $query = SubscriberRecord::find()->orderBy(['id' => SORT_ASC]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($afterId > 0) {
            $query->andWhere(['>', 'id', $afterId]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var SubscriberRecord[] $records */
        $records = $query->all();
        $lastId = $records === [] ? $afterId : (int)end($records)->id;
        $subscribers = array_map(fn(SubscriberRecord $r) => $this->toModel($r), $records);

        if (!empty($topics)) {
            // Topic filtering happens here rather than in SQL: JSON containment is expressed
            // differently on MySQL and Postgres. The page is bounded, so the cost is too.
            $subscribers = array_values(array_filter(
                $subscribers,
                static fn(Subscriber $s) => array_intersect($topics, $s->topics) !== [],
            ));
        }

        return ['subscribers' => $subscribers, 'walked' => count($records), 'lastId' => $lastId];
    }

    /**
     * How many devices a broadcast to these topics would reach.
     *
     * A plain SQL count when there are no topics. With topics, the topic column is walked in
     * pages of IDs and topics only — never the whole table in memory at once.
     */
    public function countMatching(?int $siteId = null, array $topics = []): int
    {
        if (empty($topics)) {
            return $this->countSubscribers($siteId);
        }

        $count = 0;
        $afterId = 0;

        do {
            $query = (new Query())
                ->select(['id', 'topics'])
                ->from(SubscriberRecord::tableName())
                ->where(['>', 'id', $afterId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(1000);

            if ($siteId !== null) {
                $query->andWhere(['siteId' => $siteId]);
            }

            $rows = $query->all();

            foreach ($rows as $row) {
                $afterId = (int)$row['id'];
                $subscribed = is_string($row['topics']) ? (array)Json::decodeIfJson($row['topics']) : (array)($row['topics'] ?? []);

                if (array_intersect($topics, $subscribed) !== []) {
                    $count++;
                }
            }
        } while (count($rows) === 1000);

        return $count;
    }

    /**
     * Devices per push service, counted in SQL.
     *
     * Grouped on the endpoint's host, which is what decides the service, and mapped to a name
     * afterwards — so the breakdown covers every device rather than the first page of them.
     *
     * @return array<string, int>
     */
    public function countByService(): array
    {
        $db = Craft::$app->getDb();
        $host = $db->getIsMysql()
            ? "SUBSTRING_INDEX(SUBSTRING_INDEX([[endpoint]], '/', 3), '/', -1)"
            : "SPLIT_PART([[endpoint]], '/', 3)";

        $rows = (new Query())
            ->select(['host' => new \yii\db\Expression($host), 'total' => new \yii\db\Expression('COUNT(*)')])
            ->from(SubscriberRecord::tableName())
            ->groupBy([new \yii\db\Expression($host)])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $service = (new Subscriber(['endpoint' => 'https://' . $row['host'] . '/']))->getService();
            $out[$service] = ($out[$service] ?? 0) + (int)$row['total'];
        }

        arsort($out);

        return $out;
    }

    public function getSubscriberByEndpointHash(string $hash): ?Subscriber
    {
        $record = SubscriberRecord::findOne(['endpointHash' => $hash]);

        return $record ? $this->toModel($record) : null;
    }

    public function countSubscribers(?int $siteId = null): int
    {
        $query = SubscriberRecord::find();

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return (int)$query->count();
    }

    public function getSubscriberById(int $id): ?Subscriber
    {
        $record = SubscriberRecord::findOne($id);

        return $record ? $this->toModel($record) : null;
    }

    // -------------------------------------------------------------------------- delivery

    /**
     * Sends one campaign to one device.
     *
     * Returns `[status, statusCode, error]` where status is `delivered`, `failed` or `gone`. The
     * distinction between the last two is the whole point:
     *
     * - **404 / 410** mean the push service has never heard of this endpoint or has retired it.
     *   The device is gone and the row is deleted. Retrying is pointless forever.
     * - **429 / 5xx** mean the push service is busy or broken. The device is fine; the failure
     *   counter goes up and the next broadcast tries again.
     * - **413** means the payload exceeded the ceiling, which is a mistake in the message rather
     *   than a problem with the device.
     *
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    public function send(Subscriber $subscriber, Campaign $campaign): array
    {
        // Checked again here, not only at subscribe: rows saved before the allow-list existed, or
        // after a host was removed from `extraPushHosts`, must not be posted to either.
        if (!self::isPushEndpoint($subscriber->endpoint)) {
            $this->unsubscribe($subscriber->endpoint);

            return ['gone', null, Craft::t('pwa', 'The subscription’s endpoint is not a known push service, so it was removed.')];
        }

        $settings = Plugin::getInstance()->getSettings();
        $subject = trim((string)App::parseEnv($settings->pushSubject));

        if ($subject === '') {
            $subject = self::defaultSubject();
        }

        try {
            $payload = Json::encode($campaign->toPayload());

            if (strlen($payload) > Encryptor::MAX_PAYLOAD) {
                return ['failed', 413, Craft::t('pwa', 'The notification payload is too large.')];
            }

            $body = Encryptor::encrypt($payload, $subscriber->p256dh, $subscriber->auth);

            $headers = [
                'Authorization' => Encryptor::vapidHeader(
                    $subscriber->endpoint,
                    $subject,
                    $this->getPublicKey(),
                    $this->getPrivateKey(),
                ),
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string)self::TTL,
                'Urgency' => 'normal',
            ];

            if (trim($campaign->tag) !== '') {
                // `Topic` lets the push service replace an undelivered message with a newer one
                // for the same subject, rather than delivering a queue of stale ones at once when
                // the device comes back.
                $headers['Topic'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $campaign->tag) ?: '', 0, 32);
            }

            $response = Craft::createGuzzleClient(['timeout' => 10])->request('POST', $subscriber->endpoint, [
                'headers' => $headers,
                'body' => $body,
                'http_errors' => false,
                // A push service answers; it never redirects. Following one would undo the
                // allow-list — a listed host's open redirect could still reach anywhere.
                'allow_redirects' => false,
            ]);

            $code = $response->getStatusCode();

            if ($code >= 200 && $code < 300) {
                $this->markSeen($subscriber);
                return ['delivered', $code, null];
            }

            if ($code === 404 || $code === 410) {
                $this->unsubscribe($subscriber->endpoint);
                return ['gone', $code, Craft::t('pwa', 'The push service has retired this subscription.')];
            }

            $this->markFailed($subscriber);

            return ['failed', $code, substr((string)$response->getBody(), 0, 500)];
        } catch (ConnectException $e) {
            $this->markFailed($subscriber);
            return ['failed', null, Craft::t('pwa', 'Could not reach the push service: {error}', ['error' => $e->getMessage()])];
        } catch (RequestException $e) {
            $this->markFailed($subscriber);
            return ['failed', $e->getResponse()?->getStatusCode(), $e->getMessage()];
        } catch (Throwable $e) {
            // Counted like any other failure: a subscription whose keys cannot be encrypted to
            // fails the same way every time, and must age out rather than be retried forever.
            Plugin::error('Push send failed: ' . $e->getMessage());
            $this->markFailed($subscriber);

            return ['failed', null, $e->getMessage()];
        }
    }

    /**
     * The VAPID `sub` claim when none is configured.
     *
     * Some push services accept an absent `sub`; enough of them do not that sending without one is
     * a coin flip, so a sane default beats an intermittent failure. Built from the system email
     * settings and the primary site, not the request — sends happen in queue jobs and console
     * commands, where there is no request host to borrow.
     */
    public static function defaultSubject(): string
    {
        $from = trim((string)App::parseEnv((string)(App::mailSettings()->fromEmail ?? '')));

        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $from;
        }

        $baseUrl = (string)App::parseEnv((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl());
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);

        return 'mailto:webmaster@' . ($host !== '' ? $host : 'localhost');
    }

    private function markSeen(Subscriber $subscriber): void
    {
        if ($subscriber->id === null) {
            return;
        }

        Craft::$app->getDb()->createCommand()->update(
            '{{%pwa_subscribers}}',
            ['failures' => 0, 'dateLastSeen' => Db::prepareDateForDb(new DateTime())],
            ['id' => $subscriber->id],
        )->execute();
    }

    private function markFailed(Subscriber $subscriber): void
    {
        if ($subscriber->id === null) {
            return;
        }

        $failures = $subscriber->failures + 1;
        $max = Plugin::getInstance()->getSettings()->pushMaxFailures;

        if ($failures >= $max) {
            // Not an error the site can act on, and not worth a log line each time: a device that
            // has been unreachable this many times in a row is a device that is not coming back.
            SubscriberRecord::deleteAll(['id' => $subscriber->id]);
            return;
        }

        Craft::$app->getDb()->createCommand()->update(
            '{{%pwa_subscribers}}',
            ['failures' => $failures],
            ['id' => $subscriber->id],
        )->execute();
    }

    private function toModel(SubscriberRecord $record): Subscriber
    {
        $topics = $record->topics;

        return new Subscriber([
            'id' => (int)$record->id,
            'uid' => (string)$record->uid,
            'siteId' => $record->siteId !== null ? (int)$record->siteId : null,
            'userId' => $record->userId !== null ? (int)$record->userId : null,
            'endpoint' => (string)$record->endpoint,
            'p256dh' => (string)$record->p256dh,
            'auth' => (string)$record->auth,
            'contentEncoding' => (string)$record->contentEncoding,
            'topics' => is_string($topics) ? (array)Json::decodeIfJson($topics) : (array)($topics ?? []),
            'userAgent' => (string)$record->userAgent,
            'platform' => (string)$record->platform,
            'failures' => (int)$record->failures,
            'dateLastSeen' => $record->dateLastSeen ? (DateTimeHelper::toDateTime($record->dateLastSeen) ?: null) : null,
            'dateCreated' => $record->dateCreated ? (DateTimeHelper::toDateTime($record->dateCreated) ?: null) : null,
        ]);
    }
}
