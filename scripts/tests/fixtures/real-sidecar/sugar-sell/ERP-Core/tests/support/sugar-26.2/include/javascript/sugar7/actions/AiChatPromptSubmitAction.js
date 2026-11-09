// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/actions/AiChatPromptSubmitAction.js; sha256 abe5d77ded06a8cce25389c14a5865ce4be09143c9676adcf004ee422d21e42b of the stock file, which follows unchanged.
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
         * Action to submit a prompt to AI chat
         *
         *
         * @class Core.Actions.AiChatPromptSubmitAction
         * @alias SUGAR.App.Actions.AiChatPromptSubmit
         *
         * @param {object} def Action Definition
         */
        function AiChatPromptSubmit(def) {
            this.def = def;
        }

        /**
         * Execute the prompt submit action
         *
         * @param {object} opts Execution options
         * @param {View} opts.recordView The component view triggering the action
         * @param {object} opts.recordData Data containing the prompt text and chat context
         * @param {string} opts.recordData.promptText The prompt message to submit
         * @param {string} [opts.recordData.selectedKey] Stable option key for a clarifier answer
         *     (the backend resolves on this, not the display label)
         * @param {object} [opts.recordData.filterSelections] Multiselect filter answer,
         *     a {field: [values]} map (the choose_filter clarifier)
         * @param {string} [opts.recordData.clarifierId] Id of the clarifier question these options answered
         * @param {object} [opts.additionalStateUpdates] Additional UI state updates to merge with dialog info
         * @param {object} currentExecution Queue of actions to be executed in the current context
         */
        AiChatPromptSubmit.prototype.run = function(opts, currentExecution) {
            const {recordView, recordData, context, additionalStateUpdates = {}} = opts;
            const {promptText, chatId, fromSmartPrompt, selectedKey, filterSelections, clarifierId} = recordData || {};

            if (!context) {
                app.logger.error('AiChatPromptSubmit: context missing');
                currentExecution?.nextAction();
                return;
            }

            if (!promptText && !chatId) {
                app.logger.error('AiChatPromptSubmit: prompt or chatId required');
                currentExecution?.nextAction();
                return;
            }

            if (context.get('loading') && recordView && _.isFunction(recordView.stopPrompt)) {
                recordView.stopPrompt();
            }

            const dialogStateUpdate = Object.assign(
                {dialog_info: {
                    message: promptText,
                    chatId,
                    fromSmartPrompt,
                    selectedKey,
                    filterSelections,
                    clarifierId,
                }},
                additionalStateUpdates,
            );

            // Unset first so Backbone fires the event even when the new value is deeply equal to the current one.
            context.unset('dialog_info', {silent: true});
            context.set(dialogStateUpdate);

            if (currentExecution) {
                currentExecution.nextAction();
            }
        };

        app.actions = app.actions || {};

        app.actions = _.extend(app.actions, {
            AiChatPromptSubmit: AiChatPromptSubmit,
        });
    });
})(SUGAR.App);
