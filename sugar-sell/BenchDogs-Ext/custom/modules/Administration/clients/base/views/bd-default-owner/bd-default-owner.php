<?php

// The one setting this page edits: Sugar's own lookup over Users, shown by name; the page saves the picked user's id.
$viewdefs['Administration']['base']['view']['bd-default-owner'] = array(
    'fields' => array(
        array(
            'name' => 'default_owner_user_name',
            'id_name' => 'default_owner_user_id',
            'type' => 'relate',
            'module' => 'Users',
            'rname' => 'full_name',
            'link' => false,
            'label' => 'LBL_BD_DEFAULT_OWNER',
            'help' => 'LBL_BD_DEFAULT_OWNER_HELP',
        ),
    ),
);
