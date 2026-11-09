<?php

// The Bench Dogs admin page for the default owner of ERP-created records (#Administration/layout/bd-default-owner).
$viewdefs['Administration']['base']['layout']['bd-default-owner'] = array(
    'components' => array(
        array(
            'layout' => array(
                'type' => 'base',
                'name' => 'main-pane',
                'css_class' => 'main-pane span12',
                'components' => array(
                    array(
                        'view' => 'bd-default-owner',
                    ),
                ),
            ),
        ),
    ),
);
