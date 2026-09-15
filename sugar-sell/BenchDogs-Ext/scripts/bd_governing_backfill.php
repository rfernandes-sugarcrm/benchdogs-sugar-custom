<?php

/**
 * Decision 72 BACKFILL - the retroactive half, deliberately kept out of the
 * install and out of every automatic path.
 *
 * ============================================================================
 * THIS SCRIPT IS WIRED TO NOTHING. DELIBERATELY.
 * ============================================================================
 *
 * No installdef references it, post_install.php does not call it, no logic
 * hook reaches it and no scheduler job runs it. The only way it executes is a
 * person typing its name. That is the entire design:
 *
 *     php custom/include/bd_scripts/bd_governing_backfill.php            # DRY RUN
 *     php custom/include/bd_scripts/bd_governing_backfill.php --apply    # writes
 *
 * WHY THE DEFAULT IS A DRY RUN, AND WHY THE WRITE NEEDS A FLAG
 *
 * The shipped change is FORWARD ONLY: BdGoverningAutoSelectHook fires only on
 * a line being CREATED, so no quantity break already sitting in a tenant's
 * database can be reached by it. That is a property of the event, not a
 * setting - see that class's docblock.
 *
 * Applying the rule to the lines that are ALREADY there is a completely
 * different act. On the Bench QA tenant it would mark 230 quote lines as
 * governing and rewrite every Opportunity amount behind them in one pass -
 * a bulk commercial-data change, and one that would retire measured forecast
 * evidence across several release rows mid-release, forcing re-takes. Nobody
 * approved that, and "the rule is correct" is not the same statement as
 * "apply it retroactively to everything".
 *
 * WHEN THIS SCRIPT IS THE RIGHT THING TO RUN
 *
 * At CUTOVER on a production tenant. There, retroactive is almost certainly
 * what you want: every deal should carry a forecast from day one, and there is
 * no competing evidence base to protect. It was written for exactly that
 * moment. Run the dry run first, read the two totals it prints, and only then
 * pass --apply.
 *
 * WHAT THE DRY RUN PRINTS, AND WHY EACH LINE IS THERE
 *
 *   - every quote line it would set governing = true, with the quote it
 *     belongs to and the total it was chosen for, and a COUNT;
 *   - every Opportunity amount that would change, FROM what TO what, and a
 *     count plus the net movement in pipeline;
 *   - every quote it would SKIP and why (a person already chose; two lines
 *     selected, which still fails closed; no readable amount; no production
 *     line) - because a backfill that silently skipped things would be
 *     impossible to check.
 *
 * Nothing is written in that mode. Not one row.
 *
 * THE FROM/TO AMOUNTS ARE PREDICTIONS, AND ARE LABELLED AS SUCH
 *
 * The "to" figure is this script's own arithmetic over the chosen line plus
 * the prototype and the Quote's native tax and shipping - the same sum
 * ErpQuoteOpportunityContribution::resolve() does. It does NOT re-run the
 * shared writer, so it cannot show a currency conversion, and it is not a
 * promise. Treat a dry run as "these are the deals that would move, and
 * roughly by how much", and the post-apply read-back as the measurement.
 */

if (PHP_SAPI !== 'cli') {
    die("bd_governing_backfill.php is a CLI script.\n");
}

$bdApply = in_array('--apply', $argv ?? array(), true);
$bdLimit = 0;
foreach ($argv ?? array() as $bdArg) {
    if (strpos($bdArg, '--limit=') === 0) {
        $bdLimit = max(0, (int) substr($bdArg, 8));
    }
}

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}
require_once 'include/entryPoint.php';

require_once 'custom/modules/bd01_ERP_Quote_Line/BdGoverningAutoSelect.php';

// The operator switch gates the forward path; it must gate this too, so that
// "auto-selection is off on this tenant" is one statement and not two.
if (!BdGoverningAutoSelect::enabled()) {
    die("Auto-selection is disabled on this instance "
        . "(sugar_config['bench_dogs']['governing_autoselect'] = false). Nothing to do.\n");
}

echo $bdApply
    ? "=== DECISION 72 BACKFILL - APPLYING. Rows WILL be written. ===\n\n"
    : "=== DECISION 72 BACKFILL - DRY RUN. Nothing will be written. ===\n"
    . "    Re-run with --apply to write. Read the totals at the bottom first.\n\n";

$bdSelector = new BdGoverningAutoSelect();
$bdSeed = BeanFactory::newBean('bd01_ERP_Quote');
$bdQuery = new SugarQuery();
$bdQuery->from($bdSeed);
$bdQuery->select(array('id', 'quote_num'));
$bdQuery->orderBy('quote_num', 'ASC');
if ($bdLimit > 0) {
    $bdQuery->limit($bdLimit);
}
$bdRows = $bdQuery->execute();

$bdWould = array();
$bdSkipped = array();
$bdMoves = array();

