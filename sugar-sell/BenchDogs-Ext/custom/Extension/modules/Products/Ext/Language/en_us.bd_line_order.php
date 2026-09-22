<?php

/**
 * EMPTIED IN 0.9.42-rc65 — G280 / 🔒 1507.
 *
 * LBL_BD_TO_ORDER ("To Order") and LBL_BD_ORDERED ("Ordered") labelled the
 * quote-line columns bd_to_order and bd_ordered. Both vardefs are stubs that
 * declare nothing. A label is the last thing that keeps a retired field looking
 * supported: Sugar resolves mod_strings independently of vardefs, so Studio,
 * the report builder's field chooser and every column picker kept offering
 * "To Order" and "Ordered" for columns nothing writes and nothing shows.
 *
 * 📌 THE DEPLOYED-METADATA SWEEP THAT USED TO BACK THIS IS GONE (0.9.42-rc66,
 * G280 / 🔒 1508). `BdQliColumnsLayout::bdLegacyColumnNames()` named these two
 * columns and removed them from the deployed quoted-lines grid on every
 * install; the class is deleted with the rest of the grid logic. That sweep
 * writes to DEPLOYED METADATA, which persists once written, and it has run on
 * every install since 0.9.21 - through rc65 on the only tenant carrying this
 * package (stock has no Bench Dogs by design). It was armed for instances that
 * ran 0.9.17/0.9.19 and it has fired on the one that did. THIS FILE IS STILL
 * THE RETIREMENT for the label half, and it keeps shipping emptied.
 *
 * LBL_BD_ERP_LINE_NUM was retired earlier with Product.bd_erp_line_num
 * (🔒 1032); the grid uses ERP-Core's LBL_ERP_QUOTE_LINE_NUM.
 *
 * EMPTIED, NOT DROPPED (§CW / G37): on Sugar Cloud a file a previous install
 * copied is not removed by leaving it out of a later build. Only overwriting
 * retires it, so this file keeps shipping with nothing in it.
 */
