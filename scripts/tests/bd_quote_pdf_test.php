<?php

/**
 * The customer's quote layout as a PDF Manager template, EXECUTED: what an install and an uninstall do to the
 * template record, and what the body prints from where.
 *
 * Run:  php scripts/tests/bd_quote_pdf_test.php
 * Exit: 0 all passed, 1 one or more failed.
 *
 * Only Sugar's BeanFactory, the PdfManager bean, to_html() and the logger are faked. The fake bean answers
 * retrieve_by_string_fields() from rows held in memory, matching every field asked, as SugarBean does, and does what
 * Sugar 26.1's database layer does to a value: decoded on write (DBManager::decodeHTML) and encoded on a read that
 * asks for it (fetchByAssoc, the default).
 *
 * IF IT WERE BROKEN you would see:
 *   "an install creates the template" FAIL - no template, so PDF Manager has nothing to print the layout with;
 *   "a second install saves nothing" FAIL - every install rewrites the record;
 *   "an older body is brought to this release" FAIL - an upgrade leaves the previous layout in place;
 *   "a renamed template keeps its name" FAIL - an install overwrites an administrator's rename;
 *   "an uninstall unpublishes" FAIL - the template stays offered after its logo file is gone;
 *   "no logo file, no image" FAIL - the renderer stops on an image it cannot open, and no PDF comes out;
 *   a block or a source check FAILS - the layout lost a block of the sample, or prints a value from the wrong field;
 *   "the project prints the quote's Project Name" FAIL - the Project row prints nothing, or the quote comment thread
 *   (erp_quote_comment), not Kinetic's QuoteComment the quote's Project Name holds (rc122);
 *   an F check FAILS - the footer prints PDF Manager's " 1 / 2", not the sample's "Page: 1 of 2".
 */

namespace {
    $checks = array();
    $failed = 0;

    function check(string $name, bool $ok, string $why = ''): void
    {
        global $checks, $failed;
        $checks[] = $name;
        if ($ok) {
            echo "  pass {$name}\n";
            return;
        }
        $failed++;
        echo "  FAIL {$name}" . ($why !== '' ? ": {$why}" : '') . "\n";
    }

    $GLOBALS['log'] = new class {
        public $lines = array();

        public function __call($level, $args)
        {
            $this->lines[] = $level . ': ' . (string) ($args[0] ?? '');
        }
    };

    /** Sugar's to_html() outside the REST API: HTML special characters encoded, as stored. */
    function to_html($string, $encode = true)
    {
        return (empty($string) || !$encode) ? $string : htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    class FakePdfManager
    {
        /** @var array<string, array<string, mixed>> id => stored row */
        public static $rows = array();
        public static $saves = 0;
        public static $lastEncode = null;

        public $id;
        public $name;
        public $base_module;
        public $template_name;
        public $body_html;
        public $published;
        public $team_id;

        public function retrieve_by_string_fields($fields, $encode = true, $deleted = true)
        {
            self::$lastEncode = $encode;
            foreach (self::$rows as $id => $row) {
                $match = true;
                foreach ($fields as $field => $value) {
                    if (($row[$field] ?? null) !== $value) {
                        $match = false;
                    }
                }
                if ($match) {
                    foreach ($row as $field => $value) {
                        $this->$field = ($encode && is_string($value)) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
                    }
                    $this->id = $id;
                    return $this;
                }
            }
            return null;
        }

        public function save()
        {
            self::$saves++;
            if (empty($this->id)) {
                $this->id = 'tpl-' . (count(self::$rows) + 1);
            }
            $decode = function ($value) {
                return is_string($value) ? htmlspecialchars_decode($value, ENT_QUOTES) : $value;
            };
            self::$rows[$this->id] = array(
                'name' => $decode($this->name),
                'base_module' => $decode($this->base_module),
                'template_name' => $decode($this->template_name),
                'body_html' => $decode($this->body_html),
                'published' => $decode($this->published),
                'team_id' => $decode($this->team_id),
            );
            return $this->id;
        }
    }

    class BeanFactory
    {
        public static function newBean($module)
        {
            if ($module !== 'PdfManager') {
                throw new RuntimeException('no other bean: ' . $module);
            }
            return new FakePdfManager();
        }
    }

    $root = dirname(__DIR__, 2);
    $package = $root . '/sugar-sell/BenchDogs-Ext';
    require_once $package . '/custom/src/BenchDogs/BdQuotePdf.php';
}

namespace {
    use Sugarcrm\Sugarcrm\custom\BenchDogs\BdQuotePdf;

