# rc69 fixtures (G507)

Byte-for-byte copies of two files of BenchDogs-Ext **0.9.42-rc69**, the build on every
Bench tenant when G507 was filed. Taken with `git show 9496b7e:<path>` (the rc69 cut;
identical at `main` 586f78c). `.txt` so no linter or loader mistakes them for package code.

| file | source path | sha256 |
|---|---|---|
| `BdAccountsLayoutExtensions.php.txt` | `sugar-sell/BenchDogs-Ext/custom/modules/Accounts/BdAccountsLayoutExtensions.php` | `d06791d964dde8d0eaa437c3e2806509df07db1901c90da4441fda9db353d366` |
| `bd_customer_group.php.txt` | `sugar-sell/BenchDogs-Ext/custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php` | `07bef5baf88d982e1e86826ff1fe4f3b519a861484391ea6bdb11452ec65c2af` |

`scripts/tests/bd_customer_group_move_test.php` runs rc69's REAL writer against the
Ophir-shaped view to reproduce the root cause (no `panel_body` -> the first panel with
fields -> `panel_header`), and uses rc69's real vardef as the upgraded tenant's starting
point.
