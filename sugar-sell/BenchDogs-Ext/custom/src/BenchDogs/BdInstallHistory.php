<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use BeanFactory;
use SugarQuery;
use UpgradeHistory;

/** What Module Loader's own history says about this package: the one-time steps of an upgrade are decided from it, with no setting of our own. */
class BdInstallHistory
{
    /** This package's Module Loader id. A copy of pack.php's $packageID (the builder is not shipped); a test holds the two equal. */
    public const PACKAGE_ID = 'sugarai_benchdogs_ext';

    /** The release whose install moves the quote's Project onto its lines and makes Needs Price Estimate the default. */
    public const LINE_PROJECT_RELEASE = '0.9.46-rc117';

    /**
     * The versions of this package Module Loader holds as installed. During an install the new version's row is still
     * staged and the one it replaces still installed, so this is what the tenant had before. A failed read throws.
     * @return string[]
     */
    public static function installedVersions(): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('UpgradeHistory'), array('team_security' => false));
        $query->select(array('version'));
        $query->where()->equals('id_name', self::PACKAGE_ID)->equals('status', UpgradeHistory::STATUS_INSTALLED);
        $versions = array();
        foreach ($query->execute() as $row) {
            $versions[] = trim((string) ($row['version'] ?? ''));
        }

        return $versions;
    }

    /** True when none of $installed is $release or later: this install is the first at $release (an upgrade from below it, or a fresh install). */
    public static function firstInstallOf(string $release, array $installed): bool
    {
        foreach ($installed as $version) {
            if ($version !== '' && version_compare(ltrim($version, 'v'), $release, '>=')) {
                return false;
            }
        }

        return true;
    }
}
