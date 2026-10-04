<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\pwa\helpers\RateLimit;
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Broadcasts and the devices that receive them.
 *
 * Sending is separated from saving by more than a button. A campaign is written, saved, and only
 * then sent, from its own confirmed action — because a notification cannot be recalled, edited or
 * deleted once it has left, and every UI that treats "save" and "send" as one action eventually
 * sends a draft.
 */
class BroadcastController extends Controller
{
    /** Test sends one user may make per minute. */
    public const TEST_PER_MINUTE = 10;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('pwa', 'Push notifications require PWA Pro.'));
        }

        $this->requirePermission(Plugin::PERMISSION_BROADCAST);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('pwa/_broadcast/index', [
            'campaigns' => $plugin->campaigns->getCampaigns(null, 50),
            'subscribers' => $plugin->push->countSubscribers(),
            'pushEnabled' => $plugin->getSettings()->pushEnabled,
        ]);
    }

    public function actionEdit(?int $campaignId = null, ?Campaign $campaign = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($campaign === null) {
            $campaign = $campaignId !== null
                ? $plugin->campaigns->getCampaignById($campaignId)
                : new Campaign();
        }

        if ($campaign === null) {
            throw new NotFoundHttpException();
        }

        return $this->renderTemplate('pwa/_broadcast/edit', [
            'campaign' => $campaign,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'subscribers' => $plugin->push->countMatching($campaign->siteId, $campaign->topics),
            'confirmWord' => self::sendWord(),
            'breakdown' => $campaign->id ? $plugin->campaigns->getDeliveryBreakdown($campaign->id) : [],
            'isNew' => $campaign->id === null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $plugin = Plugin::getInstance();

        $campaignId = $request->getBodyParam('campaignId');
        $campaign = $campaignId ? $plugin->campaigns->getCampaignById((int)$campaignId) : new Campaign();

        if ($campaign === null) {
            throw new NotFoundHttpException();
        }

        // A campaign that has been sent is a record of something that happened. Editing it would
        // make the record describe a message nobody received.
        if ($campaign->status === Campaign::STATUS_SENT || $campaign->status === Campaign::STATUS_SENDING) {
            throw new ForbiddenHttpException(Craft::t('pwa', 'A campaign that has been sent cannot be edited.'));
        }

        $siteId = $request->getBodyParam('siteId');
        $campaign->siteId = $siteId !== null && $siteId !== '' ? (int)$siteId : null;
        $campaign->title = (string)$request->getBodyParam('title', '');
        $campaign->body = (string)$request->getBodyParam('body', '');
        $campaign->url = (string)$request->getBodyParam('url', '');
        $campaign->icon = (string)$request->getBodyParam('icon', '');
        $campaign->badge = (string)$request->getBodyParam('badge', '');
        $campaign->tag = (string)$request->getBodyParam('tag', '');
        $campaign->requireInteraction = (bool)$request->getBodyParam('requireInteraction', false);

        $topics = $request->getBodyParam('topics', '');
        $campaign->topics = is_array($topics)
            ? array_values(array_filter(array_map('trim', $topics)))
            : array_values(array_filter(array_map('trim', explode(',', (string)$topics))));

        $scheduled = $request->getBodyParam('dateScheduled');
        $campaign->dateScheduled = $scheduled ? DateTimeHelper::toDateTime($scheduled) ?: null : null;
        $campaign->status = $campaign->dateScheduled !== null ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_DRAFT;

        if (!$plugin->campaigns->save($campaign)) {
            $this->setFailFlash(Craft::t('pwa', 'Couldn’t save the broadcast.'));

            Craft::$app->getUrlManager()->setRouteParams(['campaign' => $campaign]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('pwa', 'Broadcast saved.'));

        return $this->redirect('pwa/broadcast/' . $campaign->id);
    }

    /** Sends it. There is no undo, and the template says so before this is reachable. */
    public function actionSend(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $campaign = $plugin->campaigns->getCampaignById((int)$this->request->getRequiredBodyParam('campaignId'));

        if ($campaign === null) {
            throw new NotFoundHttpException();
        }

        // The typed confirmation is checked here as well as in the template. A destructive action
        // whose only guard is in the markup is an action with no guard.
        $word = self::sendWord();

        if (mb_strtolower(trim((string)$this->request->getBodyParam('confirm', ''))) !== mb_strtolower($word)) {
            return $this->asFailure(Craft::t('pwa', 'Type “{word}” to confirm.', ['word' => $word]));
        }

        if ($campaign->status === Campaign::STATUS_SENT || $campaign->status === Campaign::STATUS_SENDING) {
            return $this->asFailure(Craft::t('pwa', 'That broadcast has already been sent.'));
        }

        if (!$plugin->getSettings()->pushEnabled) {
            return $this->asFailure(Craft::t('pwa', 'Push is switched off in settings.'));
        }

        if (!$plugin->campaigns->broadcast($campaign)) {
            return $this->asFailure(Craft::t('pwa', 'Couldn’t queue the broadcast.'));
        }

        return $this->asSuccess(
            Craft::t('pwa', 'Queued for {count, plural, =1{# device} other{# devices}}.', ['count' => $campaign->targeted]),
            redirect: 'pwa/broadcast/' . $campaign->id,
        );
    }

    /**
     * Sends only to the person pressing the button.
     *
     * The one thing everybody wants before a real send, and the only honest way to see what a
     * notification looks like: the same code path, the same encryption, one device.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Each press is a real request to a push service, on the site's VAPID key.
        if (!RateLimit::allowFor('push-test', (string)Craft::$app->getUser()->getId(), self::TEST_PER_MINUTE)) {
            return $this->asFailure(Craft::t('pwa', 'That’s a lot of test sends. Try again in a minute.'));
        }

        $plugin = Plugin::getInstance();
        $campaign = $plugin->campaigns->getCampaignById((int)$this->request->getRequiredBodyParam('campaignId'));

        if ($campaign === null) {
            throw new NotFoundHttpException();
        }

        $endpoint = trim((string)$this->request->getRequiredBodyParam('endpoint'));
        $subscriber = $plugin->push->getSubscriberByEndpointHash(hash('sha256', $endpoint));

        if ($subscriber === null) {
            return $this->asFailure(Craft::t('pwa', 'That device is not subscribed. Subscribe from the front end first.'));
        }

        [$status, $code, $error] = $plugin->push->send($subscriber, $campaign);

        if ($status !== 'delivered') {
            return $this->asFailure(Craft::t('pwa', 'The push service said {code}: {error}', [
                'code' => $code ?? '—',
                'error' => $error ?? '',
            ]));
        }

        return $this->asSuccess(Craft::t('pwa', 'Sent to this device.'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $campaignId = (int)$this->request->getRequiredBodyParam('campaignId');
        $campaign = Plugin::getInstance()->campaigns->getCampaignById($campaignId);

        if ($campaign === null) {
            throw new NotFoundHttpException();
        }

        // Its batches are still on the queue and read the campaign as they go; deleting it
        // mid-send loses the record of a message that is reaching devices right now.
        if ($campaign->status === Campaign::STATUS_SENDING) {
            return $this->asFailure(Craft::t('pwa', 'That broadcast is still sending. Delete it once it has finished.'));
        }

        if (!Plugin::getInstance()->campaigns->delete($campaignId)) {
            return $this->asFailure(Craft::t('pwa', 'Couldn’t delete the broadcast.'));
        }

        return $this->asSuccess(Craft::t('pwa', 'Broadcast deleted.'), redirect: 'pwa/broadcast');
    }

    /**
     * The word typed to confirm a send. Translated, and used by both the template and the check
     * here, so a translated screen never asks for one word and accepts another.
     */
    public static function sendWord(): string
    {
        return Craft::t('pwa', 'SEND');
    }

    public function actionSubscribers(): Response
    {
        $plugin = Plugin::getInstance();
        $subscribers = $plugin->push->getSubscribers(null, [], 0, 200);
        $byService = $plugin->push->countByService();

        return $this->renderTemplate('pwa/_broadcast/subscribers', [
            'subscribers' => $subscribers,
            'total' => $plugin->push->countSubscribers(),
            'byService' => $byService,
        ]);
    }

    public function actionRemoveSubscriber(): Response
    {
        $this->requirePostRequest();

        $subscriber = Plugin::getInstance()->push->getSubscriberById((int)$this->request->getRequiredBodyParam('subscriberId'));

        if ($subscriber === null) {
            throw new NotFoundHttpException();
        }

        // Removing the row does not unsubscribe the browser — it stops us sending, and the device
        // keeps its (now unused) subscription until it clears site data. Nothing else is possible
        // from this side, and pretending otherwise would be worse.
        Plugin::getInstance()->push->unsubscribe($subscriber->endpoint);

        return $this->asSuccess(Craft::t('pwa', 'Device removed.'));
    }
}
