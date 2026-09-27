# archive/

Packages that must NOT sit where the build and lint tooling looks for shippable
packages (`scripts/check_built_packages.py` and `scripts/mlp_lint.py` walk
`sugar-sell/`, `sugar-predict/`, `sugar-market/`, `sugar-discover/` only).

| Directory | Why it is here |
|---|---|
| `ONEOFF-DropBdQuoteMirrorTables/` | **Deletes data:** drops the retired `bd01_*` quote-mirror tables. It ran where it was needed; a loaded data-deleting one-off does not belong beside the packages a person builds and installs (footprint item S11, 🔒 1724b). Kept for the record; rebuild it only on purpose. |
| `ONEOFF-RetireBdActionsApi/` | **Spent, et-only:** deleted the orphaned `BdBenchDogsActionsApi.php` an rc62 uninstall had restored on et; installed there 2026-09-24 (G437 closed, 🔒 1753b) and a deliberate no-op everywhere else. Kept for the record (G675). |
| `ONEOFF-RetireBdQuoteMirror/` | **Spent:** retired the `bd01_*` quote-mirror files ahead of `ONEOFF-DropBdQuoteMirrorTables` (which must run after it and is archived above). Owner-installed (🔒 915/916); on both QA tenants every mirror file was already gone, a guaranteed no-op. Kept for the record (G675). |
