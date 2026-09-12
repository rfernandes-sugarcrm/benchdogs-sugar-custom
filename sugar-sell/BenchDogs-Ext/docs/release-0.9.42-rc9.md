# BenchDogs-Ext 0.9.42-rc9 — coordinated diagnostic candidate

Built locally only; no install, deployment, commit or push in this preparation.
Not production approved, and not a claimed fix for the hosted prototype-stage
failure.

This package retains rc8's decision-only release-stage provider and fresh
identity-safe relationship retrieval. The runtime customer code is unchanged.
Its manifest now requires ERP-Epicor `1.1.24-rc5` and Partial Fulfillment
`1.0.13`, whose shared dispatcher/writer distinguish policy and fallback
refusal reasons without changing the order-success message. These declarations
are minimum dependency gates; the exact coordinated artifacts are recorded
below. Install shared ERP-Epicor first, PF second, Bench last, only after the
separate release/installation approval.

Shared PF remains the sole release-stage writer. Bench does not gain a second
save path, access Opportunity RLIs, change REQ-5 governing policy or change the
shared headline amount owner. SDK 1.18 and the Python customization server are
untouched.

## Offline evidence and limits

- Bench suite: 87 pass, two visible skips (native SugarLogic materialization
  still unverified; copied ERP-Epicor linter fixture absent in this checkout).
- Shared suite: 142 pass, plus 23 JavaScript and 19 standalone rollup cases.
- All 131 Bench payload files match source byte-for-byte; manifest/version,
  exact packaged tree and ZIP CRC pass. Shared variants contain 1,111 payload
  files each, including merged ERP-Core; PF contains 19.
- All 129 packaged Bench PHP files, including the manifest, pass PHP 8.2
  syntax checks; both shared variants and PF pass the same full ZIP check.
- Current shared scanner reports zero findings on Bench source and ZIP.
  Bench's older copied scanner still advises adding `files.md5`. That advice
  is obsolete: shared lesson L-0057 records that it makes MLPs unloadable. No
  `.md5` file was added. Offline preflight is not hosted ModuleScanner.
- All builds/tests use network-disabled local containers, no QA/ERP writes.
  Prior ignored ZIPs were preserved. `git diff --check` passes.

The two old PF `1.0.11` artifact assertions were stale relative to rc8's actual
`1.0.12` manifest; both now verify the intended `1.0.13` dependency. Passing
these assertions checks the built manifest, not hosted dependency enforcement.

## Exact artifact hashes (SHA-256)

- `sugar-sell/BenchDogs-Ext/releases/sugarai_benchdogs_ext-0.9.42-rc9.zip`:
  `0c4c91468298b9bc2bda5988671dff9c75554b376b8245e13ca5c4910f027cd4`
- Shared ERP-Epicor rc5 append-only:
  `c151f7ecb70ed8cf1622cfd14e1c9946bc7a87c86c736b2969e947bbaac4f0e2`
- Shared ERP-Epicor rc5 replace:
  `219bf1687544da2f9ddb11b7e8db859a3b50f4e4570dbe85b2ee1aaaabc66293`
- Shared PF 1.0.13:
  `61cbbe28f20d6db37d302c614a913ad286c58451b87e4908d9a5b6be77e46905`

Before acceptance, verify hosted scans and installed versions/source, perform
one controlled prototype-first release with `release_stage_status` captured,
then production-next/reconciliation, fresh stage/probability and exact ERP
order reconciliation. Preserve native materialization and revision acceptance
gaps rather than interpreting a diagnostic RC as production clearance.
