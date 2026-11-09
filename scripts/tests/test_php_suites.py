"""The PHP test suites RUN IN CI. Until now, none of them did.

🛑 WHAT THIS FILE FIXES. `.github/workflows/mlp-lint.yml` runs
`python3 -m pytest scripts/tests -q` and nothing else. pytest does not collect
`*.php`, so `scripts/tests/bench_panel_retired_test.php` — 18 checks, written
after the owner found a raw `LBL_RECORDVIEW_PANEL_BENCHDOGS` on a live quote —
has never executed on a single pull request. It is the workflow's own standard,
quoted from the step above the collected-count floor:

    A test that never runs is documentation, not a guard.

and the twin defect it names (652 of 892 collected in erp-integration-sugar)
was the same failure wearing a different hat: a runner that silently declines
to run something looks exactly like a passing suite.

WHY A WRAPPER RATHER THAN A SECOND CI STEP. A step in the workflow would run
them on CI only. Wrapping them in pytest means `python3 -m pytest scripts/tests`
— the command every one of these tests documents itself with, and the one
anybody runs locally — covers the PHP suites too, and each one lands in the
collected count that CI floors.

The PHP suites print `N checks, M failed` and exit non-zero on a failure. Both
are asserted: an exit code alone cannot see a suite that stopped running checks,
which is the same hole in miniature.
"""

import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

import shared_sugar
import smarty_pdf


ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent

# Every PHP suite, named. The set is pinned rather than only globbed so that a
# new suite nobody wired in is a FAILURE here rather than silence — see
# test_every_php_suite_in_this_directory_is_named_above.
SUITES = (
    "bench_panel_retired_test.php",
    "bd_adm_rules_test.php",
    "bd_erp_layout_test.php",
    "bd_customer_group_move_test.php",
    "bd_contact_fields_test.php",
    "bd_seller_discounts_hidden_test.php",
    "bd_order_requirements_test.php",
    "bd_shipping_provider_hidden_test.php",
    "bd_lead_fields_read_only_test.php",
    "bd_new_product_part_number_test.php",
    "bd_default_owner_test.php",
    "bd_kinetic_fields_test.php",
    "bd_cost_capacity_section_test.php",
    "bd_button_quote_project_test.php",
    "bd_order_line_total_test.php",
    "bd_quote_pdf_test.php",
    "bd_quote_pdf_render_test.php",
    "bd_quote_line_product_group_test.php",
    "bd_catalog_part_number_test.php",
    "bd_line_project_test.php",
    "bd_quote_line_product_column_test.php",
    "bd_expires_date_off_test.php",
    "bd_account_owner_test.php",
)

php = shutil.which("php")


def run(name: str, **env: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        [php, str(HERE / name)], cwd=ROOT, capture_output=True, text=True,
        env={**os.environ, **env},
    )


#: rc70 (G380, 🔒 1724b) runs against ERP-Epicor's REAL code (lane D, landed
#: a0f6b632): the sibling checkout's files when present, else the pins. The
#: package refuses to install below ERP-Epicor 1.2.4, so no run loads the global
#: ErpQuoteFacts that ERP-Epicor 1.1 shipped.
LANDED = {
    "BD_LAYOUT_FIELDS": str(shared_sugar.resolve("ErpLayoutExtraFields.php")),
    "BD_ERP_REFERENCE": str(shared_sugar.resolve("erp_reference.php")),
}

#: ERP-Epicor 1.2.0 (T2 of the Rafael review, erp-integration-sugar #158; the
#: build 280e0929): ErpQuoteFacts is Sugarcrm\Sugarcrm\custom\Erp\ErpQuoteFacts
#: in ERP-Epicor's custom/src, found only through an autoloader - ERP-Core's own
#: stand-in, the sibling checkout's when present, else the pinned mirror, whose
#: roots are that same checkout's packages (shared_sugar.py).
ERP_AUTOLOADER = str(shared_sugar.resolve("sugar_autoloader.php"))


