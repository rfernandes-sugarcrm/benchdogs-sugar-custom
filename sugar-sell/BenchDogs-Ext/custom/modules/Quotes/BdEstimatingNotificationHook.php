<?php

/**
 * Best-effort, deduplicated Sugar notifications for the Bench estimating
 * hand-off. The ERP hand-off and the persisted Quote stage are primary facts;
 * a notification is a secondary delivery and must never undo either one.
 */
class BdEstimatingNotificationHook
{
    private const ESTIMATING_STAGE = 'in_estimating';
    private const PRICED_STAGE = 'priced';
    private const PRE_PRICING_STAGES = ['draft', 'in_estimating', 'revision'];
    private const DIRECTION_ESTIMATING = 'estimating';
    private const DIRECTION_SALES = 'sales';

    /** Request-local outcomes let the initiating API report secondary delivery honestly. */
    private static $outcomes = [];

    public function notifyEstimating(SugarBean $bean, string $event, array $arguments): void
    {
        try {
            if ((string) ($bean->bd_erp_stage ?? '') !== self::ESTIMATING_STAGE) {
                return;
            }

            $change = $this->stageChange($arguments);
            if ($change === null || $change['before'] === $change['after']) {
                return;
            }

            $outcome = $this->attemptNotification(
                $bean,
                $change,
                self::DIRECTION_ESTIMATING,
                'Quote ready for estimating: ',
                'Quote "' . $bean->name . '" (' . $this->quoteReference($bean)
                    . ') has entered the estimating stage and is ready to be worked.'
            );
            self::rememberOutcome($bean, self::DIRECTION_ESTIMATING, $outcome);
        } catch (Throwable $e) {
            $outcome = self::outcome(
                'delivery_failed',
                'The hand-off completed, but Sugar could not run the in-app notification hook. '
                    . 'Use the In Estimating view and ask an administrator to inspect notification delivery.'
            );
            self::rememberOutcome($bean, self::DIRECTION_ESTIMATING, $outcome);
            $this->logOutcome('error', $bean, self::DIRECTION_ESTIMATING, $outcome, $e);
        }
    }

    public function notifyPricingReturned(SugarBean $bean, string $event, array $arguments): void
    {
        try {
            if ((string) ($bean->bd_erp_stage ?? '') !== self::PRICED_STAGE) {
                return;
            }

            $change = $this->stageChange($arguments);
            if ($change === null || $change['before'] === $change['after']) {
                return;
            }
            if (!in_array((string) ($change['before'] ?? ''), self::PRE_PRICING_STAGES, true)) {
                return;
            }

            $total = $bean->bd_erp_total;
            $priced = ($total !== null && $total !== '')
                ? ' The ERP quote total is ' . SugarCurrency::formatAmountUserLocale((float) $total) . '.'
                : '';
            $outcome = $this->attemptNotification(
                $bean,
                $change,
                self::DIRECTION_SALES,
                'Quote priced by estimating: ',
                'Estimating has finished pricing quote "' . $bean->name . '" ('
                    . $this->quoteReference($bean) . ') and handed it back to sales.' . $priced
            );
            self::rememberOutcome($bean, self::DIRECTION_SALES, $outcome);
        } catch (Throwable $e) {
            // There is no initiating API on the return leg, but the Quote save
            // still must not fail because its optional notification did.
            $outcome = self::outcome(
                'delivery_failed',
                'Estimating returned the Quote, but Sugar could not run the sales notification hook.'
            );
            self::rememberOutcome($bean, self::DIRECTION_SALES, $outcome);
            $this->logOutcome('error', $bean, self::DIRECTION_SALES, $outcome, $e);
        }
    }

    /**
     * Consume the outbound outcome after Quote::save() has run its hooks.
     * Absence is itself visible: it catches a disabled or unregistered hook.
     */
    public static function consumeEstimatingOutcome(string $quoteId): array
    {
        $key = self::outcomeKey($quoteId, self::DIRECTION_ESTIMATING);
        if (!array_key_exists($key, self::$outcomes)) {
            return self::outcome(
                'not_observed',
                'The Kinetic hand-off completed, but Sugar did not report a notification attempt. '
                    . 'Use the In Estimating view and ask an administrator to verify the notification hook.'
            );
        }

        $outcome = self::$outcomes[$key];
        unset(self::$outcomes[$key]);
        return $outcome;
    }

