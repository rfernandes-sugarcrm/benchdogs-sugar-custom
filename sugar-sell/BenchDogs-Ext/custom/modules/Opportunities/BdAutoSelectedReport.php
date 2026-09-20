<?php

/**
 * RETIRED (G116). This class no longer BUILDS decision 72's review report. All
 * it does now is take the one a previous install created back off the
 * instance — on install and on uninstall alike.
 *
 * WHAT IT USED TO DO. It created one saved report,
 * "Opportunities Valued From an Auto-Selected Quote Line", filtering
 * Opportunities on `bd_governing_origin = 'auto'` and drawing that field as a
 * column, so a sales manager could see every deal still valued from a line
 * nobody had chosen.
 *
 * WHY IT CANNOT STAY. 🔒 1044 retired `bd_governing_origin`: both
 * custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php
 * and its en_us label file are stubs that declare NOTHING, and the two things
 * that ever wrote the value — BdGoverningAutoSelect and the one-off
 * bd_governing_backfill.php — went with the retired quote mirror (decisions
 * 901/903). A report whose filter column has no vardef DOES NOT ERROR. It
 * renders empty. On a review queue that reads as "nothing to review", which is
 * the worst available failure and precisely the trap release-0.9.42-rc24's
 * operator notes describe. scripts/pre_uninstall.php has removed this report
 * for that reason since rc26; what it did not do was stop scripts/post_install.php
 * CREATING it again on the next install.
 *
 * WHY THE CLASS IS NOT SIMPLY DELETED. The report is a Reports ROW, not a
 * file. Dropping the class from the build would leave the row on every tenant
 * that installed rc26..rc56 with nothing left to remove it — the same reason
 * a retired vardef is emptied rather than deleted (§CW / G37). The removal
 * has to keep shipping until it has run everywhere.
 *
 * WHAT IT WILL NOT TOUCH. It matches on the exact name below and nothing else,
 * so an admin who renamed the report, copied it or built their own keeps it;
 * and mark_deleted() is a soft delete, so even the matched row is recoverable.
 */
class BdAutoSelectedReport
{
    public const NAME = 'Opportunities Valued From an Auto-Selected Quote Line';

    /**
     * Take the report off the instance. Called from scripts/post_install.php
     * and from scripts/pre_uninstall.php; a no-op when the row is not there,
     * so re-installing does nothing at all after the first time.
     */
    public function remove(): void
    {
        $existing = $this->existing();
        if ($existing === null) {
            return;
        }
        $existing->mark_deleted($existing->id);
        $GLOBALS['log']->fatal('BenchDogs-Ext: removed retired saved report "' . self::NAME . '"');
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
}
