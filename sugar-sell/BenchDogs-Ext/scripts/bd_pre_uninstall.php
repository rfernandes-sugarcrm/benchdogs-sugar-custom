<?php

/** Undo the things the uninstaller cannot see (G380, 🔒 1724b, G280, 🔒 1567). */

// Proof of life (G295): grep package_install.log, not sugarcrm.log, for this line (G294).
$bdVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';
$GLOBALS['log']->fatal('BenchDogs-Ext: pre_uninstall running (' . $bdVersion . ') - cleaning up deployed metadata');

// Nothing to undo here any more: ERP-Core takes the marked fields off in post_uninstall.php, once their vardefs are gone (G380 / 🔒 1724b).

// The *_cstm columns behind the bd_* fields are left in the database (G278, 🔒 1506, G234, G282).
