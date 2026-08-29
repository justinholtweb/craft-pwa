<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\pwa\models\Audit;
use justinholtweb\pwa\Plugin;
use Throwable;

/**
 * The email side of the scheduled preflight.
 *
 * One message, sent only when it says something. The default is to email nothing while everything
 * passes, because a weekly "all is well" is a message people filter within a month — and the one
 * week it does not arrive is the week nobody notices.
 */
class Notifications extends Component
{
    public function sendPreflightReport(Audit $audit): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = array_values(array_filter(array_map('trim', $settings->preflightRecipients)));

        if (empty($recipients)) {
            return false;
        }

        if ($settings->preflightOnlyOnFailure && $audit->failed === 0) {
            Plugin::info('Scheduled preflight passed; no email sent.');

            return false;
        }

        $site = $audit->siteId ? Craft::$app->getSites()->getSiteById($audit->siteId) : Craft::$app->getSites()->getPrimarySite();
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            $body = $view->renderTemplate('pwa/_email/preflight', [
                'audit' => $audit,
                'site' => $site,
                'siteName' => $site?->getName() ?? Craft::$app->getSystemName(),
                'url' => UrlHelper::cpUrl('pwa/preflight/' . $audit->id),
            ]);
        } catch (Throwable $e) {
            Plugin::error('Could not render the preflight email: ' . $e->getMessage());

            return false;
        } finally {
            $view->setTemplateMode($mode);
        }

        $subject = $audit->installable
            ? Craft::t('pwa', 'PWA preflight: {count} thing(s) to look at on {site}', [
                'count' => $audit->failed + $audit->warned,
                'site' => $site?->getName(),
            ])
            : Craft::t('pwa', 'PWA preflight: {site} can no longer be installed', ['site' => $site?->getName()]);

        $mailer = Craft::$app->getMailer();
        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                $message = $mailer->compose()
                    ->setTo(App::parseEnv($recipient))
                    ->setSubject($subject)
                    ->setHtmlBody($body);

                if ($message->send()) {
                    $sent++;
                }
            } catch (Throwable $e) {
                Plugin::error('Could not email the preflight report to ' . $recipient . ': ' . $e->getMessage());
            }
        }

        Plugin::info("Preflight report emailed to {$sent} recipient(s).");

        return $sent > 0;
    }
}
