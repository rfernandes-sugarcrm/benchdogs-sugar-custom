<?php

/**
 * ONE-OFF, AND IT DELETES DATA. Drops the Bench Dogs quote-mirror tables.
 *
 * This is the last layer of retiring the mirror, and the only irreversible
 * one. Everything else - the orphaned relationship definitions, the leftover
 * extension files, the module registration itself - is metadata, and
 * ONEOFF-RetireBdQuoteMirror handles all of it without losing a row. This
 * drops the tables the three mirror modules stored their rows in, and the rows
 * with them. There is no undo. Take a database backup first.
 *
 * RUN ONEOFF-RetireBdQuoteMirror FIRST. Dropping these tables while the
 * relationship definitions are still on disk leaves Sugar holding definitions
 * that point at tables which no longer exist, which is a worse state than
 * either end of the job: the mirror is still registered, still linked, and now
 * every read of it errors. The guard below refuses to run in that case.
 *
 * DISPOSABLE, LIKE ITS SIBLING. No copy installdef, so nothing lands in the
 * instance and uninstalling is a genuine no-op. Install it, let it run,
 * uninstall it.
 *
 * WHY THE TABLES ARE NAMED HERE RATHER THAN DISCOVERED. A pattern would be
 * actively dangerous on this schema. Every name below begins with the string
 * `bd01_erp_quote`, including the two sibling modules and all four join
 * tables, so a LIKE match written for the header table sweeps up the line and
 * cost tables too - and a slightly looser one written for `bd_` would reach
 * `quotes_cstm` and `products_cstm`, where the Bench Dogs fields on the LIVE
 * native modules live. Those columns are the whole point of the migration. So
 * the list is explicit, every entry is checked against the schema before it is
 * touched, and anything not on the list is safe by construction.
 *
 * WHY NOT ModuleInstaller::uninstall_beans(). It is the right call in general
 * and it does nothing here: it starts with BeanFactory::newBean($module) and
 * returns early when that yields nothing, which is precisely the case for a
 * module that has already been deregistered. These three were deregistered
 * before this package ran - the guard below insists on it.
 *
 * EVERY DROP IS LOGGED WITH ITS ROW COUNT FIRST, so the log records what was
 * destroyed rather than merely that something was.
 *
 * IDEMPOTENT. A second run finds nothing and drops nothing.
 */

/**
 * THE ORDERING GUARD.
 *
 * Same reasoning and same signal as ONEOFF-RetireBdQuoteMirror: an object back
 * from BeanFactory::newBean() means the module is still registered, which means
 * a BenchDogs-Ext build that declares the mirror is still installed and its
 * relationship definitions are still on disk. Dropping the tables now would
 * leave live definitions pointing at nothing.
 *
 * This guard is strictly stronger than the sibling's, because this package
 * cannot be re-run to fix a half-state. Refusing is always the cheaper error.
 */
$bdMirrorModules = array(
    'bd01_ERP_Quote',
    'bd01_ERP_Quote_Line',
    'bd01_ERP_Quote_Cost',
);

$bdStillRegistered = array();
foreach ($bdMirrorModules as $bdModule) {
    try {
        $bdBean = BeanFactory::newBean($bdModule);
    } catch (Throwable $e) {
        $bdBean = null;
    }
    if (!empty($bdBean)) {
        $bdStillRegistered[] = $bdModule;
    }
}

if ($bdStillRegistered) {
    $GLOBALS['log']->fatal(sprintf(
        'DropBdQuoteMirrorTables: REFUSING TO RUN. %s still registered on this instance. '
            . 'Dropping these tables now would leave live relationship definitions pointing at '
            . 'tables that no longer exist. Install a BenchDogs-Ext build that no longer ships '
            . 'the mirror, then run ONEOFF-RetireBdQuoteMirror, then run this. '
            . 'Nothing was dropped.',
        implode(', ', $bdStillRegistered)
    ));

    return;
}

/**
 * A second guard, on this package's own predecessor rather than on the module
 * registration. If any of the four relationship definition files is still on
 * disk, ONEOFF-RetireBdQuoteMirror has not run, and dropping the join tables
 * out from under a live definition is exactly the bad middle state described
 * at the top. file_exists is not on the scanner's deny-list; scandir, is_dir,
 * unlink and rmdir are, which is why nothing here walks a directory.
 */
$bdMirrorRelationships = array(
    'bd01_erp_quote_quotes',
    'bd01_erp_quote_accounts',
    'bd01_erp_quote_lines',
    'bd01_erp_line_costs',
);

