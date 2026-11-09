<?php

// The create form's owner follows the account's owner until the person picks one (BdAccountOwner, bd-account-owner).
// Guarded: the compiled ext outlives the class for one step of an uninstall.
if (is_array($viewdefs['Quotes']['base']['layout']['create'] ?? null)
    && class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdAccountOwner')) {
    $viewdefs['Quotes']['base']['layout']['create'] = \Sugarcrm\Sugarcrm\custom\BenchDogs\BdAccountOwner::withView(
        $viewdefs['Quotes']['base']['layout']['create']
    );
}