#: The fewest checks each suite may report and still count as having run.
#: `0 checks, 0 failed` satisfied the old assertion — a suite that stopped
#: running checks looked exactly like a passing one (the hole G21 names, in
#: miniature). Measured 2026-09-26 at 3dbe4d4: 24 / 19 / 83 (+3 in the
#: no-ErpQuoteFacts run) / 21 / 29; floors sit ~15% under, so a deleted
#: check trips them and a re-count does not. Raise a floor when a suite
#: grows; lowering one is a decision to name in the pull request.
MIN_CHECKS = {
    # 🔒2173b (2026-09-30): the one-off's K-2 cases went with its code; 9 checks
    # left (was 24), all on this package. bench_governing_origin_retired_test.php
    # is gone: it ran only the one-off's K-3, and test_governing_marker.py holds
    # its package checks. Floors ~15% under, as above.
    # rc98: 1 check left (was 9): the file-absence checks went with the
    # tombstone cleanup, the package's kept list pins every file it ships. The
    # floor is that one check.
    "bench_panel_retired_test.php": 1,
    # rc78 (G809 + G804): 113 checks (+30: sections Q, R, S, M9, M10); floor
    # ~15% under, as above. T2 (2026-09-30): 121 checks in the global run
    # (+T0, T0b), 124 in the namespaced run (+T1-T5); floor ~15% under 121.
    # rc90: 138 in the namespaced run (+14: section V, the lead baseline); floor ~15% under.
    # rc108: 134 (-10: the product group -> project default and its list went, E, F, A9; +5: section W); the
    # floor stays 117, ~13% under. rc110: 139 (+5: section X); the floor stays 117, ~16% under.
    "bd_adm_rules_test.php": 117,
    "bd_erp_layout_test.php": 18,
    # 🔒2173b: the one-off ONEOFF-MoveBdCustomerGroup's cases (M1-M12, L2, L5)
    # went with its code; 10 checks left (was 29), all on this package.
    "bd_customer_group_move_test.php": 8,
    # rc82 (G458): 44 checks at 2026-09-30; floor ~15% under, as above. rc117: 48 (+4: section J, Role editable).
    "bd_contact_fields_test.php": 41,
    # rc85 (G848, 🔒2159b): 136 checks at 2026-09-30 (rc84: 109); floor ~15% under, as above.
    "bd_seller_discounts_hidden_test.php": 115,
    # rc89 (G860, 🔒2179b): 33 checks at 2026-10-01. rc93: 48 checks at 2026-10-13 (+15: the Sales Order route,
    # section E, A8 and B2's second half); floor ~15% under, as above.
    "bd_order_requirements_test.php": 41,
    # rc92: 13 and 53 checks at 2026-10-04; floors ~15% under, as above.
    "bd_shipping_provider_hidden_test.php": 11,
    "bd_lead_fields_read_only_test.php": 45,
    # rc94: 48 checks at 2026-10-13; floor ~15% under, as above.
    "bd_new_product_part_number_test.php": 41,
    # rc99: 90 checks at 2026-10-16 (the hook, who saves, the admin page's endpoint); floor ~15% under, as above.
    "bd_default_owner_test.php": 77,
    # rc100: 27 checks at 2026-10-17 (which fields an install creates, how the three record views draw them, the
    # Sales Category list); floor ~15% under, as above. rc111: 34 (the retired lines total: not created, relabelled,
    # no delete path); floor ~15% under. rc119: 40 (+5: the quote's Due date, created, not required, drawn editable in
    # the ERP panel, on the Bench UAT layout too); floor ~15% under. rc120: 52 (+12: the Due right after Expires Date
    # on the default and the Bench UAT layouts, a Studio-saved rc119 layout, an admin's own place, the fallbacks, the
    # create form drawing the record view's panels); floor ~15% under. rc121: 53 (+1: a Studio save under rc120;
    # the Due now after Valid Until, Expires Date no longer placed); floor ~15% under. rc122: 60 (+7: the quote's
    # Project Name, a text field created where missing, drawn editable on a row of its own after the Due, an admin's
    # own place kept, no filler cell taken); floor ~15% under.
    "bd_kinetic_fields_test.php": 51,
    # rc107: 26 checks at 2026-10-20 (where the Cost & capacity section sits, what it holds, its read-only fields,
    # rebuilds, ERP-Epicor re-applying its layout, a section stored elsewhere, the controls). rc108: 37 at 2026-10-21
    # (+11: section T, the line fields a Studio save stored inside the cost panel). rc109: 48 at 2026-10-21 (+11:
    # section U, the blank cells Studio stored beside them, as Studio 26.1 writes them); floor ~15% under.
    "bd_cost_capacity_section_test.php": 41,
    # rc110: 14 checks at 2026-10-21 (the button's quote and its Project, once; the controls); floor ~15% under.
    # rc117: 22 (+8: section N, Needs Price Estimate as the button's default); floor ~15% under. rc121: 28 (+6: section
    # O, the button's Opportunity and Quote the account owner's); floor ~15% under.
    "bd_button_quote_project_test.php": 24,
    # rc111: 28 checks at 2026-10-21 (the order's record view on an upgraded, a fresh and an older-ERP-Epicor tenant,
    # the retired lines total in a stored layout, the list view, unreadable fields, rebuilds); floor ~15% under.
    "bd_order_line_total_test.php": 24,
    # rc112: 89 checks at 2026-10-22 (the template's install and uninstall, the logo, the sample's blocks in order,
    # each value's field, what the renderer needs); floor ~15% under.
    "bd_quote_pdf_test.php": 76,
    # rc114: 35 checks at 2026-10-22 (the 8890-shaped quote, a copy, two ladders, formats, what prints under a
    # line, the ladder rule against ERP-Epicor's, the stored body); floor ~15% under. rc122: 42 (+5: section I, the
    # Project row printing the quote's Project Name); floor ~15% under.
    "bd_quote_pdf_render_test.php": 36,
    # rc116: 41 checks at 2026-10-25 (the grid's Product Group column, its rebuilds and an admin's own column, the
    # fail-open cases, the G848 overlay beside it, the two relabels, ERP-Epicor's fetch of the line's category);
    # floor ~15% under.
    "bd_quote_line_product_group_test.php": 35,
    # rc117: 21 checks at 2026-10-26 (the catalog list's and Search and Select list's Part Number column, rebuilds, an
    # admin's own column, no view, ERP-Core's quick-search fields); floor ~15% under.
    "bd_catalog_part_number_test.php": 18,
    # rc117: 47 checks at 2026-10-26 (the line field, its grid column and record view, the quote views without the
    # quote-level Project and fetching the lines' own, the UAT package's stored views, the install history rule, the
    # one-time copy: fills, keeps, idempotence, column, failures, paging); floor ~15% under. rc127: 57 (+10: section F,
    # a quotes table with no Project column yet: nothing to copy, and the column lists that are no answer); floor ~15%
    # under.
    "bd_line_project_test.php": 48,
    # rc120: 33 checks at 2026-10-29 (the recorded stock grid and picker this relies on, the Product column drawn
    # cell-xxlarge and nothing else changed, rebuilds, an admin's own grid, the fail-open cases, the directory's other
    # overlays compiled with it); floor ~15% under.
    "bd_quote_line_product_column_test.php": 28,
    # rc121: 29 checks at 2026-10-29 (Expires Date off the stored record, dashlet, preview and list views, the Due beside
    # Valid Until, rc119 and rc120 Studio saves, an admin's own place, the controls, the field kept); floor ~15% under.
    # rc122: 33 (+4: R13 to R16, the Project Name on its own row after Valid Until | Due); floor ~15% under.
    "bd_expires_date_off_test.php": 28,
    # rc121: 28 checks at 2026-10-29 (the two registrations, the rule's cases and controls, the two create layouts);
    # floor ~15% under. rc123: 34 (+6: section Q, a quote's top Account Name when no billing account is set, the
    # billing account deciding when both are, and the controls); floor ~15% under.
    "bd_account_owner_test.php": 29,
}
#: bd_adm_rules_test.php's second run has no ErpQuoteFacts and is meant to be
#: tiny: an older ERP-Epicor skips the defaults and never fails a save.
MIN_CHECKS_NO_QUOTE_FACTS = 3

