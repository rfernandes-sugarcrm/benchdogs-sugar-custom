// Recorded from SugarEnt 26.1.0 (build 319): modules/Products/clients/base/fields/quote-data-actionmenu/quote-data-actionmenu.js; sha256 bb580ca0ea1e326ecf0a9210b15797b8eff3b5a6b65cc801ef70c780386f2c17 of the stock file, which follows unchanged.
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
 * @class View.Fields.Base.Products.QuoteDataActionmenuField
 * @alias SUGAR.App.view.fields.BaseProductsQuoteDataActionmenuField
 * @extends View.Fields.Base.ActionmenuField
 */
({
    /**
     * @inheritdoc
     */
    extendsFrom: 'BaseActionmenuField',

    /**
     * Skipping ActionmenuField's override, just returning this.def.buttons
     *
     * @inheritdoc
     */
    _getChildFieldsMeta: function() {
        return app.utils.deepCopy(this.def.buttons);
    },

    /**
     * Triggers massCollection events to the context.parent
     *
     * @inheritdoc
     */
    toggleSelect: function(checked) {
        var event = !!checked ? 'mass_collection:add' : 'mass_collection:remove';
        this.model.selected = !!checked;
        this.context.parent.trigger(event, this.model);
    }
})
