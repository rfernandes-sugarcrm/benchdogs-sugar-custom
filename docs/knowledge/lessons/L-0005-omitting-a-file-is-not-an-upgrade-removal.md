---
id: BD-L-0005
title: Omitting a copied file does not remove it on upgrade
rule: When retiring installed MLP code, use Module Loader's supported uninstall boundary with data tables retained before installing the replacement.
severity: high
subsystems: [benchdogs-sugar, module-loader-upgrade, rollback]
paths:
  - sugar-sell/BenchDogs-Ext/pack.php
  - sugar-sell/BenchDogs-Ext/scripts/pre_uninstall.php
  - sugar-sell/BenchDogs-Ext/scripts/post_uninstall.php
  - sugar-sell/BenchDogs-Ext/docs/release-0.9.42-rc12.md
---

# Omitting a copied file does not remove it on upgrade

**What happened.** The accepted Bench deployment is Quote-line-only, but older
packages copied an Opportunity-line vardef, hook registration and hook class.
Removing those paths from a new ZIP prevents fresh installation but does not
remove copies already present on an upgraded tenant. The same persistence made
the original rc11 rollback instruction false: reinstalling rc9 would not remove
rc11's new DropdownsStyle file.

**Rule.** Do not add scanner-denied filesystem deletion to installer code and
do not describe an in-place package replacement as rollback. Module Loader owns
the copied-file inventory and supported removal lifecycle. Uninstall the
currently installed package with tables retained, verify the package and copied
files are absent, then install the intended artifact and verify its exact
version. Suspend integration activity during this boundary.

**Applying it.** rc12 is not an in-place upgrade from rc9. Its install runbook
uses uninstall-with-tables-retained followed by rc12 installation, so obsolete
Opportunity-line files are removed before the Quote-only payload lands.
Rollback mirrors that sequence: uninstall rc12 with tables retained and install
the saved rc9 artifact. The rollback restores the prior package, including its
prior limitations; it is an emergency recovery state, not compliance with the
new Quote-only architecture. Offline manifest and uninstall harness checks do
not replace a hosted rehearsal and installed-file verification.
