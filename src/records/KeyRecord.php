<?php

namespace justinholtweb\pwa\records;

use craft\db\ActiveRecord;

/**
 * The VAPID keypair, kept out of project config on purpose.
 *
 * @property int $id
 * @property string $publicKey
 * @property string $privateKey
 */
class KeyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pwa_keys}}';
    }
}
