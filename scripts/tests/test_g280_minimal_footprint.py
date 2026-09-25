#!/usr/bin/env python3
"""G280 / 🔒 1567 - the Bench Dogs package is MINIMAL, and this file pins it.

Owner, verbatim: *"do we overwrite for benchdogs??? if so dont"*, *"Benchdog MLP
shoudl be mininal with mininal foot print of overide,,,"* and *"just if we have
to"* (🔒 1567), on top of 🔒 1508 / 🔒 1520 (customer-category code only;
removal is the default and retention needs the owner's item-by-item consent).

WHAT SHIPS (0.9.42-rc69), and why each item cannot live upstream:

  custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php
  custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php
      Bench's customer category, REQ-19: Epicor Customer.GroupCode and
      CustGrup.GroupDesc. Core has no equivalent field, and core's schema
      enforcement dead-letters the Bench connector's erp_customers writes
      without the vardef.
      (Placed on the Accounts record view by ERP-Core's ErpLayoutExtraFields
      from their `erp_layout` vardef marker since rc70 - 🔒 1724b retired
      BdAccountsLayoutExtensions.php, this package's own layout writer.)
  custom/clients/base/api/BdBenchDogsActionsApi.php
      EMPTY. The one retirement stub left: an api file is require_once'd by
      path on every REST dictionary rebuild, and dropping it would leave rc68's
      bd-tools/repair-ui route registered on upgraded tenants.
  scripts/post_execute.php, bd_pre_uninstall.php, post_uninstall.php
      the lifecycle of the above.

Everything else is REMOVED, and every removed path has a named route off a
tenant that already carries it - asserted below, not assumed.

GROWN ONCE SINCE, WITH CONSENT: G380 / G381 (🔒 1705b, the per-item consent
🔒 1520 asks for) add files for Bench Dogs' ADM company's required quote values.
🔒 1724b ("Bench keeps ONLY ADM config + ADM rules") then cut that to six: the
generic parts (Reference, the part-number refusal, field placement, the
company and product-group answers) are ERP-Epicor's. Each is listed in KEPT
with its reason; see the package README.

MUTATION-VERIFIED (each applied, this file re-run, the named case observed red):
  restore one retired stub (git checkout 019fbe1 -- <stub>)
      -> test_the_source_tree_is_exactly_the_kept_list
      -> test_the_built_zip_is_exactly_the_kept_list (after a rebuild)
  delete a kept file (the customer-group label)
      -> both exact-list cases, and test_the_kept_fields_are_exactly_the_two
  add a stray custom/x.php
      -> test_the_source_tree_is_exactly_the_kept_list
  put a route back in the api stub
      -> test_the_api_stub_registers_no_route
  restore post_install's partial_order_sales_stage write
      -> test_post_install_runs_exactly_the_two_steps
  lower the Partial Fulfillment floor back to 1.0.40
      -> test_partial_fulfillment_is_new_enough_to_own_what_this_stopped_doing
  drop an entry from the one-off's Extension worklist
      -> test_every_removed_path_has_a_route_off_the_tenant
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
import zipfile

from bd_retirement import PKG, built_zip, oneoff_worklist, zip_names

#: The whole package. A new entry here is a new override on a customer tenant,
#: and under 🔒 1520 it needs the owner's item-by-item consent first.
KEPT = {
    "custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php":
        "the customer category (REQ-19): the two Account fields",
    "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php":
        "their two labels",
    "custom/clients/base/api/BdBenchDogsActionsApi.php":
        "EMPTY: unregisters rc68's bd-tools/repair-ui on upgraded tenants",
    "scripts/post_execute.php": "lifecycle",
    "scripts/bd_pre_uninstall.php": "lifecycle",
    "scripts/post_uninstall.php": "lifecycle",
    # ── G380 / G381: the owner's per-item consent is 🔒 1705b ("Lead Source and
    # Lead Type ... required pickers in Sugar loaded from ADM's own code
    # lists"; "Project ID becomes a picker of ADM projects on the quote,
    # PRE-FILLED from a product-group -> project default list"), build
    # authorised by 🔒 1704b, cut to ADM's own by 🔒 1724b. Every one is a rule
    # or value of ONE customer's ERP company (ADM) that ERP-Epicor must not
    # carry (gate G2), and each is gated on the quote's company.
    # G460 adds two fields to these same files (no new file): the coordinator's
    # decided design per the owner pattern (GAPS G460, 2026-09-25 00:48Z): ADM
    # refuses every quote and order without a Marketing Campaign / Event.
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php":
        "G380/G381: bd_lead_source, bd_lead_type, bd_project_id; G460: bd_marketing_campaign, "
        "bd_marketing_event - on Quote (erp_layout-marked)",
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_adm_required_fields.php":
        "their five labels, in ADM's own words",
    "custom/Extension/modules/Quotes/Ext/LogicHooks/bd_adm_quote_defaults.php":
        "G380/G381: before_save fills an EMPTY Reference / Project on an unsent ADM quote",
    "custom/Extension/application/Ext/Language/en_us.bd_adm_lists.php":
        "the five lookup-type labels + the one tenant list (group->project)",
    "custom/modules/Quotes/BdAdmRules.php":
        "the ADM rules themselves: which companies are ADM, the two defaults, options",
    "custom/modules/Quotes/BdAdmLookupOptions.php":
        "the pickers' option functions (vardef 'function' needs a plain function)",
}

ONEOFF ="the one-off deletes or blanks it (🔒 1521); re-run it after installing rc69"
INERT = ("nothing shipped calls it any more; the copy an upgraded tenant keeps is "
         "unreachable (the one-off does not blank it yet - flagged for its owner)")
PF_OWNS = ("Partial Fulfillment ships the SAME path (>= 1.0.41 with the G282 preserve "
           "gate); reinstall PF after rc69 so PF's body is the one on disk (G282)")


#: Every path rc68 shipped that rc69 does not, and every path rc69 shipped that
#: rc70 does not, with the route off a tenant.
REMOVED = {
    # rc70 (🔒 1724b): ERP-Core's ErpLayoutExtraFields places the fields now.
    # Module Loader never deletes a file a later build stops shipping (§CW /
    # G37), so an upgraded tenant KEEPS this file - inert: nothing shipped
    # requires it (test_nothing_shipped_still_names_a_removed_class). The
    # one-off must not blank it while a tenant may still run rc69, whose
    # install and uninstall call it.
    "custom/modules/Accounts/BdAccountsLayoutExtensions.php": INERT,
    "custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php": PF_OWNS,
    "custom/modules/Quotes/BdQuotesLayoutExtensions.php": INERT,
    "custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php": INERT,
    "custom/modules/Quotes/BdKineticOpportunityHook.php": ONEOFF,
    "custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php": ONEOFF,
    "custom/Extension/application/Ext/Language/_override_en_us.bd_country_lookup.php": ONEOFF,
    "custom/Extension/application/Ext/Language/en_us.bd_country_lookup.php": ONEOFF,
    "custom/Extension/application/Ext/Language/en_us.bd_erp_stage_list.php": ONEOFF,
    "custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php": ONEOFF,
    "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_action_buttons.php": ONEOFF,
    "custom/Extension/modules/Accounts/Ext/LogicHooks/bd_account_country_guard.php": ONEOFF,
    "custom/Extension/modules/Contacts/Ext/LogicHooks/bd_contact_sync.php": ONEOFF,
    "custom/Extension/modules/Contacts/Ext/Vardefs/bd_contact_sync_fields.php": ONEOFF,
    "custom/Extension/modules/ERP_OrderLines/Ext/clients/base/views/record/bd_shipped_value.php": ONEOFF,
    "custom/Extension/modules/ERP_OrderLines/Ext/Language/en_us.bd_shipped_value.php": ONEOFF,
    "custom/Extension/modules/ERP_OrderLines/Ext/Vardefs/bd_shipped_value.php": ONEOFF,
    "custom/Extension/modules/ERP_Orders/Ext/clients/base/views/record/bd_shipped_value_total.php": ONEOFF,
    "custom/Extension/modules/ERP_Orders/Ext/Language/en_us.bd_shipped_value_total.php": ONEOFF,
    "custom/Extension/modules/ERP_Orders/Ext/Vardefs/bd_shipped_value_total.php": ONEOFF,
    "custom/Extension/modules/Opportunities/Ext/Language/en_us.bd_governing_origin.php": ONEOFF,
    "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_forecast_provenance.php": ONEOFF,
    "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Language/en_us.bd_line_order.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Vardefs/bd_deleted_erp_sync_key.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Vardefs/bd_governing_line_fields.php": ONEOFF,
    "custom/Extension/modules/Products/Ext/Vardefs/bd_line_order_fields.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_action_buttons.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Language/en_us.bd_erp_fields.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_comment_pending.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_comment_requested_at.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_comment_text.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_deleted_erp_sync_key.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_erp_kpi_inputs.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_erp_stage_code.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_estimating_turnaround.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_governing_line.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_priced_at.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_print_link.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_print_requested_at.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_print_status.php": ONEOFF,
    "custom/Extension/modules/Quotes/Ext/Vardefs/bd_quantity_breaks.php": ONEOFF,
    "custom/Extension/modules/RevenueLineItems/Ext/Vardefs/bd_deliverable_key.php": ONEOFF,
}

REMOVED_CLASSES = (
    "ErpQuoteOpportunityContribution", "BdQuotesLayoutExtensions",
    "BdOpportunitiesLayoutExtensions", "BdKineticOpportunityHook", "BdBenchDogsActionsApi",
    # rc70 (🔒 1724b), and the unreleased G380 branch's own layout writer.
    "BdAccountsLayoutExtensions", "BdAdmQuoteFieldsLayout",
)


def shipped_tree() -> set[str]:
    return {
        str(p.relative_to(PKG))
        for top in ("custom", "scripts")
        for p in (PKG / top).rglob("*") if p.is_file()
    }


def code_only(path) -> str:
    """PHP with comments stripped, so a retirement NOTE never reads as a caller."""
    body = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8"), flags=re.S)
    return re.sub(r"(?m)(^|\s)(//|#)[^\n]*", r"\1", body)


class TheKeptListIsTheWholePackage(unittest.TestCase):
    def test_the_source_tree_is_exactly_the_kept_list(self):
        self.assertEqual(sorted(shipped_tree()), sorted(KEPT),
                         "the package grew or shrank; under 🔒 1520 an addition needs "
                         "the owner's per-item consent - update KEPT only with it")

    def test_the_built_zip_is_exactly_the_kept_list(self):
        self.assertEqual(sorted(zip_names()), sorted(set(KEPT) | {"manifest.php"}))

    def test_the_manifest_copies_exactly_the_kept_custom_files(self):
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
        copied = set(re.findall(r"'to'\s*=>\s*'([^']+)'", manifest))
        self.assertEqual(copied, {k for k in KEPT if k.startswith("custom/")})

    def test_the_kept_and_removed_lists_do_not_overlap(self):
        self.assertEqual(set(KEPT) & set(REMOVED), set())


class EveryRemovedPathLeavesTheTenant(unittest.TestCase):
    def test_none_of_them_ships(self):
        names = zip_names()
        for rel in REMOVED:
            with self.subTest(path=rel):
                self.assertFalse((PKG / rel).exists(), f"{rel} is back in the source")
                self.assertNotIn(rel, names, f"{rel} is back in the built zip")

    def test_every_removed_path_has_a_route_off_the_tenant(self):
        """A dropped path is NOT a retirement by itself: Module Loader never
        deletes a file a later build stops shipping. So each one has to be
        reachable by something that does remove it, or be provably inert."""
        worklist = oneoff_worklist()
        for rel, route in REMOVED.items():
            with self.subTest(path=rel):
                if route is ONEOFF:
                    self.assertIn(rel, worklist, "the one-off does not remove it")
                elif route is PF_OWNS:
                    self.assertNotIn(rel, worklist,
                                     "the one-off must never blank a provider path (G280)")
                else:
                    self.assertIs(route, INERT)
                    self.assertNotIn(rel, worklist)

    def test_nothing_shipped_still_names_a_removed_class(self):
        """An inert file stays inert only while nothing reaches for it. A
        require_once of a file this build no longer ships is a compile fatal no
        catch can see."""
        offenders = []
        for rel in KEPT:
            code = code_only(PKG / rel)
            for name in REMOVED_CLASSES:
                if name in code:
                    offenders.append(f"{rel}: {name}")
        self.assertEqual(offenders, [])


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheApiStubRegistersNothing(unittest.TestCase):
    PROBE = r"""