$bdDefinitionsLeft = array();
foreach ($bdMirrorRelationships as $bdRelName) {
    if (file_exists('custom/metadata/' . $bdRelName . 'MetaData.php')) {
        $bdDefinitionsLeft[] = $bdRelName;
    }
}

if ($bdDefinitionsLeft) {
    $GLOBALS['log']->fatal(sprintf(
        'DropBdQuoteMirrorTables: REFUSING TO RUN. Relationship definition(s) still on disk: %s. '
            . 'That means ONEOFF-RetireBdQuoteMirror has not run yet. Run it first, then run this. '
            . 'Nothing was dropped.',
        implode(', ', $bdDefinitionsLeft)
    ));

    return;
}

/**
 * The ten tables, named explicitly. Three module tables, their three
 * custom-field siblings, and the four join tables of the four relationships
 * the sibling package retired.
 */
$bdDropCandidates = array(
    // The retired modules' own tables, and their custom-field siblings.
    'bd01_erp_quote',
    'bd01_erp_quote_cstm',
    'bd01_erp_quote_line',
    'bd01_erp_quote_line_cstm',
    'bd01_erp_quote_cost',
    'bd01_erp_quote_cost_cstm',

    // The join tables of the four retired relationships, and only those four.
    'bd01_erp_quote_quotes_c',
    'bd01_erp_quote_accounts_c',
    'bd01_erp_quote_lines_c',
    'bd01_erp_line_costs_c',
);

/**
 * Tables that hold Bench Dogs data and must survive, listed so the next reader
 * can see they were considered rather than missed, and asserted below so a
 * careless edit to the list above cannot reach them.
 *
 * Every one of these is a NATIVE Sugar table whose _cstm sibling carries the
 * bd_ custom fields. They are where the business process lands after the
 * mirror is gone. Dropping one would destroy live customer data and take the
 * module's record view with it.
 */
$bdProtected = array(
    'quotes_cstm',
    'products_cstm',
    'accounts_cstm',
    'contacts_cstm',
    'opportunities_cstm',
    'revenue_line_items_cstm',
);

$bdDb = DBManagerFactory::getInstance();
$bdConn = DBManagerFactory::getConnection();

$bdDropped = array();
$bdAbsent = array();
$bdRefused = array();

foreach ($bdDropCandidates as $bdTable) {
    // Belt and braces one: nothing on the protected list is droppable, whatever
    // the candidate list says.
    if (in_array($bdTable, $bdProtected, true)) {
        $bdRefused[] = $bdTable;
        continue;
    }

    // Belt and braces two, and the stronger of the pair: every table this
    // package may drop belongs to the mirror, and every mirror table is named
    // with the module prefix. A name without it is not ours to drop, however it
    // got onto the list.
    if (strpos($bdTable, 'bd01_') !== 0) {
        $bdRefused[] = $bdTable;
        continue;
    }

    if (!$bdDb->tableExists($bdTable)) {
        $bdAbsent[] = $bdTable;
        continue;
    }

    // Count before dropping, so the log says what was lost. The name comes from
    // the literal list above and never from input, which is what makes it safe
    // to interpolate where a bound parameter cannot go.
    $bdRows = '?';
    try {
        // mlp-lint: ignore MLP005 - table names are identifiers and cannot be
        // bound; every name here is a literal from $bdDropCandidates.
        $bdRows = (string) $bdConn->fetchOne('SELECT COUNT(*) FROM ' . $bdTable);
    } catch (Throwable $e) {
        $bdRows = 'uncounted (' . $e->getMessage() . ')';
    }

    $GLOBALS['log']->fatal(sprintf(
        'DropBdQuoteMirrorTables: dropping %s (%s row(s))',
        $bdTable,
        $bdRows
    ));

    // mlp-lint: ignore MLP005 - see above; identifier, not a value.
    $bdConn->executeStatement('DROP TABLE IF EXISTS ' . $bdTable);
    $bdDropped[] = $bdTable . ' (' . $bdRows . ' rows)';
}

$GLOBALS['log']->fatal(sprintf(
    'DropBdQuoteMirrorTables: dropped %d table(s)%s; %d already absent%s; %d refused%s. '
        . 'Protected and untouched: %s. The Bench Dogs quote mirror is now fully retired. '
        . 'This package can be uninstalled immediately; it left nothing behind.',
    count($bdDropped),
    $bdDropped ? ' - ' . implode(', ', $bdDropped) : '',
    count($bdAbsent),
    $bdAbsent ? ' (' . implode(', ', $bdAbsent) . ')' : '',
    count($bdRefused),
    $bdRefused ? ' (' . implode(', ', $bdRefused) . ')' : '',
    implode(', ', $bdProtected)
));
