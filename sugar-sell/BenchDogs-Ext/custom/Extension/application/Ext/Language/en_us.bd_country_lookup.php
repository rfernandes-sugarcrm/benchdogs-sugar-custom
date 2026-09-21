<?php

/**
 * RETIRED (G50). This file intentionally defines no labels.
 *
 * This is the ORIGINAL path of the bd_country type label. Up to rc22 it set
 *
 *   $app_list_strings['erp_lookup_type_list']['bd_country'] = 'Country (Bench Dogs)';
 *
 * rc23 renamed it to _override_en_us.bd_country_lookup.php and rc57 emptied
 * that one. Neither touched THIS path, so every tenant that installed rc22 or
 * earlier still has the original copy (§CW / G37: a file dropped from the
 * build is left in place; only overwriting the same path retires it).
 *
 * It was harmless while ERP-Epicor assigned erp_lookup_type_list as a whole
 * array, because that assignment merged after this file and wiped the key.
 * ERP-Core now adds its types key by key, so nothing wipes it and the label
 * came back. Seen live on Bench 2026-09-21 under rc58: 'bd_country' is the
 * FIRST key of erp_lookup_type_list, ahead of ERP-Core's '' entry. The
 * _override_ file always merges last, so the key order is what showed the
 * label comes from this earlier-sorting path, not the rc57 stub.
 *
 * Safe to delete this stub once every instance has taken a release at or
 * after this one.
 */
