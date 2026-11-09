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


if __name__ == "__main__":
    sys.exit(unittest.main())
