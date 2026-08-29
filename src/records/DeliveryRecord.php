<?php

namespace justinholtweb\pwa\records;

use craft\db\ActiveRecord;

/**
 * What happened when one campaign was pushed to one device.
 *
 * @property int $id
 * @property int $campaignId
 * @property int|null $subscriberId
 * @property string $status
 * @property int|null $statusCode
 * @property string|null $error
 */
class DeliveryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pwa_deliveries}}';
    }
}
