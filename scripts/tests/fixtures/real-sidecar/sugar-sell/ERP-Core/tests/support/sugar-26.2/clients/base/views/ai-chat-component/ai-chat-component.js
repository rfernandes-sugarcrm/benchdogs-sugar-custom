// Recorded from SugarEnt 26.2.0 (build 296): clients/base/views/ai-chat-component/ai-chat-component.js; sha256 788685f760772b5ac1b59c5036ea785ad5389601d3cf08080b831fb38cdbbcec of the stock file, which follows unchanged.
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
 * Base component for AI Chat message components.
 * All chat message components should extend from this.
 *
 * @class View.Views.Base.AiChatComponentView
 * @alias SUGAR.App.view.views.BaseAiChatComponentView
 * @augments View.View.AiChatComponentSerializableView
 */
({
    extendsFrom: 'AiChatComponentSerializableView',

    // ### START TODO: REMOVE ONCE https://sugarcrm.atlassian.net/browse/AI-5733 is merged

    /**
     * Icon mapping for AI chat components
     * Maps semantic icon names to CSS classes
     */
    iconMapping: {
        'default': {
            'arrow-right': 'sicon sicon-arrow-right text-gray-200',
            'pdf': 'sicon sicon-document-lg text-gray-200',
            'download': 'sicon sicon-download text-gray-200',
            'green-computer': 'sicon sicon-meetings-xl text-gray-200',
            'green-phone': 'sicon sicon-phone-lg text-gray-200',
            'purple-letter': 'sicon sicon-email text-gray-200',
            'task': 'sicon sicon-task-lg text-gray-200',
            'email': 'sicon sicon-email-lg text-indigo-200',
            'opportunity': 'sicon sicon-opportunity-lg bg-red-400 text-red-200',
            'quote': 'sicon sicon-quote-lg bg-green-400 text-gray-200',
            'account': 'sicon sicon-account-lg bg-emerald-400 text-gray-200',
            'contact': 'sicon sicon-contact-lg',
            'lead': 'sicon sicon-lead-lg',
            'case': 'sicon sicon-case-lg',
            'notes': 'sicon sicon-note-lg text-green-200 dark:text-green-300',
            'meeting': 'sicon sicon-meetings-lg',
            'pink-circle': 'sicon sicon-wizard-circle-xl text-fuchsia-600',
            'pink-man': 'sicon sicon-contact-lg text-gray-200',
            'purple-checkbox': 'sicon sicon-task-lg text-gray-200',
            'purple-mailbox': 'sicon sicon-email-lg text-gray-200',
            'blue-circle': 'sicon sicon-wizard-circle-xl text-green-500',
            'green-check': 'sicon sicon-check-circle-lg text-green-600',
            'green-checkmark': 'sicon sicon-check text-green-600',
            'blue-info': 'sicon sicon-info text-green-600',
            'blue-arrow': 'sicon sicon-arrow-right text-green-600',
            'red-warning': 'sicon sicon-warning-lg text-red-600',
            'red-upwards-arrow': 'sicon sicon-escalation-lg text-gray-200',
            'red-upwards-volume': 'sicon sicon-opportunity-lg text-gray-200',
            'yellow-alert': 'sicon sicon-campaigns-lg text-yellow-600',
            'yellow-speaker': 'sicon sicon-campaigns-lg text-white',
            'gray-user': 'sicon sicon-user text-gray-500',
            'gray-shield': 'sicon sicon-shield-lg text-gray-500',
            'gray-arrow': 'sicon sicon-arrow-right text-slate-400',
        },

        // Component-specific overrides
        'ai-chat-component-record-view': {
            'task': 'sicon sicon-task-lg',
        },

        'ai-chat-component-simple-pill': {
            'arrow-right': 'sicon sicon-arrow-right text-gray-600',
            'pdf': 'sicon sicon-document-lg text-purple-200 dark:text-purple-300',
        },
    },

    /**
     * Background color mapping for AI chat components
     * Maps semantic icon names to background CSS classes
     */
    backgroundColorMapping: {
        'default': {
            'arrow-right': 'bg-gray-600',
            'pdf': 'bg-red-600',
            'download': 'bg-green-600',
            'green-computer': 'bg-emerald-400',
            'green-phone': 'bg-green-400',
            'purple-letter': 'bg-indigo-400',
            'task': 'bg-purple-500',
            'email': 'bg-indigo-500',
            'notes': 'bg-emerald-500 dark:bg-emerald-700',
            'pink-circle': 'bg-pink-100',
            'pink-man': 'bg-pink-400',
            'purple-checkbox': 'bg-purple-500',
            'purple-mailbox': 'bg-indigo-400',
            'blue-circle': 'bg-green-100',
            'green-check': 'bg-green-100',
            'green-checkmark': 'bg-green-100',
            'blue-info': 'bg-green-100',
            'blue-arrow': 'bg-green-100',
            'red-warning': 'bg-red-100',
            'red-upwards-arrow': 'bg-red-500',
            'red-upwards-volume': 'bg-red-500',
            'yellow-alert': 'bg-yellow-100',
            'yellow-speaker': 'bg-yellow-400',
            'gray-user': 'bg-gray-100',
            'gray-shield': 'bg-gray-100',
            'gray-arrow': 'bg-gray-100',
        },

        'ai-chat-component-simple-pill': {
            'pdf': 'bg-pink-400 dark:bg-pink-800',
        },
    },

    /**
     * Default background color for unmapped icons
     */
    defaultBackgroundColor: 'bg-pink-400',

    // ### END TODO

    /**
     * Whether the component is expandable to show details
     *
     * @property {boolean}
     */
    isExpandable: false,

    events: {
        'click .action-button': 'handleActionClick',
        'click [data-action="toggle-details-panel"]': 'handleToggleDetailsClick',
    },

    /**
     * Initialize the component
     *
     * @param {object} options Configuration options
     * @inheritdoc
     */
    initialize: function(options) {
        this._initPlugins();
        this._super('initialize', [options]);

        this._initProperties();
        this._initState();
    },

    /**
     * @inheritdoc
     */
    bindDataChange: function() {
        if (this.isExpandable) {
            this.listenTo(this.context, 'change:details_parent_id', this.onToggleDetails);
        }
    },

    /**
     * Initialize component-specific properties
     *
     * @protected
     */
    _initProperties: function() {
        this.componentId = app.utils.generateUUID();
        this.data = this.options.data || {};
        this.title = this.data.title || '';
        this.titleMinimized = '';
        this.variant = this.data.variant || 'default';
        this.showActionsPanel = _.isUndefined(this.options.showActionsPanel) ? true : this.options.showActionsPanel;
        this.showFullData = _.isUndefined(this.options.showFullData) ? false : this.options.showFullData;


        // ### START TODO: REMOVE ONCE https://sugarcrm.atlassian.net/browse/AI-5733 is merged
        // Transform all legacy icon properties to new format
        this._normalizeIconProperties(this.data);
        // ### END TODO
    },

    /**
     * Init plugins dynamically, only adding those that are registered
     * This prevents race conditions where plugins haven't loaded yet
     *
     * @protected
     */
    _initPlugins: function() {
        this.plugins = _.union(this.plugins || [], [
            'AiChatStateManager',
            'ActionRunner',
            'AiChatComponentActions',
            'ReportExport',
        ]);
    },

    /**
     * Initialize component state
     *
     * @protected
     */
    _initState: function() {
        this.isExpanded = false;
        this.syncActions();
    },

    /**
     * Sync component state
     */
    syncActions: function() {
        if (!this.actionsCache) {
            // action cache is required to persist actions state across renders
            this.actionsCache = this.chatActionProvider.getComponentActions(this);
        }

        // available actions list may change on each render
        this.actions = this.chatActionProvider.getAvailableComponentActions(this);
        this.minimizedActions = this.getMinimizedActions();
    },

    /**
     * Get actions for minimized template
     *
     * @returns {Array<object>} Array of action definitions
     *
     * @protected
     */
    getMinimizedActions: function() {
        return this.actions.filter((action) => action.actionType === 'ai-chat-toggle-details');
    },

    /**
     * @inheritdoc
     */
    _renderHtml: function() {
        this.syncActions();
        this.template = this._getTemplate();

        this._super('_renderHtml');
    },

    /**
     * Get the appropriate template
     *
     * @returns {Function} Template function
     * @private
     */
    _getTemplate: function() {
        const templateName = this._getTemplateName();
        const fallbackTemplateName = this._getFallbackTemplateName();

        return app.template.get(templateName) || app.template.get(fallbackTemplateName);
    },

    /**
     * Get template name based on component state
     * Child components can override this to provide custom template names
     *
     * @returns {string} Template name
     * @protected
     */
    _getTemplateName: function() {
        return this.name + this._getTemplateSuffix();
    },

    /**
     * Get fallback template name based on component state
     * Used when the primary template is not found
     *
     * @returns {string} Fallback template name
     * @protected
     */
    _getFallbackTemplateName: function() {
        return 'ai-chat-component' + this._getTemplateSuffix();
    },

    /**
     * Get template suffix based on state
     * @returns {string} Template suffix
     */
    _getTemplateSuffix: function() {
        return this.isExpanded ? '.minimized' : '';
    },

    /**
     * Handle toggle details panel click
     */
    handleToggleDetailsClick: function() {
        this.runChatAction('ai-chat-toggle-details', {
            actions: this.actions,
            recordView: this,
            recordData: this.data,
        });
    },

    /**
     * Handle toggle details event
     *
     * @param {object} panelMeta - Optional panel metadata
     * @param {string} id - ID of the component triggering the event
     */
    onToggleDetails: function(_context) {
        const id = _context.get('details_parent_id')
        if (this.isExpanded) {
            this.isExpanded = false;
            this.render();
        } else if (id == this.componentId) {
            this.isExpanded = true;
            this.render();
        }
    },

    /**
     * Get details panel meta information
     *
     * @returns {object} Panel metadata
     *
     * @protected
     */
    getDetailsPanelMeta: function() {
        return {
            parentView: this,
            components: this._getDetailsPanelComponents(),
            chatTitle: this.title,
        };
    },

    /**
     * Find matching component in fullApiResponse.content array
     * Matches by comparing view property to this.name
     *
     * @param {object} data - Component data containing fullApiResponse
     * @returns {object|false} Matching component or false if not found
     * @protected
     */
    _findMatchingComponentInContent: function(data) {
        const content = data?.fullApiResponse?.content;
        if (!content || !Array.isArray(content)) {
            return false;
        }

        return content.find((component) => component.view === this.name);
    },

    /**
     * Extract component metadata tool property safely
     * Searches fullApiResponse.content for matching component by view name
     *
     * @param {object} data - Component data
     * @returns {string} Tool property value, defaults to 'render-component'
     * @protected
     */
    _extractToolProperty: function(data) {
        const matchingComponent = this._findMatchingComponentInContent(data);
        if (matchingComponent?.tool) {
            return matchingComponent.tool;
        }

        if (data?.tool) {
            return data.tool;
        }

        return 'render-component';
    },

    /**
     * Get details panel components
     *
     * @returns {Array<object>} Array of component configurations
     *
     * @protected
     */
    _getDetailsPanelComponents: function() {
        const options = this._getDetailsPanelOptions();
        const nestedComponents = this.data.nestedComponents;

        if (!Array.isArray(nestedComponents)) {
            return [this._buildDefaultPanelComponent(options)];
        }

        return nestedComponents.map((component) => this._mergeComponentOptions(component, options));
    },

    /**
     * Build the default panel component configuration
     *
     * @param {object} options - Panel options to apply
     * @returns {object} Default component configuration
     *
     * @protected
     */
    _buildDefaultPanelComponent: function(options) {
        return {
            view: this.name,
            tool: this._extractToolProperty(this.data),
            data: this.data,
            options: options,
        };
    },

    /**
     * Merge additional options into a component configuration
     *
     * @param {object} component - Original component configuration
     * @param {object} options - Options to merge
     * @returns {object} New component with merged options
     *
     * @protected
     */
    _mergeComponentOptions: function(component, options) {
        return _.defaults({
            options: _.defaults({}, component.options, options),
        }, component);
    },

    /**
     * Get details panel options
     *
     * @returns {object} Options object for details panel component
     *
     * @protected
     */
    _getDetailsPanelOptions: function() {
        return {
            showFullData: true,
            showActionsPanel: false,
        };
    },

    // ### START TODO: REMOVE ONCE https://sugarcrm.atlassian.net/browse/AI-5733 is merged
    /**
     * Recursively normalize icon properties from legacy to new format
     *
     * Transforms legacy format:
     * { "icon": "green-check" }
     *
     * To new format:
     * {
     *   "icon": "sicon-check-circle-lg",
     *   "iconStyle": "text-green-600",
     *   "iconContainerStyle": "bg-green-100"
     * }
     *
     * @param {object|Array} data - Data object or array to normalize
     * @param {string} componentName - Component name for mapping lookup
     * @protected
     */
    _normalizeIconProperties: function(data, componentName) {
        if (!data) {
            return;
        }

        componentName = componentName || this.options.type || 'default';

        // Handle arrays
        if (_.isArray(data)) {
            _.each(data, function(item) {
                this._normalizeIconProperties(item, componentName);
            }, this);
            return;
        }

        // Handle objects
        if (_.isObject(data)) {
            // Check if this object has an icon property that needs normalization
            if (data.icon && _.isString(data.icon) && !data.icon.startsWith('sicon-')) {
                // This is a legacy icon - transform it
                this._transformLegacyIconProperty(data, componentName);
            }

            // Recursively process all nested objects and arrays
            _.each(data, function(value, key) {
                // Skip the icon properties we just set to avoid infinite recursion
                // Also skip fullApiResponse to avoid processing large API data
                if (key !== 'icon' && key !== 'iconStyle' &&
                    key !== 'iconContainerStyle' && key !== 'fullApiResponse') {
                    if (_.isObject(value) || _.isArray(value)) {
                        this._normalizeIconProperties(value, componentName);
                    }
                }
            }, this);
        }
    },

    /**
     * Transform a single legacy icon property to new format
     * Modifies the data object in place
     *
     * @param {object} data - Object containing legacy icon property
     * @param {string} componentName - Component name for mapping lookup
     * @protected
     */
    _transformLegacyIconProperty: function(data, componentName) {
        const legacyIconName = data.icon;

        // Get mappings
        const defaultIcons = this.iconMapping['default'] || {};
        const componentIcons = this.iconMapping[componentName] || {};
        const defaultColors = this.backgroundColorMapping['default'] || {};
        const componentColors = this.backgroundColorMapping[componentName] || {};

        // Get full icon classes and background classes
        const fullIconClasses = componentIcons[legacyIconName] ||
            defaultIcons[legacyIconName] || legacyIconName;
        const bgClasses = componentColors[legacyIconName] ||
            defaultColors[legacyIconName] || this.defaultBackgroundColor;

        // Extract the sicon-* class name
        const iconMatch = fullIconClasses.match(/sicon-[\w-]+/);
        const iconName = iconMatch ? iconMatch[0] : legacyIconName;

        // Extract icon style classes (remove sicon base class and icon name)
        const iconStyleClasses = fullIconClasses
            .replace(new RegExp('\\b' + iconName.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&') + '\\b', 'g'), '')
            .replace(/\bsicon\b/g, '')
            .replace(/\s+/g, ' ')
            .trim();

        // Update the data object with new format
        data.icon = iconName;
        data.iconStyle = iconStyleClasses || '';
        data.iconContainerStyle = bgClasses || '';
    },
    // ### END TODO

    /**
     * Replace markdown in text with HTML
     *
     * @param {string} text - Text containing markdown
     * @param {object} options - Optional configuration for markdown
     * @returns {string} HTML string with markdown rendered
     *
     * @protected
     */
    replaceMarkdown: function(text, options) {
        return app.utils.markdown.toHtml(text, options);
    },

    /**
     * Replace markdown in text with readable plain text
     *
     * @param {string} text - Text containing markdown
     * @returns {string} Plain text with markdown removed
     *
     * @protected
     */
    replaceMarkdownWithText: function(text) {
        return app.utils.markdown.toPlainText(text);
    },

    /**
     * Handle action button clicks
     * @param {Event} e Click event
     */
    handleActionClick: function(e) {
        e.preventDefault();
        const $button = this.$(e.currentTarget);
        const actionType = $button.data('action');
        const index = $button.data('index');

        if (this.data.reportDef) {
            this.context.set('reportDef', this.data.reportDef);
        }

        this.runChatAction(actionType, {
            actions: this.actions,
            recordView: this,
            recordData: this.data,
            widgetController: this,
            index: index,
        });
    },
})
