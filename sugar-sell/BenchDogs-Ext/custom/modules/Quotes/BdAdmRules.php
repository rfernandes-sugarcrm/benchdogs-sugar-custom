<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * G380 / G381 (owner rulings 🔒 1705b): what Bench Dogs' ADM company requires
 * of a quote before it can go to the ERP. The Sugar half. Nothing else.
 *
 * MEASURED on stage t7 (benchdogs-dev -> ADM), 2026-09-23:
 *   Send to Estimation -> 400 "Reference is required. Expected Close is
 *     required. Lead Source is required. Lead Type is required."
 *   Submit Order -> 400 "Part is required. Group is required. Project ID is
 *     required." (the line was "PALLET", sent with PartNum '').
 * EPIC06, the test company, enforces none of it, so every rule below is gated
 * on the quote's ERP company (appliesTo), and a quote of any other company is
 * left exactly as it was.
 *
 * WHAT LIVES HERE, AND WHY IT CANNOT LIVE UPSTREAM. Each is a rule of ONE
 * customer's ERP company; ERP-Epicor must not learn it (gate G2):
 *
 *  - the seller's pick lists for Lead Source, Lead Type and Project, read from
 *    the ERP_LookupValues rows the Bench Dogs connector extension publishes
 *    from ADM's own code lists (types BdLeadSources / BdLeadTypes / BdProjects;
 *    ADM user-code types LEADSRC / LEADTYPE and Erp.BO.ProjectSvc, 🔒 1710b).
 *    Active rows only: a retired code is not offered;
 *  - Reference DEFAULTS to the ship-to's city and state ("WAYNE NJ"), and the
 *    seller may change it. Only an empty field is filled, and only before the
 *    quote has reached the ERP;
 *  - Project is PRE-FILLED from a product-group -> project default list
 *    (bd_adm_project_by_group_list, tenant data an admin edits in Dropdown
 *    Editor), and only when EVERY line's group maps to the SAME project;
 *  - a line with no ERP part number is BLOCKED from ordering, with a seller
 *    message, through ERP-Epicor's two ordering hook points.
 *
 * WHAT DOES NOT LIVE HERE. Sending these values to ADM, and refusing Send to
 * Estimation when one is missing. Core builds both ERP payloads, and at core
 * staging cbd3053 it offers no seam for an extension to add fields or refuse;
 * the Bench Dogs connector extension carries the builders
 * (connector_ext_benchdogs.adm_rules) waiting on that seam. Expected Close is
 * the Opportunity's close date and needs no field here at all.
 *
 * Scanner-safe: no glob, no is_callable, no dynamic dispatch, no
 * call_user_func. A hook adapter loads this file by one literal path, guarded
 * by class_exists() first (MLP001: one class, one reachable path).
 */
class BdAdmRules
{
    /** ERP_LookupValues discriminators, a contract with the connector extension. */
    public const TYPE_LEAD_SOURCES = 'BdLeadSources';
    public const TYPE_LEAD_TYPES = 'BdLeadTypes';
    public const TYPE_PROJECTS = 'BdProjects';

    /** Tenant data (app_list_strings), each editable in Admin > Dropdown Editor. */
    public const LIST_COMPANIES = 'bd_adm_companies_list';
    public const LIST_PROJECT_BY_GROUP = 'bd_adm_project_by_group_list';

    /** A quote with more lines than this is not scanned for a project default. */
    public const MAX_LINES_SCANNED = 500;

    // ── which quotes the rules cover ────────────────────────────────────────

    /**
     * The Epicor company of a quote: its billing account's ERP company, read
     * the way ERP-Epicor's QuotesErpActionsApi::resolveErpCompanyCode() reads
     * it (Account -> ERP_Companies.erp_sync_key). An account with no company
     * link falls back to the scope of its own erp_sync_key ("ADM__70"), which
     * is the company by the connector's key convention. '' when neither says.
     */
    public static function companyOf($quote): string
    {
        $accountId = trim((string) ($quote->billing_account_id ?? ''));
        if ($accountId === '') {
            return '';
        }
        $account = BeanFactory::retrieveBean('Accounts', $accountId);
        if (!$account) {
            return '';
        }
        $companyId = trim((string) ($account->erp_companies_accountserp_companies_ida ?? ''));
        if ($companyId !== '') {
            $company = BeanFactory::retrieveBean('ERP_Companies', $companyId);
            $code = $company ? trim((string) ($company->erp_sync_key ?? '')) : '';
            if ($code !== '') {
                return $code;
            }
        }
        $key = (string) ($account->erp_sync_key ?? '');
        $at = strpos($key, '__');

        return $at ? substr($key, 0, $at) : '';
    }

