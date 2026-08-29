<?php

namespace justinholtweb\pwa\records;

use craft\db\ActiveRecord;

/**
 * One device that has agreed to receive push.
 *
 * @property int $id
 * @property int|null $siteId
 * @property int|null $userId
 * @property string $endpoint
 * @property string $endpointHash
 * @property string $p256dh
 * @property string $auth
 * @property string $contentEncoding
 * @property array|string|null $topics
 * @property string|null $userAgent
 * @property string|null $platform
 * @property int $failures
 * @property string|null $dateLastSeen
 */
class SubscriberRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pwa_subscribers}}';
    }
}
