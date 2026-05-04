<?php

namespace pixelwerft\useraudit\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId v2.0+ FK to {{%elements}}.id
 * @property int|null $userId
 * @property string|null $email
 * @property string|null $userGroups
 * @property string $eventType
 * @property string|null $context
 * @property string|null $client
 * @property string|null $failureReason
 * @property string|null $ipAddress
 * @property string|null $userAgent
 * @property string|null $deviceType
 * @property string|null $osName
 * @property string|null $osVersion
 * @property string|null $browserName
 * @property string|null $browserVersion
 * @property string|null $metadata
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class UserActivityLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%user_activity_log}}';
    }
}
