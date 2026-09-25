# ONEOFF-RetireBdActionsApi 1.0.3 (G437) — et only

A **disposable** one-off: install it, read its log, uninstall it. Not part of any shipped package.

## What it does
Deletes **one file**, `custom/clients/base/api/BdBenchDogsActionsApi.php`, and only on a tenant where
**no Bench Dogs package is installed**. Up to rc63 that file declared `BdBenchDogsActionsApi` and registered
three REST routes:

- `POST Accounts/:id/bd-create-opp-quote`
- `POST Quotes/:id/bd-send-to-estimating`
- `POST bd-tools/repair-ui`

Module Loader rebuilds the REST dictionary at the end of every install
(`MetaDataManager::clearAPICache()` + `ServiceDictionaryRest::buildAllDictionaries()`, after `post_execute`
and after `reset_file_cache`). So once the file is gone, the install itself unregisters the three routes.
No cache code is needed here.

## Why et still has the file (G437)
et ran Bench Dogs 0.9.42-rc62 and then **uninstalled** it ("tables kept"). A Module Loader uninstall writes
each path's `-restore` backup back over the live file, so this path got an older, route-registering body back
instead of being deleted. `ONEOFF-RetireBdResidue` 1.0.2 then removed 45 leftovers and **kept** this path, on
purpose.

## Why it is et-only, and how that is enforced
On a tenant **with** Bench Dogs rc69+ this path belongs to the installed Bench Dogs package. rc69 ships it
**empty** (🔒 1573), and the empty body is what unregisters `bd-tools/repair-ui` there. That is why
`ONEOFF-RetireBdResidue` keeps it. So this package checks `upgrade_history` for an **installed**
`sugarai_benchdogs_ext` row:
- **Found:** it deletes nothing and logs `NOT TOUCHED` with the installed version.
- **Not found:** the file is an orphan, and it deletes it.

So it is safe if it is ever run on Ophir or benchdogs-dev/sandbox by mistake, but it is **meant for et**.

It is a separate package (`oneoff_retire_bd_actions_api`), not version 1.0.3 of `oneoff_retire_bd_residue`.
Upgrading the residue one-off to a build that does only this step would make "the latest residue one-off"
silently skip the full sweep on the next Bench tenant. It is numbered 1.0.3 to follow G437's cleanup train
(residue 1.0.0–1.0.2).

## How it deletes without `unlink()`
`ModuleInstaller::copy_path($absent, $file, $absent, true)`: in uninstall mode,
`copy_recursive_with_backup()` deletes the destination when the source is neither a file nor a directory.
This is the call `ONEOFF-RetireBdResidue` 1.0.2 used to delete `ResolveOrderableLines.php`.
`$absent` (`<unpacked package>/no-backup/<path>`) is never shipped and is checked absent first.
ModuleScanner denies `unlink`, `copy`, `file_get_contents`, `is_file` and friends; this script uses only
`file_exists`, `md5_file` and platform classes.

## Its log (Display Log + package_install.log)
One line with one outcome:
- **REMOVED:** with the file's md5.
- **ALREADY GONE:** idempotent re-run, or a tenant that never had it.
- **NOT TOUCHED:** Bench Dogs is installed.
- **SKIPPED:** the no-backup source unexpectedly exists.
- **FAILED:** still present, or an error.

## Proof after install on et
1. `POST rest/v11_*/bd-tools/repair-ui` answers **404** (no route), not 401/403/500.
2. The other two paths likewise.
3. `package_install.log` has `ONEOFF-RetireBdActionsApi 1.0.3 (G437): REMOVED`.

Then uninstall the package: there's nothing to take back, and the file does not come back.

## Build
```
cd sugar-sell/ONEOFF-RetireBdActionsApi
php pack.php          # -> releases/oneoff_retire_bd_actions_api-1.0.3.zip
```
`tests/fixture_BdBenchDogsActionsApi.pre-rc64.php.txt` is the three-route body (BenchDogs-Ext at `326a676`),
used by the local rehearsal to stand in for et's restored file.
