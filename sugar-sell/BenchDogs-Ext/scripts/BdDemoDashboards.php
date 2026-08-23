<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Pins the demo dashboards: the Accounts focus drawer and the Home dashboard.
 *
 * Without this a fresh install leaves both as whatever the stock template says,
 * and the Bench Dogs demo has to be rebuilt by hand in the UI, tile by tile.
 * The layouts baked in below are the ones captured from the QA cube
 * (sugar-sell/docs/bake_accounts_focus.json, bake_home.json); `users` and
 * `runtimeFilterOperators` from that capture are runtime injections and are
 * deliberately NOT baked.
 *
 * WHY A BENCH DOGS PACKAGE CARRIES SOMEBODY ELSE'S TILES
 *
 * The captured drawer's eight tiles are defined by four other packages -
 * ERP-Core (account-card, erp-account-snapshot), the BAQ dashlet (epicor-baq),
 * Account Hierarchy (saah-*) and sales-i (recommendations-dashlet, gai-dashlet),
 * with Sales-Targets owning sales-targets-chart on Home. No package can honestly
 * claim the composed layout, and a dashlet whose view type is not registered
 * renders as a BROKEN TILE rather than being ignored - which on a demo recording
 * is worse than a missing one.
 *
 * So this reproduces the captured layout where it can and degrades where it
 * cannot: isViewTypeRegistered() writes a tile only when its view files are
 * actually on disk, reflow() closes the holes the skips leave, and the Bench
 * Dogs tiles at the end of each spec fill the space with content this package
 * genuinely owns. On an instance carrying only ERP-Core/ERP-Epicor and this
 * package the drawer comes out shorter and coherent instead of broken, and every
 * skip is logged so a missing package looks like a missing package rather than
 * like this never ran.
 *
 * WHY IT EDITS RATHER THAN CREATES
 *
 * Both targets already exist - the Accounts focus drawer is Sugar's own OOB
 * dashboard and LBL_HOME_DASHBOARD ships with the product. Creating rows with
 * our own ids would leave TWO default dashboards for the same view, which is a
 * worse bug than an unpinned layout. So this finds the existing default and
 * rewrites its dashlet list; if it cannot find one it logs and does nothing
 * rather than inventing one.
 *
 * That is also why this package needs no pre_execute counterpart to ERP-Core's
 * ErpDashboardReconcile: that pair exists to protect rows that
 * install_dashboards() INSERTS by preset id (a soft-deleted row holding the same
 * id kills the reinstall on a duplicate primary key). This writes no rows, so
 * there is no primary key to collide with. What it does take from that same
 * lesson is the part that applies: a stable hardcoded id per tile, because
 * without one there is nothing to match on and every install appends another
 * copy. Never regenerate the ids below.
 *
 * WHAT "OWNING" A TILE MEANS, AND WHERE IT STOPS
 *
 * Ids alone only recognise tiles THIS script wrote before, so a dashboard that
 * already carries an equivalent tile from somewhere else - a hand-built layout,
 * another package's installer - keeps it and gets ours appended beside it. For
 * the captured third-party tiles this spec therefore also owns their view TYPE:
 * existing tiles of those types are displaced, ours are written, everything else
 * is left completely alone.
 *
 * The Bench Dogs filler tiles deliberately do NOT own their type ('own_type' =>
 * false). They are stock `dashablelist` tiles, and a user's own list dashlet is
 * the same type - owning it would silently delete their work. Those tiles are
 * matched by id only, which is enough to keep a second install from duplicating
 * them.
 *
 * Safe on both counts because these are default TEMPLATES: a user who has
 * rearranged their own drawer has a separate per-user Dashboards row that this
 * never touches.
 *
 * INSTALL ORDER
 *
 * If ERP-Epicor's ErpDemoDashboards is also installed, both scripts pin the same
 * two dashboards and the last one to run wins for the tiles they share. They
 * agree on the shared tiles' ids and positions, so the result converges either
 * way; install this package last for the Bench Dogs filler tiles to survive.
 */
