# Removing the Bench Dogs Sugar package cleanly

Target: `SugarAI: Bench Dogs Extensions` (`sugarai_benchdogs_ext`), observed at
0.9.40, on `case592235a.sugarondemand.com`.

Goal: retire the `bd01_ERP_Quote` / `_Line` / `_Cost` reflection modules and run
quotes entirely on the core Sugar Quote object, without the collateral damage a
previous uninstall caused.

## First: which version are you removing?

This changes most of what follows, because the package now cleans up after
itself.

**0.9.40 or earlier** is what is installed today, and it has no uninstall
script at all. Every step below applies, including the manual cleanup at step 5
and the button restore at step 6.

**The fixed version** (`fix/clean-uninstall`, PR #1 on
`ophirsw76/benchdogs-sugar-custom`) adds `scripts/pre_uninstall.php` and
`scripts/post_uninstall.php`, which do steps 5 and 7 during the uninstall
itself. Steps 1 through 4 still apply. Step 8 still applies and matters more,
not less.

Step 6 is the exception, and it is worth understanding before you choose a
route. The fix works by having the INSTALL stash each button it takes off the
Quotes record view, so the uninstall can put it back. That only records what
was still on the view at install time. On this instance the ERP-Epicor buttons
were removed by 0.9.40 and are already gone, so installing the fixed version on
top captures nothing and its uninstall will have nothing to hand back. It says
so in the log rather than finishing quietly.

So: installing the fixed version first is still worth doing, because it repairs
the layout, the grid column and the dashboard tiles automatically and correctly.
It does not save you step 6. Expect to re-run ERP-Epicor's installer once,
either way.

## Why the last uninstall broke the instance (0.9.40 and earlier)

The manifest sets `is_uninstallable => true` but registers no `pre_uninstall` or
`post_uninstall` script, while the package's `post_execute`
(`scripts/post_install.php`) writes **deployed** metadata through
`DeployedMetaDataImplementation`. Those writes sit outside the package's own
`installdefs`, so Module Loader never reverts them.

Three things survive an uninstall:

1. **Orphaned field references.** The Quotes record view keeps the Bench Dogs
   panel listing `bd_erp_total`, `bd_erp_stage`, `bd_priced_at`,
   `bd_reason_code` and `bd_governing_line`. The Accounts record view keeps
   `bd_customer_group` and `bd_customer_group_code`. The uninstall drops those
   vardefs, so both views point at fields that no longer exist.
2. **Orphaned buttons.** `bd_send_estimating_button` on Quotes and
   `bd_create_opp_quote_button` on Accounts remain, bound to Sidecar field types
   whose JavaScript the uninstall removed.
3. **Deleted third-party buttons.** This is the one that does real harm.
   `BdQuotesLayoutExtensions::writeButtons()` removes buttons owned by
   ERP-Epicor from the deployed Quotes record view: `advanced_quote_button`,
   `create_erp_order_button` and `refresh_price_availability_button`. Nothing
   restores them. Uninstalling Bench Dogs leaves the product's own quote actions
   permanently missing, with nothing pointing back at the package that took
   them.

Relationships and subpanels are declared in `installdefs['relationships']`, so
Module Loader does reverse those. They are not part of the damage.

`BdQliColumnsLayout` ships an `uninstall()` for its quoted-line-items column,
but nothing ever calls it: there is no uninstall script to do so, and by the
time you would want it the class file is already gone.

## Order of operations

Do not start until you have a database and filesystem backup of the instance.

### 1. Stop the connector writing to the modules first

Remove the three quote handlers and their pipeline steps from
`connector_ext_benchdogs` and redeploy the extension, or disable the
`benchdogs` pipeline. If the sync runs against modules that are mid-removal it
will log write failures against records that no longer exist.

The handlers are `ErpQuoteHandler`, `ErpQuoteLineHandler` and
`ErpQuoteCostHandler`, registered in `connector_ext_benchdogs/app.py` and
scheduled by `BenchdogsQuotePipeline`.

### 2. Reconcile quotes the PHP hook materialized

`BdQuoteReflectionHook` materializes Kinetic-born quotes into native Sugar
Quotes and stamps `erp_display_sync_key`, the raw quote number. Core addresses
the native Quote by `erp_sync_key`, the scoped form such as `EPIC06__1186`.

