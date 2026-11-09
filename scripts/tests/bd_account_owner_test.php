<?php

/**
 * rc121: a new Opportunity or Quote is owned by its account's owner, EXECUTED: the before_save rule on both modules and
 * the create layouts that carry the form's half (the bd-account-owner view, proven on Sugar's own Sidecar in
 * account_owner_on_real_sidecar.cjs). ERP-Epicor's Create Opportunity & Quote is run for real in
 * bd_button_quote_project_test.php (section O).
 *
 * Run:  php scripts/tests/bd_account_owner_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * What Sugar does without it (SugarEnt 26.1.0): the create form puts the signed-in user in Assigned To
 * (clients/base/views/create/create.js), a REST create with no owner keeps none, and ERP-Epicor's button gives both
 * records the user who pressed it. The quote PDF's Sales Person is the quote's assigned user (BdQuotePdf).
 *
 * The rule under test: on a create (before_save with isUpdate false), a record owned by the user saving it, or by no
 * one, takes its account's owner when that is another active user. Never on an update, never on the integration's own
 * saves, never when the create form says the person picked the owner, never over another owner.
 *
 * Only Sugar's BeanFactory and logger are faked; BeanFactory answers as 26.1 retrieveBean does (a deleted row is not
 * found). Sugar's two stock create layouts are written below in their 26.1 shape (modules/Quotes and
 * modules/Opportunities clients/base/layouts/create/create.php).
 *
 * IF IT WERE BROKEN you would see:
 *   "takes the account owner" FAIL - a record entered by an assistant stays the assistant's (the PDF names them);
 *   "an update is never changed" FAIL - an existing record's owner moves on a later save;
 *   "the person's pick stands" FAIL - the server overrides a pick made on the form;
 *   "an inactive owner is not used" FAIL - a record goes to someone who left;
 *   the layout checks - the form's half is missing, drawn twice, or another component moved.
 */

namespace {
    $checks = array();
    $failed = 0;

    function check(string $name, bool $ok, string $why = ''): void
    {
        global $checks, $failed;
        $checks[] = $name;
        if ($ok) {
            echo "  pass {$name}\n";
            return;
        }
        $failed++;
        echo "  FAIL {$name}" . ($why !== '' ? ": {$why}" : '') . "\n";
    }

    $GLOBALS['log'] = new class {
        public $lines = array();

        public function __call($level, $args)
        {
            $this->lines[] = $level . ': ' . (string) ($args[0] ?? '');
        }
    };

    class BeanFactory
    {
        /** module => id => object with the fields read. */
        public static $rows = array();
        public static $reads = array();
        public static $throw = false;

        public static function retrieveBean($module, $id = null, $params = array(), $deleted = true)
        {
            self::$reads[] = $module . ':' . $id;
            if (self::$throw) {
                throw new RuntimeException("database failure reading {$module} {$id}");
            }
            $row = self::$rows[$module][$id] ?? null;
            if ($row === null || ($deleted && !empty($row->deleted))) {
                return null;
            }

            return $row;
        }
    }

    function bdRow(array $fields): stdClass
    {
        return (object) ($fields + array('deleted' => 0));
    }

    /** A new record of the module as the hook receives it in before_save. */
    function bdNew(string $module, array $fields): stdClass
    {
        $bean = new stdClass();
        $bean->module_dir = $module;
        $bean->field_defs = array('id' => array(), 'assigned_user_id' => array('type' => 'id'));
        foreach ($fields as $k => $v) {
            $bean->$k = $v;
        }

        return $bean;
    }

