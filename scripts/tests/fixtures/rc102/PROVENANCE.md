# rc102 fixture

A byte-for-byte copy of BenchDogs-Ext **0.9.46-rc102**'s default owner page template, the build on the hosted Bench
tenants when the page printed its help sentence twice under the field (2026-10-18). Taken with `git show
68b33ebf:<path>` (main at rc102, PR #57); the file is unchanged since rc99 (841d47a1). `.txt` so no linter or loader
mistakes it for package code.

| file | source path | sha256 |
|---|---|---|
| `bd-default-owner.hbs.txt` | `sugar-sell/BenchDogs-Ext/custom/modules/Administration/clients/base/views/bd-default-owner/bd-default-owner.hbs` | `30ca464aa63671b9c2f69f03f4fc4205220d2db41b61aee9b02507f2cd7c4396` |

`scripts/tests/default_owner_page_on_real_sidecar.cjs` runs it as the page's control: on it, the page shows the help
sentence twice, once from this template's own line and once from Sugar's relate field, which draws its definition's
help itself.
