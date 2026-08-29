<?php

namespace justinholtweb\pwa\helpers;

use Craft;
use craft\models\Site;

/**
 * Looking a site up by UID without an exception.
 *
 * `Sites::getSiteByUid()` throws when the UID is unknown — which is right for Craft's own code and
 * wrong here, because this plugin keys its per-site configuration by UID and that configuration
 * outlives the site. Delete a site and the manifest for it is still in project config; a manifest
 * that threw a SiteNotFoundException while rendering a settings screen would make the site
 * impossible to remove from the settings screen that removes it.
 */
class Sites
{
    public static function byUid(?string $uid): ?Site
    {
        if ($uid === null || $uid === '') {
            return null;
        }

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            if ($site->uid === $uid) {
                return $site;
            }
        }

        return null;
    }
}
