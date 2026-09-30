#!/usr/bin/env php
<?php
/**
 * BenchDogs-Ext Package Builder
 *
 * Extension-only package, and since 0.9.42-rc69 a MINIMAL one (G280 / 🔒 1567:
 * *"Benchdog MLP shoudl be mininal with mininal foot print of overide"*, *"just
 * if we have to"*). It ships exactly:
 *
 *   custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php
 *   custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php
 *       Bench's customer category (REQ-19): Epicor Customer.GroupCode and
 *       CustGrup.GroupDesc. Core has no equivalent, and the Bench connector's
 *       erp_customers step cannot deliver them without the vardef.
 *       Placed on the Accounts record view by ERP-Core's ErpLayoutExtraFields
 *       from their `erp_layout` vardef marker (G380 (f), 🔒 1724b); this
 *       package ships no layout code. G817: the code carries ERP-Core's
 *       erp_customer_create_required_formula (the ADM gate), so the quote's
 *       "Create <account> in the ERP now?" prompt names a missing Cust. Group
 *       instead of offering a Create the ERP refuses.
 *   custom/Extension/application/Ext/Language/_override_en_us.bd_account_type_suspect.php
 *       G450: ONE guarded key, account_type_dom['Suspect'], the value core's
 *       per-connection customer_type_extra map writes for an Epicor SUS
 *       customer. Core cannot add a dom key, so the key ships here.
 *   custom/Extension/modules/Quotes/Ext/Vardefs/_override_bd_erp_reference.php
 *       G606: Bench claims the PLACEMENT of ERP-Core's generic erp_reference
 *       (its `erp_layout` marker); ERP-Core owns the field, storage and sync.
 *   custom/Extension/modules/Quotes/Ext/Dependencies/bd_adm_reference_required.php
 *       G809: Reference required in the edit views on an unsent ADM quote whose
 *       ship-to cannot default it. A VIEW dependency: never served as a
 *       required field (the connector's schema check reads that flag) and never
 *       run on a server save.
 *   custom/Extension/modules/Accounts/Ext/LogicHooks/bd_customer_group_name.php
 *       G804 (🔒 2081b): on an Account not in the ERP, the Cust. Group name
 *       follows the Group Code the seller picked from ADM's customer groups.
 *   custom/Extension/modules/Accounts/Ext/Dependencies/bd_adm_customer_group_required.php
 *       🔒 2085b / 🔒 2086b: Cust. Group required in the edit views on an ADM
 *       Customer not yet in the ERP. A VIEW dependency: never served as a
 *       required field and never run on a server save.
 *   custom/Extension/modules/Contacts/Ext/clients/base/views/record/bd_epicor_contact_fields.php
 *       G458 (🔒 2102b): a sidecar overlay that shows the customer's five
 *       Epicor contact fields (their package Bench_Dogs_Account_Contact_Fields
 *       creates them; the Bench connector extension fills them) read-only on
 *       the Contacts record view's ERP panel, only where the tenant's merged
 *       vardefs hold them. Not a field, and no deployed-view write.
 *   custom/Extension/modules/Quotes/Ext/clients/base/views/{record,
 *     quote-data-grand-totals-header,quote-data-grand-totals-footer}/_override_zz_bd_hide_seller_discounts.php
 *   custom/Extension/modules/Products/Ext/clients/base/views/{quote-data-group-list,record}/
 *     _override_zz_bd_hide_seller_discounts.php
 *       G848 (🔒 2151b): Bench Dogs sellers do not apply discounts. Five sidecar
 *       overlays, one body, that stop the quote DRAWING a discount: ERP-Epicor's
 *       Discount panel (erp_discount_panel), the totals' Order Level Discount
 *       (deal_tot in the strip, erp_document_discount_amount in the footer), the
 *       grid's Line Discount column and edit-row input (discount_field) and the
 *       line page's. Hide only: no field, no setting, no deployed-view write, no
 *       stored value or total touched; uninstall gives every one back.
 *   custom/Extension/modules/Quotes/Ext/clients/base/views/preview/_override_zz_bd_hide_seller_discounts.php
 *       G848 rc85 (🔒 2159b): the Quotes list preview draws no Order Discount (deal_tot); same body.
 *   custom/Extension/modules/{Quotes,Products}/Ext/Language/_override_en_us.bd_hide_seller_discounts.php
 *       G848 rc85 (🔒 2159b): ERP-Core's "Line Items Discounted Subtotal" / "Discounted Total" read "Subtotal" / "Total" (en_us overrides of existing keys).
 *   custom/clients/base/api/BdBenchDogsActionsApi.php
 *       EMPTY, and the one retirement stub left: it unregisters rc68's
 *       bd-tools/repair-ui route on upgraded tenants (see the file).
 *   scripts/post_execute.php, bd_pre_uninstall.php, post_uninstall.php
 *       the lifecycle of the above.
 *
 * G380 / G381 (owner rulings 🔒 1705b, 🔒 1724b: ADM config + ADM rules ONLY)
 * add ADM's own quote values: five Quote pickers (Lead Source, Lead Type,
 * Project, and G460's Marketing Campaign and Marketing Event) + labels, one
 * before_save defaults hook (Reference and Project),
 * the lookup-type labels and one tenant list, and the BdAdm* classes. Reference
 * is ERP-Epicor's generic erp_reference; the part-number refusal is
 * ERP-Epicor's per-company switch; this package no longer fills ERP-Epicor's
 * ordering hook points. See README.
 *
 * Everything else earlier builds shipped is gone. Retiring it from a tenant
 * that already has it is the job of the disposable one-off
 * sugar-sell/ONEOFF-RetireBdResidue (🔒 1521), not of this package;
 * scripts/tests/test_g280_minimal_footprint.py pins this list.
 *
 * It ships NO modules of its own. Decisions 901/903/904/905 retired the bd01
 * quote-mirror modules, which is why there are no `beans`, `relationships`,
 * `vardefs` or `layoutdefs` installdefs here any more: the Opportunity
 * contribution contract reads the native Sugar quote lines instead.
 */

