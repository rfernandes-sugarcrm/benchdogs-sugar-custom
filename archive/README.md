# archive/

Packages that must NOT sit where the build and lint tooling looks for shippable
packages (`scripts/check_built_packages.py` and `scripts/mlp_lint.py` walk
`sugar-sell/`, `sugar-predict/`, `sugar-market/`, `sugar-discover/` only).

| Directory | Why it is here |
|---|---|
| `ONEOFF-DropBdQuoteMirrorTables/` | **Deletes data:** drops the retired `bd01_*` quote-mirror tables. It ran where it was needed; a loaded data-deleting one-off does not belong beside the packages a person builds and installs (footprint item S11, 🔒 1724b). Kept for the record; rebuild it only on purpose. |