    function reset_store(array $rows = array()): void
    {
        FakePdfManager::$rows = $rows;
        FakePdfManager::$saves = 0;
    }

    // ── A. Install and uninstall ─────────────────────────────────────────────────
    echo "A. install and uninstall\n";
    // The install runs from the Sugar root, where the logo is read: here, from the package root.
    chdir($package);

    reset_store();
    $outcome = BdQuotePdf::install();
    $rows = array_values(FakePdfManager::$rows);
    check('an install creates the template', $outcome === 'created' && count($rows) === 1, $outcome);
    $row = $rows[0] ?? array();
    check('on Quotes, under its own template name',
        ($row['base_module'] ?? '') === 'Quotes' && ($row['template_name'] ?? '') === 'erp_customer_quote');
    check('named for the customer, published, for every team',
        ($row['name'] ?? '') === 'Bench Dogs Quote' && ($row['published'] ?? '') === 'yes'
        && ($row['team_id'] ?? '') === '1');
    check('its body is the layout, as the database holds a template',
        ($row['body_html'] ?? '') === BdQuotePdf::body(true));
    check('the template is read encoded, as its body is compared encoded', FakePdfManager::$lastEncode === true);

    FakePdfManager::$saves = 0;
    check('a second install saves nothing', BdQuotePdf::install() === 'unchanged' && FakePdfManager::$saves === 0);

    reset_store(array('tpl-9' => array(
        'name' => 'Our quote', 'base_module' => 'Quotes', 'template_name' => 'erp_customer_quote',
        'body_html' => '<p>an older layout</p>', 'published' => 'no', 'team_id' => '1',
    )));
    $outcome = BdQuotePdf::install();
    $row = FakePdfManager::$rows['tpl-9'];
    check('an older body is brought to this release, published again',
        $outcome === 'updated' && $row['body_html'] === BdQuotePdf::body(true) && $row['published'] === 'yes',
        $outcome);
    check('a renamed template keeps its name', $row['name'] === 'Our quote');
    check('no second template is made', count(FakePdfManager::$rows) === 1);

    reset_store(array('stock' => array(
        'name' => 'Quote', 'base_module' => 'Quotes', 'template_name' => 'quote',
        'body_html' => 'stock', 'published' => 'yes', 'team_id' => '1',
    )));
    BdQuotePdf::install();
    check("Sugar's own Quote template is left as it is",
        FakePdfManager::$rows['stock'] === array('name' => 'Quote', 'base_module' => 'Quotes',
            'template_name' => 'quote', 'body_html' => 'stock', 'published' => 'yes', 'team_id' => '1'));
    check('beside it, its own', count(FakePdfManager::$rows) === 2);

    $outcome = BdQuotePdf::uninstall();
    $own = array_values(array_filter(FakePdfManager::$rows, function ($r) {
        return $r['template_name'] === 'erp_customer_quote';
    }));
    check('an uninstall unpublishes and keeps the record',
        $outcome === 'unpublished' && count($own) === 1 && $own[0]['published'] === 'no', $outcome);
    check('and leaves the stock template published', FakePdfManager::$rows['stock']['published'] === 'yes');
    check('a second uninstall changes nothing', BdQuotePdf::uninstall() === 'already unpublished');
    reset_store();
    check('an uninstall with no template does nothing', BdQuotePdf::uninstall() === 'absent'
        && FakePdfManager::$rows === array());

    // ── B. The logo ──────────────────────────────────────────────────────────────
    echo "B. the logo\n";
    $logo = $package . '/' . BdQuotePdf::LOGO;
    $size = is_file($logo) ? getimagesize($logo) : false;
    check('the package ships the logo taken from the sample', $size !== false && $size[0] === 1119
        && $size[1] === 422 && $size['mime'] === 'image/jpeg', json_encode($size));
    check('the body draws it from the Sugar root, at its own proportions',
        strpos(BdQuotePdf::body(true), '<img src="./custom/themes/default/images/bd_quote_logo.jpg" width="210" height="79" />') !== false);
    check('no logo file, no image', strpos(BdQuotePdf::body(false), '<img') === false);
    chdir(sys_get_temp_dir());
    reset_store();
    BdQuotePdf::install();
    $row = array_values(FakePdfManager::$rows)[0];
    check('an install where the logo file is missing stores the body without it',
        strpos($row['body_html'], '<img') === false && strpos($row['body_html'], '<table') !== false);
    chdir($package);