$packageID      = 'sugarai_benchdogs_ext';
$packageLabel   = 'SugarAI: Bench Dogs Extensions';
$description    = 'Bench Dogs extensions for Sugar Sell: the two customer-group fields (a Cust. Group picker before the account is in the ERP, required for an ADM Customer and asked for before the quote offers to create an ADM account in the ERP) and the Suspect account type on Accounts, and the ADM company\'s own quote values (Lead Source, Lead Type, Project, Marketing Campaign and Marketing Event pickers, required until the quote is in the ERP and defaulted from the account\'s last quote; Reference and Project defaults; the Reference placement and requirement), and the Epicor contact Function, Role and primary flags (Bench Dogs\' own contact fields) shown read-only on the Contacts record view where the tenant has them; and, because Bench Dogs sellers do not apply discounts, no discount panel, order-level discount or line discount shown on a quote or in the Quotes list preview, with the subtotal and line total captioned Subtotal and Total.';
$supportedVersionRegex = '(26|25|14)\\..*$';
$acceptableSugarFlavors = array('ENT', 'ULT', 'PRO');

if (empty($argv[1])) {
    if (file_exists('version')) {
        $version = trim(file_get_contents('version'));
    }
} else {
    $version = $argv[1];
}
if (empty($version)) {
    die("Usage: {$argv[0]} [version]\n");
}

$dir = 'releases';
if (!is_dir($dir)) {
    mkdir($dir);
}

