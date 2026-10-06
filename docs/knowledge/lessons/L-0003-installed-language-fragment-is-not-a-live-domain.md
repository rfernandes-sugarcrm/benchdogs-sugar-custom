---
id: BD-L-0003
title: An installed language fragment is not yet a usable stage domain
rule: Compile application language extensions, refresh metadata, and verify canonical domains before a required capability reports installation success.
severity: high
subsystems: [benchdogs-sugar, module-loader-install]
paths:
  - sugar-sell/BenchDogs-Ext/scripts/post_install.php
  - sugar-sell/BenchDogs-Ext/custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php
  - sugar-sell/BenchDogs-Ext/custom/clients/base/api/BdBenchDogsActionsApi.php
---

# An installed language fragment is not yet a usable stage domain

**What happened.** Bench rc9 installed, but the first selected-line canary
returned the new `policy_invalid_stage` diagnostic despite correct policy
inputs. The admin repair endpoint subsequently reported language compilation,
metadata refresh and both stage domains present. Source inspection showed
that the endpoint explicitly called `rebuild_languages` and
`refreshLanguagesCache`, while `post_install` only installed the language
template and rebuilt module-scoped extensions. Package presence did not prove
that the request's canonical application vocabulary contained its stage keys.
The repair response alone does not prove the next seller action succeeds.

**Rule.** Required installation work cannot live only behind a manual repair
endpoint. Compile the shipped application extensions, refresh language
metadata, then read the canonical lists without the request cache. Verify the
quote stage, both opportunity stages and their probability mappings. A failed
verification must fail installation with a fixed neutral error, not merely log
and continue as a nominal success. This does not roll back prior installer
work; hosted installed-version/source checks remain mandatory after an error.

**Applying it.** Run the check after other extension rebuilds and cover English,
the configured default and the current installer language. Keep append-only
domain fragments so unrelated customer choices survive upgrades. Do not
weaken the shared writer's strict enum validation or add customer stage writes
to compensate for absent metadata. Do not repair Opportunity Revenue Line
Items in this Opportunities-only package lifecycle.

The isolated regression executes the actual `post_execute` function against
Sugar API lifecycle doubles, including repeated upgrades, missing domains,
wrong probability, rebuild/refresh failures and current-language differences.
It proves call sequencing and refusal, not native hosted cache behavior. A
fresh/upgrade hosted run without the manual endpoint remains acceptance work.
