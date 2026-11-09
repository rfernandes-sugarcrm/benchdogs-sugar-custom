# Bench Dogs UAT Config

One Module Loader package that puts the UAT configuration made in Studio and Admin on the QA Sugar
(2026-10-18 to 2026-10-22) onto another Bench Dogs UAT Sugar that already runs the same packages.
Package id `sugarai_benchdogs_uat_config`, version in `version`.

## What it carries

| Part | Files | How it installs |
|---|---|---|
| Layouts | 35 view and filter files under `custom/modules/` (Accounts, Contacts, Opportunities, Quotes, Products, ERP_Orders, Meetings, Users) | `copy`, same path |
| Quick Create menu | 10 `custom/modules/<Module>/clients/base/menus/quickcreate/quickcreate.php` for Calls, Cases, Contacts, Documents, Emails, Leads, Meetings, Notes, Opportunities and Tasks | `copy`, same path; the files Admin > Configure Navigation Bar Quick Create writes |
| Contacts subpanel under Accounts | `custom/Extension/modules/Accounts/Ext/clients/base/layouts/subpanels/_overridesubpanel-for-accounts-contacts.php` | `copy`; without it the `subpanel-for-accounts-contacts` view is never used |
| Field settings | 7 `sugarfield_*.php` under `custom/Extension/modules/{Accounts,Leads,Meetings}/Ext/Vardefs/` | `copy`, same path |
| Dropdown colours | `custom/Extension/application/Ext/DropdownsStyle/bd_uat_config_dropdown_styles.php` (Health, Industry, Lead Source, Interaction Type) | `copy` |
| Labels and lists | `language/application/en_us.lang.php` and `language/<Module>/en_us.lang.php` for Accounts, Calls, Contacts, Meetings, Reports, DRI_Workflows, DRI_SubWorkflows | the manifest's `language` section, installed as `en_us.sugarai_benchdogs_uat_config.php` |
| New field | Meetings `interaction_type_c`, enum over `interaction_type_list`, label `LBL_INTERACTION_TYPE` | the manifest's `custom_fields` section, which also adds the column |
| Navigation | `scripts/post_execute.php`: 13 displayed tabs in order, 14 hidden subpanels | Sugar's `TabController::set_system_tabs` and `SubPanelDefinitions::set_hidden_subpanels`, both read back |

The layout, Quick Create, field-setting and subpanel files are byte copies of the QA Sugar's own files. The language files hold the
same statements, gathered per module; of the three lists Rename Modules rewrote whole (`moduleList`,
`moduleListSingular`, `record_type_display_notes`) only the `Meetings` entry ships, because it is the only entry that
differs from Sugar's own labels.

## Install

Module Loader, after ERP-Epicor 1.2.120, Bench Dogs Extensions 0.9.46-rc117 and Bench_Dogs_Account_Contact_Fields
1.0.1 (the manifest's `dependencies`; the loader refuses the package without them). CI builds the zip as part of the
`mlp-package` artifact.

The post-install step logs one line per install (`BenchDogs-UAT-Config: post_install step report`) and, when a step did
not apply, says so on the install's status line rather than failing the install.

### Upgrading from an earlier version

Upload the new zip and install it over the installed one; do not uninstall first. The package id stays
`sugarai_benchdogs_uat_config`, so Module Loader takes a higher version as an upgrade: it refuses only a version that is
the same as or lower than the installed one, then runs a plain install over the old one (the manifest does not set
`uninstall_before_upgrade`, so nothing is uninstalled and the Interaction Type column and its values stay), and finally
deletes the old zip and drops it from the installed list.

- The copied files overwrite the old version's copies, and those copies become the backups. An uninstall after an
  upgrade puts the old version's layouts and field settings back, not the files from before the first install; those
  stay in the first version's restore folder on the server, which nothing reads any more.
- The Interaction Type field is found by name and its column is modified in place, not added again.
- The language files are appended to the installed ones rather than replacing them. The new version's statements come
  last, so its labels win, and the files hold plain assignments only, so nothing is listed twice.
- The post-install step runs again and sets the tabs and the hidden subpanels to the lists above.

## Release notes

### 1.0.2

- 1.0.2: the Quotes record and dashlet layouts no longer place the quote-level Project (`bd_project_id`): since Bench
  Dogs Extensions 0.9.46-rc117 the Project is each quote line's own, drawn in the quote line grid and on the line's
  record view. The cells after it move up one place in the ERP panel; nothing else changes. The quote's column and its
  values stay.
- Install after Bench Dogs Extensions 0.9.46-rc117 (the manifest now requires it), as an upgrade over 1.0.1.

### 1.0.1

- 1.0.1: Lynn's Quick Create menu. Opportunities, Documents, Emails, Tasks and Notes are shown, in that order; Calls,
  Cases, Contacts, Leads and Meetings are hidden. Accounts and Revenue Line Items are not shipped: Accounts keeps
  Sugar's own entry (shown, first), and Revenue Line Items was already hidden the same way on both Sugars.
- Operational: the menu files are new on a Sugar where Admin > Configure Navigation Bar Quick Create was never saved,
  so an uninstall deletes them and the menu goes back to Sugar's defaults. Where a file was already there, the install
  keeps it as a backup and an uninstall restores it. A later save on that Admin page rewrites the same files, and an
  uninstall then removes that edit too.
- Operational: installs over 1.0.0 as an upgrade (see Upgrading above).

### 1.0.0

- First version.
- Operational: every install, including a reinstall, sets the displayed tabs and the hidden subpanels to the lists
  above, replacing what an administrator chose in Admin > Display Modules and Subpanels since.
- Operational: uninstall puts back the layout and field-setting files that were there before the install (Module
  Loader's own backups), removes this package's language files, and asks whether to drop the Interaction Type column.
  It leaves the navigation as it is.
- The Accounts `account_type` and `industry` settings carry `len` 100 where Sugar's columns are 50. The install
  repairs no table (the package has no module of its own), so a Quick Repair lists that widening until it is run.
  Every value in both lists fits in 50 today.
