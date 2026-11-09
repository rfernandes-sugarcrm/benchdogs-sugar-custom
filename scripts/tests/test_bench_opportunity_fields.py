#!/usr/bin/env python3
"""The two fields that let the Bench connector extension give an Epicor order
with no Sugar quote (and an Epicor-born quote) exactly one Opportunity.

The owner approved exactly these two on 2026-10-14, for this package only:

1. ``Opportunities.erp_sync_key``: the key the connector upserts and links an
   Opportunity by. The connector's Sugar transport names this field on every
   upsert and every link, and no other package defines it on Opportunities.
2. An Opportunity relate on ``ERP_Orders`` (``bd_opportunity_id`` with its
   name and link), so an order sits under its Opportunity. It is a real column
   on ``erp_orders``, one Opportunity to many orders, with no join table.

What each case would SEE if the package were broken is in its docstring. The
column itself is created by Module Loader's own database repair at install
(RepairAndClear with every module and execute on), which adds any vardef field
that is not ``source => non-db``; that is why the id field must stay a real
field and must carry no default (a default would be written into every
existing order by that repair).
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest

from bd_retirement import PKG

OPPORTUNITY_VARDEFS = PKG / "custom/Extension/modules/Opportunities/Ext/Vardefs/bd_erp_sync_key.php"
OPPORTUNITY_LANGUAGE = PKG / "custom/Extension/modules/Opportunities/Ext/Language/en_us.bd_erp_sync_key.php"
ORDER_VARDEFS = PKG / "custom/Extension/modules/ERP_Orders/Ext/Vardefs/bd_opportunity.php"
ORDER_LANGUAGE = PKG / "custom/Extension/modules/ERP_Orders/Ext/Language/en_us.bd_opportunity.php"
RELATIONSHIP = "bd_opportunity_erp_orders"

#: Reads one vardef file and one language file the way Sugar merges them.
PROBE = (
    "$dictionary = []; $mod_strings = []; include $argv[1]; include $argv[2];"
    " echo json_encode(['dictionary' => $dictionary, 'labels' => $mod_strings]);"
)


def merged(vardefs, language) -> dict:
    out = subprocess.run(
        ["php", "-r", PROBE, str(vardefs), str(language)],
        capture_output=True, text=True, check=True,
    )
    return json.loads(out.stdout)


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheOpportunityKey(unittest.TestCase):
    def setUp(self):
        self.read = merged(OPPORTUNITY_VARDEFS, OPPORTUNITY_LANGUAGE)
        self.opportunity = self.read["dictionary"]["Opportunity"]

    def test_opportunities_gain_exactly_the_key_and_the_orders_link(self):
        """Broken looks like a third field on a customer's Opportunities, or the
        key missing (every Opportunity upsert answers 422)."""
        self.assertEqual(sorted(self.opportunity["fields"]), ["bd_erp_orders", "erp_sync_key"])

    def test_the_key_is_the_shape_the_connector_upserts_by(self):
        """A plain varchar the integrate API can match on, never required (the
        connector's own schema check would then refuse every seller's
        Opportunity), with no default, and not copied when a seller copies an
        Opportunity (the copy would collide on the unique index)."""
        key = self.opportunity["fields"]["erp_sync_key"]
        self.assertEqual(key["type"], "varchar")
        self.assertIs(key["is_sync_key"], True)
        self.assertEqual(key["max_size"], 255)
        self.assertIs(key["required"], False)
        self.assertEqual(key["duplicate_on_record_copy"], "no")
        self.assertNotIn("default", key)
        self.assertNotIn("source", key)
        self.assertNotIn("erp_layout", key)

    def test_the_key_is_unique(self):
        """Broken looks like two Opportunities for one order after a retried
        create: only the unique index makes the second upsert land on the first."""
        self.assertIn(
            {"name": "idx_opportunities_erp_sync_key", "type": "unique", "fields": ["erp_sync_key"]},
            self.opportunity["indices"],
        )

    def test_the_orders_link_is_the_relationship_s_many_side(self):
        link = self.opportunity["fields"]["bd_erp_orders"]
        self.assertEqual(link["type"], "link")
        self.assertEqual(link["relationship"], RELATIONSHIP)
        self.assertEqual(link["module"], "ERP_Orders")
        self.assertEqual(link["source"], "non-db")

    def test_every_label_it_names_exists(self):
        for field in self.opportunity["fields"].values():
            with self.subTest(field=field["name"]):
                self.assertIn(field["vname"], self.read["labels"])


@unittest.skipUnless(shutil.which("php"), "requires php")
class TheOrderSitsUnderItsOpportunity(unittest.TestCase):
    def setUp(self):
        self.read = merged(ORDER_VARDEFS, ORDER_LANGUAGE)
        self.order = self.read["dictionary"]["ERP_Order"]

    def test_orders_gain_exactly_the_relate_its_id_and_its_link(self):
        self.assertEqual(
            sorted(self.order["fields"]),
            ["bd_opportunity", "bd_opportunity_id", "bd_opportunity_name"],
        )

    def test_the_id_is_a_real_column(self):
        """Broken looks like ``source => non-db``: the install's repair would
        create no column, Sugar would keep the link nowhere, and the order would
        never sit under its Opportunity. A default would be written into every
        existing order by that same repair."""
        rel_id = self.order["fields"]["bd_opportunity_id"]
        self.assertNotIn("source", rel_id)
        self.assertEqual(rel_id["dbType"], "id")
        self.assertEqual(rel_id["module"], "Opportunities")
        self.assertNotIn("default", rel_id)
        self.assertIn(
            {"name": "idx_erp_orders_bd_opportunity_id", "type": "index",
             "fields": ["bd_opportunity_id"]},
            self.order["indices"],
        )

    def test_the_relationship_is_one_opportunity_to_many_orders_on_that_column(self):
        """Broken looks like a join table nothing creates, or the column on the
        wrong side."""
        self.assertEqual(
            self.order["relationships"][RELATIONSHIP],
            {
                "lhs_module": "Opportunities",
                "lhs_table": "opportunities",
                "lhs_key": "id",
                "rhs_module": "ERP_Orders",
                "rhs_table": "erp_orders",
                "rhs_key": "bd_opportunity_id",
                "relationship_type": "one-to-many",
            },
        )
        link = self.order["fields"]["bd_opportunity"]
        self.assertEqual((link["relationship"], link["link_type"], link["side"]),
                         (RELATIONSHIP, "one", "right"))
        name = self.order["fields"]["bd_opportunity_name"]
        self.assertEqual((name["type"], name["source"], name["id_name"], name["link"]),
                         ("relate", "non-db", "bd_opportunity_id", "bd_opportunity"))

    def test_a_seller_cannot_edit_it_and_it_is_on_no_layout(self):
        """The connector alone sets it (ERP_Orders refuses a non-connector edit
        of a readonly field). No ``erp_layout`` marker, so ERP-Core's layout
        step never places it, and no shipped view names it."""
        for name in ("bd_opportunity_id", "bd_opportunity_name"):
            field = self.order["fields"][name]
            with self.subTest(field=name):
                self.assertIs(field["readonly"], True)
                self.assertIs(field.get("required", False), False)
                self.assertNotIn("erp_layout", field)
        for view in (PKG / "custom").rglob("*.php"):
            if "/clients/" not in view.as_posix():
                continue
            text = view.read_text(encoding="utf-8")
            with self.subTest(view=view.name):
                self.assertNotRegex(text, r"bd_opportunity|bd_erp_orders")

    def test_every_label_it_names_exists(self):
        for field in self.order["fields"].values():
            with self.subTest(field=field["name"]):
                self.assertIn(field["vname"], self.read["labels"])


class TheTwoSidesNameOneRelationship(unittest.TestCase):
    def test_both_links_name_the_relationship_the_order_side_declares(self):
        """Broken looks like a link Sugar cannot resolve: the connector's link
        call then fails on every Opportunity it creates."""
        texts = [p.read_text(encoding="utf-8") for p in (OPPORTUNITY_VARDEFS, ORDER_VARDEFS)]
        for text in texts:
            self.assertRegex(text, rf"'relationship'\s*=>\s*'{RELATIONSHIP}'")
        self.assertEqual(len(re.findall(rf"\['relationships'\]\['{RELATIONSHIP}'\]", texts[1])), 1)
        self.assertNotIn("['relationships']", texts[0])


if __name__ == "__main__":
    unittest.main()
