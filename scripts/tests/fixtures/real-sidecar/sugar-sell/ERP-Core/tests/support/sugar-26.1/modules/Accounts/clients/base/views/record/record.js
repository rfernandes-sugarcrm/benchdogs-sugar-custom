// Recorded from SugarEnt 26.1.0 (build 319): modules/Accounts/clients/base/views/record/record.js; sha256 5cfd199eaa2085fff6bbb0ba31239f9318fec6c721779fef1e372bd27b1041c3 of the stock file, which follows unchanged.
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
 * @class View.Views.Base.Accounts.RecordView
 * @alias SUGAR.App.view.views.BaseAccountsRecordView
 * @extends View.Views.Base.RecordView
 */
 ({
    extendsFrom: 'RecordView',

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        this.plugins = _.union(this.plugins || [], ['HistoricalSummary']);
        this._super('initialize', [options]);
    }
})
