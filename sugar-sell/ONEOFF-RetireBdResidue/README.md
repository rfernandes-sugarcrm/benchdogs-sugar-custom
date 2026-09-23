# ONEOFF-RetireBdResidue 1.0.2

> **What changed in 1.0.2** (1.0.0 ran on et; 1.0.1 on stock and Ophir; both are spent)
>
> 1. **It deletes Bench Dogs' orphaned order adapter**
>    `custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php` once its planner
>    `custom/modules/Quotes/BdSubmitOrderPlan.php` is blank or absent. 1.0.0/1.0.1 blanked the planner
>    and kept the adapter, which then **refused every Submit Order** — measured on Ophir, quote 368,
>    2026-09-23 02:55Z: *"The Bench Dogs order planner is not installed, so this quote cannot be
>    adjudicated and nothing was sent."* With no adapter, ERP-Epicor's
>    `ErpQuoteHooks::fireResolveOrderableLines()` returns the candidates unchanged (`hook_absent`,
>    `ErpQuoteHooks.php:139-144` at erp-integration-sugar `bc5b176`) — the route every tenant without
>    Bench Dogs already takes. Blanking would not do: the hook still finds the file and refuses
>    (`:147-164`). It deletes **only** the body Bench Dogs shipped (md5 `f6c3d474…`, rc37–rc40) and
>    leaves any other body, reported under SKIPPED. How it deletes without `unlink()`: §2.
> 2. **"What it leaves alone" matches BenchDogs-Ext 0.9.42-rc69's kept set** — the four files rc69
>    installs, marked `KEPT BY rc69`. The repair-route file is kept because rc69 ships it **empty**
>    (🔒 1573); the old "owner answered keep" note is gone (no such ruling exists).
>    `scripts/tests/test_oneoff_retire_bd_residue.py` holds that list equal to rc69's copy list.
> 3. **The log names the version that ran**, read from the manifest. Through 1.0.1 the header and the
>    `fatal()` summary said "1.0.0" whatever was installed.
>
> **Run it after rc69 on every tenant that has had Bench Dogs**, including any that took rc67/rc68
> after an earlier run. Expect the adapter under REMOVED on Ophir (and on stock, if it is there).

A **disposable** cleanup package. Install it, let it run, uninstall it.
It is **not** part of the shipped Bench Dogs package and must never become part of it.

Owner decision 🔒 1521: *"I wnat clean rep o and clean teannants give me a one off tha cleans the
tennants too"* — the retirement work moves out of `BenchDogs-Ext` and into this disposable
package, so the shipped package can carry only customer-category code (🔒 1508 / 🔒 1514 / 🔒 1520).

---

## 1. The question that decided the design: does uninstall restore?

**Yes. Measured in SugarEnt-Full-26.1.0, not assumed.**

| step | file:line | what it does |
|---|---|---|
| `ModuleInstaller::install_copy()` | `ModuleInstall/ModuleInstaller.php:503-518` | takes `$backup_path = getBackupPath()` — `<installed zip path minus extension>-restore` — and calls `copy_path($from, $to, $backup_path)` |
| `copy_recursive_with_backup(..., $uninstall = false)` | `:2669-2678` | **`if (file_exists($dest)) { copy($dest, "$backup_path/$dest"); }`** then `copy($source, $dest)` |
| `ModuleInstaller::uninstall_copy()` | `:521-545` | recomputes the same backup path, calls `uninstall_new_files()`, then `copy_path($backup_path, $cp['to'], $backup_path, true)` |
| `copy_recursive_with_backup(..., $uninstall = true)` | `:2656-2662` | **`copy($source, $dest)` — the backed-up body is written back over the live path**, then the backup is unlinked |
| `uninstall_new_files()` | `:555-580` | deletes only files that have **no** backup, i.e. ones the package genuinely created |

So a one-off that shipped the retired paths **empty through `installdefs['copy']` would undo its own
work the moment anyone uninstalled it**, silently, on every tenant, while the install log still showed
it succeeding. That is strictly worse than doing nothing.

**This package therefore has no `copy` key in its manifest at all.** Every removal is performed from
`post_execute` by platform code. There is no copy list for `uninstall_copy()` to walk, no `-restore`
directory, and nothing to put back. **Uninstalling it is a genuine no-op** — the same property
`ONEOFF-RetireBdQuoteMirror` and `ONEOFF-DropBdQuoteMirrorTables` were built for.

