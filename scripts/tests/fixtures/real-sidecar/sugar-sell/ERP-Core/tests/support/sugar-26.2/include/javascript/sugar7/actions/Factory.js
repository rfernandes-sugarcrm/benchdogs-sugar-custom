// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/actions/Factory.js; sha256 980f9dfc458d467f7be4415453e3b6826c746a46e79f4dcdf4a0a17761fff299 of the stock file, which follows unchanged.
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
         * Action Factory
         *
         * @class Core.Actions.Factory
         * @alias SUGAR.App.Actions.Factory
         */
        function ActionFactory() { }

        /**
         * Returns a new Action instance based on type & definition
         *
         * @param {Object} actionDef
         *
         * @return {SUGAR.App.Actions.Action}
         */
        ActionFactory.prototype.create = function(actionDef) {
            let actionType = app.utils.capitalizeHyphenated(actionDef.actionType);

            let Action = app.actions[actionType];
            let action = Action && new Action(actionDef);
            return action;
        };

        app.actions = app.actions || {};

        app.actions = _.extend(app.actions, {
            ActionFactory: ActionFactory
        });
    });
})(SUGAR.App);
