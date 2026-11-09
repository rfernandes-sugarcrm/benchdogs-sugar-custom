<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use BeanFactory;
use RuntimeException;
use SugarQuery;
use Throwable;
use VardefManager;

/** The one-time copy of each quote's own Project onto its lines that have none, run by the install that moves the Project onto the lines. */
class BdLineProject
{
    /** The quote line field, named as the quote's own. */
    public const FIELD = BdAdmRules::FIELD_PROJECT;

    /** Quotes read per page; every page also reads its quotes' lines in one query. */
    public const PAGE = 200;

    /** How many failed lines a report names (the count is always whole). */
    public const NAMED_FAILURES = 5;

    /**
     * Give each line whose Project is blank its quote's own Project, never over one it has; a second run changes nothing.
     * Written without hooks or a new date_modified, so nothing goes to Kinetic. A failed read throws; a failed write counts.
     * On a quotes table with no Project column yet no quote holds one: nothing to copy, and its reason in the report.
     * @return array{quotes: int, lines: int, filled: int, kept: int, failed: int, named: string[], nothing_to_copy: string}
     */
    public static function copyFromQuotes(): array
    {
        $defs = self::productDefs();
        $report = array('quotes' => 0, 'lines' => 0, 'filled' => 0, 'kept' => 0, 'failed' => 0, 'named' => array(),
            'nothing_to_copy' => '');
        if (self::quotesLackTheColumn()) {
            $report['nothing_to_copy'] = 'the quotes table has no ' . self::FIELD . ' column yet, so no quote holds a Project';

            return $report;
        }
        self::ensureColumn($defs);
        for ($offset = 0;; $offset += self::PAGE) {
            $projects = self::quoteProjects($offset);
            if ($projects === array()) {
                break;
            }
            $report['quotes'] += count($projects);
            foreach (self::linesOf(array_keys($projects)) as $line) {
                $report['lines']++;
                if (trim((string) ($line[self::FIELD] ?? '')) !== '') {
                    $report['kept']++;
                    continue;
                }
                $id = (string) ($line['id'] ?? '');
                try {
                    $GLOBALS['db']->updateParams(
                        'products',
                        $defs,
                        array(self::FIELD => $projects[(string) ($line['quote_id'] ?? '')]),
                        array('id' => $id)
                    );
                    $report['filled']++;
                } catch (Throwable $e) {
                    $report['failed']++;
                    if (count($report['named']) < self::NAMED_FAILURES) {
                        $report['named'][] = $id . ' (' . $e->getMessage() . ')';
                    }
                }
            }
            if (count($projects) < self::PAGE) {
                break;
            }
        }

        return $report;
    }

    /** The one line that says what the copy did, for the install log and its step report. */
    public static function summary(array $report): string
    {
        if ($report['nothing_to_copy'] !== '') {
            return 'nothing to copy: ' . $report['nothing_to_copy'];
        }
        $text = $report['quotes'] . ' quotes with a Project, ' . $report['lines'] . ' of their lines: ' . $report['filled']
            . ' filled, ' . $report['kept'] . ' kept (a Project of their own), ' . $report['failed'] . ' failed';

        return $report['named'] === array() ? $text : $text . ': ' . implode('; ', $report['named']);
    }

    /** The quoted line item's merged field definitions, this package's Project among them; throws when it is missing. */
    private static function productDefs(): array
    {
        VardefManager::loadVardef('Products', 'Product', true);
        $defs = $GLOBALS['dictionary']['Product']['fields'] ?? null;
        if (!is_array($defs) || !isset($defs['id']) || !is_array($defs[self::FIELD] ?? null)) {
            throw new RuntimeException('the quoted line item has no ' . self::FIELD . ' field (its vardef was not merged)');
        }

        return $defs;
    }

    /**
     * True only when the quotes table's column list names its id and not the Project: then no quote can hold one.
     * Module Loader adds the column only after the install script, so this is a Sugar that never had it. Any other
     * answer (an empty list, or none) is not taken as "no column": the quote read decides, and copies or throws.
     */
    private static function quotesLackTheColumn(): bool
    {
        $columns = $GLOBALS['db']->get_columns('quotes');

        return is_array($columns) && isset($columns['id']) && !isset($columns[self::FIELD]);
    }

    /** Module Loader adds the new column only after the install script, so the copy adds it first when it is missing. */
    private static function ensureColumn(array $defs): void
    {
        $columns = $GLOBALS['db']->get_columns('products');
        if (!is_array($columns)) {
            throw new RuntimeException('the products table could not be read');
        }
        if (!isset($columns[self::FIELD])) {
            $GLOBALS['db']->addColumn('products', $defs[self::FIELD]);
        }
    }

    /**
     * One page of quotes holding a Project, in id order: quote id => its Project.
     * @return array<string, string>
     */
    private static function quoteProjects(int $offset): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('Quotes'), array('team_security' => false));
        $query->select(array('id', self::FIELD));
        $query->where()->isNotEmpty(self::FIELD);
        $query->orderBy('id', 'ASC');
        $query->limit(self::PAGE);
        $query->offset($offset);
        $out = array();
        foreach ($query->execute() as $row) {
            $project = trim((string) ($row[self::FIELD] ?? ''));
            if ($project !== '') {
                $out[(string) $row['id']] = $project;
            }
        }

        return $out;
    }

    /**
     * The live lines of these quotes: id, quote_id and their own Project.
     * @param string[] $quoteIds
     * @return array[]
     */
    private static function linesOf(array $quoteIds): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('Products'), array('team_security' => false));
        $query->select(array('id', 'quote_id', self::FIELD));
        $query->where()->in('quote_id', $quoteIds);
        $query->orderBy('id', 'ASC');
        $rows = $query->execute();

        return is_array($rows) ? $rows : array();
    }
}
