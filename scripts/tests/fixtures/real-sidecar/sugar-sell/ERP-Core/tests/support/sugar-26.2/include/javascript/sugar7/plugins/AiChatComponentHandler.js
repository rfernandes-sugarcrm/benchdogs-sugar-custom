// Recorded from SugarEnt 26.2.0 (build 296): include/javascript/sugar7/plugins/AiChatComponentHandler.js; sha256 31a54ffb872b6f8bf0c5af73bbb22318236cb5005af86f117d6c8ea404cee9cd of the stock file, which follows unchanged.
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
    // Chat components that render AI Analytics (report-derived charts/tables). Withheld from
    // insertion entirely when app.config.reportingAnalyticsEnabled is falsy — see AI Settings.
    const AI_ANALYTICS_COMPONENTS = ['ai-chat-report', 'ai-chat-chart'];

    app.events.on('app:init', function() {
        /**
         * AI Chat State Manager Plugin
         * @class Plugins.AiChatComponentHandler
         */
        app.plugins.register('AiChatComponentHandler', ['view', 'layout'], {

            /**
             * Plugin lifecycle hook - called when plugin is attached to a view/layout.
             * Initializes the array to track dynamically created child views for cleanup.
             */
            onAttach: function() {
                this._childComponents = [];
            },

            /**
             * Plugin lifecycle hook - called when plugin is detached from a view/layout.
             * Cleans up all tracked child components.
             */
            onDetach: function() {
                this.destroyChildComponents();
            },

            /**
             * Appends a component to the target element (without clearing existing content).
             *
             * @param {string} componentName - The name of the component to insert.
             * @param {object} targetEl - jQuery-wrapped DOM element where the component will be appended.
             * @param {object} [data] - Optional data to pass to the component template or view.
             * @param {object} [options] - Options object.
             * @param {object} [options.response] - Optional full API response data to pass to the component.
             * @param {string} [options.groupId] - Group of components rendered in scope of single AI prompt.
             * @returns {object|null} Returns the created view instance if a view was created, null otherwise.
             */
            appendComponent: function(componentName, targetEl, data, options = {}) {
                return this._insertComponent(componentName, targetEl, data, 'append', options);
            },

            /**
             * Prepends a component to the target element (inserts at the beginning).
             * Used for loading older chat messages at the top of the chat history.
             *
             * @param {string} componentName - The name of the component to insert.
             * @param {object} targetEl - jQuery-wrapped DOM element where the component will be prepended.
             * @param {object} [data] - Optional data to pass to the component template or view.
             * @param {object} [options] - Options object.
             * @param {object} [options.response] - Optional full API response data to pass to the component.
             * @param {string} [options.groupId] - Group of components rendered in scope of single AI prompt.
             * @returns {object|null} Returns the created view instance if a view was created, null otherwise.
             */
            prependComponent: function(componentName, targetEl, data, options = {}) {
                return this._insertComponent(componentName, targetEl, data, 'prepend', options);
            },

            /**
             * Replaces the target element's content with a new component (clears existing content first).
             *
             * @param {string} componentName - The name of the component to insert.
             * @param {object} targetEl - jQuery-wrapped DOM element whose content will be replaced.
             * @param {object} [data] - Optional data to pass to the component template or view.
             * @param {object} [options] - Options object.
             * @param {object} [options.response] - Optional full API response data to pass to the component.
             * @param {string} [options.groupId] - Group of components rendered in scope of single AI prompt.
             * @returns {object|null} Returns the created view instance if a view was created, null otherwise.
             */
            replaceWithComponent: function(componentName, targetEl, data, options = {}) {
                return this._insertComponent(componentName, targetEl, data, 'replace', options);
            },

            /**
             * Resolve the component name from a content item, supporting both
             * the streaming format (type: 'xxx') and the legacy blocking format
             * (tool: 'render-component', view: 'xxx').
             *
             * @param {object} contentItem - Content item from an API response.
             * @returns {string|null} Component name or null if invalid.
             */
            _getComponentName: function(contentItem) {
                if (!contentItem) {
                    return null;
                }

                // Streaming format: type contains the component name directly.
                if (contentItem.type) {
                    return contentItem.type;
                }

                // Legacy format: tool must be 'render-component'.
                if (contentItem.tool === 'render-component' && contentItem.view) {
                    return contentItem.view;
                }

                return null;
            },

            /**
             * Internal implementation of component insertion with explicit insertMode parameter.
             *
             * @private
             * @param {string} componentName - The name of the component to insert.
             * @param {object} targetEl - jQuery-wrapped DOM element where the component will be inserted.
             * @param {object} [data] - Optional data to pass to the component template or view.
             * @param {string} insertMode - 'append', 'prepend', or 'replace'.
             * @param {object} [options] - Options object.
             * @param {object} [options.response] - Optional full API response data to pass to the component.
             * @param {string} [options.groupId] - Group of components rendered in scope of single AI prompt.
             * @param {boolean} [options.showFullData] - Whether to show full data in the component.
             * @param {boolean} [options.showActionsPanel] - Whether to show actions panel in the component.
             * @returns {object|null} Returns the created view instance if a view was created, null otherwise.
             */
            _insertComponent: function(componentName, targetEl, data, insertMode, options) {
                // AI Analytics is disabled at the org level — withhold report/chart components.
                if (_.contains(AI_ANALYTICS_COMPONENTS, componentName) && !app.config.reportingAnalyticsEnabled) {
                    return null;
                }

                // to do - remove this once platform ai api will be fixed
                if (componentName === 'ai-chat-component') {
                    componentName = 'ai-chat-markdown';
                }

                const template = app.template.get('ai-chat-body.' + componentName);
                const groupId = options.groupId;
                const showFullData = options.showFullData;
                const showActionsPanel = options.showActionsPanel;

                // Try HBS template first (stateless, more performant)
                if (template) {
                    const html = template(data || {});
                    this._insertElement(html, targetEl, insertMode, groupId);

                    return null;
                }

                const viewMeta = {
                    groupId: groupId,
                    type: componentName,
                    context: this.context,
                    showFullData: showFullData,
                    showActionsPanel: showActionsPanel,
                };
                if (data) {
                    viewMeta.data = data;

                    const fullApiResponse = options.response;

                    if (fullApiResponse && Object.keys(fullApiResponse).length > 0) {
                        viewMeta.data.fullApiResponse = fullApiResponse;
                    }
                }

                const view = app.view.createView(viewMeta);
                view.parentView = this;
                view.render();
                this._insertElement(view.$el, targetEl, insertMode, groupId);

                this._childComponents.push(view);

                return view;
            },

            /**
             * Unified method to insert an element (HTML string or jQuery element) into target.
             *
             * @private
             * @param {string|jQuery} element - The HTML string or jQuery element to insert.
             * @param {jQuery} targetEl - Target container element.
             * @param {string} insertMode - 'append', 'prepend', or 'replace'.
             * @param {string} [groupId] - Optional group identifier for the element.
             */
            _insertElement: function(element, targetEl, insertMode, groupId) {
                let newElement;

                switch (insertMode) {
                    case 'replace':
                        targetEl.html(element);
                        newElement = targetEl.children().last();
                        break;
                    case 'prepend':
                        targetEl.prepend(element);
                        newElement = targetEl.children().first();
                        break;
                    case 'append':
                    default:
                        targetEl.append(element);
                        newElement = targetEl.children().last();
                        break;
                }

                if (groupId && newElement) {
                    newElement.attr('data-group', groupId);
                }
            },

            /**
             * Destroys all dynamically created child views tracked by the plugin.
             * Called automatically on view disposal, but can also be called manually
             * to clear components (e.g., when clearing chat history).
             */
            destroyChildComponents: function() {
                _.each(this._childComponents, function(view) {
                    if (view && !view.disposed) {
                        view.dispose();
                    }
                });

                this._childComponents = [];
            },
        });
    });
})(SUGAR.App);
