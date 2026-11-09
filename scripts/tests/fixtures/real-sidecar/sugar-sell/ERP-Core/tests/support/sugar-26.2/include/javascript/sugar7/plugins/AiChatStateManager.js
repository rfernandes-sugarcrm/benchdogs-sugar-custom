// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/plugins/AiChatStateManager.js; sha256 8b256effd67dea162836749ff4a5f127f84b68c4388be7998c9fc7dabb294e68 of the stock file, which follows unchanged.
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
 * AI Chat State Manager Plugin
 *
 * Provides state management functionality for AI chat widgets
 * Handles persistence of chat state across browser sessions
 */
(function(app) {
    app.events.on('app:init', function() {
        /**
         * AI Chat State Manager Plugin
         * @class Plugins.AiChatStateManager
         */
        app.plugins.register('AiChatStateManager', ['view', 'layout'], {
            /**
             * Key used to store chat state in user lastState
             */
            stateKey: 'ai-widget-chat',

            /**
             * Key used to store chat state in sessionStorage for restore-after-reload.
             * Persists open state and lastChatId across page reloads when server sync is delayed.
             */
            sessionStateKey: 'sugar_ai_widget_chat_state',

            /**
             * Duration for system panel exit animation in milliseconds
             */
            systemPanelExitAnimationMs: 300,

            /**
             * Chat widget state configuration. Options:
             *
             * - isOpen: boolean - whether chat panel is open (visible) or minimized (only floating button)
             * - lastChatId: string|null - ID of the last chat session
             * - buttonPosition: object|null - position of the floating chat button
             *
             * @returns {object} default chat state
             */
            _getDefaultChatState: function() {
                return {
                    isOpen: false,
                    lastChatId: null,
                    buttonPosition: null,
                };
            },
            /**
             * Get chat state from sessionStorage (survives reload, same tab).
             *
             * @returns {object|null} chat state or null
             */
            _getSessionChatState: function() {
                try {
                    if (_.isUndefined(sessionStorage) || !sessionStorage.getItem) {
                        return null;
                    }
                    const raw = sessionStorage.getItem(this.sessionStateKey);
                    const parsed = raw ? JSON.parse(raw) : null;
                    return parsed && _.isObject(parsed) ? parsed : null;
                } catch (e) {
                    app.logger.warn(e);
                    return null;
                }
            },

            /**
             * Write chat state to sessionStorage.
             *
             * @param {object} state - chat state to store
             */
            _setSessionChatState: function(state) {
                try {
                    if (!_.isUndefined(sessionStorage) && sessionStorage.setItem) {
                        sessionStorage.setItem(this.sessionStateKey, JSON.stringify(state));
                    }
                } catch (e) {
                    app.logger.warn(e);
                }
            },

            /**
             * Get current chat state (lastState with sessionStorage fallback for reload).
             *
             * @returns {object} chat state
             */
            _getChatState: function() {
                const state = app.user.lastState.get(this.stateKey) || this._getDefaultChatState();
                const session = this._getSessionChatState();
                if (session) {
                    const merge = _.pick(session, function(value, key) {
                        return _.contains(['isOpen', 'lastChatId', 'buttonPosition'], key) && !_.isEmptyValue(value);
                    });
                    if (!_.isEmpty(merge)) {
                        $.extend(state, merge);
                    }
                }
                return state;
            },

            /**
             * Update chat state, don't use it directly unless you can't use context changes
             *
             * @param {object} updates - key-value pairs to update in chat state
             * @returns {object} updated chat state
             */
            _updateChatState: function(updates) {
                const currentState = this._getChatState();
                const newState = $.extend(true, {}, currentState, updates);

                app.user.lastState.set(this.stateKey, newState);
                this._setSessionChatState(newState);

                return newState;
            },

            /**
             * Trigger a before event for a context-setting method.
             *
             * @param {string} type - type of state change (e.g. 'go_to_dialog', 'toggle_chat')
             * @param {object} newArgs - new values that will be applied to context
             */
            _triggerBeforeContextChange: function(type, newArgs) {
                this.context.trigger('before:state_change', type, newArgs);
            },

            /**
             * set default chat state
             */
            setDefaultState: function() {
                // Each time the widget initializes (page load) we start a fresh
                // conversation, so clear the previously active thread id. This prevents
                // the first prompt after load from silently continuing the old thread.
                this._updateChatState({lastChatId: null});
                const _state = this._getChatState();
                const newValues = {
                    /**
                     * ai-chat opened state
                     */
                    opened: _state.isOpen,
                    /**
                     * ai-chat-panel | ai-chat-history
                     */
                    active_component: 'ai-chat-panel',
                    /**
                     * visibility state which ai-chat-detail-panel is listening to
                     */
                    show_details: false,
                    /**
                     * current dialog info for ai-chat-panel to render
                     * always null on init so the widget opens on a fresh conversation
                     */
                    dialog_info: null,
                    /**
                     * details data for ai-chat-detail-panel to render
                     */
                    details: null,
                    /**
                     * notify elements of ai-chat-panel that the details is opened for a parent with a specific id
                     */
                    details_parent_id: null,
                    /**
                     * show or hide system panel
                     */
                    show_system_panel: false,
                    /**
                     * system panel view instance
                     */
                    system_panel_info: null,
                    /**
                     * container layout info
                     */
                    layout_height: _state.containerHeight || 'auto',
                    /**
                     * chat view offset position
                     */
                    chat_position: _state.panelPosition || null,
                    /**
                     * loading state
                     */
                    loading: false,
                    /**
                     * container resized state
                     */
                    resized: false,
                };
                this._triggerBeforeContextChange('set_default_state', newValues);
                this.context.set(newValues);
            },

            /**
             * call it to navigate ai-chat to chat history
             */
            goToHistory: function() {
                this.stopPrompt();

                const newValues = {
                    active_component: 'ai-chat-history',
                    show_details: false,
                    details: null,
                    details_parent_id: null,
                    show_system_panel: false,
                    system_panel_info: null,
                };
                this._triggerBeforeContextChange('go_to_history', newValues);
                this.context.set(newValues);
            },

            /**
             * call it to navigate ai-chat to dialog
             * @param {object} details - dialog details
             */
            goToDialog: function(dialogInfo) {
                this.stopPrompt();

                // New Chat: clear persisted continuity so re-init cannot reattach.
                if (!dialogInfo) {
                    this._updateChatState({lastChatId: null});
                    app.events.trigger('ai-chat:new-chat');
                }

                const newValues = {
                    active_component: 'ai-chat-panel',
                    show_details: false,
                    details: null,
                    details_parent_id: null,
                    dialog_info: dialogInfo || null,
                    show_system_panel: false,
                    system_panel_info: null,
                };
                this._triggerBeforeContextChange('go_to_dialog', newValues);
                // clear details silently to allow re-opening the same view again
                this.context.unset('dialog_info', {silent: true});
                this.context.set(newValues);
            },

            /**
             * call it to open ai-chat-detail-panel component with predefined data
             * @param {object} details - dialog details
             * @param {string} parentId - notify elements that the panel is opened for a parent with a specific id
             */
            showDetailsPanel: function(details, parentId) {
                const newValues = {
                    active_component: 'ai-chat-panel',
                    show_details: true,
                    details: details || null,
                    details_parent_id: parentId || null,
                    show_system_panel: false,
                    system_panel_info: null,
                };
                this._triggerBeforeContextChange('show_details_panel', newValues);
                this.context.set(newValues);
            },

            /**
             * Toggle dialog details panel opened/closed state
             */
            toggleDetailsPanel: function() {
                const newValues = {
                    show_details: !this.context.get('show_details'),
                    show_system_panel: false,
                    system_panel_info: null,
                };
                this._triggerBeforeContextChange('toggle_details_panel', newValues);
                this.context.set(newValues);
            },

            /**
             * Change ai-chat opened stated to opposite and vice versa
             * @param {boolean} newState - pass true to open and false to close chat
             */
            toggleChat: function(newState) {
                const newValues = {
                    opened: newState === undefined ? !this.context.get('opened') : newState,
                };
                this._triggerBeforeContextChange('toggle_chat', newValues);
                this.context.set(newValues);
            },

            /**
             * Show or hide system panel
             * @param {boolean} show - pass true to show and false to hide system panel
             * @param {object|null} panelData - system panel view data
             */
            toggleSystemPanel: function(show, panelData) {
                const newValues = {
                    show_system_panel: show === undefined ? !this.context.get('show_system_panel') : show,
                    system_panel_info: panelData || null,
                };
                this._triggerBeforeContextChange('toggle_system_panel', newValues);
                this.context.set(newValues);
            },

            /**
             * Hide system panel with animation
             */
            hideSystemPanelWithAnimation: function() {
                const newValues = {
                    show_system_panel: false,
                    system_panel_info: null,
                };
                this._triggerBeforeContextChange('hide_system_panel_with_animation', newValues);
                // Wait for the system panel exit animation (~300ms) to finish before
                // clearing its data; clearing immediately would remove the DOM node
                // and cancel the animation, making the transition feel abrupt.
                this.context.set({show_system_panel: newValues.show_system_panel});

                _.delay(() => {
                    if (this.disposed) {
                        return;
                    }

                    this.context.set({system_panel_info: newValues.system_panel_info});
                }, this.systemPanelExitAnimationMs);
            },

            /**
             * Toggle loading state
             * @param {boolean} show - true to show loading, false to hide
             */
            toggleLoadingState: function(show) {
                const newValues = {
                    loading: Boolean(show),
                };
                this._triggerBeforeContextChange('toggle_loading_state', newValues);
                this.context.set(newValues);
            },

            stopPrompt: function() {
                this.context.trigger('prompt-stop', this);
            },

            /**
             * Track an AI chat error event via analytics.
             *
             * Centralises the telemetry payload so all AI chat views emit
             * a consistent event structure to Pendo.
             *
             * @param {string} errorType - Categorisation of the error
             *   (e.g. 'prompt_response_error', 'history_load_error',
             *   'load_more_error', 'history_fetch_error').
             * @param {object|null} error - The raw error object from the
             *   failed API call, or null for client-side aborts.
             * @param {string|null} [promptText] - The user prompt text that
             *   triggered the request, when applicable.
             */
            _trackAiChatError: function(errorType, error, promptText) {
                app.analytics.track('AI Chat: Error', {
                    error_type: errorType || 'unknown_error',
                    prompt_text: promptText || null,
                    http_status: error?.status,
                    error_code: error?.responseJSON?.error,
                    error_message: error?.responseJSON?.error_message,
                });
            },
        });
    });
})(SUGAR.App);