    function bdReset(): void
    {
        BeanFactory::$rows = array(
            'Users' => array(
                'u-lynn' => bdRow(array('id' => 'u-lynn', 'status' => 'Active', 'full_name' => 'Lynn Seller')),
                'u-samie' => bdRow(array('id' => 'u-samie', 'status' => 'Active', 'full_name' => 'Samie Assistant')),
                'u-gone' => bdRow(array('id' => 'u-gone', 'status' => 'Inactive')),
                'u-deleted' => bdRow(array('id' => 'u-deleted', 'status' => 'Active', 'deleted' => 1)),
            ),
            'Accounts' => array(
                'a-lynn' => bdRow(array('id' => 'a-lynn', 'assigned_user_id' => 'u-lynn')),
                'a-samie' => bdRow(array('id' => 'a-samie', 'assigned_user_id' => 'u-samie')),
                'a-none' => bdRow(array('id' => 'a-none', 'assigned_user_id' => '')),
                'a-gone' => bdRow(array('id' => 'a-gone', 'assigned_user_id' => 'u-gone')),
                'a-deleted-user' => bdRow(array('id' => 'a-deleted-user', 'assigned_user_id' => 'u-deleted')),
                'a-deleted' => bdRow(array('id' => 'a-deleted', 'assigned_user_id' => 'u-lynn', 'deleted' => 1)),
            ),
        );
        BeanFactory::$reads = array();
        BeanFactory::$throw = false;
        $GLOBALS['log']->lines = array();
        $GLOBALS['current_user'] = (object) array('id' => 'u-samie');
        $_SESSION = array('platform' => 'base');
        $_GET = array();
    }

    $package = __DIR__ . '/../../sugar-sell/BenchDogs-Ext';
    $classFile = $package . '/custom/src/BenchDogs/BdAccountOwner.php';
    $class = 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAccountOwner';
    require $package . '/custom/src/BenchDogs/BdAdmRules.php';
    require $package . '/custom/src/BenchDogs/BdDefaultOwner.php';
    if (is_file($classFile)) {
        require $classFile;
    }
    check('the class ships where Sugar autoloads its namespace (custom/src/BenchDogs)', is_file($classFile), $classFile);
    check('the class loads', class_exists($class, false));
    if (!class_exists($class, false)) {
        echo "\n" . count($checks) . " checks, {$failed} failed\n";
        exit(1);
    }

    // ── H. the registrations: before_save, the one class and method, on each module ──────────────
    foreach (array('Opportunities', 'Quotes') as $module) {
        $file = $package . "/custom/Extension/modules/{$module}/Ext/LogicHooks/bd_account_owner.php";
        $hook_array = array();
        if (is_file($file)) {
            include $file;
        }
        $hooks = $hook_array['before_save'] ?? array();
        check("H {$module}: one before_save entry calls BdAccountOwner::beforeSave", count($hooks) === 1
            && ($hooks[0][3] ?? '') === $class && ($hooks[0][4] ?? '') === 'beforeSave'
            && count($hook_array) === 1, json_encode($hook_array));
    }

    /** Saves a new record through the hook as Sugar calls it; returns the owner it is stored with. */
    $save = function (stdClass $bean, array $arguments = array('check_notify' => false, 'isUpdate' => false))
        use ($class) {
        (new $class())->beforeSave($bean, 'before_save', $arguments);

        return $bean->assigned_user_id ?? null;
    };

