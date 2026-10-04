<?php

namespace justinholtweb\pwa\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Seven tables, in four groups.
 *
 * Configuration is not among them: the manifest, the flight plan and the prompt live in project
 * config so they deploy with the code that assumes them. What lands in the database is everything
 * that is *observed* rather than decided — audits, devices, broadcasts and their outcomes — plus
 * the one secret that must never be in project config, which is the VAPID keypair, and the small
 * amount of runtime state (the cache-invalidation counter) that changes on environments where
 * project config is read-only.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createAudits();
        $this->createSubscribers();
        $this->createCampaigns();
        $this->createDeliveries();
        $this->createEvents();
        $this->createKeys();
        $this->createState();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pwa_deliveries}}');
        $this->dropTableIfExists('{{%pwa_campaigns}}');
        $this->dropTableIfExists('{{%pwa_subscribers}}');
        $this->dropTableIfExists('{{%pwa_events}}');
        $this->dropTableIfExists('{{%pwa_audits}}');
        $this->dropTableIfExists('{{%pwa_keys}}');
        $this->dropTableIfExists('{{%pwa_state}}');

        return true;
    }

    private function createAudits(): void
    {
        $this->createTable('{{%pwa_audits}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'trigger' => $this->string(16)->notNull()->defaultValue('manual'),
            'score' => $this->integer()->notNull()->defaultValue(0),
            'installable' => $this->boolean()->notNull()->defaultValue(false),
            'passed' => $this->integer()->notNull()->defaultValue(0),
            'warned' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),
            'skipped' => $this->integer()->notNull()->defaultValue(0),

            // The whole report, as it read on the day. An audit that re-renders itself against
            // today's check definitions is not a record of anything.
            'checks' => $this->json(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%pwa_audits}}', ['siteId', 'dateCreated'], false);

        // Garbage collection deletes by age across every site, and the scheduled check asks for
        // the newest audit of one trigger; neither can use an index that starts with siteId.
        $this->createIndex(null, '{{%pwa_audits}}', ['dateCreated'], false);
        $this->createIndex(null, '{{%pwa_audits}}', ['trigger', 'dateCreated'], false);
        $this->addForeignKey(null, '{{%pwa_audits}}', ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }

    private function createSubscribers(): void
    {
        $this->createTable('{{%pwa_subscribers}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'userId' => $this->integer(),

            'endpoint' => $this->string(1000)->notNull(),

            // Push endpoints run past what MySQL will index, and they are the natural key: the
            // same browser resubscribing must update its row rather than add one. A hash of the
            // endpoint is indexable, unique, and enough.
            'endpointHash' => $this->char(64)->notNull(),

            'p256dh' => $this->string(255)->notNull(),
            'auth' => $this->string(255)->notNull(),
            'contentEncoding' => $this->string(32)->notNull()->defaultValue('aes128gcm'),
            'topics' => $this->json(),
            'userAgent' => $this->string(500),
            'platform' => $this->string(64),
            'failures' => $this->integer()->notNull()->defaultValue(0),
            'dateLastSeen' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%pwa_subscribers}}', ['endpointHash'], true);
        $this->createIndex(null, '{{%pwa_subscribers}}', ['siteId'], false);
        $this->createIndex(null, '{{%pwa_subscribers}}', ['userId'], false);

        $this->addForeignKey(null, '{{%pwa_subscribers}}', ['siteId'], Table::SITES, ['id'], 'CASCADE', null);

        // Deleting the account does not delete the device. Somebody who subscribed while logged
        // in and later had their account removed still has a browser that will keep receiving
        // pushes until it unsubscribes, so the row has to survive to be sendable — and
        // unsubscribable.
        $this->addForeignKey(null, '{{%pwa_subscribers}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
    }

    private function createCampaigns(): void
    {
        $this->createTable('{{%pwa_campaigns}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'title' => $this->string(255)->notNull(),
            'body' => $this->text(),
            'url' => $this->string(1000),
            'icon' => $this->string(1000),
            'badge' => $this->string(1000),
            'tag' => $this->string(120),
            'requireInteraction' => $this->boolean()->notNull()->defaultValue(false),
            'topics' => $this->json(),
            'entryId' => $this->integer(),
            'status' => $this->string(16)->notNull()->defaultValue('draft'),
            'dateScheduled' => $this->dateTime(),
            'dateSent' => $this->dateTime(),
            'targeted' => $this->integer()->notNull()->defaultValue(0),
            'delivered' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),
            'createdBy' => $this->integer(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%pwa_campaigns}}', ['status', 'dateScheduled'], false);
        $this->createIndex(null, '{{%pwa_campaigns}}', ['dateCreated'], false);
        $this->createIndex(null, '{{%pwa_campaigns}}', ['siteId'], false);
        $this->createIndex(null, '{{%pwa_campaigns}}', ['entryId'], false);
        $this->createIndex(null, '{{%pwa_campaigns}}', ['createdBy'], false);

        $this->addForeignKey(null, '{{%pwa_campaigns}}', ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%pwa_campaigns}}', ['entryId'], Table::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%pwa_campaigns}}', ['createdBy'], Table::USERS, ['id'], 'SET NULL', null);
    }

    private function createDeliveries(): void
    {
        $this->createTable('{{%pwa_deliveries}}', [
            'id' => $this->primaryKey(),
            'campaignId' => $this->integer()->notNull(),

            // Nullable, and deliberately not cascading from the subscriber: the interesting
            // delivery record is often the one for a device that has since been dropped.
            'subscriberId' => $this->integer(),

            'status' => $this->string(16)->notNull(),
            'statusCode' => $this->integer(),
            'error' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%pwa_deliveries}}', ['campaignId', 'status'], false);
        $this->createIndex(null, '{{%pwa_deliveries}}', ['subscriberId'], false);
        $this->createIndex(null, '{{%pwa_deliveries}}', ['dateCreated'], false);

        $this->addForeignKey(null, '{{%pwa_deliveries}}', ['campaignId'], '{{%pwa_campaigns}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%pwa_deliveries}}', ['subscriberId'], '{{%pwa_subscribers}}', ['id'], 'SET NULL', null);
    }

    private function createEvents(): void
    {
        $this->createTable('{{%pwa_events}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),

            // `prompt`, `dismiss`, `install`, `launch`, `offline`, `subscribe`, `unsubscribe`,
            // `notification-click`.
            'type' => $this->string(32)->notNull(),

            'path' => $this->string(1000),
            'platform' => $this->string(64),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%pwa_events}}', ['type', 'dateCreated'], false);
        $this->createIndex(null, '{{%pwa_events}}', ['siteId'], false);
        $this->createIndex(null, '{{%pwa_events}}', ['dateCreated'], false);

        $this->addForeignKey(null, '{{%pwa_events}}', ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }

    private function createKeys(): void
    {
        // One row, ever. The keypair identifies this site to every push service that has a
        // subscription from it: regenerate it and every existing subscription is orphaned, which
        // is why it is stored where a project config restore cannot reach it and why the CP makes
        // replacing it awkward.
        $this->createTable('{{%pwa_keys}}', [
            'id' => $this->primaryKey(),
            'publicKey' => $this->text()->notNull(),
            'privateKey' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createState(): void
    {
        // Runtime state, one row per key. It lives here rather than in project config because it
        // changes in production — the "invalidate caches" button has to work on an environment
        // where admin changes are off — and rather than in the data cache because a cache flush
        // must not quietly reset it and hand every visitor's worker a cache name it already used.
        $this->createTable('{{%pwa_state}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(64)->notNull(),
            'value' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%pwa_state}}', ['name'], true);
    }
}
