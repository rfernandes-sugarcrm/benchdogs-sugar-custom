// Recorded from SugarEnt 26.2.0 (build 296): clients/base/views/ai-chat-component-record-view/ai-chat-component-record-view.js; sha256 4080452a449c869e0d4e3c6fd12daced71c6134f4cdec35dfeb13325dd9eae34 of the stock file, which follows unchanged.
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
 * @class View.Views.Base.AiChatComponentRecordView
 * @alias SUGAR.App.view.views.BaseAiChatComponentRecordView
 * @augments View.Views.Base.AiChatComponentView
 */
/* global app */
({
    extendsFrom: 'AiChatComponentView',

    plugins: ['AiChatComponentHandler'],

    /**
     * Selector for the in-card container that holds `cardData.inlineComponents`.
     */
    INLINE_COMPONENTS_SELECTOR: '[data-type="card-inline-components"]',

    /**
     * @inheritdoc
     */
    _initProperties: function() {
        this.isExpandable = true;

        this._super('_initProperties');

        this.cardData = this.data;
        this.cardData.columnsPerRow = this.cardData.columnsPerRow || 3;

        if (!_.isObject(this.cardData.title)) {
            this.cardData.title = {text: this.cardData.title || '', meta: []};
        }

        const titleMeta = this.cardData.title.meta;
        let displayTitle = this.cardData.title.text || '';
        if (_.isArray(titleMeta) && titleMeta[0] && titleMeta[0].title) {
            displayTitle = titleMeta[0].title;
        } else if (titleMeta && titleMeta.title) {
            displayTitle = titleMeta.title;
        }
        this.cardData.titleTooltip = displayTitle;
        this.title = displayTitle;

        this.cardData.rows = _.map(this.cardData.rows || [], function(row) {
            return _.mapObject(row, function(value) {
                if (!_.isArray(value)) {
                    return value;
                }
                return _.map(value, function(item) {
                    return _.isObject(item) ? item : {text: item || '', meta: []};
                });
            });
        });
    },

    /**
     * Override to always use the same template regardless of state
     * This component doesn't have a separate minimized template
     *
     * @returns {string} Template name
     * @protected
     */
    _getTemplateName: function() {
        return this.name;
    },

    /**
     * @inheritdoc
     */
    _render: function() {
        this._super('_render');

        this._renderInlineComponents();
    },

    /**
     * Render `cardData.inlineComponents` as child views inside the card body.
     *
     * Unlike `nestedComponents` — which the base view defers to the slide-out
     * details panel behind a "View more details" toggle — these render in
     * place and stay visible. Each child is created through the
     * AiChatComponentHandler plugin so it is a full view: its own events and
     * re-render logic keep working, which is what lets the next-best-action
     * chips disable themselves in place after their drawer saves.
     *
     * @protected
     */
    _renderInlineComponents: function() {
        const inlineComponents = this.cardData.inlineComponents;
        const targetEl = this.$(this.INLINE_COMPONENTS_SELECTOR);

        if (!_.isArray(inlineComponents) || !targetEl.length) {
            return;
        }

        // The template was just re-rendered, so targetEl is a fresh empty
        // node — but the previous render's child views are still live and
        // listening (e.g. on app.events). Dispose them before replacing.
        this.destroyChildComponents();

        _.each(inlineComponents, function(contentItem) {
            const componentName = this._getComponentName(contentItem);
            if (!componentName) {
                return;
            }

            this.appendComponent(componentName, targetEl, contentItem.data, {
                groupId: this.options.groupId,
            });
        }, this);
    },

    /**
     * @inheritdoc
     */
    serializeText: function() {
        let content = '';

        try {
            if (this.title) {
                content = `${this.title}:\n`;
            }

            this.cardData.rows.forEach((row) => {
                // A row can carry more than one label/value pair (e.g. a paired
                // "Open Deals"/"Expected Close" row rendered as two columns on
                // one line) — join every entry so none are silently dropped.
                const line = Object.entries(row)
                    .map(([label, value]) => `${label}: ${value}`)
                    .join(' | ');
                content += `${line}\n`;
            });
        } catch (e) {
            app.logger.error('Error serializing AiChatComponentRecordView:', e);
        }

        return content;
    },

})
