<?php

namespace justinholtweb\pwa\records;

use craft\db\ActiveRecord;

/**
 * A single thing a visitor's browser reported — an install, a launch, an offline hit.
 *
 * @property int $id
 * @property int|null $siteId
 * @property string $type
 * @property string|null $path
 * @property string|null $platform
 */
class EventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pwa_events}}';
    }
}
