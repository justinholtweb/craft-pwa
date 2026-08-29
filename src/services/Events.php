<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use justinholtweb\pwa\Plugin;
use justinholtweb\pwa\records\EventRecord;

/**
 * The black box: what visitors' browsers report back.
 *
 * Six event types, no identifiers, no cookies, nothing that could name a person — a row says that
 * *an* install happened, on *a* platform, from *a* path, at a time. That is enough to answer the
 * only questions anybody asks of a PWA ("is anybody installing it?", "what are people hitting
 * offline?") and not enough to be an analytics product, which is deliberate: this plugin has no
 * business building a profile of anybody.
 *
 * The endpoint that writes these is public and unauthenticated, because the browser reporting an
 * install has no session. So the type is checked against a fixed list and everything else is
 * truncated — the worst an abusive caller can do is inflate a number on a dashboard.
 */
class Events extends Component
{
    public const TYPES = [
        'prompt',              // the install prompt was shown
        'dismiss',             // and dismissed
        'install',             // the app was installed
        'launch',              // opened from the home screen rather than a browser tab
        'offline',             // a page was served, or refused, while offline
        'notification-click',  // a push notification was tapped
    ];

    public function record(string $type, ?string $path = null, ?int $siteId = null, ?string $platform = null): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            return false;
        }

        if (!Plugin::getInstance()->getSettings()->trackEvents) {
            return false;
        }

        $record = new EventRecord();
        $record->type = $type;
        $record->siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $record->path = $path !== null ? substr($path, 0, 1000) : null;
        $record->platform = $platform !== null ? substr($platform, 0, 64) : $this->platform();

        return $record->save(false);
    }

    /**
     * Totals per type over a window.
     *
     * @return array<string, int>
     */
    public function totals(int $days = 30, ?int $siteId = null): array
    {
        $query = EventRecord::find()
            ->select(['type', 'COUNT(*) AS total'])
            ->where(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))])
            ->groupBy(['type'])
            ->asArray();

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $totals = array_fill_keys(self::TYPES, 0);

        foreach ($query->all() as $row) {
            $totals[(string)$row['type']] = (int)$row['total'];
        }

        return $totals;
    }

    /**
     * A daily series for one type, with the empty days filled in.
     *
     * Filled because a chart drawn from only the days that had events lies about the ones that
     * did not — a week with two installs on Monday looks like a week of steady installs.
     *
     * @return array<string, int>
     */
    public function series(string $type, int $days = 30, ?int $siteId = null): array
    {
        $query = EventRecord::find()
            ->select(['DATE([[dateCreated]]) AS day', 'COUNT(*) AS total'])
            ->where(['type' => $type])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))])
            ->groupBy(['day'])
            ->asArray();

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $found = [];

        foreach ($query->all() as $row) {
            $found[substr((string)$row['day'], 0, 10)] = (int)$row['total'];
        }

        $series = [];
        $cursor = (new DateTime())->modify('-' . ($days - 1) . ' days');

        for ($i = 0; $i < $days; $i++) {
            $key = $cursor->format('Y-m-d');
            $series[$key] = $found[$key] ?? 0;
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * The paths people hit while offline, most often first.
     *
     * The most directly useful number in the plugin: it is a list of the pages worth precaching.
     *
     * @return array<int, array{path: string, total: int}>
     */
    public function topOfflinePaths(int $days = 30, int $limit = 10, ?int $siteId = null): array
    {
        $query = EventRecord::find()
            ->select(['path', 'COUNT(*) AS total'])
            ->where(['type' => 'offline'])
            ->andWhere(['not', ['path' => null]])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))])
            ->groupBy(['path'])
            ->orderBy(['total' => SORT_DESC])
            ->limit($limit)
            ->asArray();

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return array_map(
            static fn(array $row) => ['path' => (string)$row['path'], 'total' => (int)$row['total']],
            $query->all(),
        );
    }

    public function prune(): int
    {
        $days = Plugin::getInstance()->getSettings()->eventRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        return EventRecord::deleteAll(['<', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
    }

    /** A coarse platform bucket from the user agent. Coarse on purpose: this is not fingerprinting. */
    private function platform(): string
    {
        $ua = strtolower((string)Craft::$app->getRequest()->getUserAgent());

        return match (true) {
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod') => 'ios',
            str_contains($ua, 'windows') => 'windows',
            str_contains($ua, 'mac os') => 'macos',
            str_contains($ua, 'linux') => 'linux',
            default => 'other',
        };
    }
}
