/**
 * @class View.Fields.Base.Quotes.ErpLookupField
 * @alias SUGAR.App.view.fields.BaseQuotesErpLookupField
 * @extends View.Fields.Base.ErpLookupField
 *
 * ERP-Core's erp-lookup field on Quotes, unchanged. Ship Via is not required on a quote: the ERP needs it on an
 * order, so it is checked when the seller presses Submit Order or Order Selected Lines (this package's
 * BdAdmOrderRequirements, named in ERP-Epicor's one refusal beside the FOB, before anything is sent). A quote saves
 * with Ship Via empty, at every stage.
 *
 * Why this file still ships, adding nothing: 0.9.46-rc104 to rc112 shipped a controller at this path that made Ship Via
 * required on every quote. Module Loader never deletes a file a later build stops shipping, so an upgraded tenant
 * would keep that rule; shipping this one in its place overwrites it.
 *
 * This controller is a module field, which Sugar serves with the module's metadata, so a package install reaches the
 * browser on its next load. It needs no file in a script bundle.
 */
({
    extendsFrom: 'ErpLookupField'
})
