<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * ONE admin route, and nothing a seller can reach.
 *
 *   POST bd-tools/repair-ui   Admin-only. Re-runs this package's own deployed
 *     metadata steps with nothing swallowed - the retired Bench Dogs panel
 *     removal, the quoted-line-items column order, the REQ-19 customer group
 *     fields - and reports each step verbatim, because post_install logs to
 *     sugarcrm.log and SugarCloud keeps that out of reach.
 *
 * 🛑 WHAT USED TO BE HERE, AND WHY IT IS NOT (0.9.42-rc65, G280 / 🔒 1507).
 * Two seller-facing routes shipped here until rc64 and both duplicated core:
 * `bd-create-opp-quote` (ERP-Epicor's AccountsErpActionsApi::createOppQuote is
 * the superset - ETO placeholder part, Advanced Quote typing - 🔒 1044 / G15)
 * and `bd-send-to-estimating` (ERP-Core's 'Send to Estimation', 🔒 531). A third,
 * `bd-order-winning-line`, was documented here for three releases and never
 * registered at all. Ordering selected lines is
 * ERP-Epicor-PartialFulfillment's.
 *
 * Extends ERP-Epicor's BaseErpActionsApi for the orchestrator plumbing - a hard
 * dependency, exactly as the product's own QuotesErpActionsApi requires it.
 * Note the consequence: this file defines its class only when ERP-Epicor is
 * installed, so the repair route disappears with ERP-Epicor rather than with
 * this package.
 */

// This class extends ERP-Epicor's BaseErpActionsApi, so it cannot be defined
// at all unless that package is still installed - a require_once on a
// missing file is a fatal compile error, not a \Throwable, so the
// file_exists guard has to come first (same convention as
// ERP-Epicor-PartialFulfillment's PartialFulfillmentQuotesApi.php).
// ServiceDictionary::buildAllDictionaries() require_once's every file under
// custom/clients/*/api/*.php on EVERY REST call before it ever checks
// class_exists() - so without this guard, uninstalling ERP-Epicor takes down
// the entire REST API (every endpoint, not just this one), which is exactly
// what happened live on benchdogs-dev when Epicor Integration was
// uninstalled before Bench Dogs Extensions.
$parentApiFile = 'custom/clients/base/api/BaseErpActionsApi.php';
if (file_exists($parentApiFile)) {
    require_once \Sugarcrm\Sugarcrm\Util\Files\FileLoader::validateFilePath($parentApiFile);

    class BdBenchDogsActionsApi extends BaseErpActionsApi
    {
    /**
     * 🛑 0.9.42-rc65, G280 / 🔒 1507 — TWO ROUTES ARE GONE FROM THIS CLASS, and
     * with them the last Bench implementation of something core already does:
     *
     *   POST Accounts/:record/bd-create-opp-quote  (createOppQuote)
     *   POST Quotes/:record/bd-send-to-estimating  (sendToEstimating,
     *        ESTIMATING_STAGE and estimatingStageFailure())
     *
     * Core owns both: ERP-Epicor's AccountsErpActionsApi::createOppQuote is the
     * SUPERSET (it carries the ETO placeholder part and types the quote for
     * Advanced Quote, 🔒 1044 / G15), and 'Send to Estimation' is ERP-Core's
     * (🔒 531). Neither Bench route had a caller left: the three field
     * controllers that used to POST to them are empty ({}) stubs, no viewdef
     * names their field types, and a full sweep of the connector, core,
     * features, platform and sugar repos found no live caller - only prose.
     *
     * The FILE still ships, and must: dropping it would leave all three routes
     * registered on every tenant that already has it (§CW / G37 - Module Loader
     * copies and never deletes). Overwriting the file with this version is what
     * unregisters them. bd-tools/repair-ui stays: it is an admin repair for this
     * package's own deployed metadata, and rc64 already took the button steps
     * out of it.
     */

        public function registerApiRest()
        {
            return array(
                'bdRepairUi' => array(
                    'reqType' => 'POST',
                    'path' => array('bd-tools', 'repair-ui'),
                    'pathVars' => array('', ''),
                    'method' => 'repairUi',
                    'shortHelp' => 'Admin-only: re-runs the Bench Dogs UI deploy steps (buttons, stage dropdowns) and reports each step verbatim.',
                    'exceptions' => array(
                        'SugarApiExceptionNotAuthorized',
                    ),
                ),
            );
        }

        /**
         * Re-runs the post_install UI deploy steps with NOTHING swallowed: every
         * step's exception text comes back in the response. post_install logs
         * failures to sugarcrm.log, which SugarCloud keeps out of reach - this
         * route exists because the layout/dropdown steps failed there silently.
         * Admin-only; every mutation is the same idempotent core-class call the
         * installer makes.
         */
        public function repairUi(ServiceBase $api, array $args)
        {
            global $current_user;
            if (empty($current_user) || !$current_user->isAdmin()) {
                throw new SugarApiExceptionNotAuthorized('Admins only');
            }

            $steps = array();

            try {
                $helper = 'custom/modules/Quotes/BdQuotesLayoutExtensions.php';
                require_once $helper;
                // The Bench Dogs ERP panel too (append-only: restored when a
                // later ERP-Epicor layout pass dropped it, never rewritten when
                // it is there) - an install whose post_execute did not run, or a
                // core upgrade in between, leaves the record view without it.
                BdQuotesLayoutExtensions::write(false);
                // 🛑 G276 / 🔒 1504: no button step. writeButtons() (Quotes and
                // Accounts) was removed with every other piece of record-view
                // button logic in this package; core owns every button.
                require_once 'custom/modules/Quotes/BdQliColumnsLayout.php';
                (new BdQliColumnsLayout())->install();
                $steps['quotes_layout'] = 'ok';
            } catch (Throwable $e) {
                $steps['quotes_layout'] = get_class($e) . ': ' . $e->getMessage();
            }

            try {
                $helper = 'custom/modules/Accounts/BdAccountsLayoutExtensions.php';
                require_once $helper;
                BdAccountsLayoutExtensions::writeCustomerGroupField();
                $steps['accounts_group_field'] = 'ok';
            } catch (Throwable $e) {
                $steps['accounts_group_field'] = get_class($e) . ': ' . $e->getMessage();
            }

            try {
                // 🛑 NO STAGE-DROPDOWN STEP ANY MORE (rc65, G278 / 🔒 1506).
                // This route used to re-run install_languages() over
                // custom/dropdowntemplates/bd_stage_doms.append.php. Partial
                // Fulfillment owns sales_stage_dom's two release stages, their
                // probabilities and styles, and quote_stage_dom's 'Partially
                // Fulfilled'; this package declares none of them and ships no
                // template. The language rebuild stays, because this package
                // still ships EMPTIED language fragments whose retirement only
                // takes effect once the application strings are recompiled.
                require_once 'ModuleInstall/ModuleInstaller.php';
                $mi2 = new ModuleInstaller();
                $mi2->silent = true;
                $mi2->rebuild_languages(array('en_us' => 'en_us'));
                $steps['rebuild_languages'] = 'ok';
            } catch (Throwable $e) {
                $steps['rebuild_languages'] = get_class($e) . ': ' . $e->getMessage();
            }

            try {
                SugarAutoLoader::load('modules/Administration/QuickRepairAndRebuild.php');
                // Bench Dogs is an Opportunities-only deployment. Repair the
                // Quote/Opportunity surfaces it owns without compiling a
                // disabled sales model.
                $modules = array('Quotes', 'Opportunities', 'Accounts');
                $rac = new RepairAndClear();
                $rac->show_output = false;
                $rac->module_list = $modules;
                $rac->clearVardefs();
                $rac->rebuildExtensions($modules);
                MetaDataManager::refreshModulesCache($modules);
                if (method_exists('MetaDataManager', 'refreshLanguagesCache')) {
                    MetaDataManager::refreshLanguagesCache(array('en_us'));
                }
                $steps['repair_rebuild'] = 'ok';
            } catch (Throwable $e) {
                $steps['repair_rebuild'] = get_class($e) . ': ' . $e->getMessage();
            }

            // Live read straight from the rebuilt app strings. These keys are
            // Partial Fulfillment's now, so this reports on ANOTHER package -
            // deliberately: a Bench Opportunity stores those strings, and an
            // admin running this repair is usually asking exactly this question.
            // It asserts nothing and changes nothing.
            $doms = return_app_list_strings_language('en_us');
            $steps['core_quote_stage_dom'] = isset($doms['quote_stage_dom']['Partially Fulfilled']) ? 'present' : 'MISSING';
            $steps['core_sales_stage_dom'] = (isset($doms['sales_stage_dom']['Prototype Ordered'])
                && isset($doms['sales_stage_dom']['Partial Production Ordered'])) ? 'present' : 'MISSING';

            return array('status' => 'success', 'steps' => $steps);
        }
    }
}