Any quote carrying a display key with an empty scoped key is invisible to core,
which will create a second record for the same Epicor quote. Find them before
the switch and backfill the scoped key, or accept duplicates.

### 3. Capture the current layouts

Export the deployed Quotes and Accounts record view definitions before
uninstalling. If anything goes wrong, this is what lets you see what changed.

### 4. Uninstall through Module Loader

Choose to remove tables when prompted. The manifest sets
`remove_tables => 'prompt'`, and keeping them leaves three orphaned module
tables behind for no benefit.

### 5. Run the cleanup script

**Skip this if you uninstalled the fixed version.** Its `pre_uninstall` script
does the same work during the uninstall, from the same class definitions rather
than from a reconstruction of them.

Otherwise: `bd_uninstall_cleanup.php`, in this directory. Copy it to the Sugar
instance root, run it as the web-server user, and read the dry run before
applying.

```
php bd_uninstall_cleanup.php
php bd_uninstall_cleanup.php --apply
```

It removes the orphaned panel, fields, buttons and grid column described in
classes 1 and 2 above.

### 6. Restore the ERP-Epicor buttons

Required on this instance either way, for the reason given at the top: the
version that removed those buttons never recorded what they were, so nothing
can restore them from a stash that was never written.

Neither the cleanup script nor the package's own uninstall recreates them, and
that is deliberate. Reconstructing another package's button definitions by hand
is how you get a subtly wrong layout that looks right. Re-run ERP-Epicor's own
installer instead, which writes them from its own definitions in replace mode.

The uninstall logs an error naming all three buttons when it has no stash to
restore from, so this step is not something you have to remember unprompted.

### 7. Quick Repair and Rebuild

**Skip this if you uninstalled the fixed version.** Its `post_uninstall` script
runs the repair, rebuilds the relationship cache and the compiled
TableDictionary, and refreshes the metadata cache - in that order, and after
the files are gone, which is the part a manual repair at the wrong moment gets
wrong.

Otherwise: Admin, Repair, Quick Repair and Rebuild. Then hard-refresh a browser
session.

### 8. Verify

Do this one first if you uninstalled the fixed version, because it tells you
whether anything else in this list is even meaningful. Both uninstall scripts
log a line on entry:

```
grep 'BenchDogs-Ext: pre_uninstall running' sugarcrm.log
grep 'BenchDogs-Ext: post_uninstall running' sugarcrm.log
```

Both present means the cleanup ran. Either missing means Module Loader did not
call the script - the installdef is wired wrong - and the uninstall completed
looking clean while doing none of the cleanup. That failure is invisible from
the UI, which is exactly why the line is there. If it is missing, fall back to
steps 5 and 7 as written for 0.9.40 and treat the wiring as broken.

Then:

- A Quote record view opens without error and shows no Bench Dogs panel.
- The ERP-Epicor quote buttons are back.
- An Account record view opens and shows no Bench Dogs button.
- The quoted line items grid renders with no missing column.
- A forward sync run delivers quotes to the native Quotes module with no
  duplicate records for one Epicor quote number.

## What you lose, and where it has to be rebuilt

Verified by searching core and the Epicor connector package. Neither contains
any matching code for the first four.

- **Revenue line items on the Opportunity.** The reflection hook materializes
  and maintains them. There is no revenue line item code in core at all. This is
  the largest gap and has no replacement.
- **Customer group on the Account.** Nothing in core maps it.
- **Shipped value** on the order mirror modules. Nothing in core computes it.
- **Quote comment append from Sugar to Epicor.** Core writes the Epicor quote
  comment field, but only to stamp its own deep-link marker. There is no path
  for operator-authored text.
- **The estimating notification.** It fires on an open-quote stage transition.
  Core deliberately stamps nothing while a quote is open, because the Epicor
  lead and quote progression does not map onto Sugar's draft and negotiation
  stages. Core carries the closure reason, not the open progression, so this
  cannot simply be rewired to the native quote stage.

## What core already covers

- The quote header is the native Quote, upserted by `erp_sync_key`.
- A quote line is a Products row on that Quote.
- The Epicor quantity rung's cost worksheet lands on the native line's
  `cost_price`. Core added that field specifically so the cost child module
  would not be needed.
- Quote closure drives `quote_stage`, from the Epicor quote's own closed flag
  and reason type.
