// Recorded from SugarEnt 26.2.0 (build 296): clients/base/views/ai-chat-component-dive-deeper/ai-chat-component-dive-deeper.js; sha256 83ff0da8281fc441bd55dccb6511f0d63afd25b7f8472db00cc2566eacf452f1 of the stock file, which follows unchanged.
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
 * @class View.Views.Base.AiChatComponentDiveDeeperView
 * @alias SUGAR.App.view.views.BaseAiChatComponentDiveDeeperView
 * @augments View.Views.Base.AiChatComponentView
 */
({
    extendsFrom: 'AiChatComponentView',

    events: {
        'click [data-action="dive-deeper-item"]': 'handleItemClick',
    },

    /**
     * @inheritdoc
     */
    _initProperties: function() {
        this._super('_initProperties');

        this.items = this.data.rows || [];
    },

    /**
     * Handle item click - send new prompt
     */
    handleItemClick: function(e) {
        e.preventDefault();
        const $item = $(e.currentTarget);
        const promptText = $item.data('prompt-text');
        const disabled = $item.data('disabled');
        const fromSmartPrompt = this.data && this.data.fromSmartPrompt || false;
        // When this dive-deeper renders clarifier options, each pill carries a
        // stable `key`; the backend resolves routing on the key, not on the
        // displayed (free-text) label. `clarifierId` ties the answer to the
        // question that produced these options.
        const selectedKey = $item.data('key') || null;
        const clarifierId = this.data && this.data.clarifierId || null;

        if (promptText && !disabled) {
            const chatId = this.context.get('dialog_info')?.chatId;

            // A clarifier answer is one-shot — once a pill is clicked, disable
            // every pill in this row so a second click can't submit a stale or
            // duplicate answer. Non-clarifier dive-deeper uses (no clarifierId,
            // e.g. a suggested-prompts list) are left clickable as before.
            // Mark the data disabled and re-render, so the HBS's own
            // {{#if disabled}} styling is the single source of truth for the
            // disabled look (no class strings duplicated here).
            if (clarifierId) {
                _.each(this.items, function(item) {
                    item.disabled = true;
                });
                this.render();
            }

            this.runChatAction('ai-chat-prompt-submit', {
                actions: this.actions || [],
                recordView: this,
                recordData: {
                    promptText: promptText,
                    chatId: chatId,
                    fromSmartPrompt: fromSmartPrompt,
                    selectedKey: selectedKey,
                    clarifierId: clarifierId,
                },
                context: this.context,
                additionalStateUpdates: {active_component: 'ai-chat-panel'},
            });
        }
    },

    /**
     * @inheritdoc
     */
    serializeText: function() {
        return '';
    },

    /**
     * @inheritdoc
     */
    serializePDF: function() {
        return null;
    },
})
