// Recorded from SugarEnt 26.1.0 (build 319): clients/base/fields/dashletaction/dashletaction.js; sha256 847aee16eebb44180e3cdac22af42685024451c4feb13a36f6dd4fe01be22aba of the stock file, which follows unchanged.
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
 * @class View.Fields.Base.DashletactionField
 * @alias SUGAR.App.view.fields.BaseDashletactionField
 * @augments View.Fields.Base.ButtonField
 */
({
    events: {
        'click [data-dashletaction]': 'actionClicked',
        'keypress [data-dashletaction]': 'actionClicked',
    },
    extendsFrom: 'ButtonField',
    /**
     * Trigger the function which is in the dashlet view.
     *
     * @param {Event} evt Mouse event.
     */
    actionClicked: function(evt) {
        if (this.preventClick(evt) === false || (evt.key && evt.key !== 'Enter')) {
            return;
        }

        var action = $(evt.currentTarget).data('dashletaction');
        this._runAction(evt, action);
    },

    /**
     * Handles rowaction's event trigger and propagate the event to the main dashlet.
     *
     * @param {Event} evt Mouse event.
     * @param {string} action Name of executing parent action.
     * @protected
     */
    _runAction: function(evt, action) {
        if (!action) {
            return;
        }
        var dashlet = this.view.layout ? _.first(this.view.layout._components) : null;
        if (dashlet && _.isFunction(dashlet[action])) {
            dashlet[action](evt, this.def.params);
        } else if (_.isFunction(this.view[action])) {
            this.view[action](evt, this.def.params);
        }
    },
})