$manifest = array(
    'key'                       => $packageID,
    'name'                      => $packageLabel,
    'description'               => $description,
    'author'                    => 'SugarCRM, Inc.',
    'is_uninstallable'          => true,
    'published_date'            => date('Y-m-d H:i:s'),
    'type'                      => 'module',
    'version'                   => $version,
    'remove_tables'             => 'prompt',
    'acceptable_sugar_versions' => array('regex_matches' => array($supportedVersionRegex)),
    'acceptable_sugar_flavors'  => $acceptableSugarFlavors,
    // Refuse unsafe install order before copying any file.
    'dependencies'              => array(
        array(
            // 1.1.125 is the floor because this package now RELIES on four
            // things ERP-Epicor ships from that release (G380 (d)-(g),
            // 🔒 1724b; the coordinator gates the 1.1.125 cut on all four):
            //  - Quotes.erp_reference (ERP-Core), the field the Reference
            //    default fills;
            //  - ERP_Companies.erp_order_requires_part_number, the part-number
            //    refusal this package stopped carrying;
            //  - custom/include/ErpLayoutExtraFields.php (ERP-Core), which
            //    places this package's marked fields - it ships no layout code;
            //  - custom/modules/Quotes/ErpQuoteFacts.php, the company and
            //    product-group answers BdAdmRules asks for.
            // Below it every one of those is missing: the fields would sit on no
            // panel and the defaults would skip (logged, never fatal).
            //
            // 1.1.131 since 0.9.42-rc73 (lane D13's recommendation, G530): the
            // Reference default is now SHORTENED to what the ERP takes, and the
            // limit is ERP-Epicor's, on the field - Quotes.erp_reference
            // 'erp_max_length' (10), read through
            // ErpQuoteFacts::referenceMaxLength(). Both first ship in 1.1.131.
            // On an older ERP-Epicor BdAdmRules tolerates the missing method
            // (no cut, logged), so "HARRISBURG PA" (13) would again reach ADM
            // and be refused at Send to Estimation. The floor keeps that pair
            // from being installed apart.
            //
            // 1.1.134 (G571 / G570): ERP-Core's `erp-dependent-enum` field type
            // and ErpLayoutExtraFields's erp_layout 'type' (written onto entries
            // already placed, too) first ship there. Below it the markers' type
            // is ignored and the new vardef keys are inert: the pickers stay
            // plain enums (safe, but the Event list is unfiltered and Project
            // not required, so the gap would not close). 1.1.134 is the next
            // ERP-Epicor cut: 1.1.133 was built 2026-09-25 (4584d74f) WITHOUT
            // lane D19's branch; if the release carrying it gets another number,
            // use that.
            'id_name' => 'sugarai_erp_epicor',
            'version' => '1.1.134',
        ),
        array(
            'id_name' => 'sugarai_erp_epicor_partialfulfillment',
            // 1.0.43 is the floor because this package STOPPED doing three
            // things PF now does, and PF must be new enough to do all three:
            //  - >= 1.0.40 owns the stage vocabulary (G278 / 🔒 1506):
            //    sales_stage_dom['Prototype Ordered'] / ['Partial Production
            //    Ordered'], their 80/90 probabilities and styles, and
            //    quote_stage_dom['Partially Fulfilled'];
            //  - >= 1.0.41 ships OpportunityContribution.php WITH the
            //    all-alternative preserve gate (G282 / 🔒 1511, ddf2796). rc69
            //    no longer ships that path, so PF's body is the only one;
            //  - >= 1.0.43 defaults erp_integration.partial_order_sales_stage
            //    to 'Partial Production Ordered' on the READ side (G305 /
            //    🔒 1519, 356af62), which is what post_execute.php used to write.
            // Under this floor, Module Loader refuses with ERR_UW_NO_DEPENDENCY
            // (UpgradeHistory.php:440, greaterThanOrEqualTo) - fail closed.
            //
            // 1.0.50 since 0.9.42-rc70: the Partial Fulfillment release that
            // ships with ERP-Epicor 1.1.125 (coordinator, 2026-09-24), so the
            // two floors name one tested pair.
            'version' => '1.0.50',
        ),
    ),
);

