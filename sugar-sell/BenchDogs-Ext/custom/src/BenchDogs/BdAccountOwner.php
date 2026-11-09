<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use BeanFactory;
use Throwable;

/**
 * A new Opportunity or Quote is owned by its account's owner, so a record entered for a seller is the seller's. The
 * create form fills it (the bd-account-owner view); before_save does the same for a record created without that form.
 */
class BdAccountOwner
{
    /** Each module's account fields, the first one set used, and the link a subpanel's create arrives by. */
    public const ACCOUNT_OF = array(
        'Opportunities' => array(array('account_id'), 'accounts'),
        'Quotes' => array(array('billing_account_id', 'account_id'), 'billing_accounts'),
    );

    /** The query parameter the create form sends when the person picked the owner: their pick stands. */
    public const CHOSEN_PARAM = 'bd_owner_chosen';

    /** The create layout's component this package adds (it draws nothing), right after the create view. */
    public const VIEW = 'bd-account-owner';
    public const AFTER_VIEW = 'create';

    /**
     * before_save on Opportunities and Quotes: a new record still owned by its creator, or by no one, takes its
     * account's owner. A failed read leaves the owner as it is and lets the save go on.
     * @param object $bean
     * @param string $event
     * @param array $arguments
     */
    public function beforeSave($bean, $event = '', $arguments = array()): void
    {
        try {
            $owner = self::ownerFor($bean, is_array($arguments) ? $arguments : array(),
                (string) ($GLOBALS['current_user']->id ?? ''));
            if ($owner !== '') {
                $bean->assigned_user_id = $owner;
            }
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('Bench Dogs account owner: the owner of this new ' . ($bean->module_dir ?? 'record')
                    . ' is left as it is, the account could not be read: ' . $e->getMessage());
            }
        }
    }

    /**
     * The owner a record takes, or '' to leave it: only a new record, not the integration's, with no pick sent by the
     * form, owned by $currentUserId or no one, whose account's owner is another active user.
     * @param object $bean
     */
    public static function ownerFor($bean, array $arguments, string $currentUserId): string
    {
        $module = is_object($bean) ? (string) ($bean->module_dir ?? ($bean->module_name ?? '')) : '';
        if (!isset(self::ACCOUNT_OF[$module]) || ($arguments['isUpdate'] ?? true) !== false
            || (string) ($_SESSION['platform'] ?? '') === BdAdmRules::CONNECTOR_PLATFORM
            || !empty($_GET[self::CHOSEN_PARAM])) {
            return '';
        }
        $assigned = trim((string) ($bean->assigned_user_id ?? ''));
        if ($assigned !== '' && $assigned !== $currentUserId) {
            return '';
        }
        $accountId = self::accountId($bean, self::ACCOUNT_OF[$module]);
        if ($accountId === '') {
            return '';
        }
        $account = BeanFactory::retrieveBean('Accounts', $accountId, array('disable_row_level_security' => true));
        $owner = is_object($account) && empty($account->deleted) ? trim((string) ($account->assigned_user_id ?? '')) : '';
        if ($owner === '' || $owner === $assigned) {
            return '';
        }

        return BdDefaultOwner::activeUser($owner) !== null ? $owner : '';
    }

    /** The create layout with VIEW right after the create view, once. As read when there is none or anything fails. */
    public static function withView(array $layout): array
    {
        try {
            return self::inserted($layout)[0];
        } catch (Throwable $e) {
            if (isset($GLOBALS['log']) && is_object($GLOBALS['log'])) {
                $GLOBALS['log']->error('BenchDogs-Ext: account owner view not added: ' . $e->getMessage());
            }

            return $layout;
        }
    }

    /** The record's account: its first account field set, else the account whose subpanel it is created from. */
    private static function accountId($bean, array $of): string
    {
        list($fields, $link) = $of;
        $id = '';
        foreach ($fields as $field) {
            $id = trim((string) ($bean->{$field} ?? ''));
            if ($id !== '') {
                break;
            }
        }
        if ($id === '' && (string) ($bean->new_rel_relname ?? '') === $link) {
            $id = trim((string) ($bean->new_rel_id ?? ''));
        }

        return $id;
    }

    /** [the layout node with VIEW added in the components list that holds AFTER_VIEW, whether that list was found]. */
    private static function inserted(array $node): array
    {
        if (!is_array($node['components'] ?? null)) {
            return array($node, false);
        }
        $list = array_values($node['components']);
        $names = array();
        foreach ($list as $component) {
            $names[] = self::viewOf($component);
        }
        $at = array_search(self::AFTER_VIEW, $names, true);
        if ($at !== false) {
            if (!in_array(self::VIEW, $names, true)) {
                array_splice($list, $at + 1, 0, array(array('view' => self::VIEW)));
                $node['components'] = $list;
            }

            return array($node, true);
        }
        foreach ($list as $i => $component) {
            if (is_array($component) && is_array($component['layout'] ?? null)) {
                list($inner, $found) = self::inserted($component['layout']);
                if ($found) {
                    $list[$i]['layout'] = $inner;
                    $node['components'] = $list;

                    return array($node, true);
                }
            }
        }

        return array($node, false);
    }

    /** The name of the view a layout component draws, or ''. */
    private static function viewOf($component): string
    {
        $view = is_array($component) ? ($component['view'] ?? null) : null;
        if (is_array($view)) {
            $view = $view['name'] ?? ($view['type'] ?? null);
        }

        return is_string($view) ? $view : '';
    }
}
