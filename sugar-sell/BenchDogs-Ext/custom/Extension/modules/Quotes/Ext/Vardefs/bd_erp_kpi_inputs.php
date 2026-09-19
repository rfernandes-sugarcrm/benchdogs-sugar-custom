<?php

// ════════════════════════════════════════════════════════════════════════════
// RESTORED TO THE BUILD 2026-09-19. THIS FILE STOPPED SHIPPING AT rc43 AND THE
// CONNECTOR NEVER STOPPED WRITING THE FIELDS IN IT.
//
// `bd_quoted` and `bd_date_quoted` are written on EVERY quote sync by
// connector_ext_benchdogs/transformers/quotes.py (SellBenchQuoteKpiCRM), which
// is reachable from the `app.py` that core's discovery.py imports — measured,
// 11 modules reachable and these two live in EXECUTABLE code, not prose.
//
// Shipped rc32 rc33 rc40 rc41. ABSENT rc43 rc44 rc45 rc48 rc49 rc50 rc51.
// It stayed invisible because on Sugar Cloud a build that simply stops shipping
// an Extension file does not remove it from any tenant that already has it — so
// Bench kept working on its rc41-era copy while the package could no longer
// create these fields ANYWHERE NEW. A fresh tenant would take the connector's
// writes against fields that do not exist. That is the inverse of the residue
// defect in §CW, by the identical mechanism.
//
// 🔒 1045's `bd_erp_stage_code` is NOT restored — it is genuinely retired, and
// appears in the connector only in a retirement note. Its stub lives beside
// this file.
//
// 🛑 DO NOT DELETE THIS FILE TO RETIRE ITS FIELDS. Empty it to a stub instead
// (§CW). Deleting is what caused this.
// ════════════════════════════════════════════════════════════════════════════

