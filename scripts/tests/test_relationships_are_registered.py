#!/usr/bin/env python3
"""Every custom/metadata/*MetaData.php this package ships is REGISTERED in the manifest.

Copying a relationship file into custom/metadata is not enough: Sugar builds its
relationship cache from the TableDictionary entry the installer writes for each
manifest `relationships` entry (`meta_data`). rc129 to rc131 only copied the files,
so the relate fields loaded in /metadata but never saved, and
POST /<Module>/<id>/link/<link> answered 404 "Could not find a relationship named".
ERP-Epicor registers its own the same way (pack.php, `relationships` => meta_data).

Checked on the built zip, as the neighbouring guards are: the manifest's `meta_data`
paths must equal the zip's custom/metadata/*MetaData.php files, and each file must
declare a relationship named after its file.
"""
import re
import sys
import unittest
import zipfile
from pathlib import Path

PACKAGE = Path(__file__).resolve().parents[2] / "sugar-sell" / "BenchDogs-Ext"


class RelationshipsAreRegistered(unittest.TestCase):
    def test_every_metadata_file_is_a_manifest_relationship(self):
        version = (PACKAGE / "version").read_text().strip()
        zip_path = PACKAGE / "releases" / f"sugarai_benchdogs_ext-{version}.zip"
        with zipfile.ZipFile(zip_path) as archive:
            shipped = {n for n in archive.namelist()
                       if n.startswith("custom/metadata/") and n.endswith("MetaData.php")}
            manifest = archive.read("manifest.php").decode()
        self.assertTrue(shipped, "no relationship file in the zip: the check is vacuous")
        registered = set(re.findall(r"'meta_data' => '<basepath>/([^']+)'", manifest))
        self.assertEqual(shipped, registered,
                         "a relationship file is shipped but not registered (or the reverse)")

    def test_each_file_declares_the_relationship_its_name_says(self):
        for f in sorted((PACKAGE / "custom" / "metadata").glob("*MetaData.php")):
            name = f.name[: -len("MetaData.php")]
            self.assertIn(f"$dictionary[\"{name}\"]", f.read_text(), f"{f.name} declares another name")


    def test_every_relationship_has_a_link_on_both_modules_named_by_its_join_keys(self):
        """The naming rule: ERP-Core's erp_quotes_fob declares a link on BOTH modules, and every id name is the join key.

        A relationship with a link on one module only registers (the link POST answers 200) but the relate reads back
        empty and its id does not save. Each link's id_name must be one of the relationship's join_key_lhs/rhs, and the
        relate and id fields on the holding module must use that same name.
        """
        ext = PACKAGE / "custom" / "Extension" / "modules"
        text = "\n".join(p.read_text() for p in ext.glob("*/Ext/Vardefs/*.php"))
        for f in sorted((PACKAGE / "custom" / "metadata").glob("*MetaData.php")):
            name = f.name[: -len("MetaData.php")]
            meta = f.read_text()
            keys = set(re.findall(r"'join_key_(?:lhs|rhs)' => '([^']+)'", meta))
            if not keys:  # a one-to-many keeps its key on the rhs table; its id field is named by the rhs_key
                keys = set(re.findall(r"'rhs_key' => '([^']+)'", meta)) - {"id"}
            links = re.findall(r"\['fields'\]\['" + name + r"'\] = array\((.*?)\n\);", text, re.S)
            sides = {m for body in links for m in re.findall(r"'side' => '(\w+)'", body)}
            self.assertGreaterEqual(len(links), 2, f"{name}: needs a link vardef on both modules, found {len(links)}")
            self.assertEqual(sides, {"left", "right"}, f"{name}: one link must be side left, one side right")
            for body in links:
                (id_name,) = re.findall(r"'id_name' => '([^']+)'", body)
                self.assertIn(id_name, keys, f"{name}: link id_name {id_name} is not a join/rhs key of its metadata")


if __name__ == "__main__":
    sys.exit(unittest.main())