foreach ($bdRows as $bdRow) {
    $bdErpQuote = BeanFactory::retrieveBean('bd01_ERP_Quote', $bdRow['id'], array('use_cache' => false));
    if (!$bdErpQuote || empty($bdErpQuote->id)) {
        continue;
    }
    $bdLabel = 'quote ' . (string) ($bdErpQuote->quote_num ?? '?') . ' (' . $bdErpQuote->id . ')';

    try {
        // ALWAYS dry-run first, so the verdict is known before anything is
        // written even on an --apply run. This is what makes the printed
        // "would change" list and the actual write the same decision.
        $bdVerdict = $bdSelector->applyToQuote($bdErpQuote, true);
    } catch (Throwable $bdE) {
        $bdSkipped[] = $bdLabel . ' - REFUSED: ' . $bdE->getMessage();
        continue;
    }

    if ($bdVerdict['action'] !== 'selected' && $bdVerdict['action'] !== 'reselected') {
        $bdSkipped[] = $bdLabel . ' - ' . $bdVerdict['action'];
        continue;
    }

    $bdBefore = bdBackfillOpportunityAmount($bdErpQuote);
    $bdWould[] = array(
        'label' => $bdLabel,
        'line' => $bdVerdict['line_id'],
        'amount' => $bdVerdict['amount'],
        'before' => $bdBefore,
    );

    if (!$bdApply) {
        continue;
    }

    $bdSelector->applyToQuote($bdErpQuote);
    require_once 'custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php';
    (new BdQuoteReflectionHook())->refreshOpportunityAmount($bdErpQuote);
    $bdMoves[] = array(
        'label' => $bdLabel,
        'before' => $bdBefore,
        'after' => bdBackfillOpportunityAmount($bdErpQuote),
    );
}

echo "LINES THAT WOULD BE SET governing = true (" . count($bdWould) . "):\n";
foreach ($bdWould as $bdItem) {
    printf(
        "  %-34s line %-38s lowest total %s\n",
        $bdItem['label'],
        (string) $bdItem['line'],
        number_format((float) $bdItem['amount'], 2)
    );
}

echo "\nOPPORTUNITY AMOUNTS THAT WOULD CHANGE (" . count($bdWould) . "):\n";
$bdNet = 0.0;
foreach ($bdWould as $bdItem) {
    $bdFrom = $bdItem['before'];
    $bdTo = (float) $bdItem['amount'];
    $bdNet += $bdTo - (float) ($bdFrom ?? 0);
    printf(
        "  %-34s %14s  ->  %14s   (predicted; excludes tax/shipping/currency)\n",
        $bdItem['label'],
        $bdFrom === null ? 'no opportunity' : number_format((float) $bdFrom, 2),
        number_format($bdTo, 2)
    );
}
printf("\n  net predicted pipeline movement: %s\n", number_format($bdNet, 2));

echo "\nQUOTES SKIPPED (" . count($bdSkipped) . "):\n";
foreach ($bdSkipped as $bdReason) {
    echo '  ' . $bdReason . "\n";
}

if ($bdApply) {
    echo "\nMEASURED AFTER WRITING (" . count($bdMoves) . "):\n";
    foreach ($bdMoves as $bdMove) {
        printf(
            "  %-34s %14s  ->  %14s\n",
            $bdMove['label'],
            $bdMove['before'] === null ? 'no opportunity' : number_format((float) $bdMove['before'], 2),
            $bdMove['after'] === null ? 'no opportunity' : number_format((float) $bdMove['after'], 2)
        );
    }
    echo "\n=== WRITTEN. " . count($bdMoves) . " quotes selected and re-valued. ===\n";
} else {
    echo "\n=== DRY RUN COMPLETE. Nothing was written. Re-run with --apply to write. ===\n";
}

/**
 * The current Opportunity amount behind an ERP quote, or null when there is no
 * primary Sugar quote or no single linked Opportunity. Read-only.
 */
function bdBackfillOpportunityAmount(SugarBean $erpQuote)
{
    $quoteId = (string) ($erpQuote->sugar_quote_id ?? '');
    if ($quoteId === '') {
        $quoteId = (string) ($erpQuote->bd_materialized_quote_id ?? '');
    }
    if ($quoteId === '') {
        return null;
    }
    $quote = BeanFactory::retrieveBean('Quotes', $quoteId, array('use_cache' => false));
    if (!$quote || empty($quote->id) || empty($quote->erp_is_primary_quote)) {
        return null;
    }
    if (!$quote->load_relationship('opportunities') || !is_object($quote->opportunities)) {
        return null;
    }
    $ids = $quote->opportunities->get();
    if (count($ids) !== 1) {
        return null;
    }
    $opportunity = BeanFactory::retrieveBean('Opportunities', reset($ids), array('use_cache' => false));
    if (!$opportunity || empty($opportunity->id)) {
        return null;
    }
    return is_numeric($opportunity->amount ?? null) ? (float) $opportunity->amount : null;
}