/**
 * The raw Kinetic facts BdQuoteKpiHook's stage machine consumes, landed by the
 * connector directly on the NATIVE Quote.
 *
 * These replace the identically-shaped fields that used to live on the
 * bd01_ERP_Quote mirror header (current_stage on the module vardefs, quoted /
 * date_quoted in Ext/Vardefs/bd_quote_completion.php). They are CARRIERS, not
 * a mirror: three columns on the one native record, no second module, no
 * second row, nothing duplicated that core already holds (gate G1).
 *
 * ===================================================================
 * 🚩 `bd_quoted` IS A varchar, NOT A bool, AND THAT IS THE WHOLE POINT
 * ===================================================================
 *
 * The design property this file is built on is that `quoted` carries three
 * states - true, false, and ABSENT, where absent means "the source did not
 * answer" and may not clear or advance a lifecycle decision already made in
 * Sugar. `BdQuoteKpiHook::quotedSignal()` returns `?bool` for exactly that
 * reason and `mapStage()` branches on `=== true`, `=== false` and neither.
 *
 * THIS FILE USED TO DECLARE IT `'type' => 'bool'` AND CLAIM, IN A COMMENT,
 * THAT "null remains unknown". THAT CLAIM WAS FALSE. A Sugar `bool` cannot
 * hold unknown - not by convention, by platform, and by FOUR independent
 * mechanisms, each of which is on its own sufficient. Verified in real Sugar
 * source, IDENTICALLY in 26.1.0 and 25.2.0:
 *
 *  1. `DBManager::massageFieldDef()` (26.1.0 include/database/DBManager.php,
 *     final clause of the method) ends with
 *         if ($fieldDef['type'] === 'bool') { $fieldDef['required'] = 'true'; }
 *     - a bool vardef is FORCED required at the DB layer whatever it says.
 *
 *  2. `MysqlManager::massageFieldDef()` (26.1.0 MysqlManager.php:994-996):
 *         if ($fieldDef['dbType'] == 'bool' && empty($fieldDef['default'])) {
 *             $fieldDef['default'] = '0';
 *         }
 *     THE PLATFORM SUPPLIES THE DEFAULT THE VARDEF DELIBERATELY REFUSES TO
 *     WRITE. Omitting 'default' from a bool does not withhold a default; it
 *     only decides who writes the zero.
 *
 *  3. `DBManager::isNullable()` (26.1.0:1098; the bool rule at :1105-1107,
 *     25.2.0:1094 with the same rule at :1101-1103) returns FALSE for
 *     `type => 'bool'`, so the write path at 26.1.0 DBManager:2507
 *     (25.2.0:2499)
 *         } elseif ($val === null && !$this->isNullable($fieldDef)) {
 *             $values[$field] = $this->emptyValue($fieldType, true);
 *     stores `emptyValue('bool')` - which is `0` (DBManager:3841).
 *
 *  4. `SugarBean::fixUpFormatting()` (26.1.0 data/SugarBean.php:3618, case
 *     'bool' at :3718-3721; 25.2.0 :3606 / :3706-3709), called from
 *     `save()` at :2047 (`save()` itself declared at :2036):
 *         case 'bool': if (empty($this->$field)) { $this->$field = false; }
 *     STATED PRECISELY, because the obvious wording is wrong: this does NOT
 *     flatten a literal null. The loop guard at :3625-3627 is
 *         if (!isset($this->$field)) { continue; }
 *     and isset() is FALSE for null, so a literal null skips this method
 *     entirely - mechanism 3 is what catches that one. What mechanism 4
 *     catches is every EMPTY NON-NULL value: '', '0', 0, false. And '' is
 *     exactly what a NULL column hands back through Sugar's row encoding.
 *     Between 3 and 4 there is no path that leaves the value null.
 *
 * ONE CLAIM DELIBERATELY NOT MADE: that the bool COLUMN is `NOT NULL`. Read
 * `oneColumnSQLRep()` and it is not - neither NOT NULL branch fires without an
 * explicit `isnull` key in the vardef (26.1.0 DBManager.php:3151 for the
 * second), nothing on MySQL injects one (only IBMDB2Manager does, at :1480),
 * and `MysqlManager::massageFieldDef()` has already rewritten `type` to
 * 'tinyint' before that branch tests for 'bool'. So the live column is almost
 * certainly `tinyint(1) DEFAULT '0'` and NULLABLE. THAT CHANGES NOTHING: a
 * column that accepts NULL and has no code path able to send it one is just a
 * more confusing way to be unable to hold unknown.
 *
 * So the unknown branch of `mapStage()` was unreachable and the live reading
 * of `false` on 134/134 records was not NULL rendered at REST - the column
 * GENUINELY COULD NOT HOLD UNKNOWN. This is decision 59's fabricated zero
 * arriving by PLATFORM rather than by default: the same defect, a different
 * route. (Note what the existing mutation test does and does not prove: M21
 * proves the VARDEF has no default. It does NOT prove the BEAN preserves
 * null, and mechanism 2 shows the vardef is not even where the default comes
 * from.)
 *
 * WHY varchar AND NOT enum. `isNullable()`'s third clause -
 *     if (empty($vardef['auto_increment'])
 *         && (empty($vardef['type']) || $vardef['type'] != 'id' || ...)
 *         && (empty($vardef['name']) || ($vardef['name'] != 'id' && ...))
 *     ) { return true; }
 * (26.1.0 DBManager.php:1109-1115, 25.2.0:1105-1111) returns TRUE for both
 * varchar and enum, so either would be nullable. varchar wins on surface
 * area: an enum needs an $app_list_strings dropdown shipped at APPLICATION
 * scope, and shipping one at the wrong scope is a defect this package has
 * already paid for once (REQ-14, `bd_erp_stage_list`). A tri-state that
 * depends on a dropdown resolving is a tri-state with a new way to fail.
 *
 * THE VOCABULARY IS '1' / '0' / NULL, AND IT IS NOT NEGOTIABLE, because
 * `BdQuoteKpiHook::normalizeBool()` - which this package must not change,
 * another lane depends on it - accepts exactly `true|1|'1'`, `false|0|'0'`,
 * and returns null for `null`, `''` and everything else. 'yes'/'no' would
 * read as UNKNOWN. `BdQuoteCarrierCanonicalHook` (before_save, priority 0)
 * enforces the vocabulary so no writer has to know it.
 *
 * NO 'len' IS DECLARED, DELIBERATELY. `SugarFieldBase::apiSave()` (26.1.0
 * include/SugarFields/Fields/Base/SugarFieldBase.php:674) trims - and
 * SILENTLY DROPS non-string, non-numeric values with only a log warning -
 * only when `isset($properties['len'])` and the type is trimmable
 * (`isTrimmable()` returns true for 'varchar'). Without 'len' a producer
 * that PUTs a JSON boolean still lands it on the bean, where the
 * canonicaliser converts it. MySQL normalises the column to varchar(255)
 * anyway (MysqlManager.php:997-999), so nothing is left undecided.
 *
 * NEITHER `bd_quoted` NOR `bd_date_quoted` CARRIES A DEFAULT, and with these
 * types that is now true rather than merely written down.
 */

$dictionary['Quote']['fields']['bd_quoted'] = array(
    'name' => 'bd_quoted',
    'vname' => 'LBL_BD_ERP_QUOTED',
    // varchar, NOT bool. See the header: a Sugar bool is forced required,
    // given a DEFAULT '0' by MySQL, reported not-nullable by isNullable()
    // and flattened to false by fixUpFormatting() - four ways for "the
    // source did not answer" to become "the source said no".
    'type' => 'varchar',
    'comment' => "Kinetic QuoteHed.Quoted completion fact as '1' / '0'; NULL is unknown and must stay reachable",
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'studio' => false,
);

$dictionary['Quote']['fields']['bd_date_quoted'] = array(
    'name' => 'bd_date_quoted',
    'vname' => 'LBL_BD_ERP_DATE_QUOTED',
    // datetime is nullable on the same isNullable() clause that makes the
    // varchar above nullable, and fixUpFormatting()'s datetime case
    // (26.1.0 SugarBean.php:3637-3640) returns early on empty rather than
    // substituting a value, so absent survives here too.
    'type' => 'datetime',
    'comment' => 'Kinetic QuoteHed.DateQuoted business completion date; null remains unknown',
    'reportable' => true,
    'audited' => true,
    'importable' => false,
    'massupdate' => false,
    'studio' => false,
);
