<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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

        if (!str_starts_with($endpoint, 'https://')) {
            return null;
        }

        $hash = hash('sha256', $endpoint);
        $record = SubscriberRecord::findOne(['endpointHash' => $hash]) ?? new SubscriberRecord();

        $record->siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $record->userId = $userId;
        $record->endpoint = $endpoint;
        $record->endpointHash = $hash;
        $record->p256dh = $p256dh;
        $record->auth = $auth;
        $record->contentEncoding = 'aes128gcm';
        $record->topics = Json::encode(array_values(array_unique(array_map('strval', $topics))));
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

    /** @return Subscriber[] */
    public function getSubscribers(?int $siteId = null, array $topics = [], int $offset = 0, ?int $limit = null): array
    {
        $query = SubscriberRecord::find()->orderBy(['id' => SORT_ASC]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($offset > 0) {
            $query->offset($offset);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $subscribers = array_map(fn(SubscriberRecord $r) => $this->toModel($r), $query->all());

        if (empty($topics)) {
            return $subscribers;
        }

        // Topic filtering happens here rather than in SQL: JSON containment is expressed
        // differently on MySQL and Postgres, and the subscriber list a broadcast walks is already
        // being paged through in memory.
        return array_values(array_filter(
            $subscribers,
            static fn(Subscriber $s) => array_intersect($topics, $s->topics) !== [],
        ));
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
        $settings = Plugin::getInstance()->getSettings();
        $subject = trim((string)App::parseEnv($settings->pushSubject));

        if ($subject === '') {
            // Some push services accept an absent `sub`; enough of them do not that sending
            // without one is a coin flip, so a sane default beats an intermittent failure.
            $subject = 'mailto:' . (Craft::$app->getProjectConfig()->get('email.fromEmail') ?? 'webmaster@' . Craft::$app->getRequest()->getHostName());
        }

        try {
            $payload = Json::encode($campaign->toPayload());

            if (strlen($payload) > Encryptor::MAX_PAYLOAD) {
                return ['failed', 413, 'The notification payload is too large.'];
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
            ]);

            $code = $response->getStatusCode();

            if ($code >= 200 && $code < 300) {
                $this->markSeen($subscriber);
                return ['delivered', $code, null];
            }

            if ($code === 404 || $code === 410) {
                $this->unsubscribe($subscriber->endpoint);
                return ['gone', $code, 'The push service has retired this subscription.'];
            }

            $this->markFailed($subscriber);

            return ['failed', $code, substr((string)$response->getBody(), 0, 500)];
        } catch (ConnectException $e) {
            $this->markFailed($subscriber);
            return ['failed', null, 'Could not reach the push service: ' . $e->getMessage()];
        } catch (RequestException $e) {
            $this->markFailed($subscriber);
            return ['failed', $e->getResponse()?->getStatusCode(), $e->getMessage()];
        } catch (Throwable $e) {
            Plugin::error('Push send failed: ' . $e->getMessage());
            return ['failed', null, $e->getMessage()];
        }
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
            'dateLastSeen' => $record->dateLastSeen ? new DateTime($record->dateLastSeen) : null,
            'dateCreated' => $record->dateCreated ? new DateTime($record->dateCreated) : null,
        ]);
    }
}