    // ── C. The blocks of the sample, top to bottom ───────────────────────────────
    echo "C. the blocks, in the sample's order\n";
    $body = BdQuotePdf::body(true);
    $blocks = array(
        'bd_quote_logo.jpg', '<b>Quote</b>', '<b>Quote Date:</b>', '<b>Expires:</b>', '<b>Quote To:</b>',
        '<b>Ship To:</b>', '<b>Project:</b>', '<b>Sales Person:</b>', '<b>Email:</b>', '<b>Mobile:</b>',
        '<b>Terms:</b>', '(full terms &amp; conditions on last page)', '<b>Contact:</b>', '<b>Phone:</b>',
        '<b>Part #</b>', '<b>Customer Part #</b>', '<b>Description</b>', '<b>Qty</b>', '<b>Unit Price</b>',
        '<b>Ext Price</b>', '<b>Quantity</b>', '<b>Terms &amp; Conditions</b>',
        'https://benchdogs.com/wp-content/uploads/2020/05/Terms-and-Conditions-of-Sale.pdf',
        '<b>Freight:</b> FOB Bench Dogs', '<b>Payment:</b> TBD', '<i>Quotation is valid for 30 days.</i>',
        '<b>Billing:</b> Product is billable and deliverable upon completion of manufacturing.',
        '<b>Indemnification:</b> Buyer', 'dictate the full terms of the agreement.',
        'Please review and accept by signing below and returning a copy to our offices.', 'By:', 'Title:',
        'Date: ____/____/______', 'Purchase Order #', 'Buyer acknowledges that all items ordered are correct',
        'unless approved in writing by Bench Dogs.', 'We collect sales tax for states',
        'Thank you for the opportunity to provide this quotation.',
    );
    $at = -1;
    foreach ($blocks as $block) {
        $found = strpos($body, $block, $at + 1);
        check("prints '{$block}' after the block before it", $found !== false && $found > $at);
        if ($found !== false) {
            $at = $found;
        }
    }

    // ── D. Where each value comes from ───────────────────────────────────────────
    echo "D. each value from its stored field\n";
    $sources = array(
        'the quote number' => '<b>Quote</b></td><td width="128%">{$fields.quote_num}</td>',
        'the quote date, the day Kinetic priced it' => '{$fields.erp_priced_at|date_format:"n/j/Y"}',
        'expires, the valid-until date, as M/D/YYYY' => '{$fields.date_quote_expected_closed|regex_replace:',
        'quote to: the billing account and address' => '{$fields.billing_account_name}<br />',
        'ship to: the shipping address' => '{$fields.shipping_address_street}',
        'the sales person, the quote owner' => '{$fields.assigned_user_link.name}',
        "the sales person's email" => '{$fields.assigned_user_link.email1}',
        "the sales person's mobile" => '{$fields.assigned_user_link.phone_mobile}',
        'the terms, the quote billing terms' => '{$fields.erp_quotes_billing_terms_name}',
        'the contact' => '{$fields.billing_contact_name}',
        "the contact's phone" => '{$fields.billing_contacts.phone_work}',
        "the contact's email" => '{$fields.billing_contacts.email1}',
        'the line number, a count of the lines printed' => '<td width="28%"><b>{$bd_line_count}</b></td>',
        'part #' => '{$product.mft_part_num}',
        'customer part #' => '{$product.e_customer_part_num_c}',
        'the description' => '{$product.name}',
        'qty' => '{$product.quantity}',
        'unit price, with no currency symbol' => "{\$product.discount_price|regex_replace:'#^[^0-9-]+#':''}",
        'ext price, with no currency symbol' => "{\$product.ext_price|regex_replace:'#^[^0-9-]+#':''}",
        "the line's long description" => '{$product.e_line_comment_c}',
        "the line's own description, when it says more than its name" => '{$product.description}',
        "a break's quantity and unit" => '{$bd_rung.erp_break_quantity}{if isset($bd_rung.erp_unit_of_measure)} {$bd_rung.erp_unit_of_measure}{/if}',
        "a break's unit price, with no currency symbol" => "{\$bd_rung.erp_break_unit_price|regex_replace:'#^[^0-9-]+#':''}",
    );
    foreach ($sources as $what => $token) {
        check("prints {$what}", strpos($body, $token) !== false, $token);
    }
    // rc122: the Project row prints the quote's Project Name, the field the connector fills from Kinetic's QuoteComment.
    check("the project prints the quote's Project Name (e_project_name_c), only when the quote has one",
        strpos($body, '<b>Project:</b></td><td width="128%">{if isset($fields.e_project_name_c)}'
            . '{$fields.e_project_name_c}{/if}</td>') !== false);
    check('the project row is never the quote comment (on this connection the comment thread, not QuoteComment)',
        strpos($body, 'erp_quote_comment') === false);
    check("core's ERP quote line number is not read (a count is printed)", strpos($body, 'erp_quote_line_num') === false);
    check('a break the seller did not choose is no line row of its own',
        strpos($body, '{elseif !isset($product.erp_total_role) || $product.erp_total_role != "alternative"}') !== false);
    // rc114: a synced rung carries its ladder only in its key (erp_ladder_group is what a copy keeps once the key is
    // shed), so rc112's erp_ladder_group comparison printed no breaks on a quote the ERP priced.
    check("a line's ladder is its key less its last part, else the ladder a copy kept (ERP-Epicor's rule)",
        strpos($body, "{if isset(\$bd_item.erp_sync_key) && \$bd_item.erp_sync_key != \"\"}{append var=\"bd_ladder_of\" value=\$bd_item.erp_sync_key|regex_replace:'#_[^_]*\$#':'' index=\$bd_key}{elseif isset(\$bd_item.erp_ladder_group) && \$bd_item.erp_ladder_group != \"\"}{append var=\"bd_ladder_of\" value=\$bd_item.erp_ladder_group index=\$bd_key}") !== false);
    check("a line's breaks are the lines of its ladder, across every group",
        strpos($body, '{foreach from=$product_bundles item="bd_ladder_bundle"}{foreach from=$bd_ladder_bundle.products key="bd_rung_key" item="bd_rung"}') !== false
        && strpos($body, '{if $bd_ladder_of[$bd_rung_key] == $bd_ladder}') !== false);
    check('a ladder of one rung is no ladder: no table', strpos($body, '{if $bd_rungs > 1}') !== false);
    check('the rc112 comparison, which no synced rung meets, is gone',
        strpos($body, '$bd_rung.erp_ladder_group == $product.erp_ladder_group') === false);
    // A regex_replace's own pattern and back references ('$2/$3/$1') are a format, not a value.
    $values = preg_replace("/regex_replace:'[^']*':'[^']*'/", 'regex_replace', $body);
    check('every value it prints is a stored field: no literal amount, date or name in a value cell',
        preg_match('/\$\d|\d{1,2}\/\d{1,2}\/\d{4}/', $values) === 0);

