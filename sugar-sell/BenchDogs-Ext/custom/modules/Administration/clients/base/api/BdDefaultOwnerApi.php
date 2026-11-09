<?php

use Sugarcrm\Sugarcrm\custom\BenchDogs\BdDefaultOwner;

/** The Bench Dogs admin page's one setting, the default owner for ERP-created records: administrators only. */
class BdDefaultOwnerApi extends SugarApi
{
    public function registerApiRest()
    {
        return array(
            'readDefaultOwner' => array(
                'reqType' => 'GET',
                'path' => array('BenchDogs', 'default-owner'),
                'pathVars' => array('', ''),
                'method' => 'readDefaultOwner',
                'shortHelp' => 'The default owner for ERP-created records: the user id, name, and whether it is active',
                'longHelp' => '',
            ),
            'saveDefaultOwner' => array(
                'reqType' => 'PUT',
                'path' => array('BenchDogs', 'default-owner'),
                'pathVars' => array('', ''),
                'method' => 'saveDefaultOwner',
                'shortHelp' => 'Saves the default owner for ERP-created records: an active user id, or empty for off',
                'longHelp' => '',
            ),
        );
    }

    public function readDefaultOwner(ServiceBase $api, array $args): array
    {
        if (!$api->user->isAdmin()) {
            throw new SugarApiExceptionNotAuthorized('Only administrators can read the default owner');
        }

        return BdDefaultOwner::describe();
    }

    /** Saves the id an administrator picked; an id that is not an active user is refused and nothing is saved. */
    public function saveDefaultOwner(ServiceBase $api, array $args): array
    {
        if (!$api->user->isAdmin()) {
            throw new SugarApiExceptionNotAuthorized('Only administrators can change the default owner');
        }
        if (!array_key_exists(BdDefaultOwner::KEY, $args)) {
            throw new SugarApiExceptionMissingParameter('Name the default owner as ' . BdDefaultOwner::KEY);
        }
        $id = $args[BdDefaultOwner::KEY];
        if (!is_string($id) || !BdDefaultOwner::save($id)) {
            throw new SugarApiExceptionInvalidParameter(
                'The default owner must be an active Sugar user. Nothing was saved.'
            );
        }

        return BdDefaultOwner::describe();
    }
}
