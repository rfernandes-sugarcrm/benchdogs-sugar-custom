<?php

/**
 * EMPTIED IN 0.9.42-rc65 — G280 / 🔒 1507.
 *
 * LBL_BD_TO_ORDER ("To Order") and LBL_BD_ORDERED ("Ordered") labelled the
 * quote-line columns bd_to_order and bd_ordered. Both vardefs are stubs that
 * declare nothing, and BdQliColumnsLayout REMOVES both columns from the
 * deployed grid on every install (bdLegacyColumnNames()). A label is the last
 * thing that keeps a retired field looking supported: Sugar resolves
 * mod_strings independently of vardefs, so Studio, the report builder's field
 * chooser and every column picker kept offering "To Order" and "Ordered" for
 * columns nothing writes and nothing shows.
 *
 * 🛑 THE TWO NAMES STAY IN bdLegacyColumnNames(). That list is the REMOVAL's
 * input - the removal has to name what it removes - and deleting them there
 * would quietly turn the sweep into a no-op on every tenant that still has the
 * columns deployed.
 *
 * LBL_BD_ERP_LINE_NUM was retired earlier with Product.bd_erp_line_num
 * (🔒 1032); the grid uses ERP-Core's LBL_ERP_QUOTE_LINE_NUM.
 *
 * EMPTIED, NOT DROPPED (§CW / G37): on Sugar Cloud a file a previous install
 * copied is not removed by leaving it out of a later build. Only overwriting
 * retires it, so this file keeps shipping with nothing in it.
 */
