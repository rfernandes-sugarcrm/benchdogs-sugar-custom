# G594 fixture

A byte-for-byte copy of the release-stage policy BenchDogs-Ext **rc45-rc64** shipped at
`custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php`: the body benchdogs-dev
still held from rc60 when G594 was filed. `.txt` so no linter or loader mistakes it for package code.

| file | source | md5 | sha256 |
|---|---|---|---|
| `OpportunityReleaseStagePolicy.rc45-rc64.php.txt` | `git show 6d9ae6e2:sugar-sell/BenchDogs-Ext/custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php` (blob `fbb81c48`) | `e5e6e3fff432a5dcd6624accf6bb8598` | `805689abd523baffa4b11f4e950007c88cbdf09524cd34f913fef95f31486798` |

It used to sit under `sugar-sell/ONEOFF-RetireBdResidue/tests/fixtures/release-stage-policy/`. That
one-off was withdrawn and its code deleted (owner 🔒2173b), and the file moved here with `git mv`, so
its history follows it.

`scripts/tests/test_release_stage_absent_equals_null.py` (case E) runs Partial Fulfillment's real
resolver over this body.