    // ── O. the rule ──────────────────────────────────────────────────────────────────────────
    bdReset();
    check('O1 a new Quote its creator saves as their own, for an account owned by another active user: the account '
        . 'owner', $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-lynn')))
        === 'u-lynn');
    bdReset();
    check('O2 a new Opportunity, the same (its account_id)',
        $save(bdNew('Opportunities', array('assigned_user_id' => 'u-samie', 'account_id' => 'a-lynn'))) === 'u-lynn');
    bdReset();
    check('O3 a REST create with no owner (Sugar fills none): the account owner',
        $save(bdNew('Quotes', array('assigned_user_id' => '', 'billing_account_id' => 'a-lynn'))) === 'u-lynn');
    bdReset();
    check('O4 created from the account\'s subpanel (no account field yet, the link it arrives by): the account owner',
        $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'new_rel_relname' => 'billing_accounts',
            'new_rel_id' => 'a-lynn'))) === 'u-lynn'
        && $save(bdNew('Opportunities', array('assigned_user_id' => 'u-samie', 'new_rel_relname' => 'accounts',
            'new_rel_id' => 'a-lynn'))) === 'u-lynn');
    bdReset();
    check('O5 control: from the account\'s ship-to subpanel (another link) the owner is kept', $save(bdNew('Quotes',
        array('assigned_user_id' => 'u-samie', 'new_rel_relname' => 'shipping_accounts', 'new_rel_id' => 'a-lynn')))
        === 'u-samie');

    bdReset();
    check('O6 an owner other than the creator (a pick, a copy) is kept', $save(bdNew('Quotes', array(
        'assigned_user_id' => 'u-gone', 'billing_account_id' => 'a-lynn'))) === 'u-gone');
    bdReset();
    $_GET = array($class::CHOSEN_PARAM => '1');
    check('O7 the create form says the person picked the owner (themselves): their pick stands, no account read',
        $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-lynn'))) === 'u-samie'
        && BeanFactory::$reads === array());
    bdReset();
    $updated = bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-lynn'));
    check('O8 an update is never changed (isUpdate true), nor a save that does not say (no isUpdate)',
        $save($updated, array('isUpdate' => true)) === 'u-samie' && $save($updated, array()) === 'u-samie'
        && BeanFactory::$reads === array());
    bdReset();
    $_SESSION = array('platform' => 'sugarai_erp_connector');
    check('O9 the integration\'s own create (the connector platform) is left to it',
        $save(bdNew('Opportunities', array('assigned_user_id' => '', 'account_id' => 'a-lynn'))) === '');

    bdReset();
    check('O10 an account owned by no one: the owner is kept', $save(bdNew('Quotes', array(
        'assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-none'))) === 'u-samie');
    bdReset();
    check('O11 an account owned by an Inactive user, or a deleted one: kept', $save(bdNew('Quotes', array(
        'assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-gone'))) === 'u-samie'
        && $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-deleted-user')))
        === 'u-samie');
    bdReset();
    check('O12 a deleted account, an unknown one, or none: kept', $save(bdNew('Quotes', array(
        'assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-deleted'))) === 'u-samie'
        && $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-x'))) === 'u-samie'
        && $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie'))) === 'u-samie');
    bdReset();
    check('O13 the creator owns the account: unchanged', $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie',
        'billing_account_id' => 'a-samie'))) === 'u-samie');
    bdReset();
    BeanFactory::$throw = true;
    $logged = false;
    check('O14 a read that fails: the owner is kept, the save goes on, the failure is logged',
        $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-lynn'))) === 'u-samie'
        && count(array_filter($GLOBALS['log']->lines, function ($l) {
            return strpos($l, 'error: Bench Dogs account owner') === 0;
        })) === 1, json_encode($GLOBALS['log']->lines));
    bdReset();
    $order = bdNew('ERP_Orders', array('assigned_user_id' => 'u-samie', 'account_id' => 'a-lynn'));
    check('O15 another module is never touched', $save($order) === 'u-samie' && BeanFactory::$reads === array());

    // ── Q. rc123: a Quote's top Account Name (account_id, link billing_accounts) counts when no billing account is set;
    // the billing account decides when both are. Red before rc123: Q1, Q2 and Q6.
    bdReset();
    check('Q1 a new Quote with only the top Account Name (account_id): the account owner',
        $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'account_id' => 'a-lynn'))) === 'u-lynn');
    bdReset();
    check('Q2 an empty billing account and the top Account Name: the account owner; REST create with no owner too',
        $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => '',
            'account_id' => 'a-lynn'))) === 'u-lynn'
        && $save(bdNew('Quotes', array('assigned_user_id' => '', 'account_id' => 'a-lynn'))) === 'u-lynn');
    bdReset();
    check('Q3 both set to different accounts: the billing account decides',
        $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-lynn',
            'account_id' => 'a-gone'))) === 'u-lynn'
        && $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'billing_account_id' => 'a-samie',
            'account_id' => 'a-lynn'))) === 'u-samie');
    bdReset();
    $onlyTop = function (array $more = array()) {
        return bdNew('Quotes', $more + array('assigned_user_id' => 'u-samie', 'account_id' => 'a-lynn'));
    };
    $kept = $save($onlyTop(), array('isUpdate' => true)) === 'u-samie'
        && $save($onlyTop(array('assigned_user_id' => 'u-rich'))) === 'u-rich';
    $_GET = array($class::CHOSEN_PARAM => '1');
    $kept = $kept && $save($onlyTop()) === 'u-samie';
    $_GET = array();
    $_SESSION = array('platform' => 'sugarai_erp_connector');
    $kept = $kept && $save($onlyTop(array('assigned_user_id' => ''))) === '';
    check('Q4 only the top Account Name: an update, another owner, the person\'s pick and the integration\'s save are '
        . 'kept, with no account read', $kept && BeanFactory::$reads === array(), json_encode(BeanFactory::$reads));
    bdReset();
    check('Q5 only the top Account Name, owned by no one, an Inactive user, or deleted: kept', $save(bdNew('Quotes',
        array('assigned_user_id' => 'u-samie', 'account_id' => 'a-none'))) === 'u-samie'
        && $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'account_id' => 'a-gone'))) === 'u-samie'
        && $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'account_id' => 'a-deleted'))) === 'u-samie');
    bdReset();
    check('Q6 the top Account Name\'s account is read once, then whether its owner is Active',
        $save(bdNew('Quotes', array('assigned_user_id' => 'u-samie', 'account_id' => 'a-lynn'))) === 'u-lynn'
        && BeanFactory::$reads === array('Accounts:a-lynn', 'Users:u-lynn'), json_encode(BeanFactory::$reads));

    // ── L. the create layouts: the form's half after Sugar's create view ────────────────────────
    $stockCreate = function (string $second) {
        return array('components' => array(array('layout' => array('type' => 'default', 'name' => 'sidebar',
            'components' => array(
                array('layout' => array('type' => 'base', 'name' => 'main-pane',
                    'css_class' => 'main-pane overflow-y-auto span8',
                    'components' => array(array('view' => 'create'), array('layout' => $second)))),
                array('layout' => array('type' => 'base', 'name' => 'preview-pane',
                    'components' => array(array('layout' => 'create-preview')))),
            ), 'last_state' => array('id' => 'create-default')))));
    };
    foreach (array('Quotes' => 'extra-info', 'Opportunities' => 'subpanels-create') as $module => $second) {
        $stock = $stockCreate($second);
        $file = $package . "/custom/Extension/modules/{$module}/Ext/clients/base/layouts/create/bd_account_owner.php";
        $viewdefs = array($module => array('base' => array('layout' => array('create' => $stock))));
        if (is_file($file)) {
            include $file;
        }
        $served = $viewdefs[$module]['base']['layout']['create'];
        $main = $served['components'][0]['layout']['components'][0]['layout']['components'] ?? array();
        check("L1 {$module}: the create layout draws bd-account-owner right after the create view, once",
            $main === array(array('view' => 'create'), array('view' => 'bd-account-owner'), array('layout' => $second)),
            json_encode($main));
        $expected = $stock;
        $expected['components'][0]['layout']['components'][0]['layout']['components'] = $main;
        check("L2 {$module}: nothing else in the layout changes", $served === $expected);
        $viewdefs = array($module => array('base' => array('layout' => array('create' => $served))));
        if (is_file($file)) {
            include $file;
        }
        check("L3 {$module}: every metadata build runs it; the second changes nothing",
            $viewdefs[$module]['base']['layout']['create'] === $served);
    }
    $none = array('components' => array(array('view' => 'record')));
    check('L4 a layout with no create view is served as read', $class::withView($none) === $none);
    $named = array('components' => array(array('view' => array('name' => 'create', 'type' => 'create'))));
    check('L5 a create view given as a definition is found too', $class::withView($named)['components'][1]
        === array('view' => 'bd-account-owner'));
    $views = array();
    foreach (array('Quotes', 'Opportunities') as $module) {
        $views[] = (string) @file_get_contents($package
            . "/custom/modules/{$module}/clients/base/views/bd-account-owner/bd-account-owner.js");
    }
    check('L6 each create layout\'s module ships the view (one controller, the same file), and the parameter it sends '
        . 'is the one the rule reads', $class::CHOSEN_PARAM === 'bd_owner_chosen' && $views[0] === $views[1]
        && strpos($views[0], "chosenParam: 'bd_owner_chosen'") !== false);

    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
