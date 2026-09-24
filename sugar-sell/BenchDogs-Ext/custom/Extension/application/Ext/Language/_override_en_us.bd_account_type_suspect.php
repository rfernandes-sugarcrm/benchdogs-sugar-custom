<?php

/**
 * G450: Epicor's THIRD customer type, on Bench Dogs tenants only.
 *
 * Kinetic types a customer CUS, PRO or SUS. ERP-Core's account_type_dom has two
 * values (Prospect, Customer), so core types an Epicor SUS customer "Prospect":
 * on ADM that is 1,022 of the 1,132 accounts Sugar shows as Prospect (read-only
 * count, 2026-09-24; 846 of them active). The owner said Yes to a Bench-only
 * third value "Suspect" BESIDE the stock two - never renaming them; the
 * customer confirmed it (2026-09-25, "all 3 record types ... including
 * Suspects").
 *
 * WHO WRITES IT. Core, once the ADM connection's config holds
 * customer_type_extra = {"SUS": "Suspect"} (connector_epicor.normalize): inbound
 * a SUS customer is typed with the configured value AS GIVEN, and outbound a
 * Sugar "Suspect" targets SUS. The value is case-sensitive and must be exactly
 * this key; one the dom lacks renders blank. Core cannot add a dom key, which
 * is why this file lives in the Bench package.
 *
 * WHY THE `_override` NAME. SugarEnt 26.1 merges application language
 * fragments sorted by is_override first, then by an order-map mtime refreshed
 * only when a file's md5 changes (ModuleInstaller::sortExtensionFiles).
 * ERP-Core's REPLACE install assigns account_type_dom as a WHOLE ARRAY
 * (ERP-Core/src/custom/dropdowntemplates/account_type_dom.replace.php), so a
 * plain fragment that sorted before it would lose this key after every
 * ERP-Epicor REPLACE upgrade - the exact failure rc23 measured for the Bench
 * lookup-type label. `_override*` always merges last.
 *
 * WHY ONE KEY AND A GUARD. Never a whole-array assignment (that would wipe the
 * stock Customer/Prospect and anything an admin added). And the key is set only
 * when absent, so an admin's relabel in Admin -> Dropdown Editor (saved to its
 * own, earlier-merging fragment) survives every reinstall of this package.
 */
if (!isset($app_list_strings['account_type_dom']['Suspect'])) {
    $app_list_strings['account_type_dom']['Suspect'] = 'Suspect';
}
