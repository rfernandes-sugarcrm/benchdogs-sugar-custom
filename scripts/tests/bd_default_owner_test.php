<?php

/**
 * The default owner for ERP-created records, EXECUTED: the before_save hook on Opportunities and ERP orders.
 *
 * Run:  php scripts/tests/bd_default_owner_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * What Sugar does without it (SugarEnt 26.1.0 source): a record created through the REST API with no
 * assigned_user_id keeps an empty owner. ModuleApi::createBean, IntegrateApi::upsertByField and SugarBean::save
 * fill nothing; only the browser's create view puts the signed-in user there (clients/base/views/create/create.js).
 * So an Opportunity or an ERP order the integration creates with no owner stays unowned.
 *
 * The rule under test: when the integration saves a record (the connector's own API platform) with an empty owner
 * and the setting names an active Sugar user, the owner becomes that user. A seller's own save is never filled, so an
 * owner a seller clears stays empty. An owner that is set is never changed; an empty setting, a user who is not
 * Active, a deleted user, or a read that fails leaves the owner empty and the save goes on.
 *
 * Only Sugar's BeanFactory, Administration and logger are faked. Administration hands config values back the way
 * Sugar's decodeConfigVar does (26.1 modules/Administration/Administration.php: an all-digit value comes back as an
 * integer), because the admin user's id is '1'. BeanFactory answers a user read the way 26.1 retrieveBean does: a
 * deleted row is not found, but a bean already in its registry is handed back as it is, deleted flag and all.
 *
 * IF IT WERE BROKEN you would see:
 *   "fills an empty owner" FAIL - the hook does nothing, or is not registered on that module;
 *   "a seller's own save" FAIL - the hook overwrites a seller's edit (an owner cleared in the browser);
 *   "an owner that is set is kept" FAIL - the hook overwrites an owner;
 *   "an empty setting does nothing" FAIL - the hook invents an owner;
 *   "an Inactive / Reserved / deleted user is not used" FAIL - records go to someone who cannot own them;
 *   "the admin user's id '1'" FAIL - the integer Sugar hands back is written, or refused, instead of '1';
 *   "a failed read" FAIL - the save is aborted, which stops the connector's write.
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

    class Administration
    {
        /** Stored rows as Sugar's config table holds them: category => platform => name => the text saved. */
        public static $rows = array();
        public static $reads = 0;
        public static $throw = false;

        public function getConfigForModule($category, $platform = 'base', $clean = false)
        {
            self::$reads++;
            if (self::$throw) {
                throw new RuntimeException('database failure reading the config');
            }
            // 26.1: the base platform reads only rows saved for 'base'; another platform reads base, then its own.
            $platform = strtolower((string) $platform);
            $rows = self::$rows[$category]['base'] ?? array();
            if ($platform !== 'base') {
                $rows = array_merge($rows, self::$rows[$category][$platform] ?? array());
            }
            $out = array();
            foreach ($rows as $name => $value) {
                $out[$name] = self::decodeConfigVar($value);
            }

            return $out;
        }

        public function saveSetting($category, $key, $value, $platform = '')
        {
            self::$rows[$category][strtolower((string) $platform)][$key] = is_array($value) ? json_encode($value)
                : (string) $value;

            return 1;
        }

        /** Sugar 26.1's decodeConfigVar: JSON-looking text is decoded, all-digit text becomes an integer. */
        private static function decodeConfigVar($var)
        {
            if (!is_null($var) && strlen($var) > 0) {
                if ($var[0] == '{' || $var[0] == '[') {
                    $decoded = json_decode($var, true);
                    if (json_last_error() == JSON_ERROR_NONE) {
                        $var = $decoded;
                    }
                } elseif (is_numeric($var) && ctype_digit($var)) {
                    $var = intval($var);
                }
            }

            return $var;
        }
    }

    class BeanFactory
    {
        /** Users rows by id: an object with id, status and deleted. */
        public static $users = array();
        /** Ids whose bean is in the registry: handed back as it is, even if it was deleted since. */
        public static $registry = array();
        public static $reads = array();
        public static $throw = false;

        public static function retrieveBean($module, $id = null, $params = array(), $deleted = true)
        {
            self::$reads[] = $module . ':' . $id;
            if (self::$throw) {
                throw new RuntimeException("database failure reading {$module} {$id}");
            }
            if ($module !== 'Users') {
                throw new LogicException("unexpected read of {$module}");
            }
            if (isset(self::$registry[$id])) {
                return self::$registry[$id];
            }
            $row = self::$users[$id] ?? null;
            if ($row === null || ($deleted && !empty($row->deleted))) {
                return null;
            }

            return $row;
        }

        public static function newBean($module)
        {
            if ($module === 'Administration') {
                return new Administration();
            }
            throw new LogicException("unexpected new bean of {$module}");
        }
    }

    function bdUser(string $id, string $status, int $deleted = 0): stdClass
    {
        $user = new stdClass();
        $user->id = $id;
        $user->status = $status;
        $user->deleted = $deleted;
        $user->full_name = 'User ' . $id;

        return $user;
    }

    /** A record of the module as the hook receives it in before_save. */
    function bdRecord(string $module, $owner): stdClass
    {
        $bean = new stdClass();
        $bean->module_dir = $module;
        $bean->field_defs = array('id' => array(), 'name' => array(), 'assigned_user_id' => array('type' => 'id'));
        $bean->assigned_user_id = $owner;

        return $bean;
    }

    function bdReset(): void
    {
        Administration::$rows = array();
        Administration::$reads = 0;
        Administration::$throw = false;
        BeanFactory::$users = array(
            'u-active' => bdUser('u-active', 'Active'),
            'u-other' => bdUser('u-other', 'Active'),
            'u-inactive' => bdUser('u-inactive', 'Inactive'),
            'u-reserved' => bdUser('u-reserved', 'Reserved'),
            'u-deleted' => bdUser('u-deleted', 'Active', 1),
            '1' => bdUser('1', 'Active'),
        );
        BeanFactory::$registry = array();
        BeanFactory::$reads = array();
        BeanFactory::$throw = false;
        $GLOBALS['log']->lines = array();
        // Sugar's REST service puts the OAuth platform of the request in the session; the connector's is this one.
        $_SESSION = array('platform' => 'sugarai_erp_connector');
    }

    /** The setting as the admin page stores it, through Sugar's saveSetting. */
    function bdSetting(string $id): void
    {
        (new Administration())->saveSetting('benchdogs_ext', 'default_owner_user_id', $id, 'base');
    }

    // Sugar's REST base classes, as far as the endpoint uses them; the status each exception answers with is Sugar's.
    class SugarApi
    {
    }

    class ServiceBase
    {
        public $user;
    }

    class SugarApiException extends Exception
    {
        public $httpCode = 500;
        public $errorLabel = 'unknown_exception';
    }

    class SugarApiExceptionNotAuthorized extends SugarApiException
    {
        public $httpCode = 403;
        public $errorLabel = 'not_authorized';
    }

    class SugarApiExceptionMissingParameter extends SugarApiException
    {
        public $httpCode = 422;
        public $errorLabel = 'missing_parameter';
    }

    class SugarApiExceptionInvalidParameter extends SugarApiException
    {
        public $httpCode = 422;
        public $errorLabel = 'invalid_parameter';
    }

    function bdApiAs(bool $admin): ServiceBase
    {
        $api = new ServiceBase();
        $api->user = new class ($admin) {
            private $admin;

            public function __construct(bool $admin)
            {
                $this->admin = $admin;
            }

            public function isAdmin()
            {
                return $this->admin;
            }
        };

        return $api;
    }

    $package = __DIR__ . '/../../sugar-sell/BenchDogs-Ext';
    $classFile = $package . '/custom/src/BenchDogs/BdDefaultOwner.php';
    $apiFile = $package . '/custom/modules/Administration/clients/base/api/BdDefaultOwnerApi.php';
    $class = 'Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdDefaultOwner';
    // The connector platform is BdAdmRules' constant; Sugar autoloads that class, this harness loads it by path.
    require $package . '/custom/src/BenchDogs/BdAdmRules.php';
    if (is_file($classFile)) {
        require $classFile;
    }
    if (is_file($apiFile)) {
        require $apiFile;
    }

    // SERVE: one request from the page test (scripts/tests/default_owner_page_on_real_sidecar.cjs) through the REAL
    // endpoint, as an administrator. stdin: {users, rows, method, body}; stdout: {status, answer | error, rows}.
    if (getenv('BD_DEFAULT_OWNER_SERVE') === '1') {
        $in = json_decode(stream_get_contents(STDIN), true);
        Administration::$rows = $in['rows'] ?: array();
        foreach ($in['users'] as $id => $row) {
            BeanFactory::$users[(string) $id] = (object) ($row + array('id' => (string) $id));
        }
        $endpoint = new BdDefaultOwnerApi();
        try {
            $answer = $in['method'] === 'update'
                ? $endpoint->saveDefaultOwner(bdApiAs(true), (array) $in['body'])
                : $endpoint->readDefaultOwner(bdApiAs(true), array());
            $out = array('status' => 200, 'answer' => $answer);
        } catch (SugarApiException $e) {
            $out = array('status' => $e->httpCode, 'error' => array('code' => $e->errorLabel, 'message' => $e->getMessage()));
        }
        echo json_encode($out + array('rows' => (object) Administration::$rows));
        exit(0);
    }

    check('the class ships where Sugar autoloads its namespace (custom/src/BenchDogs)', is_file($classFile), $classFile);
    check('the class loads', class_exists($class, false));
    if (!class_exists($class, false)) {
        echo "\n" . count($checks) . " checks, {$failed} failed\n";
        exit(1);
    }

    // ---- The two registrations: before_save, the one class and method, on each module -------------------------
    $registered = array();
    foreach (array('Opportunities', 'ERP_Orders') as $module) {
        $file = $package . "/custom/Extension/modules/{$module}/Ext/LogicHooks/bd_default_owner.php";
        $hook_array = array();
        if (is_file($file)) {
            include $file;
        }
        $hooks = $hook_array['before_save'] ?? array();
        check("{$module}: one before_save registration", is_file($file) && count($hooks) === 1
            && count($hook_array) === 1, json_encode($hook_array));
        $hook = $hooks[0] ?? array();
        check("{$module}: it names the class and its beforeSave method", ($hook[3] ?? '') === $class
            && ($hook[4] ?? '') === 'beforeSave' && method_exists($class, 'beforeSave'), json_encode($hook));
        $registered[$module] = array($hook[3] ?? '', $hook[4] ?? '');
    }
    check('both modules run the same rule (one class, one method)',
        count(array_unique(array_map('json_encode', $registered))) === 1, json_encode($registered));

    // ---- The hook, the same cases on both modules ------------------------------------------------------------
    $hook = new $class();
    foreach (array('Opportunities', 'ERP_Orders') as $module) {
        $save = function ($owner) use ($hook, $module) {
            $bean = bdRecord($module, $owner);
            $hook->beforeSave($bean, 'before_save', array());

            return $bean;
        };

        bdReset();
        bdSetting('u-active');
        check("{$module}: fills an empty owner with the default owner", $save('')->assigned_user_id === 'u-active');
        bdReset();
        bdSetting('u-active');
        check("{$module}: ...and a null owner", $save(null)->assigned_user_id === 'u-active');
        bdReset();
        bdSetting('u-active');
        check("{$module}: ...and an owner of spaces only", $save('   ')->assigned_user_id === 'u-active');

        bdReset();
        bdSetting('u-active');
        $bean = $save('u-other');
        check("{$module}: an owner that is set is kept", $bean->assigned_user_id === 'u-other');
        check("{$module}: ...and nothing is read for it (no config read, no user read)",
            Administration::$reads === 0 && BeanFactory::$reads === array(),
            Administration::$reads . ' ' . json_encode(BeanFactory::$reads));

        bdReset();
        check("{$module}: no setting at all does nothing", $save('')->assigned_user_id === ''
            && BeanFactory::$reads === array());
        bdReset();
        bdSetting('');
        check("{$module}: an empty setting does nothing", $save('')->assigned_user_id === '');
        check("{$module}: ...reads no user and logs nothing (off is not a fault)", BeanFactory::$reads === array()
            && $GLOBALS['log']->lines === array(), json_encode($GLOBALS['log']->lines));

        bdReset();
        bdSetting('u-inactive');
        $bean = $save('');
        check("{$module}: an Inactive user is not used", $bean->assigned_user_id === '');
        check("{$module}: ...and the log says why", (bool) preg_grep('/^warn: .*not an active/', $GLOBALS['log']->lines),
            json_encode($GLOBALS['log']->lines));
        bdReset();
        bdSetting('u-reserved');
        check("{$module}: a Reserved user (Sugar's own support users) is not used", $save('')->assigned_user_id === '');
        bdReset();
        bdSetting('u-deleted');
        check("{$module}: a deleted user is not used", $save('')->assigned_user_id === '');
        bdReset();
        bdSetting('u-deleted');
        BeanFactory::$registry['u-deleted'] = bdUser('u-deleted', 'Active', 1);
        check("{$module}: a user deleted after it was read in this request is not used",
            $save('')->assigned_user_id === '');
        bdReset();
        bdSetting('u-missing');
        check("{$module}: an id that names no user is not used", $save('')->assigned_user_id === '');

        bdReset();
        bdSetting('1');
        $bean = $save('');
        check("{$module}: the admin user's id '1' (Sugar hands it back as the integer 1) is written as '1'",
            $bean->assigned_user_id === '1', var_export($bean->assigned_user_id, true));

        bdReset();
        bdSetting('u-active');
        Administration::$throw = true;
        $thrown = null;
        try {
            $bean = $save('');
        } catch (Throwable $e) {
            $thrown = $e;
        }
        check("{$module}: a failed config read leaves the owner empty and the save goes on",
            $thrown === null && $bean->assigned_user_id === '', $thrown ? $thrown->getMessage() : '');
        check("{$module}: ...and the log says so", (bool) preg_grep('/^error: .*left empty/', $GLOBALS['log']->lines),
            json_encode($GLOBALS['log']->lines));
        bdReset();
        bdSetting('u-active');
        BeanFactory::$throw = true;
        $thrown = null;
        try {
            $bean = $save('');
        } catch (Throwable $e) {
            $thrown = $e;
        }
        check("{$module}: a failed user read leaves the owner empty and the save goes on",
            $thrown === null && $bean->assigned_user_id === '', $thrown ? $thrown->getMessage() : '');
    }

    // ---- Who saves: only the integration's saves are filled -----------------------------------------------------
    foreach (array('Opportunities', 'ERP_Orders') as $module) {
        bdReset();
        bdSetting('u-active');
        $bean = bdRecord($module, '');
        $hook->beforeSave($bean, 'before_save', array());
        check("{$module}: the integration's save (platform sugarai_erp_connector) is filled",
            $bean->assigned_user_id === 'u-active');
        foreach (array('base' => 'a seller\'s own save in the browser', 'mobile' => 'the mobile app',
            'portal' => 'the portal', 'epicor_seed' => 'the seed loader (not the connector)') as $platform => $who) {
            bdReset();
            bdSetting('u-active');
            $_SESSION['platform'] = $platform;
            $bean = bdRecord($module, '');
            $hook->beforeSave($bean, 'before_save', array());
            check("{$module}: {$who} (platform {$platform}) is not filled, and nothing is read",
                $bean->assigned_user_id === '' && Administration::$reads === 0 && BeanFactory::$reads === array());
        }
        bdReset();
        bdSetting('u-active');
        $_SESSION = array();
        $bean = bdRecord($module, '');
        $hook->beforeSave($bean, 'before_save', array());
        check("{$module}: a save with no platform in the session (a job, a script) is not filled",
            $bean->assigned_user_id === '');
    }
    // A seller opens an Opportunity that has an owner, clears the owner in the browser and saves.
    bdReset();
    bdSetting('u-active');
    $_SESSION['platform'] = 'base';
    $cleared = bdRecord('Opportunities', '');
    $cleared->id = 'opp-owned-by-a-seller';
    $cleared->fetched_row = array('id' => 'opp-owned-by-a-seller', 'assigned_user_id' => 'u-other');
    $hook->beforeSave($cleared, 'before_save', array('isUpdate' => true));
    check("a seller's own save that clears an Opportunity's owner keeps it empty (never overwrites a seller's edit)",
        $cleared->assigned_user_id === '', var_export($cleared->assigned_user_id, true));

    // ---- An Opportunity created through the REST API with no owner in the body ----------------------------------
    // Sugar 26.1 ModuleApi::createBean (which IntegrateApi::upsertByField calls for a new key): a new bean, the id
    // set, new_with_id and in_save on, then populateBean copies only the fields the body names. The connector's
    // order and quote Opportunities leave assigned_user_id out of the body, so the bean never gets one.
    $restOpportunity = function (array $body) {
        $bean = new stdClass();
        $bean->module_dir = 'Opportunities';
        $bean->field_defs = array('id' => array(), 'name' => array(), 'erp_sync_key' => array(),
            'sales_stage' => array(), 'amount' => array(), 'date_closed' => array(), 'account_id' => array(),
            'assigned_user_id' => array('type' => 'id'));
        $bean->id = 'opp-created-by-rest';
        $bean->new_with_id = true;
        $bean->in_save = true;
        $bean->fetched_row = false;
        foreach ($body as $field => $value) {
            $bean->{$field} = $value;
        }

        return $bean;
    };
    $body = array('name' => 'Epicor Order 12076', 'erp_sync_key' => 'ADM__12076__opportunity',
        'sales_stage' => 'Closed Won', 'amount' => '1250.00', 'date_closed' => '2026-10-16', 'account_id' => 'acct-1');
    bdReset();
    bdSetting('u-active');
    $bean = $restOpportunity($body);
    check('REST-created Opportunity: the body carries no owner, so the bean has no assigned_user_id at all',
        !property_exists($bean, 'assigned_user_id'));
    $hook->beforeSave($bean, 'before_save', array());
    check('REST-created Opportunity (the integration\'s platform): the hook fills the owner from the setting',
        ($bean->assigned_user_id ?? null) === 'u-active', var_export($bean->assigned_user_id ?? null, true));
    $untouched = true;
    foreach ($body as $field => $value) {
        $untouched = $untouched && $bean->{$field} === $value;
    }
    check('REST-created Opportunity: ...and changes nothing else it was sent', $untouched);
    bdReset();
    bdSetting('u-active');
    $bean = $restOpportunity($body + array('assigned_user_id' => 'u-other'));
    $hook->beforeSave($bean, 'before_save', array());
    check('REST-created Opportunity that names its owner: the owner is kept', $bean->assigned_user_id === 'u-other');
    bdReset();
    $bean = $restOpportunity($body);
    $hook->beforeSave($bean, 'before_save', array());
    check('REST-created Opportunity with the setting off: still no owner', !property_exists($bean, 'assigned_user_id'));

    bdReset();
    bdSetting('u-active');
    $other = bdRecord('Opportunities', '');
    unset($other->field_defs['assigned_user_id']);
    $hook->beforeSave($other, 'before_save', array());
    check('a record with no owner field is left alone', $other->assigned_user_id === '');

    // ---- The setting as the rest of the package reads it -----------------------------------------------------
    bdReset();
    check('savedUserId: nothing saved reads as off', $class::savedUserId() === '');
    bdSetting(' u-active ');
    check('savedUserId: the saved id, trimmed', $class::savedUserId() === 'u-active');
    bdReset();
    bdSetting('1');
    check("savedUserId: '1' reads as the string '1'", $class::savedUserId() === '1');
    bdReset();
    check('activeUser: an Active user', is_object($class::activeUser('u-active')));
    bdReset();
    check('activeUser: an empty id names nobody, and nothing is read', $class::activeUser('') === null
        && BeanFactory::$reads === array());
    check('activeUser: Inactive, Reserved, deleted and missing users name nobody',
        $class::activeUser('u-inactive') === null && $class::activeUser('u-reserved') === null
        && $class::activeUser('u-deleted') === null && $class::activeUser('u-missing') === null);

    // ---- The admin page's endpoint ------------------------------------------------------------------------------
    check('the endpoint ships in a folder Sugar reads REST classes from (custom/modules/<module>/clients/base/api)',
        is_file($apiFile) && class_exists('BdDefaultOwnerApi', false) && is_subclass_of('BdDefaultOwnerApi', 'SugarApi'));
    $endpoint = new BdDefaultOwnerApi();
    $routes = array();
    foreach ($endpoint->registerApiRest() as $route) {
        $routes[] = implode(' ', (array) $route['reqType']) . ' ' . implode('/', $route['path']) . ' ' . $route['method'];
    }
    sort($routes);
    check('it registers GET and PUT BenchDogs/default-owner, each to a method it has', $routes === array(
        'GET BenchDogs/default-owner readDefaultOwner', 'PUT BenchDogs/default-owner saveDefaultOwner')
        && method_exists($endpoint, 'readDefaultOwner') && method_exists($endpoint, 'saveDefaultOwner'),
        json_encode($routes));
    $refused = function (callable $call) {
        try {
            $call();
        } catch (SugarApiException $e) {
            return get_class($e);
        }

        return '';
    };
    $saved = function () {
        return Administration::$rows['benchdogs_ext']['base'] ?? array();
    };

    bdReset();
    bdSetting('u-active');
    check('a user who is not an administrator cannot read it', $refused(function () use ($endpoint) {
        $endpoint->readDefaultOwner(bdApiAs(false), array());
    }) === 'SugarApiExceptionNotAuthorized');
    check('...nor save it, and nothing is saved', $refused(function () use ($endpoint) {
        $endpoint->saveDefaultOwner(bdApiAs(false), array('default_owner_user_id' => 'u-other'));
    }) === 'SugarApiExceptionNotAuthorized' && $saved() === array('default_owner_user_id' => 'u-active'));

    bdReset();
    $answer = $endpoint->readDefaultOwner(bdApiAs(true), array());
    check('read, nothing saved: off', $answer === array('default_owner_user_id' => '',
        'default_owner_user_name' => '', 'default_owner_user_active' => false), json_encode($answer));
    $answer = $endpoint->saveDefaultOwner(bdApiAs(true), array('default_owner_user_id' => 'u-active'));
    check('save an Active user: the id is stored', $saved() === array('default_owner_user_id' => 'u-active'),
        json_encode($saved()));
    check('...for the base platform, the one it is read from', array_keys(Administration::$rows['benchdogs_ext'])
        === array('base'), json_encode(Administration::$rows));
    check('...and the answer names the user', $answer === array('default_owner_user_id' => 'u-active',
        'default_owner_user_name' => 'User u-active', 'default_owner_user_active' => true), json_encode($answer));
    check('...and the hook now fills an empty owner with it', (function () use ($hook) {
        $bean = bdRecord('ERP_Orders', '');
        $hook->beforeSave($bean, 'before_save', array());

        return $bean->assigned_user_id === 'u-active';
    })());
    $answer = $endpoint->saveDefaultOwner(bdApiAs(true), array('default_owner_user_id' => '  u-other '));
    check('save with spaces around the id: the id is stored trimmed', $saved() === array(
        'default_owner_user_id' => 'u-other') && $answer['default_owner_user_id'] === 'u-other', json_encode($saved()));
    $answer = $endpoint->saveDefaultOwner(bdApiAs(true), array('default_owner_user_id' => ''));
    check('save empty: stored empty, read back as off', $saved() === array('default_owner_user_id' => '')
        && $answer === array('default_owner_user_id' => '', 'default_owner_user_name' => '',
            'default_owner_user_active' => false), json_encode($answer));

    foreach (array('u-inactive' => 'an Inactive user', 'u-reserved' => 'a Reserved user', 'u-deleted' => 'a deleted user',
        'u-missing' => 'an id that names no user') as $id => $who) {
        bdReset();
        bdSetting('u-active');
        check("save {$who}: refused, and the saved owner is unchanged", $refused(function () use ($endpoint, $id) {
            $endpoint->saveDefaultOwner(bdApiAs(true), array('default_owner_user_id' => $id));
        }) === 'SugarApiExceptionInvalidParameter' && $saved() === array('default_owner_user_id' => 'u-active'),
            json_encode($saved()));
    }
    foreach (array('a number' => 7, 'a list' => array('u-active'), 'null' => null) as $what => $value) {
        bdReset();
        bdSetting('u-active');
        check("save {$what} as the id: refused, nothing saved", $refused(function () use ($endpoint, $value) {
            $endpoint->saveDefaultOwner(bdApiAs(true), array('default_owner_user_id' => $value));
        }) === 'SugarApiExceptionInvalidParameter' && $saved() === array('default_owner_user_id' => 'u-active'));
    }
    bdReset();
    bdSetting('u-active');
    check('save with no id at all: refused as a missing parameter, nothing saved', $refused(function () use ($endpoint) {
        $endpoint->saveDefaultOwner(bdApiAs(true), array('default_owner_user_name' => 'User u-other'));
    }) === 'SugarApiExceptionMissingParameter' && $saved() === array('default_owner_user_id' => 'u-active'));

    bdReset();
    $endpoint->saveDefaultOwner(bdApiAs(true), array('default_owner_user_id' => '1'));
    $answer = $endpoint->readDefaultOwner(bdApiAs(true), array());
    check("the admin user '1': saved, and read back as the string '1'", $answer['default_owner_user_id'] === '1'
        && $answer['default_owner_user_active'] === true, var_export($answer, true));

    bdReset();
    bdSetting('u-active');
    BeanFactory::$users['u-active']->status = 'Inactive';
    $answer = $endpoint->readDefaultOwner(bdApiAs(true), array());
    check('a saved user made Inactive since: read shows the name and says not active', $answer === array(
        'default_owner_user_id' => 'u-active', 'default_owner_user_name' => 'User u-active',
        'default_owner_user_active' => false), json_encode($answer));
    bdReset();
    bdSetting('u-deleted');
    $answer = $endpoint->readDefaultOwner(bdApiAs(true), array());
    check('a saved user deleted since: read keeps the id, shows no name, says not active', $answer === array(
        'default_owner_user_id' => 'u-deleted', 'default_owner_user_name' => '', 'default_owner_user_active' => false),
        json_encode($answer));

    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