_CHECKS = re.compile(r"(\d+) checks, (\d+) failed")


@unittest.skipUnless(php, "requires a PHP CLI")
class PhpSuitesTest(unittest.TestCase):
    def assert_suite_passes(self, name: str, **env: str) -> None:
        result = run(name, **env)
        report = result.stdout + result.stderr
        self.assertIn("checks, 0 failed", report, report)
        self.assertEqual(result.returncode, 0, report)
        self.assertEqual(result.stderr, "", result.stderr)
        counts = _CHECKS.findall(report)
        self.assertTrue(counts, f"{name} reported no 'N checks, M failed' line:\n{report}")
        checks = int(counts[-1][0])
        # With no 1.2.x ErpQuoteFacts the suite runs only its refusal checks.
        floor = MIN_CHECKS_NO_QUOTE_FACTS if env.get("BD_NO_QUOTE_FACTS") else MIN_CHECKS[name]
        self.assertGreaterEqual(
            checks, floor,
            f"{name} ran {checks} checks; the floor is {floor}. A suite that "
            f"stopped running checks is documentation, not a guard.")

    def test_bench_panel_retired(self):
        """Nothing the retired Bench Dogs Quotes panel carried ships again: its
        fields, its remover, the duplicate notifier. (The removal itself was the
        one-off's K-2, withdrawn with its code by 🔒2173b.)"""
        self.assert_suite_passes("bench_panel_retired_test.php")

    def test_bd_adm_rules(self):
        """G380/G381 (🔒 1724b): the Bench Dogs ADM rules (which companies are
        ADM, the Reference default (rc110: the Project only on a create that sends none) and its exit order, the
        pickers' options, the vardef markers) against ERP-Epicor's namespaced,
        autoloaded ErpQuoteFacts (T2, #158) - and, in a run with no
        ErpQuoteFacts at all, that an older ERP-Epicor skips the defaults and
        never fails a save."""
        self.assert_suite_passes("bd_adm_rules_test.php", BD_NO_QUOTE_FACTS="1")
        self.assert_suite_passes(
            "bd_adm_rules_test.php", BD_ERP_AUTOLOADER=ERP_AUTOLOADER,
            BD_ERP_REFERENCE=LANDED["BD_ERP_REFERENCE"])

    def test_bd_erp_layout(self):
        """rc70: the Bench fields are placed and retired by ERP-Core's REAL
        ErpLayoutExtraFields through rc70's REAL lifecycle scripts - upgrade from
        rc69, reinstall, ERP-Epicor reinstall, uninstall fresh and upgraded."""
        self.assert_suite_passes("bd_erp_layout_test.php", **LANDED)

    def test_bd_customer_group_move(self):
        """G507: rc69 put the customer-group pair in the Account HEADER on a
        view with no panel_body (reproduced with rc69's real writer). With
        rc72's real scripts and ERP-Core's real sync(): an upgrade does not move
        them (the one-off that did was withdrawn, 🔒2173b), a fresh install puts
        them on the first tab after Industry, labelled, and uninstall takes them
        off."""
        self.assert_suite_passes("bd_customer_group_move_test.php", **LANDED)

    def test_bd_contact_fields(self):
        """G458 (rc82): the five Epicor contact fields the customer's package
        creates are shown on the Contacts record view (ERP panel), the four
        Epicor-owned READ-ONLY and, since rc117, Role editable, by a sidecar
        overlay run the way MetaDataFiles includes it - twice per build - and
        a tenant WITHOUT that package gets its view back byte for byte."""
        self.assert_suite_passes("bd_contact_fields_test.php")

    def test_bd_seller_discounts_hidden(self):
        """G848 (rc84, 🔒2151b): no discount on a Bench quote - ERP-Epicor's
        Discount panel, the totals' Order Level Discount, the grid's Line
        Discount column and the line page's discount - by five sidecar
        overlays run the way MetaDataFiles includes them and compiled the way
        mergeExtensionFiles compiles them, over ERP-Epicor's OWN panel and
        totals definitions; the fetch list and every other entry untouched.

        ERP-Epicor's QuotesLayout is the PIN (1.2.0, 280e0929), not the sibling
        checkout: the rc this ships in is built against 1.2.0, and a sibling
        left on an older build branch would grade it against a layout no
        Bench tenant runs."""
        self.assert_suite_passes(
            "bd_seller_discounts_hidden_test.php",
            BD_QUOTES_LAYOUT=str(shared_sugar.pinned_path("QuotesLayout.php")),
            BD_BASE_ERP_LAYOUT=str(shared_sugar.pinned_path("BaseErpLayout.php")))


    def test_bd_order_requirements(self):
        """G860 (rc89, 🔒2179b): ADM's Ship Via requirement at ERP-Epicor 1.2.4's
        OrderRequirements slot - an order converting an ADM ERP quote with no
        Ship Via is named in the one pre-send refusal; since rc93 so is a Sales
        Order whose quote AND billing account have no Ship Via (Kinetic takes
        the customer's there), and one whose customer has a default is not;
        another company and a failing read are not judged; and a half-installed
        package answers nothing rather than refusing every order. Against
        ERP-Epicor's real ErpQuoteFacts, found by ERP-Core's autoloader."""
        self.assert_suite_passes("bd_order_requirements_test.php", BD_ERP_AUTOLOADER=ERP_AUTOLOADER)

    def test_bd_shipping_provider_hidden(self):
        """rc92: Shipping Provider (shipper_name) is off the quote record, create and edit views and the list
        preview on Bench; every other field stays where it was, and the discount overlay still works beside it."""
        self.assert_suite_passes("bd_shipping_provider_hidden_test.php")

    def test_bd_lead_fields_read_only(self):
        """rc92: Lead Source, Lead Type, Mktg Campaign and Marketing Event are read-only once the quote is ordered
        or closed, editable while it is being quoted; Project is unchanged."""
        self.assert_suite_passes("bd_lead_fields_read_only_test.php")

    def test_bd_new_product_part_number(self):
        """rc94: a new product that is not in the catalog takes the Part Number the seller types once its Product
        Group is picked and until the ERP holds the line; a catalog line, a blank row and a line the ERP holds keep
        ERP-Core's read-only Part Number. Against ERP-Core's REAL rule, merged in Sugar's order."""
        self.assert_suite_passes(
            "bd_new_product_part_number_test.php",
            BD_ERP_PART_NUMBER_RULE=str(shared_sugar.resolve("erp_part_number_read_only.php")))

    def test_bd_default_owner(self):
        """rc99: an Opportunity or ERP order the integration saves with no owner (one created through the REST API
        with no owner in its body included) takes the default owner for ERP-created records; a seller's own save is
        never filled, so an owner a seller clears stays empty; an owner that is set is kept; an empty
        setting, a user who is not Active or is deleted, and a failed read leave the owner empty and the save goes on.
        The admin page's endpoint: administrators only; it stores an active user's id or empty, and refuses any
        other id with nothing stored."""
        self.assert_suite_passes("bd_default_owner_test.php")

    def test_bd_kinetic_fields(self):
        """rc100: Bench Dogs' Kinetic fields (23 since rc119) are created only where a tenant lacks them, with the
        existing Studio fields' types and sizes and the mapping's places; a field a tenant has is never created again,
        a module whose fields cannot be read gets none, and one failed create does not stop the next. The three record
        views draw the fields a tenant has, once: the line ones in a Cost & capacity panel, Kinetic's own read-only;
        the Sales Category list is defined only where a tenant has none of its own. rc111: the retired order lines
        total is never created, is relabelled 'Line Total (old)' only while it reads a label a release gave it, and an
        install has no way to delete it. rc119: the quote's Due date (a date labelled Due, never required) is created and
        drawn editable. rc120: drawn right after Expires Date wherever that is drawn (the Bench UAT layout's body), in
        the ERP panel above the status block only when none of the quote's top fields is drawn; the create form shows
        the record view's panels. rc122: the quote's Project Name (Kinetic's QuoteComment, a text field labelled
        Project Name, never required) is created where missing and drawn editable on a row of its own after the Due."""
        self.assert_suite_passes("bd_kinetic_fields_test.php")

    def test_bd_cost_capacity_section(self):
        """rc107: Bench Dogs' Cost & capacity section is drawn directly under ERP-Epicor's Epicor Cost Worksheet panel
        on the quoted line item's record view, holds none of that panel's eight values, draws read-only every field
        the seller does not send to Kinetic, and stays under the panel after ERP-Epicor re-applies its layout (an
        upgrade, a REPLACE install); rc108: Bench Dogs' line fields a Studio save stored inside that panel are drawn
        in the section, every other field staying where it is; rc109: the rows they leave holding only blank cells go,
        whatever form Studio stored a blank cell in, and a blank cell sharing no row with them stays. Run against ERP-Epicor 1.2.107's own ProductsLayout, the copies under
        fixtures/rc107/, never the sibling checkout: a sibling left on an older branch would grade it against a
        layout no Bench tenant runs."""
        self.assert_suite_passes("bd_cost_capacity_section_test.php")

    def test_bd_button_quote_project(self):
        """The quote ERP-Epicor's Create Opportunity & Quote button makes on an ADM account, through ERP-Epicor's real
        createOppQuote() and this package's shipped before_save registration: since rc117 it gets no quote-level
        Project (the Project is each line's own) and starts with Needs Price Estimate on once the install has set
        ERP-Epicor's own default; an administrator's later choice stands."""
        self.assert_suite_passes(
            "bd_button_quote_project_test.php", BD_ERP_AUTOLOADER=ERP_AUTOLOADER,
            BD_ACCOUNT_ACTIONS=str(shared_sugar.resolve("AccountsErpActionsApi.php")))

    def test_bd_expires_date_off(self):
        """rc121: Expires Date off every quote view this package overlays (record, record dashlet, list preview, list),
        Valid Until being the same date; the Due drawn beside Valid Until, also where a Studio save stored it under
        rc119 or rc120; the field and its data kept. rc122: the Project Name on a row of its own right after Valid
        Until | Due, on the Bench UAT layout and ERP-Epicor's own."""
        self.assert_suite_passes("bd_expires_date_off_test.php")

    def test_bd_account_owner(self):
        """rc121: a new Opportunity or Quote still owned by its creator, or by no one, takes its account's active
        owner on save, unless the create form says the person picked the owner; never on an update or the
        integration's own save. The create layouts carry the form's half. rc123: a Quote's account is its Billing
        Account, else its top Account Name (account_id)."""
        self.assert_suite_passes("bd_account_owner_test.php")

    def test_bd_order_line_total(self):
        """rc111: on the ERP order's record view, ERP-Epicor's own ERP Line Total is drawn right after its ERP Misc.
        Charges where the stored view lacks it (a tenant that had ERP-Epicor before 1.2.111 keeps such a view), its
        company-currency twin is not, and nothing is drawn for a field the tenant lacks; Bench Dogs' retired copy of
        the lines total (e_total_charges_c) is drawn on neither the record view nor the list view, wherever a stored
        layout holds it. Run against ERP-Epicor's own views, vardefs and layout installer, the copies under
        fixtures/rc111/ and fixtures/rc107/BaseErpLayout.php.txt."""
        self.assert_suite_passes("bd_order_line_total_test.php")

    def test_bd_catalog_part_number(self):
        """rc117: the Product Catalog list and its Search and Select list show each product's Kinetic part number
        (mft_part_num) read-only right after the name, once per build; an admin's own column is kept as set; and the
        part number is one of the catalog's quick-search fields (ERP-Core's real fragment, not a copy)."""
        self.assert_suite_passes(
            "bd_catalog_part_number_test.php",
            BD_PART_NUMBER_SEARCH=str(shared_sugar.resolve("erp_part_number.php")))

    def test_bd_line_project(self):
        """rc117: the Project is each quote line's own: the line field and where it is drawn, the quote's views without
        the quote-level Project, and the one-time copy of each quote's Project onto its blank lines, run only by the
        upgrade from below rc117 as Module Loader's history tells it. rc127: a quotes table with no Project column yet
        has nothing to copy, and the step reads ok."""
        self.assert_suite_passes("bd_line_project_test.php")

    def test_bd_quote_line_product_column(self):
        """rc120: the quote line grid draws its Product column at Sugar's widest list width (cell-xxlarge, 460px, over
        stock's cell-large, 180px), in the line's edit row too, with nothing else of the column or the grid changed,
        through rebuilds and beside the directory's other overlays; a grid it cannot read is served as read. Over stock
        Sugar 26.1.0's own grid viewdef and relate field, as recorded."""
        self.assert_suite_passes("bd_quote_line_product_column_test.php")

    def test_bd_quote_pdf(self):
        """rc112: the customer's quote layout as a PDF Manager template on Quotes. An install creates it or brings it to
        this release's body, published, and keeps an administrator's rename; an uninstall unpublishes it; with no logo
        file the body draws no image. The body prints the sample's blocks in order, each value from its stored field,
        and nothing the PDF security policy refuses. rc122: the Project row prints the quote's Project Name."""
        self.assert_suite_passes("bd_quote_pdf_test.php")

    def test_bd_quote_pdf_render(self):
        """rc114: the same template RENDERED by Sugar 26.1's own Smarty under the PDF security policy, over PDF
        Manager's data for a quote shaped like Epicor quote 8890: a break the seller did not choose is no line, each
        laddered line prints its own rungs (grouped by ERP-Epicor's ErpLineRole rule, checked against the real
        method), a line with one rung prints none, the long description prints under its line, prices print with no
        currency symbol and Expires as M/D/YYYY; rc122: the Project row prints the quote's Project Name, line breaks
        kept, and nothing for a quote with none. Smarty comes from smarty_pdf.py: CI downloads it, a developer's
        SugarEnt tree has it; CI never skips this."""
        libs = smarty_pdf.resolve()
        if libs is None:
            if os.environ.get("GITHUB_ACTIONS") == "true":
                self.fail("no Smarty libs in CI: the workflow must run scripts/tests/smarty_pdf.py fetch first")
            self.skipTest("no Smarty libs: set BD_SMARTY_LIBS, or run scripts/tests/smarty_pdf.py fetch <dir>")
        self.assert_suite_passes(
            "bd_quote_pdf_render_test.php", BD_SMARTY_LIBS=str(libs),
            BD_ERP_LINE_ROLE=str(shared_sugar.resolve("ErpLineRole.php")))

    def test_bd_quote_line_product_group(self):
        """rc116 (G1338): the quote line grid draws each line's Product Group (its stock category, which the sync fills
        from Epicor's product groups) once, read-only and as plain text, right after Part Number, through rebuilds and
        beside the G848 discount overlay; a column an admin already placed stays where it is; a grid it cannot read is
        served as read. Category reads "Product Group" on quoted line items and products, over any plain fragment with
        the same key. Against ERP-Epicor's own QuotesLayout (the pin), which fetches the line's category for the grid."""
        self.assert_suite_passes(
            "bd_quote_line_product_group_test.php",
            BD_QUOTES_LAYOUT=str(shared_sugar.pinned_path("QuotesLayout.php")),
            BD_BASE_ERP_LAYOUT=str(shared_sugar.pinned_path("BaseErpLayout.php")))


class PhpSuiteCoverageTest(unittest.TestCase):
    """This one needs no PHP: it is about what is WIRED, not what passes."""

    def test_every_named_suite_has_a_check_floor(self):
        self.assertEqual(sorted(MIN_CHECKS), sorted(SUITES),
                         "MIN_CHECKS and SUITES disagree; a suite without a "
                         "floor can go quiet")

    def test_every_php_suite_in_this_directory_is_named_above(self):
        found = sorted(p.name for p in HERE.glob("*_test.php"))
        self.assertEqual(found, sorted(SUITES),
                         "a PHP suite that nothing runs is documentation, not a guard")

    def test_each_named_suite_has_a_test_method_of_its_own(self):
        """One method per suite, so a failure names the suite rather than
        stopping the loop at the first one."""
        methods = [m for m in dir(PhpSuitesTest) if m.startswith("test_")]
        self.assertEqual(len(methods), len(SUITES), methods)


if __name__ == "__main__":
    unittest.main()
