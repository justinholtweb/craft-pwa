<?php

namespace justinholtweb\pwa\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use DateTime;

/**
 * A broadcast — one notification, sent to a chosen set of devices.
 *
 * Push is the one thing in this plugin that reaches a person who is not currently looking at the
 * site, so it is modelled as something with a record and a state rather than as a method call. A
 * campaign can be drafted, previewed, queued, and read back afterwards with its delivery counts,
 * because "did that actually go out, and to how many?" is asked every single time.
 */
class Campaign extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public ?int $id = null;
    public ?string $uid = null;

    /** Null broadcasts to every site. */
    public ?int $siteId = null;

    public string $title = '';
    public string $body = '';

    /** Where a tap lands. Relative URIs are resolved against the site. */
    public string $url = '';

    public string $icon = '';
    public string $badge = '';

    /**
     * Collapse key. Two notifications with the same tag replace each other on the device.
     *
     * Worth setting for anything that supersedes itself — a score, a status, a delivery — and
     * worth leaving empty for anything that does not, or the second article of the day silently
     * eats the first.
     */
    public string $tag = '';

    /** Keep the notification on screen until it is acted on. Ignored by most mobile browsers. */
    public bool $requireInteraction = false;

    /** @var string[] */
    public array $topics = [];

    /** Set when the campaign was raised by publishing an entry. */
    public ?int $entryId = null;

    public string $status = self::STATUS_DRAFT;

    public ?DateTime $dateScheduled = null;
    public ?DateTime $dateSent = null;

    public int $targeted = 0;
    public int $delivered = 0;
    public int $failed = 0;

    public ?int $createdBy = null;
    public ?DateTime $dateCreated = null;

    public function rules(): array
    {
        return [
            [['title'], 'required'],
            [['title'], 'string', 'max' => 120],
            [['body'], 'string', 'max' => 400],
            [['status'], 'in', 'range' => [
                self::STATUS_DRAFT,
                self::STATUS_SCHEDULED,
                self::STATUS_SENDING,
                self::STATUS_SENT,
                self::STATUS_FAILED,
            ]],
        ];
    }

    public function getEntry(): ?Entry
    {
        return $this->entryId ? Entry::find()->id($this->entryId)->status(null)->one() : null;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => Craft::t('pwa', 'Draft'),
            self::STATUS_SCHEDULED => Craft::t('pwa', 'Scheduled'),
            self::STATUS_SENDING => Craft::t('pwa', 'Sending'),
            self::STATUS_SENT => Craft::t('pwa', 'Sent'),
            self::STATUS_FAILED => Craft::t('pwa', 'Failed'),
            default => $this->status,
        };
    }

    /**
     * The notification as the service worker's `push` handler receives it.
     *
     * Kept small on purpose. A push payload has a hard ceiling of about 4KB *after* encryption,
     * and the parts that vary — a long title, a URL with tracking parameters — are exactly the
     * parts that push it over on the one message that mattered.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];

        foreach (['icon' => $this->icon, 'badge' => $this->badge, 'tag' => $this->tag] as $key => $value) {
            if (trim((string)$value) !== '') {
                $payload[$key] = $value;
            }
        }

        if ($this->requireInteraction) {
            $payload['requireInteraction'] = true;
        }

        if ($this->id !== null) {
            $payload['campaign'] = $this->id;
        }

        return $payload;
    }
}
