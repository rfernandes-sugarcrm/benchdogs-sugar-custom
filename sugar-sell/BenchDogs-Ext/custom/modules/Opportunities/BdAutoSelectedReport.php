<?php

/**
 * Decision 72, item 3: one saved report listing EVERY Opportunity still valued
 * from an auto-selected line.
 *
 * WHY THIS IS THE HIGHEST-VALUE PIECE OF THE WHOLE CHANGE, AND THE CHEAPEST
 *
 * The on-screen marker helps exactly one person: whoever happens to open that
 * one record. It does nothing at all for the deals nobody opens, and those are
 * precisely the deals a machine-made valuation is dangerous on - the pipeline
 * fills up completely, every number looks like every other number, and nothing
 * ever prompts a review. The report is what turns "this one was not reviewed"
 * into "here is the list of everything that was not reviewed", which is the
 * only form of it a sales manager can act on.
 *
 * SHAPE TAKEN FROM SUGAR'S OWN SEEDS, NOT FROM MEMORY
 *
 * A report_def that is slightly wrong does not error - it renders a blank
 * report, which looks like "nothing to review" and is the worst possible
 * failure for this particular report. The structure below (display_columns /
 * group_defs / summary_columns / filters_def / full_table_list / order_by, and
 * the link_def shapes for the Accounts and assigned-user joins) is copied from
 * the stock "My Open Opportunities" tabular seed in
 * SugarEnt-Full 26.1.0 modules/Reports/SeedReports.php, and `equals` is the
 * qualifier Sugar itself emits for a varchar filter
 * (src/Reports/Types/Reporter.php:786).
 *
 * WHAT THIS CLASS WILL NOT DO
 *
 * It creates the report if no report of this name exists and otherwise LEAVES
 * IT ALONE. An admin who has edited the columns, retitled it or moved it into
 * a dashboard keeps their version; re-running an install does not silently
 * revert somebody's work. That is the same append-only, skip-if-present
 * discipline the layout writers keep.
 */
class BdAutoSelectedReport
{
    public const NAME = 'Opportunities Valued From an Auto-Selected Quote Line';

    private const DESCRIPTION =
        'Bench Dogs (decision 72): every open Opportunity whose amount comes from a quantity '
        . 'break the system picked because nobody had chosen one. The lowest-total break is '
        . 'selected automatically so the deal is never blank, but nobody has reviewed these '
        . 'numbers yet. Choosing a governing line on the ERP quote clears the marker and '
        . 'removes the row from this report.';

    public function install(): void
    {
        if ($this->existing() !== null) {
            return;
        }
        $report = BeanFactory::newBean('Reports');
        if (!$report || !method_exists($report, 'save_report')) {
            $GLOBALS['log']->fatal(
                'BenchDogs-Ext: Reports module unavailable; skipping the auto-selected review report'
            );
            return;
        }
        $report->save_report(
            null,
            '1',                       // owner: admin, as the stock seeds do
            self::NAME,
            'Opportunities',
            'tabular',
            json_encode($this->reportDef()),
            '1',                       // published: the point is that others see it
            '1',                       // global team
            'none',
            null,
            self::DESCRIPTION,
            null,
            0
        );
        $GLOBALS['log']->fatal('BenchDogs-Ext: installed saved report "' . self::NAME . '"');
    }

    /**
     * Remove it on uninstall, because the field it filters on goes with the
     * package. A report filtering a column whose vardef no longer exists is
     * the trap release-0.9.42-rc24's operator notes describe: it does not
     * error, it silently returns nothing.
     */
    public function uninstall(): void
    {
        $existing = $this->existing();
        if ($existing === null) {
            return;
        }
        $existing->mark_deleted($existing->id);
        $GLOBALS['log']->fatal('BenchDogs-Ext: removed saved report "' . self::NAME . '"');
    }

    /** The report of this name, if one is already on the instance. */
    private function existing(): ?SugarBean
    {
        $seed = BeanFactory::newBean('Reports');
        if (!$seed) {
            return null;
        }
        $query = new SugarQuery();
        $query->from($seed);
        $query->select(array('id'));
        $query->where()->equals('name', self::NAME);
        $query->limit(1);
        $rows = $query->execute();
        if (empty($rows[0]['id'])) {
            return null;
        }
        $bean = BeanFactory::retrieveBean('Reports', $rows[0]['id']);
        return ($bean && !empty($bean->id)) ? $bean : null;
    }