*What it would look like if this were wrong:* `uninstall_copy()` would call only `uninstall_new_files()`
and never `copy_path(..., true)`, and `copy_recursive_with_backup()` would have no `$uninstall` branch.
Both are there, at the lines above.

### 🚩 The converse, and it is an operating rule

`BenchDogs-Ext` **does** ship every one of these paths through `copy`. Uninstalling *it* restores the
bodies that were on disk **immediately before that zip installed** — so uninstalling et's rc62 puts
back the **rc60-era** bodies, not rc62's. Said precisely because it is the sentence a reviewer will
check against `copy_recursive_with_backup():2669`: the backup is taken from `$dest` at install time.
The conclusion is unchanged and if anything worse: four of those rc60-era language files still
**declare** keys (measured: `en_us.bd_stage_doms.php`, `Accounts/en_us.bd_action_buttons.php`,
`Products/en_us.bd_line_order.php`, `Quotes/en_us.bd_action_buttons.php` all carry
`$app_list_strings`/`$mod_strings` assignments at `c4e8874`, and none do at rc66 `74a846e`).

> **Run this one-off AFTER any BenchDogs-Ext install or uninstall, and RE-RUN it after any future
> BenchDogs-Ext uninstall.** It is idempotent; re-running costs nothing.

Installing a *later* BenchDogs-Ext that no longer ships these paths does **not** resurrect them:
`install_copy()` only touches paths in its own copy list.

---

## 2. What it removes, and how

The worklist is **every path `BenchDogs-Ext` has ever installed under `custom/`, taken from the
package's whole git history** (133 paths) — not from its current tree. A census of the current package
misses the orphans: files an old version installed and a later one stopped shipping, which Module
Loader therefore never deleted. That is the failure that left `bd01_erp_rung_costs` for
`ONEOFF-RetireBdQuoteMirror` to find; only the tenant knew about it.

| item | what it is | how this package reproduces it |
|---|---|---|
| **K-1** | the emptied `custom/Extension/` stubs — every `bd_*` vardef, label, hook registration, dropdown style and language fragment the package ever installed | **87 of the 89 historical Extension paths are DELETED** by `ModuleInstaller::uninstallExt()` (`:675-712`), driven from the public `installdefs` property (`:85`) with a synthetic worklist. It builds `custom/Extension/{modules/<M>\|application}/Ext/<subdir>/<name>.php` and hands it to `rmdir_recursive()`, which unlinks a plain file (`include/dir_inc.php:96-99`). Grouped into 7 calls by Ext subdirectory. |
| **K-2** | `BdQuotesLayoutExtensions::write()` — splices the Bench Dogs panel out of the **deployed** Quotes record view | the class is **carried in this zip** (`lib/`, verbatim from rc66 `74a846e`) and called. Reported by hashing `custom/modules/Quotes/clients/base/views/record/record.php` before and after: `write()` only calls `deployRecordView()` when it actually changed something, so a changed hash is a real removal and an unchanged one is the *spentness* evidence. |
| **K-3** | `BdOpportunitiesLayoutExtensions::remove()` — the retired `bd_governing_origin` marker | identical mechanism against the Opportunities record viewdef. |
| **K-4** | `BdKineticOpportunityHook.php` tombstone + its empty registration | the **registration** `custom/Extension/modules/Quotes/Ext/LogicHooks/bd_kinetic_opportunity.php` is deleted with K-1; the **tombstone class** is blanked (see below). Registration first, so nothing is left pointing at a blanked class. |
| **K-5** | `post_install.php`'s `uninstall_languages('zz_bd_stage_doms')` — the fragment `install_languages()` CONCATENATED across every past version | the same `ModuleInstaller` call with the same `id_name`, `base_dir` and template path, **copied from `BenchDogs-Ext/scripts/post_install.php:302-317`**, not reinvented. Reported by `file_exists()` on `custom/Extension/application/Ext/Language/en_us.zz_bd_stage_doms.php` before and after. |
| **K-6** | the `pre_uninstall` / `post_uninstall` pair | **NOT carried, and it must not be.** See §5. |
| **D-3** | the orphan `LBL_RECORDVIEW_PANEL_BENCHDOGS = 'Bench Dogs'` label | `custom/Extension/modules/Quotes/Ext/Language/en_us.bd_erp_fields.php` is in the K-1 delete list. The file goes, so the orphan label goes with it — a stronger outcome than emptying the line, and available only because this package can delete where a shipped package can only overwrite. |

