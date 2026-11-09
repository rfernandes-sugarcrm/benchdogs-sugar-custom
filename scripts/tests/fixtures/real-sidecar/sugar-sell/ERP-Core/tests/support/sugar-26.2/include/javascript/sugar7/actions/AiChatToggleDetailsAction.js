// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/actions/AiChatToggleDetailsAction.js; sha256 e41264ce7e112023574252b6de264542e6ca02cb9a7734c644d4d6a4394b4c1d of the stock file, which follows unchanged.
/*
 * Your installation or use of this SugarCRM file is subject to the applicable
 * terms available at
 * http://support.sugarcrm.com/Resources/Master_Subscription_Agreements/.
 * If you do not agree to all of the applicable terms or do not have the
 * authority to bind the entity as an authorized representative, then do not
 * install or use this SugarCRM file.
 *
 * Copyright (C) SugarCRM Inc. All rights reserved.
 */
(function register(app) {
    app.events.on('app:init', function init() {
        /**
         * AI Chat Toggle Details Action
         *
         * @class Core.Actions.AiChatToggleDetailsAction
         * @alias SUGAR.App.Actions.AiChatToggleDetailsAction
         *
         * @param {object} def Action Definition
         */
        function AiChatToggleDetails(def) {
            this.def = def;
        }

        /**
         * Execute action
         *
         * @param {View} opts.recordView Current component view
         * @param {object} currentExecution Queue of actions to be executed in the current context
         *
         */
        AiChatToggleDetails.prototype.run = function(opts, currentExecution) {
            const view = opts.recordView;

            if (view.isExpanded) {
                view.showDetailsPanel();
                view.toggleDetailsPanel();
            } else {
                const id = this.def.id || view.componentId || null;
                const meta = opts.meta || view.getDetailsPanelMeta();
                view.showDetailsPanel(meta, id);
            }

            currentExecution.nextAction();
        };

        app.actions = app.actions || {};

        app.actions = _.extend(app.actions, {
            AiChatToggleDetails: AiChatToggleDetails,
        });
    });
})(SUGAR.App);