namespace Sugarcrm\Sugarcrm\Util\Files { class FileLoader { public static function validateFilePath($p) { return $p; } } }
namespace {
    class SugarApi {}
    @mkdir('custom/clients/base/api', 0777, true);
    // ERP-Epicor present: the one condition under which rc68 DID define the class.
    file_put_contents('custom/clients/base/api/BaseErpActionsApi.php', '<?php class BaseErpActionsApi extends SugarApi {}');
    $before = get_declared_classes();
    require_once getenv('BD_API_FILE');
    echo json_encode(['new_classes' => array_values(array_diff(get_declared_classes(), $before)),
                      'defined' => class_exists('BdBenchDogsActionsApi', false)]);
}
"""

    def test_the_api_stub_registers_no_route(self):
        """EXECUTED the way ServiceDictionary::buildAllDictionaries() loads it:
        require_once, then class_exists(<file name>). No class means no route."""
        api = PKG / "custom/clients/base/api/BdBenchDogsActionsApi.php"
        with tempfile.TemporaryDirectory(prefix="g280-api-") as tmp:
            out = subprocess.run(["php", "-r", self.PROBE], cwd=tmp, capture_output=True,
                                 text=True, env={**os.environ, "BD_API_FILE": str(api)})
        self.assertEqual(out.returncode, 0, out.stderr + out.stdout)
        observed = json.loads(out.stdout)
        self.assertFalse(observed["defined"], "the api stub defines the class again")
        self.assertEqual(observed["new_classes"], [], observed)

    def test_the_api_stub_carries_no_code_at_all(self):
        tokens = subprocess.run(
            ["php", "-r", "foreach (token_get_all(file_get_contents($argv[1])) as $t) {"
             " if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE,"
             " T_OPEN_TAG], true)) continue; echo is_array($t) ? $t[1] : $t; }",
             str(PKG / "custom/clients/base/api/BdBenchDogsActionsApi.php")],
            capture_output=True, text=True, check=True).stdout
        self.assertEqual(tokens, "")


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheKeptFieldsAreExactlyTheTwo(unittest.TestCase):
    PROBE = ("$dictionary = []; $mod_strings = []; include $argv[1]; include $argv[2];"
             " echo json_encode(['fields' => array_keys($dictionary['Account']['fields'] ?? []),"
             " 'labels' => $mod_strings]);")

    def test_the_kept_fields_are_exactly_the_two(self):
        out = subprocess.run(
            ["php", "-r", self.PROBE,
             str(PKG / "custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php"),
             str(PKG / "custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php")],
            capture_output=True, text=True, check=True)
        observed = json.loads(out.stdout)
        self.assertEqual(sorted(observed["fields"]), ["bd_customer_group", "bd_customer_group_code"])
        # G532: short enough to read in full at the record view's label width
        # (about 12 characters show before the ellipsis), and not the same text.
        self.assertEqual(observed["labels"], {"LBL_BD_CUSTOMER_GROUP_CODE": "Group Code",
                                              "LBL_BD_CUSTOMER_GROUP": "Cust. Group"})
        for text in observed["labels"].values():
            self.assertLessEqual(len(text), 11, text)


class TheLifecycleDoesOnlyTheKeptWork(unittest.TestCase):
    def test_post_install_runs_exactly_the_kept_steps(self):
        code = code_only(PKG / "scripts/post_execute.php")
        steps = sorted(set(re.findall(r"\$bdStepReport\['([a-z_]+)'\]\s*=\s*'ok'", code)))
        self.assertEqual(steps, ["accounts_erp_layout", "quotes_erp_layout", "repair_rebuild"])
        self.assertEqual(code.count("ErpLayoutExtraFields::sync("), 2)
        self.assertLess(code.index("rebuildExtensions"), code.index("ErpLayoutExtraFields::sync("),
                        "sync() must read the vardefs AFTER the rebuild merged this package's")
        for gone in ("partial_order_sales_stage", "uninstall_languages", "zz_bd_stage_doms",
                     "saveSetting('erp_integration'", "rebuild_tabledictionary",
                     "return_app_list_strings_language"):
            self.assertNotIn(gone, code, f"post_install still does '{gone}'")
        self.assertIn("array('Accounts', 'Quotes')", code,
                      "the rebuild is not narrowed to the two modules this package extends")

    def test_pre_uninstall_undoes_only_the_kept_placements(self):
        """rc70 (🔒 1724b): nothing before the files go. The marked fields are
        taken off both views by post_uninstall's ErpLayoutExtraFields::sync(),
        once their vardefs are gone - leaving them would be the
        orphan-on-a-view the G280 grade looks for."""
        pre = code_only(PKG / "scripts/bd_pre_uninstall.php")
        self.assertEqual(re.findall(r"\b([A-Z][A-Za-z]+)::", pre), [])
        post = code_only(PKG / "scripts/post_uninstall.php")
        self.assertIn("ErpLayoutExtraFields::sync($bdModule)", post)
        self.assertIn("foreach (array('Accounts', 'Quotes') as $bdModule)", post)
        self.assertLess(post.index("rebuildExtensions"), post.index("ErpLayoutExtraFields::sync"),
                        "sync() must read the vardefs AFTER the rebuild dropped this package's")

    def test_post_uninstall_rebuilds_only_the_extended_modules(self):
        code = code_only(PKG / "scripts/post_uninstall.php")
        self.assertRegex(code, r"\$bdModules\s*=\s*array\(\s*'Accounts',\s*'Quotes',\s*\);")
        self.assertIn("MetaDataManager::refreshCache()", code)

    def test_partial_fulfillment_is_new_enough_to_own_what_this_stopped_doing(self):
        """PF >= 1.0.41: OpportunityContribution.php with the all-alternative
        preserve gate (G282). PF >= 1.0.43: the reader-side default
        'Partial Production Ordered' this package's post_install used to write
        (G305). A lower floor lets rc69 install over a PF that does neither."""
        with zipfile.ZipFile(built_zip()) as zipped:
            manifest = zipped.read("manifest.php").decode()
        pf = re.search(r"'id_name'\s*=>\s*'sugarai_erp_epicor_partialfulfillment',\s*"
                       r"'version'\s*=>\s*'([0-9.]+)'", manifest)
        self.assertIsNotNone(pf, "the Partial Fulfillment dependency is gone")
        # 1.0.50 since rc70: the PF release that ships with ERP-Epicor 1.1.125
        # (coordinator, 2026-09-24).
        self.assertEqual(pf.group(1), "1.0.50")
        epicor = re.search(r"'id_name'\s*=>\s*'sugarai_erp_epicor',\s*'version'\s*=>\s*'([0-9.]+)'",
                           manifest)
        self.assertIsNotNone(epicor)
        # 1.1.125: the release carrying G380 (d)-(g) - erp_reference, the
        # part-number switch, ErpLayoutExtraFields, ErpQuoteFacts (🔒 1724b).
        # 1.1.131 since rc73: the release carrying G530's limit on the field
        # (erp_reference.erp_max_length, ErpQuoteFacts::referenceMaxLength()),
        # which the Reference default is shortened to.
        self.assertEqual(epicor.group(1), "1.1.131")


if __name__ == "__main__":
    unittest.main()
