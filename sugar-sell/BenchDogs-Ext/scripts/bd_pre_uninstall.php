<?php

/** Undo the things the uninstaller cannot see (G380, 🔒 1724b, G280, 🔒 1567). */

// Proof of life (G295): grep package_install.log, not sugarcrm.log, for this line (G294).
$bdVersion = isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';
$GLOBALS['log']->fatal('BenchDogs-Ext: pre_uninstall running (' . $bdVersion . ') - cleaning up deployed metadata');

// ERP-Core takes the marked fields off in post_uninstall.php, once their vardefs are gone (G380 / 🔒 1724b).

// The quote PDF template is unpublished while its class is still on disk: its logo goes with the files, and the PDF
// renderer stops on an image it cannot open. The record is kept.
try {
    if (class_exists('Sugarcrm\\Sugarcrm\\custom\\BenchDogs\\BdQuotePdf')) {
        $GLOBALS['log']->fatal('BenchDogs-Ext: quote PDF template '
            . \Sugarcrm\Sugarcrm\custom\BenchDogs\BdQuotePdf::uninstall());
    } else {
        $GLOBALS['log']->fatal('BenchDogs-Ext: class BdQuotePdf not found, quote PDF template left as it is');
    }
} catch (Throwable $e) {
    $GLOBALS['log']->fatal('BenchDogs-Ext: quote PDF template not unpublished: ' . $e->getMessage());
}

// The *_cstm columns behind the bd_* fields are left in the database (G278, 🔒 1506, G234, G282).