Plus, beyond the seven:

* **8 Bench-only directories deleted** via `uninstall_customizations()` (`:2625-2640`): the three
  retired `bd01_ERP_Quote*` module directories and five retired button-field directories
  (`bd-create-opp-quote`, `bd-best-pricing`, `bd-order-selected`, `bd-order-winning`,
  `bd-send-estimating`).
* **1.0.2 — Bench Dogs' order adapter DELETED** (`custom/modules/Quotes/ErpQuoteHooks/ResolveOrderableLines.php`),
  only for Bench Dogs' own body and only when its planner is blank or absent. The deletion is
  `ModuleInstaller::copy_path($absent, $adapter, $absent, true)`: in uninstall mode
  `copy_recursive_with_backup()` unlinks the destination when the source is neither a file nor a
  directory (`:2680-2684`) — the same route `uninstall_copy()` (`:521-547`) takes to remove a file a
  package installed with no backup. `$absent` is a path under this unpacked package that it never ships,
  checked absent first (if it existed, `copy_path` would copy it over the adapter instead).
* **21 orphaned class files blanked** with `lib/emptied.php` through
  `ModuleInstaller::copy_path()` (`:1424-1462`) with **no** backup path — so nothing is backed up and
  nothing can be restored. Blanked rather than deleted because there is **no platform primitive that
  deletes a single file at an arbitrary path**: `uninstallExt` only builds `custom/Extension/**` paths,
  `uninstall_customizations` only removes directories, and `unlink`, `rmdir`, `rmdir_recursive`,
  `file_put_contents`, `copy`, `glob`, `is_dir`, `is_file`, `sugar_file_put_contents` and
  `SugarAutoLoader::unlink` are all on ModuleScanner's deny-list
  (`ModuleInstall/ModuleScanner.php:106-218`). Shipping the path empty really is the only removal —
  the brief was right about that; it is *where* the empty body comes from that had to change.

### What it deliberately does **not** touch — reported by name on every run