    private function attemptNotification(
        SugarBean $bean,
        array $change,
        string $direction,
        string $namePrefix,
        string $description
    ): array {
        try {
            $recipient = $direction === self::DIRECTION_ESTIMATING
                ? $this->resolveEstimatingRecipient($bean)
                : $this->resolveSalesRecipient($bean);
            if (($recipient['status'] ?? '') !== 'resolved') {
                $this->logOutcome('warn', $bean, $direction, $recipient);
                return $recipient;
            }

            $syncKey = $this->eventSyncKey($bean, $change, $direction);
            if ($syncKey === '') {
                $outcome = self::outcome(
                    'event_identity_unavailable',
                    'The hand-off completed, but Sugar could not derive a safe notification identity. '
                        . 'Use the Quote stage view and ask an administrator to inspect the Quote timestamps.'
                );
                $this->logOutcome('error', $bean, $direction, $outcome);
                return $outcome;
            }

            $existing = $this->findNotificationsBySyncKey($syncKey);
            $existingOutcome = $this->classifyExisting(
                $existing,
                $syncKey,
                (string) $recipient['id'],
                (string) $bean->id
            );
            if ($existingOutcome !== null) {
                $this->logOutcome(
                    $existingOutcome['status'] === 'already_created' ? 'info' : 'error',
                    $bean,
                    $direction,
                    $existingOutcome
                );
                return $existingOutcome;
            }

            $notification = BeanFactory::newBean('Notifications');
            if (!$notification) {
                $outcome = self::outcome(
                    'factory_unavailable',
                    'The hand-off completed, but Sugar could not create a notification record.'
                );
                $this->logOutcome('error', $bean, $direction, $outcome);
                return $outcome;
            }

            $notification->name = $namePrefix . mb_substr((string) $bean->name, 0, 200);
            $notification->description = $description;
            $notification->severity = 'information';
            $notification->is_read = 0;
            $notification->assigned_user_id = (string) $recipient['id'];
            $notification->parent_type = 'Quotes';
            $notification->parent_id = (string) $bean->id;
            // Notifications already owns a unique sync_key. It closes the
            // race between two stale concurrent saves without another table.
            $notification->sync_key = $syncKey;

            try {
                $savedId = $notification->save(false);
            } catch (Throwable $e) {
                return $this->classifySaveFailure(
                    $bean,
                    $direction,
                    $syncKey,
                    (string) $recipient['id'],
                    $e
                );
            }

            if ($savedId === false || $savedId === null || $savedId === '' || empty($notification->id)) {
                return $this->classifySaveFailure(
                    $bean,
                    $direction,
                    $syncKey,
                    (string) $recipient['id']
                );
            }

            $persisted = BeanFactory::retrieveBean(
                'Notifications',
                (string) $notification->id,
                ['use_cache' => false]
            );
            if (!$this->notificationMatches(
                $persisted,
                $syncKey,
                (string) $recipient['id'],
                (string) $bean->id
            )) {
                $outcome = self::outcome(
                    'persistence_unconfirmed',
                    'The hand-off completed, but Sugar could not confirm notification persistence. '
                        . 'Use the Quote stage view and ask an administrator to inspect notification delivery.'
                );
                $this->logOutcome('error', $bean, $direction, $outcome);
                return $outcome;
            }

            $outcome = self::outcome(
                'created',
                'Sugar created an in-app notification for the receiving team.'
            );
            $this->logOutcome('info', $bean, $direction, $outcome);
            return $outcome;
        } catch (Throwable $e) {
            // A notification is secondary. Never rethrow through Quote::save().
            $outcome = self::outcome(
                'delivery_failed',
                'The hand-off completed, but Sugar could not create the in-app notification. '
                    . 'Use the Quote stage view and ask an administrator to inspect notification delivery.'
            );
            $this->logOutcome('error', $bean, $direction, $outcome, $e);
            return $outcome;
        }
    }

    private function classifySaveFailure(
        SugarBean $bean,
        string $direction,
        string $syncKey,
        string $recipientId,
        ?Throwable $error = null
    ): array {
        try {
            $existing = $this->findNotificationsBySyncKey($syncKey);
            $outcome = $this->classifyExisting(
                $existing,
                $syncKey,
                $recipientId,
                (string) $bean->id
            );
            if ($outcome !== null) {
                $this->logOutcome(
                    $outcome['status'] === 'already_created' ? 'info' : 'error',
                    $bean,
                    $direction,
                    $outcome,
                    $error
                );
                return $outcome;
            }
        } catch (Throwable $lookupError) {
            $error = $lookupError;
        }

        $outcome = self::outcome(
            'save_failed',
            'The hand-off completed, but Sugar could not save the in-app notification. '
                . 'Use the Quote stage view and ask an administrator to inspect notification delivery.'
        );
        $this->logOutcome('error', $bean, $direction, $outcome, $error);
        return $outcome;
    }