    // ── E. What the renderer needs ───────────────────────────────────────────────
    echo "E. what the renderer needs\n";
    foreach (array('foreach', 'if') as $tag) {
        $open = preg_match_all('/\{' . $tag . '\s/', $body);
        $close = substr_count($body, '{/' . $tag . '}');
        check("every {{$tag}} is closed", $open === $close, "{$open} opened, {$close} closed");
    }
    check('no tag the PDF security policy refuses', preg_match('/\{(math|fetch|php|include_php|eval)\b/', $body) === 0);
    check('no static call, constant or super global', preg_match('/\$smarty\.|::|\$_(GET|POST|REQUEST|SERVER)/', $body) === 0);
    // The renderer reads a cell's percent width against its column's even share of the table.
    preg_match('/<tr bgcolor="#E6E6E6">\s*(.*?)<\/tr>/s', $body, $header);
    preg_match_all('/width="(\d+)%"/', $header[1] ?? '', $widths);
    check('the line table header spans the table: 7 columns whose widths sum to 700%',
        count($widths[1]) === 7 && array_sum($widths[1]) === 700, json_encode($widths[1]));
    check('the template is not one stock or ERP-Epicor rewrites (no product loop markers)',
        strpos($body, 'START_PRODUCT_LOOP') === false && strpos($body, '$fields.products') === false);

    // ── F. The footer's page label ───────────────────────────────────────────────
    // PDF Manager's footer prints " 1 / 2"; from 1.2.120 ERP-Epicor's quote PDF prints the label a template declares
    // in this Smarty comment (ErpPdfPageLabel), which prints nothing. A comment Sugar's cleaner would drop (an HTML
    // one) is not the marker.
    echo "F. the footer's page label\n";
    $marker = '{* ERP_PAGE_LABEL: Page: %s of %s *}';
    check('the body declares the page label the sample prints ("Page: 1 of 2"), once', substr_count($body, $marker) === 1
        && substr_count($body, 'ERP_PAGE_LABEL') === 1);
    check('...at the very start, before anything that prints', strpos($body, $marker . '<table') === 0);
    check('...and so does the body stored without a logo', strpos(BdQuotePdf::body(false), $marker . '<table') === 0);

    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
