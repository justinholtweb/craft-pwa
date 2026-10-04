<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pwa\helpers\RateLimit;
use justinholtweb\pwa\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * Where browsers hand over and take back their push subscriptions.
 *
 * Anonymous, because push does not require an account and a site that made it require one would
 * be a site nobody subscribes to. CSRF validation is off for the same reason as the event
 * endpoint: the service worker re-subscribes on `pushsubscriptionchange` without a page, and
 * therefore without a token.
 *
 * What that costs is worth stating plainly. A forged subscribe adds *the attacker's own device* to
 * the list — they cannot produce a subscription for somebody else's browser, because the keys come
 * from that browser's own push manager. So the risk is junk rows, not exposure. It is bounded by a
 * per-address rate limit, a cap on topics, and an endpoint that must be on a real push service —
 * the server POSTs to it on every broadcast, so "any https URL" made it a way to reach internal
 * hosts (see Push::PUSH_HOSTS).
 */
class PushController extends Controller
{
    /** Subscribe and unsubscribe requests allowed per address per minute. */
    public const PER_MINUTE = 10;

    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->enabled || !$plugin->getSettings()->pushEnabled || !$plugin->isPro()) {
            throw new ForbiddenHttpException(Craft::t('pwa', 'Push is not enabled for this site.'));
        }

        // A browser subscribes once and resubscribes when its endpoint rotates; ten a minute from
        // one address is generous for that and stingy for a script filling the table.
        if (!RateLimit::allow('push', self::PER_MINUTE)) {
            throw new TooManyRequestsHttpException(Craft::t('pwa', 'Too many push subscription requests. Try again in a minute.'));
        }

        return true;
    }

    public function actionSubscribe(): Response
    {
        $this->requirePostRequest();

        $subscription = $this->request->getBodyParam('subscription');

        if (!is_array($subscription)) {
            return $this->asJson(['subscribed' => false, 'error' => Craft::t('pwa', 'No subscription was sent.')]);
        }

        $topics = $this->request->getBodyParam('topics', []);

        $subscriber = Plugin::getInstance()->push->subscribe(
            $subscription,
            Craft::$app->getUser()->getId(),
            Craft::$app->getSites()->getCurrentSite()->id,
            is_array($topics) ? $topics : [],
        );

        if ($subscriber === null) {
            return $this->asJson(['subscribed' => false, 'error' => Craft::t('pwa', 'The subscription was not usable.')]);
        }

        // A resubscribe by a browser that had rotated its endpoint leaves the old row behind,
        // and the old endpoint is the only thing that identifies it.
        $previous = $this->request->getBodyParam('previous');

        if (is_string($previous) && $previous !== '' && $previous !== $subscriber->endpoint) {
            Plugin::getInstance()->push->unsubscribe($previous);
        }

        return $this->asJson(['subscribed' => true, 'uid' => $subscriber->uid]);
    }

    public function actionUnsubscribe(): Response
    {
        $this->requirePostRequest();

        $endpoint = (string)$this->request->getBodyParam('endpoint', '');

        if ($endpoint === '') {
            return $this->asJson(['unsubscribed' => false]);
        }

        return $this->asJson([
            'unsubscribed' => Plugin::getInstance()->push->unsubscribe($endpoint),
        ]);
    }
}