    private function classifyExisting(
        array $matches,
        string $syncKey,
        string $recipientId,
        string $quoteId
    ): ?array {
        if (count($matches) > 1) {
            return self::outcome(
                'identity_conflict',
                'The hand-off completed, but Sugar found conflicting notification identities. '
                    . 'Ask an administrator to inspect Notifications.sync_key.'
            );
        }
        if (count($matches) === 0) {
            return null;
        }
        if (!$this->notificationMatches($matches[0], $syncKey, $recipientId, $quoteId)) {
            return self::outcome(
                'identity_conflict',
                'The hand-off completed, but the existing notification identity belongs to different data. '
                    . 'Ask an administrator to inspect Notifications.sync_key.'
            );
        }
        return self::outcome(
            'already_created',
            'Sugar had already created the in-app notification for this hand-off.'
        );
    }

    /** @return SugarBean[] */
    private function findNotificationsBySyncKey(string $syncKey): array
    {
        $seed = BeanFactory::newBean('Notifications');
        if (!$seed) {
            throw new RuntimeException('Notifications bean is unavailable');
        }
        $query = new SugarQuery();
        $query->select(['id']);
        $query->from($seed);
        $query->where()->equals('sync_key', $syncKey);
        $query->limit(2);

        $matches = [];
        foreach ($query->execute() as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $bean = BeanFactory::retrieveBean('Notifications', $id, ['use_cache' => false]);
            if ($bean && !empty($bean->id)) {
                $matches[] = $bean;
            }
        }
        return $matches;
    }

    private function notificationMatches($bean, string $syncKey, string $recipientId, string $quoteId): bool
    {
        return $bean
            && !empty($bean->id)
            && empty($bean->deleted)
            && (string) ($bean->sync_key ?? '') === $syncKey
            && (string) ($bean->assigned_user_id ?? '') === $recipientId
            && (string) ($bean->parent_type ?? '') === 'Quotes'
            && (string) ($bean->parent_id ?? '') === $quoteId;
    }

    private function resolveEstimatingRecipient(SugarBean $bean): array
    {
        $configured = trim((string) SugarConfig::getInstance()->get(
            'benchdogs_ext.estimating_notify_user_id',
            ''
        ));
        if ($configured !== '') {
            return $this->configuredRecipient(
                $configured,
                'benchdogs_ext.estimating_notify_user_id'
            );
        }

        $assignedId = trim((string) ($bean->assigned_user_id ?? ''));
        if ($assignedId === '') {
            return self::outcome(
                'recipient_unavailable',
                'The hand-off completed, but the Quote has no active estimating notification recipient. '
                    . 'Assign the Quote or configure benchdogs_ext.estimating_notify_user_id.'
            );
        }

        $assigned = $this->retrieveUser($assignedId);
        if ($assigned) {
            $managerId = trim((string) ($assigned->reports_to_id ?? ''));
            if ($managerId !== '') {
                $manager = $this->retrieveUser($managerId);
                if ($this->isActiveInternalUser($manager, $managerId)) {
                    return ['status' => 'resolved', 'id' => $managerId];
                }
            }
            if ($this->isActiveInternalUser($assigned, $assignedId)) {
                return ['status' => 'resolved', 'id' => $assignedId];
            }
        }

        return self::outcome(
            'recipient_unavailable',
            'The hand-off completed, but neither the Quote owner nor their manager can receive '
                . 'Sugar notifications. Configure benchdogs_ext.estimating_notify_user_id.'
        );
    }

    private function resolveSalesRecipient(SugarBean $bean): array
    {
        $configured = trim((string) SugarConfig::getInstance()->get(
            'benchdogs_ext.pricing_notify_user_id',
            ''
        ));
        if ($configured !== '') {
            return $this->configuredRecipient(
                $configured,
                'benchdogs_ext.pricing_notify_user_id'
            );
        }

        foreach (['assigned_user_id', 'created_by'] as $field) {
            $candidate = trim((string) ($bean->{$field} ?? ''));
            if ($candidate === '') {
                continue;
            }
            if ($this->isActiveInternalUser($this->retrieveUser($candidate), $candidate)) {
                return ['status' => 'resolved', 'id' => $candidate];
            }
        }

        return self::outcome(
            'recipient_unavailable',
            'Estimating returned the Quote, but no active sales recipient can receive a Sugar '
                . 'notification. Assign the Quote or configure benchdogs_ext.pricing_notify_user_id.'
        );
    }

