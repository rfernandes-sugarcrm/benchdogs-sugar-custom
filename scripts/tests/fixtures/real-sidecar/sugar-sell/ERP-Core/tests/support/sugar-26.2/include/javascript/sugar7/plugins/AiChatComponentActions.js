// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/plugins/AiChatComponentActions.js; sha256 4785b31c6e11ccebd169c20b7220144d622ab27259b33da2957d065f49cde7d4 of the stock file, which follows unchanged.
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
/**
 * AI Chat Actions Plugin
 *
 * Provides state management functionality for AI chat widgets
 * Handles persistence of chat state across browser sessions
 */
(function(app) {
    app.events.on('app:init', function() {
        const ChatActionProvider = function() {
            this.actionFactory = new app.actions.ActionFactory();
        };

        const ACL_DEPENDABLE_ACTIONS = {
            'ai-chat-copy': 'export',
            'ai-chat-export-to-pdf': 'export',
            'ai-chat-export-to-csv': 'export',
            'ai-chat-export-to-excel': 'export',
        };

        // Actions that only make sense when AI Analytics is enabled — omitted entirely
        // (not just disabled) when app.config.reportingAnalyticsEnabled is falsy.
        const AI_ANALYTICS_ONLY_ACTIONS = ['ai-chat-save-to-dashboard'];

        Object.assign(ChatActionProvider.prototype, {
            execute: function(actionDefs, options) {
                const actions = actionDefs.map((def) => this.actionFactory.create(def));
                const runner = app.actions.Runner.create(actions, options);
                runner.execute();

                return runner;
            },

            /**
             * Get action definition by its type/name
             *
             * @param {string} actionType - Action type/name
             * @param {Array<object>} actions - Array of action definitions to search
             * @returns {object} Action definition if found, otherwise a default action definition with the actionType
            */
            getActionDefsByType: function(actionType, actions) {
                for (const action of actions) {
                    if (action.actionType === actionType) {
                        return action;
                    }
                    // Check nested children (for actiondropdown)
                    if (action.children && action.children.length > 0) {
                        const found = this.getActionDefsByType(actionType, action.children);
                        if (found) {
                            return found;
                        }
                    }
                }
                return {actionType: actionType};
            },

            /**
             * Build action objects from metadata definitions.
             * Transforms button metadata into action objects suitable for rendering.
             *
             * @private
             * @param {object} meta - Button metadata definitions
             * @returns {Array<object>} Array of action objects with name, action, icon, title, tooltip
             */
            _buildActionsFromMeta(meta) {
                const actions = [];

                function withAclCheck(actionDef) {
                    const requiredPermission = ACL_DEPENDABLE_ACTIONS[actionDef.actionType];

                    if (requiredPermission && !app.acl.hasAccess(requiredPermission, 'AIExperience')) {
                        actionDef.tooltip = app.lang.get('LBL_EXPORT_NO_PERMISSIONS');
                        actionDef.disabled = true;
                    }

                    return actionDef;
                }

                _.each(meta, (buttonDef, key) => {
                    const actionType = buttonDef.action || key;

                    // AI Analytics disabled at the org level — omit its actions entirely.
                    if (
                        _.contains(AI_ANALYTICS_ONLY_ACTIONS, actionType) &&
                        !app.config.reportingAnalyticsEnabled
                    ) {
                        return;
                    }

                    // Preserve full button definition for actiondropdown
                    if (buttonDef.type === 'actiondropdown') {
                        const dropdownDef = _.extend({}, buttonDef, {
                            name: key,
                            actionType: key,
                            tooltip: app.lang.get(buttonDef.title),
                        });

                        if (buttonDef.buttons && buttonDef.buttons.length > 0) {
                            dropdownDef.children = _.map(buttonDef.buttons, (child) => {
                                return withAclCheck({
                                    type: child.type,
                                    actionType: child.action,
                                    label: app.lang.get(child.label),
                                });
                            });
                        }

                        actions.push(dropdownDef);
                    } else {
                        // Action def
                        actions.push(withAclCheck({
                            actionType: actionType,
                            icon: buttonDef.icon,
                            tooltip: app.lang.get(buttonDef.title),
                            label: app.lang.get(buttonDef.label),
                            disabled: buttonDef.disabled || false,
                            engaged: buttonDef.engaged || false,
                            complete: buttonDef.complete || false,
                            data: buttonDef.data || {},
                        }));
                    }
                });

                return actions;
            },

            /**
             * Get expand/collapse action based on current state
             *
             * @param {object} view - The view instance
             * @returns {Array<object>} Array with a single expand/collapse action definition
             */
            _getToggleDetailsActions: function(view) {
                if (!view.isExpandable) {
                    return [];
                }

                return [{
                    actionType: 'ai-chat-toggle-details',
                    icon: 'sicon sicon-expand-right',
                    tooltip: app.lang.get('LBL_EXPAND_TO_DETAIL_PANEL'),
                    hasDivider: true,
                    availableKey: 'isExpanded',
                    availableValue: false,
                    id: view.componentId,
                },
                {
                    actionType: 'ai-chat-toggle-details',
                    icon: 'sicon sicon-minimize',
                    tooltip: app.lang.get('LBL_MINIMIZE_VIEW'),
                    hasDivider: true,
                    availableKey: 'isExpanded',
                    availableValue: true,
                    id: view.componentId,
                }];
            },

            /**
             * Get component actions
             *
             * @param {object} view - The view instance
             * @returns {Array<object>} Array of action definitions
             */
            getComponentActions(view) {
                const buttonsMeta = view.data?.buttons || app.metadata.getView(null, view.name)?.buttons || [];

                return [...this._buildActionsFromMeta(buttonsMeta), ...this._getToggleDetailsActions(view)];
            },

            /**
             * Update available component actions based on current state
             *
             * @param {object} view - The view instance
             * @returns {Array<object>} Array of available action definitions
             */
            getAvailableComponentActions(view) {
                const actions = _.isEmpty(view.actionsCache) ? this.getComponentActions(view) : view.actionsCache;

                const availableActions = actions.filter((action) => {
                    if (action.availableKey && action.hasOwnProperty('availableValue')) {
                        return view[action.availableKey] === action.availableValue;
                    }
                    return true;
                });

                return availableActions;
            },

            /**
             * Run action by its name
             *
             * @param {string} actionType - Action type/name to execute
             * @param {object} options - Options object containing action execution context
             * @param {Array<object>} options.actions - Array of available action definitions
             * @param {object} options.recordView - The view instance where action is executed
             * @param {object} options.recordData - The record data associated with the action
             * @param {number} [options.index] - Optional index to directly access action definition in actions array
             * @param {Function} [options.successCallback] - Optional callback to execute on successful action execution
             * @param {Array<View>} [options.components] - Optional array of components to pass to the action
             * @param {object} [options.context] - Context object for state management
             * @param {object} [options.additionalStateUpdates] - Additional UI state updates to merge
             */
            runChatAction: function(actionType, options) {
                const disabledExportActions = [
                    'ai-chat-export-to-png',
                    'ai-chat-export-report-to-pdf',
                    'ai-chat-export-to-csv',
                    'ai-chat-export-to-excel',
                ];
                if (_.contains(disabledExportActions, actionType)) {
                    return;
                }

                const actions = options.actions;
                const recordView = options.recordView;
                const recordData = options.recordData;
                const widgetController = options.widgetController;
                const index = options.index;
                const actionDef = _.isNumber(index) ? actions[index] : this.getActionDefsByType(actionType, actions);

                if (actionDef && !actionDef.disabled && !actionDef.engaged) {
                    const executionOptions = {
                        recordView: recordView,
                        recordData: recordData,
                        widgetController: widgetController,
                    };

                    if (options.successCallback) {
                        executionOptions.successCallback = options.successCallback;
                    }

                    if (options.components) {
                        executionOptions.components = options.components;
                    }

                    if (options.context) {
                        executionOptions.context = options.context;
                    }

                    if (options.additionalStateUpdates) {
                        executionOptions.additionalStateUpdates = options.additionalStateUpdates;
                    }

                    this.execute([actionDef], executionOptions);
                }
            },
        });

        let chatActionProvider = null;

        /**
         * AI Chat Actions Plugin
         * @class Plugins.AiChatComponentActions
         */
        app.plugins.register('AiChatComponentActions', ['view'], {
            onAttach() {
                if (!chatActionProvider) {
                    chatActionProvider = new ChatActionProvider();
                }

                this.chatActionProvider = chatActionProvider;
            },

            /**
             * Run action by its name
             *
             * @param {string} actionType - Action type/name to execute
             * @param {object} options - Options object containing action execution context
             * @param {Array<object>} [options.actions] - Array of available action definitions (optional)
             * @param {View} options.recordView - The view instance where action is executed
             * @param {object} options.recordData - The record data associated with the action
             * @param {object} [options.context] - Context object for state management
             * @param {object} [options.additionalStateUpdates] - Additional UI state updates to merge
             * @param {number} [options.index] - Optional index to select action from array instead of by type
             */
            runChatAction: function(actionType, options) {
                chatActionProvider.runChatAction(actionType, options);
            },
        });
    });
})(SUGAR.App);
