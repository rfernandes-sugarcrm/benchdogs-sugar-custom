<?php

/**
 * Bench Dogs stage keys, append-only (existing keys and labels untouched):
 *
 * quote_stage_dom 'Partially Fulfilled' - REQ-1: a subset of the quote's
 * lines has been ordered; the quote is neither open-untouched nor closed.
 * bd-order-winning-line sets it; QuoteAcceptSiblingReject ignores it (that
 * hook fires only on 'Closed Accepted'), which is exactly the point.
 *
 * sales_stage_dom 'Prototype Ordered' / 'Partial Production Ordered' -
 * REQ-22's agreed answer: express what closed in the OPPORTUNITY STAGE
 * rather than splitting records. Neither key is in the forecast
 * won/lost sets, so the remainder stays open pipeline.
 *
 * sales_probability_dom rows keep Sugar Logic's stage->probability
 * dependency defined for the new stages.
 *
 * Installed by post_install via ModuleInstaller::install_languages() - the
 * scanner-safe route BaseErpDropdown documents (direct file writes are
 * denylisted by ModuleScanner for uploaded package code).
 */

$app_list_strings['quote_stage_dom']['Partially Fulfilled'] = 'Partially Fulfilled';

$app_list_strings['sales_stage_dom']['Prototype Ordered'] = 'Prototype Ordered';
$app_list_strings['sales_stage_dom']['Partial Production Ordered'] = 'Partial Production Ordered';

$app_list_strings['sales_probability_dom']['Prototype Ordered'] = 80;
$app_list_strings['sales_probability_dom']['Partial Production Ordered'] = 90;

/*
 * 🛑 G268 - THE TWO RETIRED STAGE NAMES ARE TAKEN BACK OUT, HERE, AT THE TAIL.
 *
 * Decision 314 renamed 'Prototype Closed' / 'Partial Production Closed' to the
 * '...Ordered' pair above (a1f0276 / 8b32a60, 2026-09-15 21:07). Bench kept
 * serving the old pair next to the new one after rc61 (8 sales_stage_dom keys
 * at 11:38Z and ~15:12Z on 2026-09-22; et, same rc61, serves 6).
 *
 * WHERE THEY LIVE. Not in a file this package ships. From 0.7.3 (1fd545f)
 * to rc37 (84edda2) THIS template declared the '...Closed' pair, and
 * post_install hands it to ModuleInstaller::install_languages() with id_name
 * 'zz_bd_stage_doms'. When custom/Extension/application/Ext/Language/
 * en_us.zz_bd_stage_doms.php already exists, install_languages() does NOT
 * overwrite it: it concatenates the old file and this template
 * (SugarEnt 26.1.0 ModuleInstall/ModuleInstaller.php:1227-1235, via
 * getExtensionFileContents() :2471). So every pre-rename Bench install left
 * its '...Closed' lines in that file for good, and every later install only
 * appended the '...Ordered' ones after them. et's file was born after the
 * rename, which is the whole Bench/et difference.
 *
 * WHY AN unset() AT THE TAIL AND NOT AN OVERWRITE OF THAT PATH.
 *  - This text is appended AFTER everything already in that file, so the
 *    unset() below runs after every historical '...Closed' assignment in it.
 *    The compiled language file is included with the accumulated
 *    $app_list_strings in scope (include/utils.php _mergeCustomAppListStrings),
 *    so an earlier-merged fragment's copy of the key goes too.
 *  - Shipping a stub AT en_us.zz_bd_stage_doms.php would make that file's
 *    content identical on every install. Its merge position is the mtime
 *    recorded in orderMapping.php, refreshed ONLY when its md5 changes
 *    (ModuleInstaller.php:2426-2438). A file that stops changing stops moving
 *    behind ERP-Core's whole-array sales_stage_dom.replace.php, and
 *    "reinstall Bench Dogs after ERP-Epicor" (G220/G273) stops working.
 *    Appending keeps the file growing, so that lever is untouched.
 *  - A copy stub would also hand the path to uninstall_copy(), which restores
 *    its backup on uninstall - the '...Closed' file itself on Bench - and
 *    fights G234's pre_uninstall over the same file.
 *  - unset() is a language construct: nothing ModuleScanner denylists, no
 *    file removed, and silent when the key is already absent (et, stock).
 *
 * The '...Ordered' pair and quote_stage_dom['Partially Fulfilled'] above are
 * NEVER touched here. Opportunities still holding an old literal are moved to
 * the new one by post_install's decision-314 migration on every install.
 */
unset(
    $app_list_strings['sales_stage_dom']['Prototype Closed'],
    $app_list_strings['sales_stage_dom']['Partial Production Closed'],
    $app_list_strings['sales_probability_dom']['Prototype Closed'],
    $app_list_strings['sales_probability_dom']['Partial Production Closed']
);
