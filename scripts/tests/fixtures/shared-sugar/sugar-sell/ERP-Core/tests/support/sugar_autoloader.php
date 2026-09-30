<?php

/**
 * Test-only stand-in for SugarAutoLoader (SugarEnt 26.1.0 include/utils/autoloader.php),
 * limited to the rules package code relies on:
 *   Sugarcrm\Sugarcrm\custom\X\Y -> custom/src/X/Y.php (PSR-4, vendor/composer/autoload_psr4.php)
 *   SugarACLFoo                  -> custom/data/acl/SugarACLFoo.php ($prefixMap)
 *   Foo                          -> custom/include/Foo.php, custom/clients/base/api/Foo.php ($dirMap)
 * A candidate is looked up the way a harness's Sugar root is set up (cwd and include_path),
 * then in the repository's packages, ERP-Epicor before ERP-Core (the overlay wins).
 */
// Harness code requires the other test stubs relative to this, never by a machine path.
if (!defined('ERP_TEST_SUPPORT')) {
    define('ERP_TEST_SUPPORT', __DIR__);
}

/** The file SugarAutoLoader would load for $class, or null. */
function erp_test_class_file(string $class, ?array $customRoots = null): ?string
{
    $customRoots = $customRoots ?? $GLOBALS['erp_test_custom_roots'] ?? array();
    $class = ltrim($class, '\\');
    $prefix = 'Sugarcrm\\Sugarcrm\\custom\\';
    if (strpos($class, $prefix) === 0) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $candidates = array("src/$relative.php");
    } elseif (strncmp($class, 'SugarACL', 8) === 0) {
        $candidates = array("data/acl/$class.php");
    } else {
        $candidates = array("include/$class.php", "clients/base/api/$class.php");
    }
    foreach ($candidates as $candidate) {
        $found = stream_resolve_include_path("custom/$candidate");
        if ($found !== false) {
            return $found;
        }
    }
    foreach ($customRoots as $root) {
        foreach ($candidates as $candidate) {
            if (is_file("$root/$candidate")) {
                return "$root/$candidate";
            }
        }
    }
    return null;
}

function erp_test_register_autoloader(array $customRoots): void
{
    $GLOBALS['erp_test_custom_roots'] = $customRoots;
    spl_autoload_register(function (string $class) use ($customRoots): void {
        $file = erp_test_class_file($class, $customRoots);
        if ($file !== null) {
            require_once $file;
        }
    });
}

erp_test_register_autoloader(array_values(array_filter(array(
    __DIR__ . '/../../../ERP-Epicor/src/custom',
    __DIR__ . '/../../src/custom',
), 'is_dir')));