$installdefs = array(
    'id'           => $packageID,
    // Empty since the quote mirror was retired: this package installs no bean
    // of its own, so it creates no table and no module tab.
    'beans'        => array(),
    'copy'         => array(),
    // 🛑 NOT scripts/post_install.php, and pre_uninstall below is NOT
    // scripts/pre_uninstall.php (0.9.42-rc69, G294/G295). Those two paths are
    // RESERVED: PackageZipFile::PACKAGE_SCRIPT_LIST (SugarEnt 25.2.0 / 26.1.0)
    // names scripts/{pre,post}_install.php and scripts/{pre,post}_uninstall.php,
    // and PackageManager plain-`include`s whichever one ships - AFTER the
    // installer has already require_once'd it as post_execute, and BEFORE the
    // installer runs it as pre_uninstall. So a script at a reserved path that is
    // ALSO an installdef runs twice. Measured on rc68 (Ophir, PID 2438880): the
    // second post_install pass had no $manifest, logged "(unknown): 8/8" and
    // overwrote the durable report. post_uninstall.php keeps its reserved name
    // because PackageManager runs POST_UNINSTALL_FILE only for a `patch`
    // package; this one is `module`. scripts/tests/test_g294_single_pass.py.
    'post_execute' => array('<basepath>/scripts/post_execute.php'),
    // The uninstall counterpart to post_execute, in two halves because the two
    // jobs need opposite conditions. pre_uninstall undoes the DEPLOYED METADATA
    // post_execute.php wrote - record-view panels, buttons, the fields the
    // uninstaller cannot see because they live in a file this package does not
    // ship - and needs the helper classes under custom/ still on disk.
    // post_uninstall rebuilds the caches and has to run after those same files
    // are gone, or it simply re-bakes what it is meant to clear.
    //
    // Their absence is what broke the instance the last time this package was
    // removed. See the docblock at the top of each script.
    'pre_uninstall'  => array('<basepath>/scripts/bd_pre_uninstall.php'),
    'post_uninstall' => array('<basepath>/scripts/post_uninstall.php'),
);

// Add custom/ files: the customer-group and ADM Extension files, the BdAdm*
// classes and the empty REST stub. There is no modules/ tree to add: the package
// ships no module.
$customReal = realpath('custom');
if ($customReal) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($customReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        $relInZip = 'custom' . str_replace($customReal, '', $real);
        $relInZip = str_replace(DIRECTORY_SEPARATOR, '/', $relInZip);
        $installdefs['copy'][] = array(
            'from' => "<basepath>/{$relInZip}",
            'to'   => $relInZip,
        );
    }
}

// scripts/ ships ONLY the three lifecycle scripts, and they reach the instance
// through the post_execute / pre_uninstall / post_uninstall installdefs above,
// never as copy entries. The loop that used to copy every OTHER script to
// custom/include/bd_scripts/ copied nothing (no other script exists) and is
// removed (0.9.42-rc70, footprint S7, 🔒 1724b). A new non-lifecycle script
// would need a reason to reach a tenant, and its own copy entry here.

// ---------------------------------------------------------------------------
// Build the zip
// ---------------------------------------------------------------------------

$manifestContent = sprintf(
    "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
    var_export($manifest, true),
    var_export($installdefs, true)
);

$zipPath = "{$dir}/{$packageID}-{$version}.zip";
if (file_exists($zipPath)) {
    die("Error: {$zipPath} already exists. Delete it or bump version.\n");
}

echo "Creating {$zipPath} ...\n";
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);

$addTree = function (string $rootName) use ($zip) {
    $rootReal = realpath($rootName);
    if (!$rootReal) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootReal, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $real = $file->getRealPath();
        $relInZip = $rootName . str_replace($rootReal, '', $real);
        $relInZip = str_replace(DIRECTORY_SEPARATOR, '/', $relInZip);
        $zip->addFile($real, $relInZip);
        echo " [*] {$relInZip}\n";
    }
};

// custom/ + scripts/ both ship at the zip root: the copy entries and the
// post_execute / pre_uninstall / post_uninstall installdef paths all resolve
// against <basepath>.
$addTree('custom');
$addTree('scripts');

$zip->addFromString('manifest.php', $manifestContent);
$zip->close();

echo "Done. Wrote {$zipPath}\n";
exit(0);
