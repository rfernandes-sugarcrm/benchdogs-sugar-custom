<?php
/**
 * Post-uninstall cleanup for "SugarAI: Bench Dogs Extensions"
 * (package key sugarai_benchdogs_ext, observed at 0.9.40).
 *
 * WHY THIS EXISTS
 * ---------------
 * The package manifest declares is_uninstallable => true but registers NO
 * pre_uninstall or post_uninstall script. Its post_execute
 * (scripts/post_install.php) writes DEPLOYED metadata through
 * DeployedMetaDataImplementation. Those writes live outside the package's own
 * installdefs, so Module Loader does not revert them when it removes the
 * package.
 *
 * Three classes of damage survive an uninstall:
 *
 *   1. ORPHANED FIELD REFERENCES. The Quotes record view keeps the Bench Dogs
 *      panel listing bd_erp_total, bd_erp_stage, bd_priced_at, bd_reason_code
 *      and bd_governing_line. The Accounts record view keeps bd_customer_group
 *      and bd_customer_group_code. The uninstall drops those vardefs, so both
 *      views reference fields that no longer exist.
 *
 *   2. ORPHANED BUTTONS. bd_send_estimating_button on Quotes and
 *      bd_create_opp_quote_button on Accounts remain, pointing at Sidecar field
 *      types whose JavaScript the uninstall removed.
 *
 *   3. DELETED THIRD-PARTY BUTTONS, the worst of the three.
 *      BdQuotesLayoutExtensions::writeButtons() REMOVES buttons owned by
 *      ERP-Epicor from the deployed Quotes record view: advanced_quote_button,
 *      create_erp_order_button and refresh_price_availability_button. Nothing
 *      restores them. Uninstalling Bench Dogs therefore leaves the product's
 *      own quote actions permanently missing, with no visible connection to the
 *      package that removed them.
 *
 * This script repairs classes 1 and 2. It deliberately does NOT attempt 3:
 * re-running ERP-Epicor's own installer restores those buttons from its own
 * definitions, which is safer than reconstructing them here. See the runbook.
 *
 * USAGE
 *   php bd_uninstall_cleanup.php            report only, changes nothing
 *   php bd_uninstall_cleanup.php --apply    perform the removals
 *
 * Run from the Sugar instance root (the directory holding config.php), as the
 * web-server user, AFTER the package has been uninstalled. Take a database and
 * filesystem backup first. Follow with Admin, Repair, Quick Repair and Rebuild.
 */

if (PHP_SAPI !== 'cli') {
    die("Run this from the command line.\n");
}

$apply = in_array('--apply', $argv, true);

if (!file_exists('config.php')) {
    die("config.php not found. Run this from the Sugar instance root.\n");
}

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}
require_once 'include/entryPoint.php';
require_once 'modules/ModuleBuilder/parsers/constants.php';
require_once 'modules/ModuleBuilder/parsers/views/DeployedMetaDataImplementation.php';

/** Panel added to the Quotes record view by BdQuotesLayoutExtensions::write(). */
const BD_QUOTES_PANEL = 'LBL_RECORDVIEW_PANEL_BENCHDOGS';

/** Buttons this package added, by module. */
const BD_BUTTONS = [
    'Quotes'   => ['bd_send_estimating_button'],
    'Accounts' => ['bd_create_opp_quote_button'],
];

/** Fields this package placed on deployed record views, by module. */
const BD_FIELDS = [
    'Quotes' => [
        'bd_erp_total',
        'bd_erp_stage',
        'bd_priced_at',
        'bd_reason_code',
        'bd_governing_line',
    ],
    'Accounts' => [
        'bd_customer_group',
        'bd_customer_group_code',
    ],
];

/**
 * Column added to the Quotes quoted-line-items grid by
 * BdQliColumnsLayout::install(). That class ships its own uninstall() for
 * exactly this, but nothing ever calls it: there is no uninstall script in the
 * manifest, and by the time you would want it the class file is gone.
 */
const BD_QLI_FIELD = 'bd_erp_line_num';

