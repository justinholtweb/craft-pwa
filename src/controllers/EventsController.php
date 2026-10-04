<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pwa\helpers\RateLimit;
use justinholtweb\pwa\Plugin;
use yii\web\Response;

/**
 * The black box recorder's one endpoint.
 *
 * CSRF validation is off, and that is a considered decision rather than an oversight. Two of the
 * callers — the service worker recording an offline hit, and `sendBeacon` firing as the page is
 * being closed after an install — have no access to a token, and the alternative is losing exactly
 * the events worth having. The exposure is bounded by what the endpoint can do: it appends a row
 * with a type from a fixed list of six and a truncated path, tied to no user and no session, and a
 * per-address rate limit stops a script filling the table. The worst a forged request achieves is
 * a wrong number on a dashboard.
 */
class EventsController extends Controller
{
    /** Events allowed per address per minute — far more than a person installing an app sends. */
    public const PER_MINUTE = 60;

    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionRecord(): Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled || !$settings->trackEvents) {
            return $this->asJson(['recorded' => false]);
        }

        if (!RateLimit::allow('events', self::PER_MINUTE)) {
            $this->response->setStatusCode(429);

            return $this->asJson(['recorded' => false]);
        }

        $type = (string)$this->request->getBodyParam('type', '');
        $path = $this->request->getBodyParam('path');

        $recorded = Plugin::getInstance()->events->record(
            $type,
            is_string($path) ? $path : null,
            Craft::$app->getSites()->getCurrentSite()->id,
        );

        return $this->asJson(['recorded' => $recorded]);
    }
}
