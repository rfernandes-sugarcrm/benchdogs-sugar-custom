<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use BeanFactory;
use Throwable;

/**
 * The default owner for ERP-created records: an active user an administrator picks, stored by id; empty means off.
 * Sugar fills no owner on a record created through its REST API, so the integration's saves give an empty owner this
 * user. An owner that is set, or one a seller clears, is never changed.
 */
class BdDefaultOwner
{
    /** Where the setting is stored: this package's own config category, read and written for the base platform. */
    public const CATEGORY = 'benchdogs_ext';
    public const KEY = 'default_owner_user_id';
    public const PLATFORM = 'base';

    /** The user id the setting holds, or '' when it is off. */
    public static function savedUserId(): string
    {
        $config = BeanFactory::newBean('Administration')->getConfigForModule(self::CATEGORY, self::PLATFORM);
        // Sugar hands an all-digit value back as an integer, and the admin user's id is '1'.
        return is_array($config) ? trim((string) ($config[self::KEY] ?? '')) : '';
    }

    /**
     * The user the id names, when that user may own records: found, not deleted, and Active. Otherwise null.
     * @return object|null
     */
    public static function activeUser(string $id)
    {
        $user = self::liveUser($id);

        return $user !== null && (string) ($user->status ?? '') === 'Active' ? $user : null;
    }

    /** Saves an active user's id, or '' for off. Any other id is refused: nothing is saved and false is returned. */
    public static function save(string $id): bool
    {
        $id = trim($id);
        if ($id !== '' && self::activeUser($id) === null) {
            return false;
        }
        BeanFactory::newBean('Administration')->saveSetting(self::CATEGORY, self::KEY, $id, self::PLATFORM);

        return true;
    }

    /** What the admin page shows: the saved id, that user's name ('' when deleted or missing), and if it is Active. */
    public static function describe(): array
    {
        $id = self::savedUserId();
        $user = self::liveUser($id);

        return array(
            self::KEY => $id,
            'default_owner_user_name' => $user === null ? '' : trim((string) ($user->full_name ?? '')),
            'default_owner_user_active' => self::activeUser($id) !== null,
        );
    }

    /**
     * The user the id names, unless there is none or it was deleted.
     * @return object|null
     */
    private static function liveUser(string $id)
    {
        if ($id === '') {
            return null;
        }
        $user = BeanFactory::retrieveBean('Users', $id, array('disable_row_level_security' => true));
        // A bean Sugar already holds for this request is handed back as it is, even if it was deleted since.
        return is_object($user) && empty($user->deleted) ? $user : null;
    }

    /**
     * before_save on Opportunities and ERP orders: on the integration's save, an empty owner takes the default owner.
     * A seller's own save is never filled. A failed read leaves the owner empty and lets the save go on.
     * @param object $bean
     * @param string $event
     * @param array $arguments
     */
    public function beforeSave($bean, $event = '', $arguments = array()): void
    {
        if (!is_object($bean) || !isset($bean->field_defs['assigned_user_id'])
            || trim((string) ($bean->assigned_user_id ?? '')) !== '') {
            return;
        }
        // Told by the request's platform, as BdAdmRules tells the connector's send: never by user (it may be admin).
        if ((string) ($_SESSION['platform'] ?? '') !== BdAdmRules::CONNECTOR_PLATFORM) {
            return;
        }
        try {
            $id = self::savedUserId();
            if ($id === '') {
                return;
            }
            if (self::activeUser($id) === null) {
                $GLOBALS['log']->warn('Bench Dogs default owner: the setting names a user who is not an active Sugar'
                    . " user ({$id}), so the owner of this " . ($bean->module_dir ?? 'record') . ' is left empty');
                return;
            }
            $bean->assigned_user_id = $id;
        } catch (Throwable $e) {
            $GLOBALS['log']->error('Bench Dogs default owner: the owner of this ' . ($bean->module_dir ?? 'record')
                . ' is left empty, the setting could not be read: ' . $e->getMessage());
        }
    }
}
