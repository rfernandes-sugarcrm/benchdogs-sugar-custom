# rc100 fixture

A byte-for-byte copy of BenchDogs-Ext **0.9.45-rc100**'s Quote vardef, the build on the hosted Bench tenant when a
quote created in Epicor could not be saved in Sugar (2026-10-17). Taken with `git show 13822bf:<path>` (the rc100
branch head); the file is unchanged since rc92 (48ed61e). `.txt` so no linter or loader mistakes it for package code.

| file | source path | sha256 |
|---|---|---|
| `bd_adm_required_fields.php.txt` | `sugar-sell/BenchDogs-Ext/custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php` | `bcd1a4ee9fafc3660437f81947a010cfab97f26d4f6555237a33cabd192d64e8` |

`scripts/tests/project_required_on_real_sidecar.cjs` runs it as the page's control: on it, Sugar's record view
refuses the Epicor-born quote's save, naming Project and no other field.
