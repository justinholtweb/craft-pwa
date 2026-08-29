<?php

namespace justinholtweb\pwa\records;

use craft\db\ActiveRecord;

/**
 * One broadcast.
 *
 * @property int $id
 * @property int|null $siteId
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property string|null $icon
 * @property string|null $badge
 * @property string|null $tag
 * @property bool $requireInteraction
 * @property array|string|null $topics
 * @property int|null $entryId
 * @property string $status
 * @property string|null $dateScheduled
 * @property string|null $dateSent
 * @property int $targeted
 * @property int $delivered
 * @property int $failed
 * @property int|null $createdBy
 */
class CampaignRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pwa_campaigns}}';
    }
}
