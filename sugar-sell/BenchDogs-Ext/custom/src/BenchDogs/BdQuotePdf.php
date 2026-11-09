<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

/** The customer's quote layout as a PDF Manager template on Quotes, found by template_name so a rename is kept. */
class BdQuotePdf
{
    public const BASE_MODULE = 'Quotes';

    /** The key this package finds its template by. */
    public const TEMPLATE_NAME = 'erp_customer_quote';

    /** The display name a new template gets. */
    public const NAME = 'Bench Dogs Quote';

    /** Shipped by this package; read from the Sugar root, as Sugar's own seeded templates read their logo. */
    public const LOGO = 'custom/themes/default/images/bd_quote_logo.jpg';

    /** Where the logo goes in the body. */
    private const LOGO_SLOT = '<!--BD_LOGO-->';

    /** Create the template, or bring this package's one to this release's body: created, updated or unchanged. */
    public static function install(): string
    {
        $bean = self::template();
        $values = array(
            'base_module' => self::BASE_MODULE,
            'template_name' => self::TEMPLATE_NAME,
            'body_html' => to_html(self::body(file_exists(self::LOGO))),
            'published' => 'yes',
        );
        $created = empty($bean->id);
        if ($created) {
            $values['name'] = self::NAME;
            $values['team_id'] = '1';
        }
        $changed = false;
        foreach ($values as $field => $value) {
            if (($bean->$field ?? null) !== $value) {
                $bean->$field = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $bean->save();
        }

        return $created ? 'created' : ($changed ? 'updated' : 'unchanged');
    }

    /** Unpublish the template and keep the record: unpublished, already unpublished or absent. */
    public static function uninstall(): string
    {
        $bean = self::template();
        if (empty($bean->id)) {
            return 'absent';
        }
        if ($bean->published === 'no') {
            return 'already unpublished';
        }
        $bean->published = 'no';
        $bean->save();

        return 'unpublished';
    }

    /** The template this package made, or an empty bean. */
    private static function template()
    {
        $bean = \BeanFactory::newBean('PdfManager');
        // Read encoded, as the body is compared encoded (the database layer decodes on write and encodes on read).
        $bean->retrieve_by_string_fields(
            array('base_module' => self::BASE_MODULE, 'template_name' => self::TEMPLATE_NAME)
        );

        return $bean;
    }

    /** The Smarty body; with no logo file it draws no image, because the PDF renderer stops on one it cannot open. */
    public static function body(bool $withLogo = true): string
    {
        $logo = $withLogo ? '<img src="./' . self::LOGO . '" width="210" height="79" />' : '';

        return str_replace(self::LOGO_SLOT, $logo, self::BODY);
    }

    /**
     * Widths are percents of each column's even share (112/88 = 56/44). Price breaks group by ERP-Epicor's ladder rule
     * (ErpLineRole::ladderGroupOf), copied in Smarty as a template cannot call PHP; the render test holds them equal.
     * No backslash, so Sugar's cleaner leaves the saved body alone; the first comment is ERP-Epicor's footer page label.
     */
    private const BODY = <<<'HTML'
{* ERP_PAGE_LABEL: Page: %s of %s *}<table width="100%" cellpadding="1">
<tr>
<td width="112%"><!--BD_LOGO--></td>
<td width="88%">
<table width="100%" cellpadding="1">
<tr><td width="72%"><b>Quote</b></td><td width="128%">{$fields.quote_num}</td></tr>
<tr><td width="72%"><b>Quote Date:</b></td><td width="128%">{if isset($fields.erp_priced_at) && $fields.erp_priced_at != ""}{$fields.erp_priced_at|date_format:"n/j/Y"}{/if}</td></tr>
<tr><td width="72%"><b>Expires:</b></td><td width="128%">{if isset($fields.date_quote_expected_closed) && $fields.date_quote_expected_closed != ""}{$fields.date_quote_expected_closed|regex_replace:'#^([0-9][0-9][0-9][0-9])[-/.]0?([0-9][0-9]?)[-/.]0?([0-9][0-9]?)$#':'$2/$3/$1'|regex_replace:'#^0?([0-9][0-9]?)/0?([0-9][0-9]?)/([0-9][0-9][0-9][0-9])$#':'$1/$2/$3'}{/if}</td></tr>
</table>
</td>
</tr>
</table>
<br />
<table width="100%" cellpadding="1">
<tr>
<td width="112%">
<b>Quote To:</b><br />
&nbsp;&nbsp;&nbsp;&nbsp;{$fields.billing_account_name}<br />
&nbsp;&nbsp;&nbsp;&nbsp;{$fields.billing_address_street}<br />
&nbsp;&nbsp;&nbsp;&nbsp;{$fields.billing_address_city} {$fields.billing_address_state} {$fields.billing_address_postalcode}<br />
&nbsp;&nbsp;&nbsp;&nbsp;{$fields.billing_address_country}<br />
<br />
<b>Ship To:</b><br />
&nbsp;&nbsp;&nbsp;&nbsp;{if isset($fields.shipping_address_name) && $fields.shipping_address_name != ""}{$fields.shipping_address_name}{else}{$fields.shipping_account_name}{/if}<br />
&nbsp;&nbsp;&nbsp;&nbsp;{$fields.shipping_address_street}<br />
&nbsp;&nbsp;&nbsp;&nbsp;{$fields.shipping_address_city} {$fields.shipping_address_state} {$fields.shipping_address_postalcode}<br />
&nbsp;&nbsp;&nbsp;&nbsp;{$fields.shipping_address_country}
</td>
<td width="88%">
<table width="100%" cellpadding="1">
<tr><td width="72%"><b>Project:</b></td><td width="128%">{if isset($fields.e_project_name_c)}{$fields.e_project_name_c}{/if}</td></tr>
<tr><td width="72%"><b>Sales Person:</b></td><td width="128%">{if isset($fields.assigned_user_link.name)}{$fields.assigned_user_link.name}{/if}</td></tr>
<tr><td width="72%"><b>Email:</b></td><td width="128%">{if isset($fields.assigned_user_link.email1)}{$fields.assigned_user_link.email1}{/if}</td></tr>
<tr><td width="72%"><b>Mobile:</b></td><td width="128%">{if isset($fields.assigned_user_link.phone_mobile)}{$fields.assigned_user_link.phone_mobile}{/if}</td></tr>
<tr><td width="72%"><b>Terms:</b></td><td width="128%">{if isset($fields.erp_quotes_billing_terms_name)}{$fields.erp_quotes_billing_terms_name}{/if}<br /><i>(full terms &amp; conditions on last page)</i></td></tr>
</table>
</td>
</tr>
</table>
<br />
<table width="100%" cellpadding="0">
<tr><td width="24%"><b>Contact:</b></td><td width="176%">{$fields.billing_contact_name}</td></tr>
<tr><td width="24%"><b>Phone:</b></td><td width="176%">{if isset($fields.billing_contacts.phone_work)}{$fields.billing_contacts.phone_work}{/if}</td></tr>
<tr><td width="24%"><b>Email:</b></td><td width="176%">{if isset($fields.billing_contacts.email1)}{$fields.billing_contacts.email1}{/if}</td></tr>
</table>
<br />
{foreach from=$product_bundles item="bd_bundle"}{foreach from=$bd_bundle.products key="bd_key" item="bd_item"}{if isset($bd_item.erp_sync_key) && $bd_item.erp_sync_key != ""}{append var="bd_ladder_of" value=$bd_item.erp_sync_key|regex_replace:'#_[^_]*$#':'' index=$bd_key}{elseif isset($bd_item.erp_ladder_group) && $bd_item.erp_ladder_group != ""}{append var="bd_ladder_of" value=$bd_item.erp_ladder_group index=$bd_key}{else}{append var="bd_ladder_of" value="" index=$bd_key}{/if}{/foreach}{/foreach}
<table width="100%" cellpadding="2">
<tr bgcolor="#E6E6E6">
<td width="28%"></td>
<td width="98%"><b>Part #</b></td>
<td width="112%"><b>Customer Part #</b></td>
<td width="231%"><b>Description</b></td>
<td width="70%" align="right"><b>Qty</b></td>
<td width="77%" align="right"><b>Unit Price</b></td>
<td width="84%" align="right"><b>Ext Price</b></td>
</tr>
{assign var="bd_line_count" value=0}
{foreach from=$product_bundles item="bundle"}{foreach from=$bundle.products key="bd_key" item="product"}
{if !isset($product.quantity)}
<tr><td width="28%"></td><td colspan="6">{$product.name}</td></tr>
{elseif !isset($product.erp_total_role) || $product.erp_total_role != "alternative"}
{assign var="bd_line_count" value=$bd_line_count+1}
<tr>
<td width="28%"><b>{$bd_line_count}</b></td>
<td width="98%">{$product.mft_part_num}</td>
<td width="112%">{if isset($product.e_customer_part_num_c)}{$product.e_customer_part_num_c}{/if}</td>
<td width="231%">{$product.name}</td>
<td width="70%" align="right">{$product.quantity}</td>
<td width="77%" align="right">{$product.discount_price|regex_replace:'#^[^0-9-]+#':''}</td>
<td width="84%" align="right">{$product.ext_price|regex_replace:'#^[^0-9-]+#':''}</td>
</tr>
{if isset($product.description) && $product.description != "" && $product.description != $product.name}
<tr><td width="28%"></td><td colspan="6">{$product.description}</td></tr>
{/if}
{if isset($product.e_line_comment_c) && $product.e_line_comment_c != ""}
<tr><td width="28%"></td><td colspan="6">{$product.e_line_comment_c}</td></tr>
{/if}
{assign var="bd_ladder" value=$bd_ladder_of[$bd_key]}
{assign var="bd_rungs" value=0}
{if $bd_ladder != ""}{foreach from=$bd_ladder_of item="bd_other"}{if $bd_other == $bd_ladder}{assign var="bd_rungs" value=$bd_rungs+1}{/if}{/foreach}{/if}
{if $bd_rungs > 1}
<tr><td width="28%"></td><td colspan="6">
<table width="45%" cellpadding="1">
<tr bgcolor="#E6E6E6"><td align="center"><b>Quantity</b></td><td align="center"><b>Unit Price</b></td></tr>
{foreach from=$product_bundles item="bd_ladder_bundle"}{foreach from=$bd_ladder_bundle.products key="bd_rung_key" item="bd_rung"}
{if $bd_ladder_of[$bd_rung_key] == $bd_ladder}
<tr><td align="right">{$bd_rung.erp_break_quantity}{if isset($bd_rung.erp_unit_of_measure)} {$bd_rung.erp_unit_of_measure}{/if}</td><td align="right">{$bd_rung.erp_break_unit_price|regex_replace:'#^[^0-9-]+#':''}</td></tr>
{/if}
{/foreach}{/foreach}
</table>
</td></tr>
{/if}
{/if}
{/foreach}{/foreach}
</table>
<br />
<table width="100%" cellpadding="2">
<tr><td align="center"><b>Terms &amp; Conditions</b></td></tr>
<tr><td bgcolor="#F0F0F0">
<a href="https://benchdogs.com/wp-content/uploads/2020/05/Terms-and-Conditions-of-Sale.pdf">https://benchdogs.com/wp-content/uploads/2020/05/Terms-and-Conditions-of-Sale.pdf</a><br />
<br />
<b>Freight:</b> FOB Bench Dogs<br />
<b>Payment:</b> TBD<br />
<i>Quotation is valid for 30 days.</i><br />
<b>Billing:</b> Product is billable and deliverable upon completion of manufacturing.<br />
<b>Indemnification:</b> Buyer (it's employees, agents and/or contractors) agrees to protect, defend, indemnify and save harmless Bench Dogs (its owners, partners, employees, agents, heirs, successors and/or assigns) from and against all loss, liability, cost and expense (including reasonable attorneys' fees and costs of suit) of every kind arising out of or attributed, directly or indirectly, to the installation and use of these displays and related goods hereunder by Buyer (its employees, agents and/or contractors). Full contract provision language can be found on our website at <a href="https://benchdogs.com/wp-content/uploads/2020/05/Terms-and-Conditions-of-Sale.pdf">https://benchdogs.com/wp-content/uploads/2020/05/Terms-and-Conditions-of-Sale.pdf</a>. The language on the website will, in addition to the language on this quotation, dictate the full terms of the agreement.
</td></tr>
</table>
<br />
Please review and accept by signing below and returning a copy to our offices.<br />
<br />
<table width="100%" cellpadding="1">
<tr>
<td width="108%">By:______________________</td>
<td width="114%">Title:______________________</td>
<td width="78%">Date: ____/____/______</td>
</tr>
<tr><td colspan="3"><b>{$fields.billing_contact_name}</b></td></tr>
<tr>
<td width="108%">{if isset($fields.shipping_address_name) && $fields.shipping_address_name != ""}{$fields.shipping_address_name}{else}{$fields.shipping_account_name}{/if}</td>
<td colspan="2">Purchase Order # ____________________</td>
</tr>
</table>
<br />
Buyer acknowledges that all items ordered are correct in quantity and description. Processing of this order begins promptly after buyer signs this quote and returns a copy to our offices along with any required deposit specified above. All revisions and/or additions to this order placed after work begins may be considered a separate order, may require an additional deposit and possibly shipped at a later date. No deletions from the order will be accepted after work has begun unless approved in writing by Bench Dogs.<br />
<br />
We collect sales tax for states in which we currently meet requirements to collect. The regulations and our requirements to collect state sales tax change regularly, please inquire with any specific questions. Please contact your tax professional with any questions and consider paying any required tax for states in which we do not collect sales tax.<br />
<br />
<br />
<table width="100%" cellpadding="1"><tr><td align="center"><font color="#808080">Thank you for the opportunity to provide this quotation.</font></td></tr></table>
HTML;
}
