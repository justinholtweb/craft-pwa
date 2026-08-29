<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pwa\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The preflight check, on demand and in history.
 *
 * Running one takes a few seconds — it makes real HTTP requests to the site — so it is a POST with
 * a spinner rather than something that happens on page load. A check that ran automatically every
 * time somebody opened the screen would be a check nobody trusts, because it would be measuring
 * the server's ability to talk to itself under whatever load it happened to be under.
 */
class PreflightController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('pwa/preflight/index', [
            'site' => $site,
            'audit' => $plugin->preflight->getLatest($site->id),
            'history' => $plugin->preflight->getAudits($site->id, 20),
            'isPro' => $plugin->isPro(),
            'settings' => $plugin->getSettings(),
        ]);
    }

    public function actionDetail(int $auditId): Response
    {
        $audit = Plugin::getInstance()->preflight->getAuditById($auditId);

        if ($audit === null) {
            throw new NotFoundHttpException();
        }

        return $this->renderTemplate('pwa/preflight/detail', [
            'audit' => $audit,
            'site' => $audit->siteId ? Craft::$app->getSites()->getSiteById($audit->siteId) : null,
        ]);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $site = Craft::$app->getSites()->getCurrentSite();
        $plugin = Plugin::getInstance();

        $audit = $plugin->preflight->run($site, 'manual');
        $plugin->preflight->save($audit);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'score' => $audit->score,
                'installable' => $audit->installable,
                'auditId' => $audit->id,
            ]);
        }

        return $this->redirect('pwa/preflight');
    }
}
