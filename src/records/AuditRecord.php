<?php

namespace justinholtweb\pwa\records;

use craft\db\ActiveRecord;

/**
 * A stored preflight report.
 *
 * @property int $id
 * @property int|null $siteId
 * @property string $trigger
 * @property int $score
 * @property bool $installable
 * @property int $passed
 * @property int $warned
 * @property int $failed
 * @property int $skipped
 * @property array|string|null $checks
 */
class AuditRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pwa_audits}}';
    }
}