/**
 * Remove the Bench Dogs panel, fields, buttons and grid column from one
 * module's deployed record view, in a single get/mutate/deploy cycle.
 *
 * One cycle per module matters: DeployedMetaDataImplementation reads the
 * currently deployed definition, so two separate cycles against the same module
 * would have the second overwrite the first if the read happened before the
 * first deploy landed.
 */
function cleanRecordView(string $module, bool $apply, array &$changes): void
{
    $deploy = new DeployedMetaDataImplementation(MB_RECORDVIEW, $module, 'base');
    $viewdefs = $deploy->getViewdefs();
    $record =& $viewdefs['base']['view']['record'];
    $dirty = false;

    if (!empty($record['panels']) && is_array($record['panels'])) {
        $panels =& $record['panels'];

        // Drop the whole Bench Dogs panel (Quotes only).
        $at = array_search(BD_QUOTES_PANEL, array_column($panels, 'name'), true);
        if ($at !== false) {
            array_splice($panels, $at, 1);
            $changes[] = "$module: removed panel " . BD_QUOTES_PANEL;
            $dirty = true;
        }

        // Drop any remaining bd_ fields wherever they sit. An admin may have
        // moved them since install, so match by name across every panel rather
        // than by position. Also strip the quoted-line-items grid column, which
        // lives nested inside the product_bundle_items collection field.
        $wanted = BD_FIELDS[$module] ?? [];
        foreach ($panels as $pi => $panel) {
            if (empty($panel['fields']) || !is_array($panel['fields'])) {
                continue;
            }
            $kept = [];
            foreach ($panel['fields'] as $fi => $field) {
                $name = is_array($field) ? ($field['name'] ?? '') : (string) $field;

                if (in_array($name, $wanted, true)) {
                    $changes[] = "$module: removed field $name from panel "
                        . ($panel['name'] ?? $pi);
                    $dirty = true;
                    continue;
                }

                if ($name === 'product_bundle_items'
                    && !empty($field['fields'])
                    && is_array($field['fields'])
                ) {
                    $cols = [];
                    foreach ($field['fields'] as $col) {
                        $colName = is_array($col) ? ($col['name'] ?? '') : (string) $col;
                        if ($colName === BD_QLI_FIELD) {
                            $changes[] = "$module: removed " . BD_QLI_FIELD
                                . ' from the quoted-line-items grid';
                            $dirty = true;
                            continue;
                        }
                        $cols[] = $col;
                    }
                    $field['fields'] = $cols;
                }

                $kept[] = $field;
            }
            $panels[$pi]['fields'] = $kept;
        }
        unset($panels);
    }

    if (!empty($record['buttons']) && is_array($record['buttons'])) {
        $unwanted = BD_BUTTONS[$module] ?? [];
        $kept = [];
        foreach ($record['buttons'] as $button) {
            $name = is_array($button) ? ($button['name'] ?? '') : '';
            if (in_array($name, $unwanted, true)) {
                $changes[] = "$module: removed button $name";
                $dirty = true;
                continue;
            }
            $kept[] = $button;
        }
        $record['buttons'] = $kept;
    }

    if (!$dirty) {
        $changes[] = "$module: nothing to do";
        return;
    }
    if ($apply) {
        $deploy->setViewdefs($viewdefs);
        $deploy->deploy($viewdefs);
    }
}

$changes = [];
foreach (['Quotes', 'Accounts'] as $module) {
    cleanRecordView($module, $apply, $changes);
}

echo $apply ? "APPLIED:\n" : "DRY RUN. Pass --apply to perform these:\n";
foreach ($changes as $line) {
    echo "  - $line\n";
}
echo "\nThis does NOT restore the ERP-Epicor buttons the Bench Dogs install\n"
   . "deleted (advanced_quote_button, create_erp_order_button,\n"
   . "refresh_price_availability_button). Re-run ERP-Epicor's own installer to\n"
   . "bring those back, then Quick Repair and Rebuild.\n";
