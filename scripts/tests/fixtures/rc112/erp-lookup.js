// NOT SHIPPED. sugar-sell/BenchDogs-Ext/custom/modules/Quotes/clients/base/fields/erp-lookup/erp-lookup.js as BenchDogs-Ext 0.9.46-rc112 shipped it (source 8a21b491, sha256 51cd14e90193fe41be723b083323266cea614cad51c20190b48fa7955e8832e1 of the shipped file), word for word on the lines below. The CONTROL of ship_via_required_on_real_sidecar.cjs and project_required_on_real_sidecar.cjs: it makes Ship Via required on every quote, so a Save with no Ship Via is refused from the quote's creation on.
/**
 * @class View.Fields.Base.Quotes.ErpLookupField
 * @alias SUGAR.App.view.fields.BaseQuotesErpLookupField
 * @extends View.Fields.Base.ErpLookupField
 *
 * Ship Via is required on every quote: a seller's Save of a quote with no Ship Via is refused, on the create form,
 * the record view and wherever else the field is drawn for editing, at every stage, a quote the ERP sent included.
 * Sugar's own check refuses it and turns the field red with its own message. No "Required" text is drawn: ERP-Core's
 * erp-lookup field draws none (ERP-Epicor 1.2.101 or later).
 *
 * Required in the browser only. The field's definition stays optional, so the connector's quote writes are never
 * asked for a Ship Via; this package's send-time refusal (BdAdmOrderRequirements) stays the guard at Submit Order.
 *
 * Every other ERP lookup on Quotes (FOB, whose rule is ERP-Epicor's, Billing Terms) is ERP-Core's field, unchanged.
 *
 * This controller is a module field, which Sugar serves with the module's metadata, so a package install reaches the
 * browser on its next load. It needs no file in a script bundle.
 */
({
    extendsFrom: 'ErpLookupField',

    /**
     * The id field of the quote lookup this package requires: Ship Via.
     * @property {string}
     */
    bdRequiredIdField: 'erp_quotes_ship_viaerp_lookupvalues_idb',

    /**
     * @inheritdoc
     *
     * Ship Via's own definition on this view is marked required, with Sugar's no_required_placeholder flag, before
     * it is first drawn.
     */
    initialize: function(options) {
        this._super('initialize', [options]);
        if (this.def && this.def.id_name === this.bdRequiredIdField) {
            this.def.required = true;
            this.def.no_required_placeholder = true;
        }
    }
})