    /**
     * The report definition. Built as an array and encoded, rather than pasted
     * as a JSON string, so that scripts/tests/test_governing_report.py can
     * decode it and assert the filter really is bd_governing_origin = auto -
     * a report that quietly lost its filter would list the whole pipeline and
     * read as a catastrophe rather than as a bug.
     */
    public function reportDef(): array
    {
        return array(
            'display_columns' => array(
                array('name' => 'name', 'label' => 'Opportunity', 'table_key' => 'self'),
                array('name' => 'name', 'label' => 'Account', 'table_key' => 'Opportunities:accounts'),
                array('name' => 'amount', 'label' => 'Auto-Selected Value', 'table_key' => 'self'),
                array('name' => 'bd_governing_origin', 'label' => 'Value Source', 'table_key' => 'self'),
                array('name' => 'sales_stage', 'label' => 'Sales Stage', 'table_key' => 'self'),
                array('name' => 'date_closed', 'label' => 'Expected Close Date', 'table_key' => 'self'),
                array(
                    'name' => 'user_name',
                    'label' => 'Assigned to',
                    'table_key' => 'Opportunities:assigned_user_link',
                ),
            ),
            'module' => 'Opportunities',
            'group_defs' => array(),
            'summary_columns' => array(),
            'report_name' => self::NAME,
            'chart_type' => 'none',
            'do_round' => 1,
            'numerical_chart_column' => '',
            'numerical_chart_column_type' => '',
            'assigned_user_id' => '1',
            'report_type' => 'tabular',
            // Biggest unreviewed number first: if this list is ever too long
            // to work through, the top of it is where the money is.
            'order_by' => array(
                array(
                    'name' => 'amount',
                    'label' => 'Auto-Selected Value',
                    'table_key' => 'self',
                    'sort_dir' => 'd',
                ),
            ),
            'full_table_list' => array(
                'self' => array(
                    'value' => 'Opportunities',
                    'module' => 'Opportunities',
                    'label' => 'Opportunities',
                    'dependents' => array(),
                ),
                'Opportunities:accounts' => array(
                    'name' => 'Opportunities  >  Accounts',
                    'parent' => 'self',
                    'link_def' => array(
                        'name' => 'accounts',
                        'relationship_name' => 'accounts_opportunities',
                        'bean_is_lhs' => false,
                        'link_type' => 'one',
                        'label' => 'Account Name',
                        'module' => 'Accounts',
                        'table_key' => 'Opportunities:accounts',
                    ),
                    'dependents' => array(),
                    'module' => 'Accounts',
                    'label' => 'Account Name',
                    'optional' => true,
                ),
                'Opportunities:assigned_user_link' => array(
                    'name' => 'Opportunities  >  Assigned to User',
                    'parent' => 'self',
                    'link_def' => array(
                        'name' => 'assigned_user_link',
                        'relationship_name' => 'opportunities_assigned_user',
                        'bean_is_lhs' => false,
                        'link_type' => 'one',
                        'label' => 'Assigned to User',
                        'module' => 'Users',
                        'table_key' => 'Opportunities:assigned_user_link',
                    ),
                    'dependents' => array(),
                    'module' => 'Users',
                    'label' => 'Assigned to User',
                    'optional' => true,
                ),
            ),
            'filters_def' => array(
                'Filter_1' => array(
                    'operator' => 'AND',
                    // The whole report is this one line. 'auto' is written by
                    // BdQuoteReflectionHook::refreshGoverningOrigin() and is
                    // cleared the moment a person chooses a governing line, so
                    // a row LEAVING this report is the review actually
                    // happening.
                    '0' => array(
                        'name' => 'bd_governing_origin',
                        'table_key' => 'self',
                        'qualifier_name' => 'equals',
                        // The literal, NOT BdGoverningAutoSelect::ORIGIN_AUTO,
                        // so this file loads and can be inspected without
                        // dragging the selector in. The two are held together
                        // by an assertion in test_governing_report.py rather
                        // than by a require, which is the cheaper coupling.
                        'input_name0' => 'auto',
                    ),
                    // Closed deals are history, not a review queue.
                    '1' => array(
                        'name' => 'sales_stage',
                        'table_key' => 'self',
                        'qualifier_name' => 'not_one_of',
                        'input_name0' => array('Closed Won', 'Closed Lost'),
                    ),
                ),
            ),
        );
    }
}
