<?php

/**
 * after_save hook class for Quotes - see the registration in
 * custom/Extension/modules/Quotes/Ext/LogicHooks/bd_estimating_notification.php
 * for why this class lives here and not alongside that registration.
 *
 * Bench Dogs REQ-13 (estimating hand-off), minimal honest mechanism: when a
 * Quote's bd_erp_stage transitions into 'in_estimating' (the closest
 * bd_erp_stage_list key to "ready for estimating" - the list has no
 * ready_for_estimating value; see en_us.bd_erp_fields.php), create a Sugar
 * Notifications record so the estimating owner sees the hand-off in the
 * notification center. bd_erp_stage is normally set by BdQuoteReflectionHook
 * from the ERP quote's own stage, so this fires on the sync path as well as
 * on manual edits.
 *
 * Recipient resolution, in order:
 *   1. $sugar_config['benchdogs_ext']['estimating_notify_user_id'] - the
 *      package's one config knob (set it in config_override.php), for shops
 *      with a fixed estimating coordinator;
 *   2. the quote's assigned user's manager (Users.reports_to_id);
 *   3. the quote's assigned user themselves (last resort - a hand-off that
 *      notifies nobody is worse than one that notifies the requester).
 *
 * Deliberately NOT SugarBPM: shipping a process definition this package
 * can't honestly claim to have designed with the customer would be a fake
 * artifact. Customers who prefer BPM can disable this hook and model the
 * same trigger there post-install - see the package README.
 */
class BdEstimatingNotificationHook
{
    /** bd_erp_stage_list key that means "ready for estimating". */
    private const ESTIMATING_STAGE = 'in_estimating';

    public function notifyEstimating(SugarBean $bean, string $event, array $arguments): void
    {
        if ((string) ($bean->bd_erp_stage ?? '') !== self::ESTIMATING_STAGE) {
            return;
        }

        // Only a real transition into the stage, not every resave of a Quote
        // already there. dataChanges, not fetched_row: after_save fires after
        // SugarBean has overwritten fetched_row with the bean's own
        // post-write values, so fetched_row can never show a transition
        // (see OrderStageOpportunityCascade for the confirmed-live account).
        $stageChange = null;
        foreach ($arguments['dataChanges'] ?? [] as $change) {
            if (($change['field_name'] ?? '') === 'bd_erp_stage') {
                $stageChange = $change;
                break;
            }
        }
        if ($stageChange === null || $stageChange['before'] === $stageChange['after']) {
            return;
        }

        try {
            $recipientId = $this->resolveRecipient($bean);
            if ($recipientId === '') {
                $GLOBALS['log']->warn(
                    'BdEstimatingNotificationHook: quote ' . $bean->id
                    . ' entered in_estimating but no recipient could be resolved '
                    . '(no config user, no assigned user) - no notification created'
                );
                return;
            }

            $notification = BeanFactory::newBean('Notifications');
            $notification->name = 'Quote ready for estimating: ' . mb_substr((string) $bean->name, 0, 200);
            $notification->description = 'Quote "' . $bean->name . '" ('
                . ($bean->quote_num ?? $bean->id) . ') has entered the estimating stage'
                . ' and is ready to be worked.';
            $notification->severity = 'information';
            $notification->is_read = 0;
            $notification->assigned_user_id = $recipientId;
            $notification->parent_type = 'Quotes';
            $notification->parent_id = $bean->id;
            $notification->save();

            $GLOBALS['log']->info(
                'BdEstimatingNotificationHook: notification ' . $notification->id
                . ' created for user ' . $recipientId . ' (quote ' . $bean->id
                . ' -> in_estimating)'
            );
        } catch (Throwable $e) {
            // A failed notification must never fail the Quote save.
            $GLOBALS['log']->error(
                'BdEstimatingNotificationHook: failed notifying for quote '
                . $bean->id . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Who gets the notification - see the class docblock for the order.
     */
    private function resolveRecipient(SugarBean $bean): string
    {
        $configured = (string) SugarConfig::getInstance()->get(
            'benchdogs_ext.estimating_notify_user_id',
            ''
        );
        if ($configured !== '') {
            return $configured;
        }

        $assignedId = (string) ($bean->assigned_user_id ?? '');
        if ($assignedId === '') {
            return '';
        }

        $assigned = BeanFactory::retrieveBean('Users', $assignedId);
        if ($assigned && !empty($assigned->reports_to_id)) {
            return (string) $assigned->reports_to_id;
        }

        return $assignedId;
    }
}
