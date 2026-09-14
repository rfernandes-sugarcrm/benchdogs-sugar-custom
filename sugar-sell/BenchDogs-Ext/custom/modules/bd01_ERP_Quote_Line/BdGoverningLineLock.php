<?php

/**
 * Cross-process mutual exclusion for the governing-line selection of ONE
 * bd01_ERP_Quote (Bench Dogs D29-R1, decision 29).
 *
 * Two estimators selecting different quantity breaks at the same moment are
 * two PHP processes. Sugar commits each row in SugarBean::save() BEFORE
 * after_save fires, so by the time either enforcement hook runs, both rows
 * already carry governing = 1 and each process still holds an in-memory copy
 * saying so. Each hook then demotes the other's line and the quote lands on
 * ZERO selected - measured live on 2026-09-14 in 2 of 3 clean trials
 * (journal D29-LIVE-2). A per-quote critical section is what makes the pair
 * serialisable.
 *
 * The store is Sugar's own `system_process_lock` table, through the platform
 * class that owns it. That table ships with stock Sugar (verified present in
 * SugarEnt-Full 25.2.0 and 26.1.0), so this adds no schema of its own and
 * needs no layout, dropdown or post_execute step to become live: it travels
 * with the package's custom/ copy entries like every other class here.
 *
 * DELIBERATELY NOT a Sugar row lock or a transaction: the rows being
 * serialised belong to several records, the section must survive a bean save
 * that fires further hooks, and SugarCloud's connection is shared.
 */
class BdGoverningLineLock
{
    /**
     * Namespaced platform class. Probed by name so this file can be read and
     * unit-tested outside a Sugar instance, then instantiated LONGHAND below:
     * ModuleScanner rejects `new $variable()` outright and one occurrence
     * fails the whole package upload.
     */
    private const STORE_CLASS = 'Sugarcrm\\Sugarcrm\\SystemProcessLock\\DbImplementation';

    /** One lock family; the quote id is the additional key. */
    private const UNIQUE_ID = 'bd_governing_line';

    /**
     * How long a lock row stays valid if its holder dies. The section itself
     * is a handful of bean saves, so anything past this is a dead worker.
     */
    private const EXPIRY_SECONDS = 30;

    /** Bounded wait: two people clicking, not a batch job. */
    private const ATTEMPTS = 40;
    private const WAIT_MICROSECONDS = 25000; // 40 x 25ms = 1s worst case

    /** @var object|null platform store, null when it cannot be resolved */
    private $store;

    private string $key = '';
    private bool $held = false;

    public function __construct($store = null)
    {
        if ($store !== null) {
            $this->store = $store;
            return;
        }
        if (class_exists(self::STORE_CLASS)) {
            $this->store = new \Sugarcrm\Sugarcrm\SystemProcessLock\DbImplementation();
        }
    }

    /**
     * Whether exclusion is possible AT ALL on this instance. False means the
     * platform class or its table is missing - a different situation from a
     * busy quote, and the caller must treat it differently.
     */
    public function isAvailable(): bool
    {
        return $this->store !== null && !empty($this->store->isAvailable);
    }

    /**
     * Take the lock for one ERP quote. False means another writer holds it and
     * did not finish inside the bounded wait.
     */
    public function acquire(string $key): bool
    {
        if (!$this->isAvailable() || $key === '') {
            return false;
        }
        $this->key = $key;
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            if ($this->store->lock(self::UNIQUE_ID, $key, self::EXPIRY_SECONDS)) {
                $this->held = true;
                return true;
            }
            if ($attempt === 0) {
                // A worker that died mid-selection leaves its row behind. The
                // platform reaps by the expiry stamp it wrote. Done once, not
                // on every spin: a contended quote must not become a storm of
                // DELETE statements.
                $this->store->processTimedOutLocks();
                continue;
            }
            usleep(self::WAIT_MICROSECONDS);
        }
        return false;
    }

    /**
     * Safe to call whether or not the lock was taken, and safe to call twice.
     */
    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        $this->store->unlock(self::UNIQUE_ID, $this->key);
    }
}