| path | why |
|---|---|
| `custom/Extension/modules/Accounts/Ext/Vardefs/bd_customer_group.php` | **KEPT BY rc69**: the customer-category field 🔒 1508 / 🔒 1514 / 🔒 1567 keep |
| `custom/Extension/modules/Accounts/Ext/Language/en_us.bd_customer_group.php` | **KEPT BY rc69**: its label |
| `custom/modules/Accounts/BdAccountsLayoutExtensions.php` | **KEPT BY rc69**: places those two fields |
| `custom/clients/base/api/BdBenchDogsActionsApi.php` | **KEPT BY rc69**, which ships it **empty** (🔒 1573): the empty body is what unregisters `bd-tools/repair-ui` |
| `custom/modules/Quotes/BdQuotesLayoutExtensions.php` | not shipped since rc69 and inert (K-2 runs this package's own `lib/` copy); left because an rc68-or-earlier Bench Dogs uninstall still requires it |
| `custom/modules/Opportunities/BdOpportunitiesLayoutExtensions.php` | the same, for K-3 |
| `custom/modules/Quotes/ErpQuoteHooks/OpportunityContribution.php` | **Partial Fulfillment 1.0.41 ships the same path.** Blanking puts an empty stub at a provider path (🔒 1508 / G280 forbid it); deleting drops ERP-Core to `(float) $quote->total`, the fabricated zero 🔒 1511 forbids. Retired only by the PF-reinstall sequence in the port review §4.1 |
| `.../ErpQuoteHooks/OpportunityLineRollupPolicy.php`, `OpportunityReleaseStagePolicy.php`, `OrderSelectedLinesPolicy.php` | Bench Dogs' old adapters at hook paths, checked one by one in 1.0.2 and left because none can block anything: PF no longer consults the line-rollup policy (🔒 1468); the release-stage policy reads no class this package blanks; the order-selected policy answers "no objection" when its selector is blank. **Never blank a file at a hook path**: the hook still finds it and fails closed. |
| `.../ErpQuoteHooks/ResolveOrderableLines.php` | **no longer here — 1.0.2 DELETES it** when it is Bench Dogs' body and its planner is blank or absent (see the top of this file) |
| `custom/modules/ProductBundles/clients/base/views/quote-data-group-list/quote-data-group-list.js` | **ERP-Core ships this exact path** on the deployed tree. Removing it takes out the core quote grid on every tenant |
| `custom/modules/Products/clients/base/views/quote-data-group-list/quote-data-group-list.php` | the Products grid viewdef ERP-Core also manages |

### 🚩 The one mechanism here with no precedent in this codebase

`dirname(__DIR__)` is used to read this package's **own** `lib/` from inside `post_execute`. Neither
sibling one-off reads anything from its package, and `BenchDogs-Ext`'s `post_install` reads only tenant
paths that `install_copy` has already landed. **So this is unproven on a real install and is stated as
unproven.**

Why it should hold: `post_execute` is `require_once`'d from `<base_dir>/scripts/post_execute.php`
(`ModuleInstaller.php:435`), and `base_dir` is the unpacked package directory created by
`ScanningPackageZipFile::createPackageDir():204-211` → `mk_temp_dir()` → `tempnam()`
(`include/utils/file_utils.php:98-110`). `tempnam()` returns a **real filesystem path**, not a stream
URL, so `__DIR__` is an ordinary absolute path and `require_once`, `copy()` (inside `copy_path`) and
`md5_file()` all tolerate it.

**And if it is wrong, the failure is visible, not silent.** Every use is guarded by `file_exists()`:
K-2, K-3 and the whole blanking group report themselves under **SKIPPED**, naming the path that was
not found, and the Extension deletions — the bulk of the work — do not use it at all.

### A second mechanism, unproven by this package but proven on a tenant

Driving a freshly constructed `new ModuleInstaller()` from `post_execute` is **not** novel:
`BenchDogs-Ext/scripts/post_install.php:302-317` already does exactly that for
`uninstall_languages()`, and **G268 is CLOSED on Bench**, so that call has run on a live tenant.
The relevant detail is that `uninstall_languages():1248` opens with `$this->log(translate(...))`, the
same first statement `uninstallExt():677` has — and `log():2641-2650` is safe on a fresh instance
because `isInstalling` is declared `protected $isInstalling = false` (`:125`), so it skips
`addInstallationMessage()`, and `silent = true` suppresses its `echo`.

---

## 3. Idempotency, and how an operator reads the result

Every entry is guarded by `file_exists()` before and re-checked after. The blanked files still *exist*,
so they are compared by `md5_file()` against `lib/emptied.php` — `filesize()`, `file_get_contents()`
and `file()` are all deny-listed; `md5_file()` is not, and it is used here only to compare two local
files.

**Run 1 reports 118 removals. Run 2 reports `REMOVED (0): NOTHING LEFT TO REMOVE.`**

The report goes to two places, neither of which is `sugarcrm.log` — which is the problem that made
these items hard to retire in the first place:

* **`echo`** → `ModuleInstaller::post_execute()` wraps the require in
  `ob_start(function ($val) { $this->log($val); }, 64)` (`:435-440`), and `log()` calls
  `addInstallationMessage()`, which is what Module Loader's **"Display Log"** link renders on the
  install page.
* **`$GLOBALS['log']->fatal()`** → `package_install.log`, because
  `MlpLogger::replaceDefault()` (`src/PackageManager/PackageManager.php:941`,
  `modules/Administration/UpgradeWizard_commit.php:17`) points the default logger at
  `package_install` **at debug level** for the duration of an install. Export it from
  **Admin ▸ Diagnostic Tool with only "Package Install Log" ticked**.

`bd-tools/repair-ui` is **not** used: routing through it would mean editing
`custom/clients/base/api/BdBenchDogsActionsApi.php`, which the Sugar-package lane owns. The two
destinations above make it unnecessary rather than merely blocked.

---

## 4. Build, lint, test

```
cd sugar-sell/ONEOFF-RetireBdResidue
php pack.php                 # -> releases/oneoff_retire_bd_residue-<version>.zip
php tests/harness.php        # run twice over one synthetic tenant, plus four adapter tenants
                             # (CI runs it: scripts/tests/test_oneoff_retire_bd_residue.py)
```

**Expected build numbers, stated because an ERP-class build is different:**

```
zip entries          = 5        (4 files + manifest.php)
installdefs['copy']  = ABSENT   (not an empty array: absent)
ERP-Core merge       = NONE, correctly
```

A healthy ERP-Epicor build shows `copy = 443` over 1309 entries because
`sugar-sell/buildPackages.sh` merges `ERP-Core/src` into the work dir first. **This is not an
ERP-class package**: it contains no ERP-Core content, ships no file into the instance, and
`benchdogs-sugar-custom` has no `buildPackages.sh` at all. `php pack.php` is the complete build, as it
is for both sibling one-offs.

Lint with the **build-SHA linter (106,052 bytes, 19 rules incl. MLP019)**, `--zip`, and a known-bad
control. The working tree's `scripts/mlp_lint.py` is a 56,380-byte copy and must not be used.

---

## 5. What the shipped package can drop once this has run everywhere

| item | droppable? | why |
|---|---|---|
| **K-1** the 41 shipped `custom/Extension/` stubs | **YES**, on the evidence of a run that reports them already gone — except the two `bd_customer_group` files, which are kept code, not stubs | this package deletes the paths outright, and a later install that does not ship them cannot bring them back |
| **K-4** tombstone + registration | **YES** | registration deleted, class blanked |
| **D-3** the orphan label | **YES** | its whole file is deleted |
| **K-5** the `uninstall_languages` block in `post_install.php` | **YES** | the fragment is deleted and re-checked |
| **K-2 / K-3 — the CALL SITES in `post_install.php`** | **YES** | this package runs them and reports whether anything moved |
| **K-2 / K-3 — the CLASS FILES `BdQuotesLayoutExtensions.php` / `BdOpportunitiesLayoutExtensions.php`** | **NO — see below** | |
| **K-6** the `pre_uninstall` / `post_uninstall` pair | **NO** | they undo deployed metadata on the package's *own* uninstall, which is a moment no external one-off can be present for. They stay as long as the package is uninstallable at all |

**Why the two class files cannot go.** `pre_uninstall.php` calls them, by design and *pre*, because
deployed-metadata surgery needs the class file and the uninstall then removes it. Drop the files and
the package's own uninstall stops cleaning the deployed viewdefs. This package carries its own copies
so *it* does not depend on the tenant's — but that does not make the tenant's copies removable, because
K-6 still needs them. **They become droppable only when K-6 does**, i.e. when the package stops being
uninstallable, which is not being proposed.

**And one item is not this package's to retire at all.** `OpportunityContribution.php` (D-1) is
retired by the sequence in the port review §4.1 — *drop it from Bench, then reinstall Partial
Fulfillment so PF's `install_copy` overwrites the path* — never by omission and never by an empty stub.

---

## 6. Order of operations

**The one-off runs FIRST. rc67 is built on its evidence, not the other way round.**

1. **Now**, on the current state — `etsugarcube` at rc62, `ophirsx177` at rc66, `ossugarcube2` with no
   Bench Dogs at all: install this package, read its Display Log / `package_install.log`, uninstall it.
   This is the run that does the work, and `et` is the tenant it exists for.
2. Repeat on every tenant until each one's log reads **`REMOVED (0): NOTHING LEFT TO REMOVE.`**
   On `ophirsx177` (rc66) the *first* run should already be close to that — which is the control that
   proves the report is reading the tenant and not reciting its own worklist. On `ossugarcube2` it
   should be a complete no-op.
3. **Then** the Sugar-package lane builds rc67 dropping the items §5 marks droppable, citing those
   logs as the evidence — the same bar rc66 used to retire `BdAutoSelectedReport`.
4. Install rc67. **Re-run this package**; expect a no-op. Uninstall it.
5. Re-run it after **any** future Bench Dogs uninstall, for the `-restore` reason in §1.

Step 1 does not wait on anything. Waiting for rc67 first would be circular: rc67 cannot drop an item
until a log says the item is already gone.
