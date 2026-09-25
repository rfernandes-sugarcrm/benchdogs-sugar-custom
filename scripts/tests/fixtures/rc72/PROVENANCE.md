# rc72 fixture (G460)

A byte-for-byte copy of BenchDogs-Ext **0.9.42-rc72**'s Quote vardef, the build on the
Bench tenants (benchdogs-dev / benchdogs-sandbox, 2026-09-24) when G460 was filed. Taken
with `git show 23d26e1:<path>` (the rc72 build commit; identical at 8acec19, the G530/G532
branch it was built on). `.txt` so no linter or loader mistakes it for package code.

| file | source path | sha256 |
|---|---|---|
| `bd_adm_required_fields.php.txt` | `sugar-sell/BenchDogs-Ext/custom/Extension/modules/Quotes/Ext/Vardefs/bd_adm_required_fields.php` | `b80f9381942b526df124e68a4e995899543a504ca531fb1a4f53829d6f9c590e` |

`scripts/tests/bd_erp_layout_test.php` (T6) installs it as the upgraded tenant's starting
point: its three pickers already placed, then this build's vardef adds the two marketing
pickers after Project.