    /** True only for a company named in bd_adm_companies_list (shipped: ADM). */
    public static function appliesTo(string $company): bool
    {
        $wanted = strtoupper(trim($company));
        if ($wanted === '') {
            return false;
        }
        foreach (array_keys(self::appList(self::LIST_COMPANIES)) as $key) {
            if (strtoupper(trim((string) $key)) === $wanted) {
                return true;
            }
        }

        return false;
    }

    // ── G380: Reference defaults to the ship-to's city and state ─────────────

    /** "WAYNE NJ": the city and the state, whichever are present, one space apart. */
    public static function defaultReference(string $city, string $state): string
    {
        $parts = array();
        foreach (array($city, $state) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode(' ', $parts);
    }

    // ── G381: the part number, resolved exactly as ERP-Epicor sends it ───────

    /**
     * The PartNum core will send for this line. It MUST match
     * QuotesErpActionsApi::getQuoteRecord(): a catalog line sends its
     * ProductTemplates.erp_display_sync_key, a free-text line its own
     * mft_part_num. Judging by any other field would let through a line core
     * then sends with PartNum ''.
     */
    public static function partNumberOf($line): string
    {
        $templateId = trim((string) ($line->product_template_id ?? ''));
        if ($templateId !== '') {
            $template = BeanFactory::retrieveBean('ProductTemplates', $templateId);

            return $template ? trim((string) ($template->erp_display_sync_key ?? '')) : '';
        }

        return trim((string) ($line->mft_part_num ?? ''));
    }

    /**
     * The product group (Epicor ProdCode) of a line's catalog part, as the
     * product catalog sync lands it: ProductTemplates.category_id ->
     * ProductCategories.erp_display_sync_key. '' for a free-text line or a
     * part with no group.
     */
    public static function productGroupOf($line): string
    {
        $templateId = trim((string) ($line->product_template_id ?? ''));
        if ($templateId === '') {
            return '';
        }
        $template = BeanFactory::retrieveBean('ProductTemplates', $templateId);
        $categoryId = $template ? trim((string) ($template->category_id ?? '')) : '';
        if ($categoryId === '') {
            return '';
        }
        $category = BeanFactory::retrieveBean('ProductCategories', $categoryId);

        return $category ? trim((string) ($category->erp_display_sync_key ?? '')) : '';
    }

    /**
     * The seller's refusal when any of these lines has no ERP part number, or
     * null to let the order proceed. Null for every quote of a company the
     * rules do not cover.
     *
     * The words say "has no ERP part number", not "is not an ERP part": the
     * measured PALLET line was catalog part 49000450 (ProdCode DISPLAYS) whose
     * part number had not reached Sugar (G382). Both are blocked alike.
     */
    public static function nonPartRefusal($quote, array $lineIds): ?string
    {
        if (!self::appliesTo(self::companyOf($quote))) {
            return null;
        }
        $names = array();
        foreach ($lineIds as $lineId) {
            $line = BeanFactory::retrieveBean('Products', (string) $lineId);
            if (!$line) {
                continue;
            }
            if (self::partNumberOf($line) === '') {
                $name = trim((string) ($line->name ?? ''));
                $names[] = $name !== '' ? '"' . $name . '"' : 'line ' . $line->id;
            }
        }
        if ($names === array()) {
            return null;
        }
        $last = array_pop($names);
        $who = $names === array() ? $last : implode(', ', $names) . ' and ' . $last;

        return "Bench Dogs' ERP will not accept this order: " . $who
            . ($names === array() ? ' has' : ' have')
            . ' no ERP part number and cannot be ordered until mapped to an ADM part (Part).'
            . ' Fix these on the quote, then submit again. Nothing was sent to the ERP.';
    }

    // ── G381: Project pre-filled from the product-group default list ─────────

    /**
     * The one project the default list gives these product groups, or '' when
     * any group is blank or unmapped, or the groups map to different projects.
     * One project per quote goes on every order line, so a quote mixing a
     * mapped group with an unmapped one (CMI with DISPLAYS, which has no
     * dominant project) is left for the seller to pick.
     */
    public static function defaultProject(array $groups): string
    {
        if ($groups === array()) {
            return '';
        }
        $map = array();
        foreach (self::appList(self::LIST_PROJECT_BY_GROUP) as $group => $project) {
            $group = strtoupper(trim((string) $group));
            $project = trim((string) $project);
            if ($group !== '' && $project !== '') {
                $map[$group] = $project;
            }
        }
        $chosen = '';
        foreach ($groups as $group) {
            $project = $map[strtoupper(trim((string) $group))] ?? '';
            if ($project === '' || ($chosen !== '' && $project !== $chosen)) {
                return '';
            }
            $chosen = $project;
        }

        return $chosen;
    }

    // ── the before_save hook ─────────────────────────────────────────────────

    /**
     * Logic hook entry point (before_save on Quotes).
     *
     * FAILS OPEN, and that is the non-lossy direction here: these are
     * DEFAULTS, a convenience for the seller. A defaults lookup that throws
     * must not fail the save of a quote the seller is editing (MLP004); the
     * worst case is an empty Reference or Project, which the send refuses by
     * name. The failure is logged, never swallowed silently.
     */
    public function beforeSave($bean, $event, $arguments): void
    {
        try {
            self::applyDefaults($bean);
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('BenchDogs-Ext: ADM quote defaults failed for quote '
                . (string) ($bean->id ?? '') . ': ' . $e->getMessage());
        }
    }

    /**
     * Fill an EMPTY Reference and an EMPTY Project on an ADM quote that has not
     * reached the ERP yet. Never overwrites what a seller typed, never touches
     * a quote that already has an ERP number (its values are the ERP's), never
     * touches another company's quote. Returns the names of the fields set.
     */
    public static function applyDefaults($bean): array
    {
        $set = array();
        if (trim((string) ($bean->erp_display_sync_key ?? '')) !== '') {
            return $set;
        }
        if (!self::appliesTo(self::companyOf($bean))) {
            return $set;
        }
        if (trim((string) ($bean->bd_reference ?? '')) === '') {
            $reference = self::defaultReference(
                (string) ($bean->shipping_address_city ?? ''),
                (string) ($bean->shipping_address_state ?? '')
            );
            if ($reference !== '') {
                $bean->bd_reference = $reference;
                $set[] = 'bd_reference';
            }
        }
        if (trim((string) ($bean->bd_project_id ?? '')) === '') {
            $project = self::defaultProject(self::lineGroups($bean));
            if ($project !== '') {
                $bean->bd_project_id = $project;
                $set[] = 'bd_project_id';
            }
        }

        return $set;
    }

    /** The product group of every live line on the quote, in line order. */
    private static function lineGroups($bean): array
    {
        if (empty($bean->id) || !$bean->load_relationship('products')) {
            return array();
        }
        $groups = array();
        $lines = $bean->products->getBeans(array('limit' => self::MAX_LINES_SCANNED + 1));
        if (count($lines) > self::MAX_LINES_SCANNED) {
            return array();
        }
        foreach ($lines as $line) {
            if (!empty($line->deleted)) {
                continue;
            }
            $groups[] = self::productGroupOf($line);
        }

        return $groups;
    }

    // ── the pickers' options ─────────────────────────────────────────────────

    /**
     * Options for one pick list: '' first, then every ACTIVE row of the type,
     * keyed by its ERP code (what ADM stores) and labelled "CODE - Name".
     */
    public static function lookupOptions(string $type): array
    {
        $query = new SugarQuery();
        $query->from(BeanFactory::newBean('ERP_LookupValues'), array('team_security' => false));
        $query->select(array('erp_display_sync_key', 'name'));
        $query->where()->equals('type', $type)->equals('is_active', 1);
        $query->orderBy('name', 'ASC');

        return self::optionsFromRows($query->execute());
    }

    /** The pure half of lookupOptions(), so it can be tested without a database. */
    public static function optionsFromRows(array $rows): array
    {
        $options = array('' => '');
        foreach ($rows as $row) {
            $code = trim((string) ($row['erp_display_sync_key'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $options[$code] = ($name === '' || $name === $code) ? $code : $code . ' - ' . $name;
        }

        return $options;
    }

    private static function appList(string $name): array
    {
        global $app_list_strings;
        $list = is_array($app_list_strings) ? ($app_list_strings[$name] ?? null) : null;
        if (!is_array($list)) {
            $strings = return_app_list_strings_language($GLOBALS['current_language'] ?? 'en_us');
            $list = is_array($strings) ? ($strings[$name] ?? array()) : array();
        }

        return is_array($list) ? $list : array();
    }
}