class BdDemoDashboards
{
    /**
     * The baked layouts. Grid geometry is the flat `dashlets` form Sugar
     * actually stores for these two dashboards - x/y/width/height plus a
     * per-tile id - not the older components[]/rows[][] shape that
     * modules/<M>/dashboards/*-dashboard.php files use.
     *
     * Tiles are listed in reading order; the x/y written to the database come
     * from reflow(), not from the values here, so a skipped tile closes up
     * instead of leaving a hole. Width still matters: it drives the pairing.
     */
    private const DASHBOARDS = [
    // Accounts / focus
    [
        'name' => 'LBL_ACCOUNTS_FOCUS_DRAWER_DASHBOARD',
        'dashboard_module' => 'Accounts',
        'view_name' => 'focus',
        'extra_metadata' => [],
        'dashlets' => [
            [
                'id' => '1ef679bb-a97e-41d5-a665-7a6088093488',
                'width' => 6,
                'height' => 6,
                'view' => [
                    'label' => 'Account Card',
                    'type' => 'account-card',
                    'module' => 'Accounts',
                    'templateEdit' => 'edit',
                ],
                'context' => [
                    'module' => 'Accounts',
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => 'e2e2fb34-8b1e-4227-992a-fc7ad2b8dba6',
                'width' => 6,
                'height' => 6,
                'view' => [
                    'label' => 'ERP Snapshot',
                    'type' => 'erp-account-snapshot',
                    'module' => 'Accounts',
                    'templateEdit' => 'edit',
                ],
                'context' => [
                    'module' => 'Accounts',
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => '87b6ac4c-eb5e-4cda-ac73-6b75820ec498',
                'width' => 6,
                'height' => 6,
                'view' => [
                    'type' => 'epicor-baq',
                    'label' => 'ERP Unpaid Invoices',
                    'query_id' => 'UnpaidInvoicesByCustomer',
                    'param_map' => 'AccountID:erp_account_id',
                    'company_field' => '',
                    'cross_company' => false,
                    'max_records' => 100,
                    'column_labels' => 'InvcHead_InvoiceNum::Invoice|InvcHead_InvoiceDate::Inv Date|InvcHead_DueDate::Due Date|InvcHead_DocInvoiceAmt::Amount|InvcHead_DocInvoiceBal::Balance',
                    'link_template' => 'InvcHead_InvoiceNum::{base_url}/apps/erp/home/#/view/ARGL3007?channelid={uuid}&company={company}&site=MfgSys&pageId=Details&KeyFields.InvoiceNum={InvcHead_InvoiceNum}',
                    'disable_hyperlinks' => false,
                    'footer_label' => '',
                    'footer_link' => '',
                    'module' => 'Accounts',
                    'skipFetch' => true,
                    'templateEdit' => 'edit',
                    'link' => null,
                    'default_currency' => '',
                    'currency_columns' => 'InvcHead_DocInvoiceAmt|InvcHead_DocInvoiceBal',
                ],
                'context' => [
                    'module' => 'Accounts',
                    'link' => null,
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => 'd29c9e1f-cbed-4636-8da8-f85de79e75db',
                'width' => 6,
                'height' => 6,
                'view' => [
                    'query_id' => 'OpenCasesByCustomer',
                    'param_map' => 'AccountID:erp_account_id',
                    'company_field' => '',
                    'cross_company' => false,
                    'max_records' => 100,
                    'link_template' => 'HDCase_HDCaseNum::{base_url}/apps/erp/home/#/view/HDMN2100?channelid={uuid}&company={company}&site=MfgSys&pageId=Details&KeyFields.HDCaseNum={HDCase_HDCaseNum}',
                    'disable_hyperlinks' => false,
                    'column_labels' => 'HDCase_HDCaseNum::Case Number|HDCase_HDCaseStatus::Status|HDCase_PartNum::Part|HDCase_CaseOwner::Owner',
                    'footer_label' => 'Create Case in Epicor',
                    'footer_link' => '{base_url}/apps/erp/home/#/view/HDMN2100?channelid={uuid}&company={company}&site=MfgSys&pageId=Details&KeyFields.HDCaseNum=0',
                    'label' => 'ERP Open Cases',
                    'type' => 'epicor-baq',
                    'module' => 'Accounts',
                    'templateEdit' => 'edit',
                    'skipFetch' => true,
                ],
                'context' => [
                    'module' => 'Accounts',
                    'link' => null,
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => 'b81001b8-abc6-4a38-984e-7389f774caf3',
                'width' => 6,
                'height' => 6,
                'view' => [
                    'label' => 'Account Hierarchy',
                    'type' => 'saah-dashlet',
                    'module' => 'Accounts',
                    'templateEdit' => 'edit',
                ],
                'context' => [
                    'module' => 'Accounts',
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => '0df1accf-f224-4fe3-8e7e-1c3db78e5bb8',
                'width' => 6,
                'height' => 6,
                'view' => [
                    'label' => 'Account Hierarchy Rollup',
                    'type' => 'saah-rollup',
                    'module' => 'Accounts',
                    'templateEdit' => 'edit',
                ],
                'context' => [
                    'module' => 'Accounts',
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => 'cfc440c7-5c2d-416f-9c8d-f38ed79357e3',
                'width' => 12,
                'height' => 5,
                'view' => [
                    'orderBy' => [
                        'field' => 'status',
                        'direction' => 'desc',
                    ],
                    'module' => 'Recommendation',
                    'display_columns' => [
                        'name',
                        'parent_name',
                        'type',
                    ],
                    'label' => 'Recommendations',
                    'type' => 'recommendations-dashlet',
                    'last_state' => [
                        'id' => 'dashable-list',
                    ],
                    'intelligent' => true,
                    'linked_fields' => 'recommendations_details',
                    'limit' => 5,
                    'filter_id' => '95cd1762-2be7-11f0-a6b0-0242ac110003',
                    'freeze_first_column' => true,
                    'templateEdit' => 'edit',
                    'skipFetch' => true,
                ],
                'context' => [
                    'module' => 'Recommendation',
                    'link' => null,
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => '4bb119e2-5c2a-489b-bac8-0fa1dc2fe497',
                'width' => 12,
                'height' => 6,
                'view' => [
                    'label' => 'AI Summary',
                    'type' => 'gai-dashlet',
                    'module' => 'Accounts',
                    'templateEdit' => 'edit',
                ],
                'context' => [
                    'module' => 'Accounts',
                    'skipFetch' => true,
                ],
            ],
            // --- Bench Dogs content ---
            //
            // Reached through the stock `quotes` link on Accounts, which is the
            // only route from an Account to Bench Dogs data: bd01_ERP_Quote hangs
            // off Quotes (bd01_erp_quote_quotes), not off Accounts, so a tile
            // pointed straight at bd01_ERP_Quote has nothing to filter by here.
            // The bd_* fields are this package's own reflection of the ERP quote,
            // so the Epicor total and stage show on the account either way.
            [
                'id' => 'bd010001-4d1a-4a4c-9a7f-0d3b6f5b9101',
                'own_type' => false,
                'width' => 12,
                'height' => 6,
                'view' => [
                    'label' => 'ERP Quote Pipeline',
                    'type' => 'dashablelist',
                    'module' => 'Quotes',
                    'link' => 'quotes',
                    'display_columns' => [
                        'name',
                        'quote_num',
                        'bd_erp_stage',
                        'bd_erp_total',
                        'bd_governing_line',
                        'date_modified',
                    ],
                    'orderBy' => [
                        'field' => 'date_modified',
                        'direction' => 'desc',
                    ],
                    'last_state' => [
                        'id' => 'dashable-list',
                    ],
                    'limit' => 10,
                    'freeze_first_column' => true,
                    'templateEdit' => 'edit',
                    'skipFetch' => true,
                ],
                'context' => [
                    'module' => 'Quotes',
                    'link' => 'quotes',
                    'skipFetch' => true,
                ],
            ],
        ],
    ],
    // Home / home
    [
        'name' => 'LBL_HOME_DASHBOARD',
        'dashboard_module' => 'Home',
        'view_name' => 'home',
        'extra_metadata' => [
            'type' => 'primary_home_dashboard',
        ],
        'dashlets' => [
            [
                'id' => '4074826d-aac9-46cf-84cb-576ad0a41452',
                'width' => 12,
                'height' => 11,
                'view' => [
                    'orderBy' => [
                        'field' => 'estimated_value',
                        'direction' => 'desc',
                    ],
                    'module' => 'Recommendation',
                    'display_columns' => [
                        'parent_name',
                        'name',
                        'description',
                        'estimated_value',
                        'quantity',
                        'expiry_date',
                        'account_value',
                        'status',
                        'assigned_user_name',
                    ],
                    'label' => 'Recommendations',
                    'type' => 'recommendations-dashlet',
                    'last_state' => [
                        'id' => 'dashable-list',
                    ],
                    'rowactions' => [
                        'actions' => [
                            [
                                'type' => 'rowaction',
                                'name' => 'edit_button',
                                'label' => 'LBL_EDIT_BUTTON',
                                'event' => 'list:editrow:fire',
                                'acl_action' => 'edit',
                            ],
                            [
                                'type' => 'rowaction',
                                'name' => 'delete_button',
                                'event' => 'list:deleterow:fire',
                                'label' => 'LBL_DELETE_BUTTON',
                                'acl_action' => 'delete',
                            ],
                        ],
                    ],
                    'intelligent' => '0',
                    'limit' => 5,
                    'filter_id' => '9ceeaf42-94c8-11f1-aa24-02b131184c4f',
                    'freeze_first_column' => true,
                    'templateEdit' => 'edit',
                    'skipFetch' => true,
                    'link' => null,
                ],
                'context' => [
                    'module' => 'Recommendation',
                    'link' => null,
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => '8ccbd195-55ff-4f72-b2d8-cdf75f13f290',
                'width' => 12,
                'height' => 6,
                'view' => [
                    'fiscal_year' => '',
                    'basis' => 'bookings',
                    'erp_company' => '',
                    'seller_id' => '',
                    'category_id' => '',
                    'label' => 'Sales Attainment',
                    'type' => 'sales-targets-chart',
                    'templateEdit' => 'edit',
                ],
                'context' => [],
            ],
            // --- Bench Dogs content ---
            //
            // Home is module scope rather than record scope, so these can point
            // straight at the reflection modules and the whole quote ladder is
            // on screen without a drill-down: the quote, its lines (the
            // prototype and the three quantity breaks) and the cost breakdown
            // behind them.
            [
                'id' => 'bd010002-4d1a-4a4c-9a7f-0d3b6f5b9102',
                'own_type' => false,
                'width' => 12,
                'height' => 5,
                'view' => [
                    'label' => 'ERP Quotes',
                    'type' => 'dashablelist',
                    'module' => 'bd01_ERP_Quote',
                    'link' => null,
                    'display_columns' => [
                        'name',
                        'quote_num',
                        'current_stage',
                        'quote_amt',
                        'quote_total',
                        'due_date',
                    ],
                    'orderBy' => [
                        'field' => 'quote_num',
                        'direction' => 'desc',
                    ],
                    'last_state' => [
                        'id' => 'dashable-list',
                    ],
                    'limit' => 10,
                    'freeze_first_column' => true,
                    'templateEdit' => 'edit',
                    'skipFetch' => true,
                ],
                'context' => [
                    'module' => 'bd01_ERP_Quote',
                    'link' => null,
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => 'bd010003-4d1a-4a4c-9a7f-0d3b6f5b9103',
                'own_type' => false,
                'width' => 12,
                'height' => 6,
                'view' => [
                    'label' => 'ERP Quote Lines',
                    'type' => 'dashablelist',
                    'module' => 'bd01_ERP_Quote_Line',
                    'link' => null,
                    'display_columns' => [
                        'line_num',
                        'part_num',
                        'selling_qty',
                        'doc_unit_price',
                        'doc_ext_price',
                        'governing',
                        'prototype',
                    ],
                    'orderBy' => [
                        'field' => 'line_num',
                        'direction' => 'asc',
                    ],
                    'last_state' => [
                        'id' => 'dashable-list',
                    ],
                    'limit' => 10,
                    'freeze_first_column' => true,
                    'templateEdit' => 'edit',
                    'skipFetch' => true,
                ],
                'context' => [
                    'module' => 'bd01_ERP_Quote_Line',
                    'link' => null,
                    'skipFetch' => true,
                ],
            ],
            [
                'id' => 'bd010004-4d1a-4a4c-9a7f-0d3b6f5b9104',
                'own_type' => false,
                'width' => 12,
                'height' => 5,
                'view' => [
                    'label' => 'ERP Quote Costs',
                    'type' => 'dashablelist',
                    'module' => 'bd01_ERP_Quote_Cost',
                    'link' => null,
                    'display_columns' => [
                        'qty_num',
                        'quantity',
                        'material_cost',
                        'labor_cost',
                        'material_burden',
                        'labor_burden',
                        'misc_cost',
                        'gross_margin_pct',
                    ],
                    'orderBy' => [
                        'field' => 'qty_num',
                        'direction' => 'asc',
                    ],
                    'last_state' => [
                        'id' => 'dashable-list',
                    ],
                    'limit' => 10,
                    'freeze_first_column' => true,
                    'templateEdit' => 'edit',
                    'skipFetch' => true,
                ],
                'context' => [
                    'module' => 'bd01_ERP_Quote_Cost',
                    'link' => null,
                    'skipFetch' => true,
                ],
            ],
        ],
    ],
    ];

    public function install(): void
    {
        foreach (self::DASHBOARDS as $spec) {
            try {
                $this->applyOne($spec);
            } catch (\Exception $e) {
                $GLOBALS['log']->error(
                    "BdDemoDashboards: could not apply {$spec['dashboard_module']}/"
                    . "{$spec['view_name']} - " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Nothing to undo. Removing the tiles on uninstall would also strip whatever
     * an admin has since arranged around them, and the dashlet types themselves
     * disappear with their own packages.
     */
    public function uninstall(): void
    {
    }

    private function applyOne(array $spec): void
    {
        $bean = $this->findDefaultDashboard($spec);
        if (!$bean) {
            $GLOBALS['log']->info(
                "BdDemoDashboards: no default dashboard named {$spec['name']} for "
                . "{$spec['dashboard_module']}/{$spec['view_name']}, so there is nothing "
                . 'to pin - deliberately not creating one, because a second default '
                . 'dashboard for the same view is worse than an unpinned layout'
            );
            return;
        }

        $metadata = $this->decodeMetadata($bean->metadata);
        $existing = isset($metadata['dashlets']) && is_array($metadata['dashlets'])
            ? $metadata['dashlets']
            : [];

        $ourIds = [];
        $managedTypes = [];
        $keep = [];
        $skipped = [];
        foreach ($spec['dashlets'] as $tile) {
            $type = $tile['view']['type'] ?? '';
            $ourIds[] = $tile['id'];
            // Owning the TYPE displaces an equivalent tile somebody else placed.
            // Correct for the captured third-party tiles, wrong for the stock
            // dashablelist the Bench Dogs tiles use - see the class comment.
            if ($type !== '' && ($tile['own_type'] ?? true)) {
                $managedTypes[] = $type;
            }
            if (!$this->isViewTypeRegistered($type)) {
                $skipped[] = $type;
                continue;
            }
            $keep[] = $tile;
        }

        $ours = $this->reflow($keep);

        if ($skipped) {
            $GLOBALS['log']->info(
                'BdDemoDashboards: skipped ' . count($skipped) . " tile(s) on {$spec['name']} - "
                . implode(', ', $skipped) . ' - their views are not installed on this '
                . 'instance, so writing them would render broken tiles; the remaining '
                . 'tiles were re-flowed to close the gaps'
            );
        }

        // Drop every tile this spec owns, then write ours; keep everything else
        // in its existing order and at its existing position.
        $kept = [];
        $displaced = 0;
        foreach ($existing as $tile) {
            $id = is_array($tile) ? ($tile['id'] ?? '') : '';
            $type = is_array($tile) ? ($tile['view']['type'] ?? '') : '';
            if (($id !== '' && in_array($id, $ourIds, true))
                || ($type !== '' && in_array($type, $managedTypes, true))) {
                $displaced++;
                continue;
            }
            $kept[] = $tile;
        }

        $metadata['dashlets'] = array_merge($kept, $ours);

        // Only fill in extra metadata that is missing - `type` on the Home
        // dashboard identifies it as the primary one, and clobbering a value the
        // instance already set would change behaviour, not just layout.
        foreach (($spec['extra_metadata'] ?? []) as $key => $value) {
            if (!isset($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        $bean->metadata = json_encode($metadata);
        $bean->save();

        $GLOBALS['log']->info(
            "BdDemoDashboards: pinned {$spec['name']} - wrote " . count($ours)
            . ' of ' . count($spec['dashlets']) . ' tiles, displaced ' . $displaced
            . ' tile(s) it owns, left ' . count($kept) . ' other tile(s) alone'
        );
    }

    /**
     * Lay tiles out top to bottom with no holes.
     *
     * The captured grids are 12 columns wide and every tile is either half width
     * (6, meant to sit beside its neighbour) or full width (12). So: full-width
     * tiles take a row of their own, half-width tiles pair with the next
     * half-width tile if there is one, and the cursor advances by the taller of
     * the pair. Run over the full captured list this reproduces the capture's
     * x/y exactly; run over a list with tiles missing it closes up rather than
     * leaving the gap a skipped dashlet would otherwise leave behind.
     *
     * @param array<int, array> $tiles Tiles in reading order.
     * @return array<int, array> The same tiles with x/y/width/height set.
     */
    private function reflow(array $tiles): array
    {
        $out = [];
        $y = 0;
        $i = 0;
        $count = count($tiles);

        while ($i < $count) {
            $tile = $tiles[$i];
            $width = (int) ($tile['width'] ?? 12);
            $height = (int) ($tile['height'] ?? 6);

            $partner = null;
            if ($width <= 6 && $i + 1 < $count && (int) ($tiles[$i + 1]['width'] ?? 12) <= 6) {
                $partner = $tiles[$i + 1];
            }

            $out[] = $this->positioned($tile, 0, $y);
            if ($partner !== null) {
                $out[] = $this->positioned($partner, $width, $y);
                $height = max($height, (int) ($partner['height'] ?? 6));
                $i += 2;
            } else {
                $i += 1;
            }

            $y += $height;
        }

        return $out;
    }

    /**
     * One tile, placed. `own_type` is a directive to applyOne(), not part of the
     * dashlet, so it never reaches the database.
     */
    private function positioned(array $tile, int $x, int $y): array
    {
        unset($tile['own_type']);
        $tile['x'] = $x;
        $tile['y'] = $y;
        $tile['autoPosition'] = false;

        return $tile;
    }

    /**
     * The live default dashboard a spec targets.
     *
     * Name alone is NOT a reliable key, which is why this is three-tiered. The
     * same Accounts focus drawer is called 'LBL_ACCOUNTS_FOCUS_DRAWER_DASHBOARD'
     * on one instance and literally 'Accounts Focus Dashboard' on another, so
     * requiring the name silently matched nothing and the layout appeared not to
     * install at all. Home needs the opposite: it carries a dozen default
     * dashboards (every console), so module and view alone are ambiguous there.
     *
     * So: prefer an exact name match, then the metadata discriminator the spec
     * declares (Home's `type => primary_home_dashboard` identifies the primary
     * one whatever it is called), then accept the row only if it is the single
     * default for that view. Anything still ambiguous is reported and left alone
     * rather than guessed at.
     */
    private function findDefaultDashboard(array $spec)
    {
        $query = new SugarQuery();
        $query->select(['id', 'name']);
        $query->from(BeanFactory::newBean('Dashboards'));
        $query->where()
            ->equals('dashboard_module', $spec['dashboard_module'])
            ->equals('view_name', $spec['view_name'])
            ->equals('default_dashboard', 1);
        $query->orderBy('date_entered', 'DESC');
        $rows = $query->execute();

        if (empty($rows)) {
            return null;
        }

        // 1. exact name
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $spec['name']) {
                return BeanFactory::retrieveBean('Dashboards', $row['id']);
            }
        }

        // 2. the metadata discriminator, when the spec declares one
        $wantType = $spec['extra_metadata']['type'] ?? '';
        if ($wantType !== '') {
            foreach ($rows as $row) {
                $candidate = BeanFactory::retrieveBean('Dashboards', $row['id']);
                if (!$candidate) {
                    continue;
                }
                $meta = $this->decodeMetadata($candidate->metadata);
                if (($meta['type'] ?? '') === $wantType) {
                    $GLOBALS['log']->info(
                        "BdDemoDashboards: {$spec['dashboard_module']}/{$spec['view_name']} "
                        . "matched by metadata type '{$wantType}' rather than by name "
                        . "(found '{$row['name']}', spec says '{$spec['name']}')"
                    );
                    return $candidate;
                }
            }
        }

        // 3. unambiguous single default for this view
        if (count($rows) === 1) {
            $GLOBALS['log']->info(
                "BdDemoDashboards: {$spec['dashboard_module']}/{$spec['view_name']} "
                . "matched as the only default for that view (named '{$rows[0]['name']}', "
                . "spec says '{$spec['name']}')"
            );
            return BeanFactory::retrieveBean('Dashboards', $rows[0]['id']);
        }

        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }
        $GLOBALS['log']->error(
            "BdDemoDashboards: {$spec['dashboard_module']}/{$spec['view_name']} has "
            . count($rows) . ' default dashboards and none matches the spec, so none was '
            . 'changed - candidates: ' . implode(', ', $names)
        );

        return null;
    }

    /**
     * Is a dashlet's view actually installed?
     *
     * Explicit candidate paths rather than a directory scan: glob() and is_dir()
     * are both on ModuleScanner's denylist, so a package cannot look around the
     * filesystem - it can only ask about paths it names. These four cover where
     * the contributing packages put their views, including stock Sugar
     * (dashablelist and gai-dashlet live under clients/, not custom/clients/).
     */
    private function isViewTypeRegistered(string $type): bool
    {
        if ($type === '') {
            return false;
        }

        $candidates = [
            "custom/clients/base/views/{$type}/{$type}.php",
            "custom/clients/base/views/{$type}/{$type}.js",
            "clients/base/views/{$type}/{$type}.php",
            "clients/base/views/{$type}/{$type}.js",
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dashboards.metadata is a JSON string on the bean.
     *
     * @param mixed $raw
     * @return array
     */
    private function decodeMetadata($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
