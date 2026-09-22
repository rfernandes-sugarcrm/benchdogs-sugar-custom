<?php

/**
 * RETIRED BY ONEOFF-RetireBdResidue 1.0.0.
 *
 * Whatever class or template used to be at this path was installed by an earlier
 * version of the Bench Dogs package (sugarai_benchdogs_ext) for a feature that no
 * longer exists. The registration that referred to it has been deleted from
 * custom/Extension/, so nothing loads this file any more.
 *
 * WHY THE FILE IS STILL HERE AT ALL. Module Loader copies and never deletes, and
 * unlink(), rmdir(), rmdir_recursive() and SugarAutoLoader::unlink() are all on
 * ModuleScanner's deny-list for packaged code (SugarEnt 26.1.0
 * ModuleInstall/ModuleScanner.php:106-218), so no package can remove a file at an
 * arbitrary path. Overwriting it with a body that declares nothing is the removal
 * the platform actually offers. It is deliberately NOT a stub of the old class:
 * a file that still declares the class would keep the retired feature loadable,
 * and a file that declares an EMPTY version of a class other code depends on is
 * the silent-failure shape 🔒 1508 and G280 rule out.
 *
 * DO NOT put anything back in this file. If a Bench Dogs feature needs to come
 * back, it comes back through a shipped package version, at a path that package
 * owns, with its own registration.
 */