    private function configuredRecipient(string $userId, string $configKey): array
    {
        if ($this->isActiveInternalUser($this->retrieveUser($userId), $userId)) {
            return ['status' => 'resolved', 'id' => $userId];
        }
        return self::outcome(
            'configured_recipient_invalid',
            'The hand-off completed, but the user in ' . $configKey
                . ' is missing, inactive, or cannot use the Sugar notification center. '
                . 'Correct that setting; Sugar did not reroute the notification.'
        );
    }

    private function retrieveUser(string $userId)
    {
        $bean = BeanFactory::retrieveBean('Users', $userId, ['use_cache' => false]);
        if (!$bean || empty($bean->id) || (string) $bean->id !== $userId || !empty($bean->deleted)) {
            return null;
        }
        return $bean;
    }

    private function isActiveInternalUser($bean, string $expectedId): bool
    {
        return $bean
            && (string) ($bean->id ?? '') === $expectedId
            && empty($bean->deleted)
            && strcasecmp(trim((string) ($bean->status ?? '')), 'Active') === 0
            // An active Users row is not necessarily allowed to sign in.
            // Sugar's notification center is available only to users whose
            // Sugar login is enabled. Missing/unknown must fail closed too:
            // accepting an older or partial bean would claim delivery to an
            // account that cannot observe it.
            && !empty($bean->sugar_login)
            && empty($bean->is_group)
            && empty($bean->portal_only);
    }

    private function stageChange(array $arguments): ?array
    {
        // dataChanges is confirmed on the hosted Bench version. It includes
        // auditable unchanged fields too, so the caller must compare values.
        foreach ($arguments['dataChanges'] ?? [] as $change) {
            if (($change['field_name'] ?? '') === 'bd_erp_stage') {
                return [
                    'before' => (string) ($change['before'] ?? ''),
                    'after' => (string) ($change['after'] ?? ''),
                ];
            }
        }
        return null;
    }

    private function eventSyncKey(SugarBean $bean, array $change, string $direction): string
    {
        $quoteId = trim((string) ($bean->id ?? ''));
        $modified = trim((string) ($bean->date_modified ?? ''));
        if ($quoteId === '' || $modified === '') {
            return '';
        }
        $identity = implode('|', [
            $quoteId,
            $direction,
            (string) ($change['before'] ?? ''),
            (string) ($change['after'] ?? ''),
            $modified,
        ]);
        return 'bdh:' . hash('sha256', $identity);
    }

    private function quoteReference(SugarBean $bean): string
    {
        $kinetic = trim((string) ($bean->erp_display_sync_key ?? ''));
        if ($kinetic !== '') {
            return 'Kinetic quote ' . $kinetic;
        }
        return 'Sugar quote ' . ($bean->quote_num ?? $bean->id);
    }

    private static function rememberOutcome(SugarBean $bean, string $direction, array $outcome): void
    {
        self::$outcomes[self::outcomeKey((string) ($bean->id ?? ''), $direction)] = $outcome;
    }

    private static function outcomeKey(string $quoteId, string $direction): string
    {
        return $quoteId . '|' . $direction;
    }

    private static function outcome(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }

    private function logOutcome(
        string $level,
        SugarBean $bean,
        string $direction,
        array $outcome,
        ?Throwable $error = null
    ): void {
        $message = 'BdEstimatingNotificationHook: quote ' . (string) ($bean->id ?? '')
            . ' direction=' . $direction . ' notification_status=' . ($outcome['status'] ?? 'unknown');
        if ($error !== null) {
            // Exception messages may carry SQL or tenant values; the class is
            // enough to correlate while the public outcome stays redacted.
            $message .= ' exception=' . get_class($error);
        }
        // Scanner-forced shape: SugarCloud's ModuleScanner refuses a
        // dynamically-named method call such as `->{$level}()` and rejected
        // rc16 for it. Keep each level as a literal call.
        if ($level === 'error') {
            $GLOBALS['log']->error($message);
        } elseif ($level === 'warn') {
            $GLOBALS['log']->warn($message);
        } else {
            $GLOBALS['log']->info($message);
        }
    }
}
