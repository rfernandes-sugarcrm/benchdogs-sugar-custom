<?php

/**
 * The Bench Dogs quote template RENDERED, the way a tenant renders it: Sugar 26.1's own Smarty (4.5.6), under the
 * PDF security policy, over the two variables PDF Manager hands a Quotes template. What it checks is the HTML that
 * TCPDF then draws, read back as rows and cells.
 *
 * Run:  BD_SMARTY_LIBS=<Smarty libs dir> php scripts/tests/bd_quote_pdf_render_test.php
 *       test_php_suites.py finds the libs (smarty_pdf.py: CI downloads them, a SugarEnt tree has them) and passes
 *       BD_ERP_LINE_ROLE, ERP-Epicor's ErpLineRole.php, for section F.
 * Exit: 0 all passed, 1 one or more failed.
 *
 * The data is scripts/tests/fixtures/rc114/quote-8890-pdf-data.json, shaped as Sugar 26.1's PdfManagerHelper builds
 * it for a quote like Epicor quote 8890 (its "about" says where each shape was read). Two pieces of Sugar are copied
 * here because they cannot be loaded without a Sugar install, and each is named where it is copied: the security
 * policy SugarpdfSmarty::initSmartyInstance() sets, and the Quotes rewrite SugarpdfPdfmanager::buildTemplateFile()
 * applies to a template body. Sugar's Smarty mutes undefined-key warnings from a compiled template (Sugar_Smarty);
 * this harness mutes the same ones and fails on any other.
 *
 * IF IT WERE BROKEN you would see:
 *   A1 FAIL - a price break the seller did not choose prints as a line of its own (4 numbered lines, not 2);
 *   A3 / B1 / C FAIL - no price-break table under a laddered line, or one holding another line's rungs;
 *   A4 FAIL - a table under a line that has one rung;
 *   A5 / E FAIL - the line's long description is missing, or its line breaks are lost;
 *   A7 / D FAIL - a unit or extended price prints a currency symbol, or loses its thousands separator;
 *   A8 / D FAIL - Expires prints the user's raw date format, or a date that is not the one stored;
 *   F FAIL - the template's ladder rule and ERP-Epicor's (ErpLineRole::ladderGroupOf) group two lines differently;
 *   G FAIL - the stored body holds a character Sugar's cleaner rewrites, so the tenant renders a different template;
 *   H FAIL - the footer's page label marker prints on the page, or pushes the layout down;
 *   I FAIL - the Project row does not print the quote's Project Name (rc122: the field the connector fills from
 *   Kinetic's QuoteComment), loses its line breaks, or prints something for a quote that has none.
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

    $root = dirname(__DIR__, 2);
    $package = $root . '/sugar-sell/BenchDogs-Ext';
    require_once $package . '/custom/src/BenchDogs/BdQuotePdf.php';

    $libs = getenv('BD_SMARTY_LIBS');
    if (!is_string($libs) || $libs === '' || !is_file($libs . '/Smarty.class.php')) {
        echo "  FAIL Smarty: BD_SMARTY_LIBS names no Smarty libs directory (see scripts/tests/smarty_pdf.py)\n";
        echo "\n1 checks, 1 failed\n";
        exit(1);
    }
    require_once $libs . '/Smarty.class.php';

    /** SugarpdfPdfmanager::buildTemplateFile()'s rewrite of a Quotes template body (Sugar 26.1.0), copied. */
    function sugar_quotes_template(string $body): string
    {
        $body = str_replace('$fields.product_bundles', '$bundle', $body);
        $body = str_replace('$fields.products', '$product', $body);
        $body = str_replace('<!--START_BUNDLE_LOOP-->', '{foreach from=$product_bundles item="bundle"}', $body);
        $body = str_replace('<!--START_PRODUCT_LOOP-->', '{foreach from=$bundle.products item="product"}', $body);

        return str_replace(array('<!--END_PRODUCT_LOOP-->', '<!--END_BUNDLE_LOOP-->'), '{/foreach}', $body);
    }

    /** What the render raised that Sugar's Smarty would not have muted. */
    $GLOBALS['bd_render_errors'] = array();

    /** Render the body as SugarpdfSmarty does: a template file, Sugar's security policy, MOD/APP, fields, bundles. */
    function render(array $fields, array $bundles, ?string $body = null): string
    {
        static $n = 0;
        $dir = sys_get_temp_dir() . '/bd_quote_pdf_render_' . getmypid();
        @mkdir($dir . '/c', 0777, true);
        $file = $dir . '/t' . (++$n) . '.tpl';
        file_put_contents($file, sugar_quotes_template($body ?? \Sugarcrm\Sugarcrm\custom\BenchDogs\BdQuotePdf::body(true)));

        $ss = new Smarty();
        $ss->setTemplateDir($dir);
        $ss->setCompileDir($dir . '/c');
        $ss->error_reporting = error_reporting() & ~E_NOTICE;
        // SugarpdfSmarty::initSmartyInstance() (Sugar 26.1.0), copied.
        $policy = new Smarty_Security($ss);
        $policy->static_classes = array(null);
        $policy->php_functions = array('isset', 'empty', 'count', 'sizeof', 'in_array', 'is_array', 'time');
        $policy->allow_super_globals = false;
        $policy->allow_constants = false;
        $policy->streams = null;
        $policy->disabled_tags = array('eval', 'fetch', 'include_php', 'math', 'php');
        $policy->disabled_special_smarty_vars = array('template_object', 'current_dir');
        $ss->enableSecurity($policy);
        $ss->assign('MOD', array());
        $ss->assign('APP', array());
        $ss->assign('product_bundles', $bundles);
        $ss->assign('fields', $fields);

        // The warnings Sugar_Smarty::mutingErrorHandler() mutes; anything else is recorded.
        set_error_handler(function ($no, $str, $file) {
            if (preg_match('/^(Undefined array key|Trying to access array offset on|Attempt to read property "value" on null)/', $str)
                || preg_match('/Passing null to parameter #.+? is deprecated/', $str)) {
                return true;
            }
            $GLOBALS['bd_render_errors'][] = "{$str} ({$file})";
            return true;
        });
        try {
            return $ss->fetch($file);
        } finally {
            restore_error_handler();
        }
    }

    /** A cell's text as it reads on the page: white space collapsed, as HTML does, and a line break per <br />. */
    function text_of(DOMNode $node): string
    {
        $lines = explode(BR, preg_replace('/\s+/u', ' ', $node->textContent));

        return trim(implode("\n", array_map('trim', $lines)));
    }

    /** What read_back() puts in place of a <br />, so the white space around it collapses as the renderer's does. */
    const BR = "\x1E";

    /** The HTML read back: the header cells, and each numbered line with what prints under it. */
    function read_back(string $html): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        foreach (iterator_to_array($doc->getElementsByTagName('br')) as $br) {
            $br->parentNode->replaceChild($doc->createTextNode(BR), $br);
        }
        $xp = new DOMXPath($doc);
        $header = function (string $label) use ($xp) {
            $cell = $xp->query('//td[b[normalize-space()="' . $label . '"]]/following-sibling::td[1]')->item(0);
            return $cell ? text_of($cell) : null;
        };
        $out = array('expires' => $header('Expires:'), 'quote_date' => $header('Quote Date:'),
            'project' => $header('Project:'), 'lines' => array(), 'notes' => array(), 'table_text' => '');
        $table = $xp->query('//table[tr[1]/td/b[normalize-space()="Part #"]]')->item(0);
        if (!$table) {
            return $out;
        }
        $out['table_text'] = $table->textContent;
        $current = null;
        foreach ($xp->query('./tr', $table) as $i => $tr) {
            if ($i === 0) {
                continue;
            }
            $cells = $xp->query('./td', $tr);
            $first = $cells->length ? text_of($cells->item(0)) : '';
            if ($cells->length === 7 && ctype_digit($first)) {
                $v = array();
                foreach ($cells as $c) {
                    $v[] = text_of($c);
                }
                $out['lines'][] = array('num' => $v[0], 'part' => $v[1], 'customer_part' => $v[2], 'desc' => $v[3],
                    'qty' => $v[4], 'unit' => $v[5], 'ext' => $v[6], 'under' => array(), 'breaks' => null);
                $current = count($out['lines']) - 1;
                continue;
            }
            $nested = $xp->query('.//table', $tr)->item(0);
            if ($nested) {
                $rows = array();
                foreach ($xp->query('./tr', $nested) as $r) {
                    $row = array();
                    foreach ($xp->query('./td', $r) as $c) {
                        $row[] = text_of($c);
                    }
                    $rows[] = $row;
                }
                if ($current === null) {
                    $out['notes'][] = 'a table before any line';
                } else {
                    $out['lines'][$current]['breaks'] = $rows;
                    $out['lines'][$current]['under'][] = 'breaks';
                }
                continue;
            }
            $text = $cells->length > 1 ? text_of($cells->item(1)) : text_of($tr);
            if ($current === null) {
                $out['notes'][] = $text;
            } else {
                $out['lines'][$current]['under'][] = $text;
            }
        }

        return $out;
    }

    function fixture(): array
    {
        $data = json_decode(file_get_contents(__DIR__ . '/fixtures/rc114/quote-8890-pdf-data.json'), true);

        return array($data['fields'], $data['product_bundles']);
    }

    function breaks_of(array $line): ?array
    {
        return $line['breaks'];
    }

    $HEAD = array(array('Quantity', 'Unit Price'));
}

namespace {
    use Sugarcrm\Sugarcrm\custom\BenchDogs\BdQuotePdf;

    // ── A. A quote shaped like Epicor quote 8890 ─────────────────────────────────
    echo "A. a quote shaped like Epicor quote 8890\n";
    list($fields, $bundles) = fixture();
    $GLOBALS['bd_render_errors'] = array();
    $html = render($fields, $bundles);
    $q = read_back($html);
    $lines = $q['lines'];
    check('A1 two lines print, numbered 1 and 2, from four quoted line items: a break the seller did not choose is no line',
        count($lines) === 2 && $lines[0]['num'] === '1' && $lines[0]['part'] === '20498P04W'
        && ($lines[1]['num'] ?? '') === '2' && ($lines[1]['part'] ?? '') === '20498P07W',
        json_encode(array_map(function ($l) {
            return $l['num'] . ' ' . $l['part'] . ' x ' . $l['qty'];
        }, $lines)));
    check('A2 each line prints its quantity, unit price and extended price',
        isset($lines[1]) && array($lines[0]['qty'], $lines[0]['unit'], $lines[0]['ext']) === array('2.00', '22.81', '45.62')
        && array($lines[1]['qty'], $lines[1]['unit'], $lines[1]['ext']) === array('3.00', '22.81', '68.43'),
        json_encode($lines));
    check("A3 under line 1, its ladder's three rungs in a Quantity / Unit Price table",
        isset($lines[0]) && breaks_of($lines[0]) === array_merge($HEAD, array(
            array('1.00 EA', '24.00'), array('10.00 EA', '22.00'), array('50.00 EA', '20.00'))),
        json_encode($lines[0]['breaks'] ?? null));
    check('A4 under line 2, which has one rung, no table', isset($lines[1]) && $lines[1]['breaks'] === null,
        json_encode($lines[1]['breaks'] ?? 'no line 2'));
    check("A5 under line 1, its long description line by line, then its price breaks",
        isset($lines[0]) && $lines[0]['under'] === array(
            "ESSENTIAL TOWER - IMPORT\nINCLUDED:\nSTEEL FRAME WELDMENT, PC-WHITE\nBACK PANELS, PAINTED WHITE\n\nPACKAGING\nPALLET\nLABEL",
            'breaks'),
        json_encode($lines[0]['under'] ?? null));
    check('A6 nothing else prints under line 2 (its description is its name, and it has no comment)',
        isset($lines[1]) && $lines[1]['under'] === array(), json_encode($lines[1]['under'] ?? null));
    check('A7 no currency symbol in the line table', $q['table_text'] !== '' && strpos($q['table_text'], '$') === false,
        $q['table_text']);
    check('A8 Expires M/D/YYYY, as the Quote Date', $q['expires'] === '11/21/2026' && $q['quote_date'] === '10/22/2026',
        json_encode(array($q['expires'], $q['quote_date'])));
    check('A9 the render raised nothing Sugar would not mute', $GLOBALS['bd_render_errors'] === array(),
        implode('; ', $GLOBALS['bd_render_errors']));
    check('A10 nothing prints between the header and the first line', $q['notes'] === array(), json_encode($q['notes']));

    // ── B. A copied quote: the keys are shed, the ladder kept ─────────────────────
    echo "B. a copied quote\n";
    list($fields, $bundles) = fixture();
    foreach ($bundles[0]['products'] as $k => $p) {
        unset($bundles[0]['products'][$k]['erp_sync_key']);
        if (strpos($p['erp_sync_key'], 'ADM__8890_1_') === 0) {
            // ERP-Core's copy hook keeps a ladder of more than one rung; a ladder of one is dropped.
            $bundles[0]['products'][$k]['erp_ladder_group'] = 'ADM__8890_1';
        }
    }
    $q = read_back(render($fields, $bundles));
    check('B1 the copy prints the same ladder under line 1, from erp_ladder_group',
        isset($q['lines'][0]) && breaks_of($q['lines'][0]) === array_merge($HEAD, array(
            array('1.00 EA', '24.00'), array('10.00 EA', '22.00'), array('50.00 EA', '20.00'))),
        json_encode($q['lines']));
    check('B2 and none under line 2', isset($q['lines'][1]) && $q['lines'][1]['breaks'] === null && count($q['lines']) === 2);

    // ── C. Two ladders on one quote ───────────────────────────────────────────────
    echo "C. two ladders on one quote\n";
    list($fields, $bundles) = fixture();
    $second = $bundles[0]['products'][1];
    $second['id'] = 'line-2-rung-2';
    $second['erp_sync_key'] = 'ADM__8890_2_2';
    $second['erp_total_role'] = 'alternative';
    $second['quantity'] = '25.00';
    $second['erp_break_quantity'] = '25.00';
    $second['erp_break_unit_price'] = '$19.50';
    $bundles[0]['products'][4] = $second;
    $q = read_back(render($fields, $bundles));
    check('C1 still two lines', count($q['lines']) === 2, json_encode(array_column($q['lines'], 'part')));
    check("C2 line 1 prints only its own ladder",
        isset($q['lines'][0]) && breaks_of($q['lines'][0]) === array_merge($HEAD, array(
            array('1.00 EA', '24.00'), array('10.00 EA', '22.00'), array('50.00 EA', '20.00'))),
        json_encode($q['lines'][0]['breaks'] ?? null));
    check("C3 line 2 prints only its own two rungs",
        isset($q['lines'][1]) && breaks_of($q['lines'][1]) === array_merge($HEAD, array(
            array('1.00 EA', '22.81'), array('25.00 EA', '19.50'))),
        json_encode($q['lines'][1]['breaks'] ?? null));

    // ── D. Formats ────────────────────────────────────────────────────────────────
    echo "D. formats\n";
    list($fields, $bundles) = fixture();
    $bundles[0]['products'][0]['quantity'] = '75.00';
    $bundles[0]['products'][0]['discount_price'] = '$341.00';
    $bundles[0]['products'][0]['ext_price'] = '$25,575.00';
    $bundles[0]['products'][0]['erp_break_unit_price'] = '$1,341.00';
    $bundles[0]['products'][1]['discount_price'] = "\xE2\x82\xAC1,234.50";
    $bundles[0]['products'][1]['ext_price'] = '$-5.00';
    $q = read_back(render($fields, $bundles));
    check('D1 thousands separators kept, symbol gone: 341.00 and 25,575.00',
        isset($q['lines'][0]) && $q['lines'][0]['unit'] === '341.00' && $q['lines'][0]['ext'] === '25,575.00',
        json_encode($q['lines'][0] ?? null));
    check('D2 a break price over a thousand: 1,341.00',
        isset($q['lines'][0]['breaks'][1]) && $q['lines'][0]['breaks'][1][1] === '1,341.00',
        json_encode($q['lines'][0]['breaks'] ?? null));
    check('D3 any currency symbol goes, a minus stays', isset($q['lines'][1])
        && $q['lines'][1]['unit'] === '1,234.50' && $q['lines'][1]['ext'] === '-5.00', json_encode($q['lines'][1] ?? null));
    $dates = array(
        // the user's date format, as preDisplay writes it => what prints
        '2026-11-05' => '11/5/2026', '2026/11/05' => '11/5/2026', '2026.11.05' => '11/5/2026',
        '2026-12-31' => '12/31/2026', '11/05/2026' => '11/5/2026', '12/31/2026' => '12/31/2026',
        // day-first or dash/dot year-last: the order is the user's own, so it is never swapped
        '05/11/2026' => '5/11/2026', '05.11.2026' => '05.11.2026', '11-05-2026' => '11-05-2026',
    );
    foreach ($dates as $stored => $printed) {
        list($fields, $bundles) = fixture();
        $fields['date_quote_expected_closed'] = $stored;
        $q = read_back(render($fields, $bundles));
        check("D4 Expires '{$stored}' prints '{$printed}'", $q['expires'] === $printed, (string) $q['expires']);
    }
    list($fields, $bundles) = fixture();
    unset($fields['date_quote_expected_closed']);
    $q = read_back(render($fields, $bundles));
    check('D5 no Valid Until, an empty Expires', $q['expires'] === '', (string) $q['expires']);

    // ── E. What prints under a line ───────────────────────────────────────────────
    echo "E. what prints under a line\n";
    list($fields, $bundles) = fixture();
    $bundles[0]['products'][1]['description'] = "INCLUDES MOUNTING HARDWARE<br />\nSHIPS FLAT";
    unset($bundles[0]['products'][0]['e_line_comment_c'], $bundles[0]['products'][0]['description']);
    $bundles[0]['products'][5] = array('id' => 'note-1', 'name' => 'A note the seller typed', 'description' => 'A note the seller typed');
    $q = read_back(render($fields, $bundles));
    check("E1 a line's own description that says more than its name prints under it",
        isset($q['lines'][1]) && $q['lines'][1]['under'] === array("INCLUDES MOUNTING HARDWARE\nSHIPS FLAT", 'A note the seller typed'),
        json_encode($q['lines'][1]['under'] ?? null));
    check('E2 a line with no comment and no description prints only its price breaks',
        isset($q['lines'][0]) && $q['lines'][0]['under'] === array('breaks'), json_encode($q['lines'][0]['under'] ?? null));
    check('E3 a note prints as text, with no number and no table', count($q['lines']) === 2);

    // ── F. The ladder rule agrees with ERP-Epicor's ───────────────────────────────
    echo "F. the ladder rule agrees with ERP-Epicor's ErpLineRole::ladderGroupOf\n";
    $lineRole = getenv('BD_ERP_LINE_ROLE');
    if (!is_string($lineRole) || !is_file($lineRole)) {
        check('F0 ERP-Epicor ErpLineRole.php is given (BD_ERP_LINE_ROLE)', false, (string) $lineRole);
    } else {
        require_once $lineRole;
        // Every shape a quoted line item's identity takes: keyed rungs, a key with no underscore, copies that kept
        // their ladder, a key beside a ladder (the key wins), a ladder of one, and a hand-typed line.
        $shapes = array(
            array('erp_sync_key' => 'ADM__7001_1_1'), array('erp_sync_key' => 'ADM__7001_1_2'),
            array('erp_ladder_group' => 'ADM__7001_1'), array('erp_sync_key' => 'ADM__7001_2_1'),
            array('erp_sync_key' => 'ADM__7001_2_2', 'erp_ladder_group' => 'ADM__7001_9'),
            array('erp_ladder_group' => 'ADM__7001_9'), array('erp_ladder_group' => 'ADM__7001_9'),
            array('erp_sync_key' => 'NOUNDERSCORE'), array('erp_sync_key' => 'NOUNDERSCORE'),
            array('erp_sync_key' => 'ADM__7001_3_1'), array(),
        );
        $products = array();
        $group = array();
        foreach ($shapes as $i => $shape) {
            $products[$i] = array_merge(array('id' => "row-{$i}", 'name' => "PART {$i}", 'mft_part_num' => "P{$i}",
                'quantity' => '1.00', 'discount_price' => '$1.00', 'ext_price' => '$1.00', 'erp_total_role' => '',
                'erp_break_quantity' => ($i + 1) . '.00', 'erp_break_unit_price' => '$1.00', 'erp_unit_of_measure' => 'EA'), $shape);
            $g = \Sugarcrm\Sugarcrm\custom\Erp\ErpLineRole::ladderGroupOf((object) $products[$i]);
            $group[$i] = strpos($g, 'sugar:') === 0 ? null : $g;
        }
        list($fields) = fixture();
        $q = read_back(render($fields, array(array('id' => 'b', 'name' => '', 'products' => $products))));
        $agree = count($q['lines']) === count($shapes);
        $why = array();
        foreach ($q['lines'] as $n => $line) {
            $mates = array();
            foreach ($group as $j => $g) {
                if ($group[$n] !== null && $g === $group[$n]) {
                    $mates[] = array(($j + 1) . '.00 EA', '1.00');
                }
            }
            $expected = count($mates) > 1 ? array_merge($HEAD, $mates) : null;
            if ($line['breaks'] !== $expected) {
                $agree = false;
                $why[] = "line {$line['num']}: printed " . json_encode($line['breaks']) . ', ErpLineRole groups ' . json_encode($expected);
            }
        }
        check('F1 each line prints exactly the rows ErpLineRole puts in its ladder, and only a ladder of two or more',
            $agree, implode('; ', $why) . ' lines=' . count($q['lines']));
        check('F2 the shapes span every branch of the rule (key, key with no underscore, ladder, neither)',
            count(array_filter($group, 'is_null')) === 1 && count(array_unique(array_filter($group))) === 5,
            json_encode($group));
    }

    // ── G. The stored body is the body that renders ───────────────────────────────
    echo "G. the stored body is the body that renders\n";
    $body = BdQuotePdf::body(true);
    $stored = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    // SugarCleaner::cleanHtml()'s test for text it leaves alone (Sugar 26.1.0, include/clean.php), copied: the
    // install saves the body encoded, and a body with only these characters is stored as it is, unpurified.
    check("G1 the encoded body holds only characters Sugar's cleaner leaves alone",
        !preg_match('<[^-A-Za-z0-9 `~!@#$%^&*()_=+{}\[\];:\'",./\\?\r\n|\x80-\xFF]>', $stored),
        preg_match_all('<[^-A-Za-z0-9 `~!@#$%^&*()_=+{}\[\];:\'",./\\?\r\n|\x80-\xFF]>', $stored, $m) ? json_encode(array_unique($m[0])) : '');
    check('G2 no backslash in the body', strpos($body, '\\') === false);

    // ── H. The footer's page label prints nothing in the body ─────────────────────
    echo "H. the footer's page label prints nothing in the body\n";
    list($fields, $bundles) = fixture();
    $html = render($fields, $bundles);
    check('H1 the page label marker is in the body and not in what renders',
        strpos($body, 'ERP_PAGE_LABEL') !== false && strpos($html, 'ERP_PAGE_LABEL') === false
        && strpos($html, '%s') === false && strpos($html, '{*') === false);
    check('H2 what renders starts with the logo table, as before', strpos($html, '<table') === 0, substr($html, 0, 40));

    // ── I. The Project row ────────────────────────────────────────────────────────
    // parseBeanFields hands a text field over as the bean holds it (HTML-encoded by the database layer) through
    // nl2br(), and leaves a null one out: the three shapes below are those.
    echo "I. the Project row\n";
    list($fields, $bundles) = fixture();
    check('I0 control: the recorded quote has no Project Name, and its Project row prints empty',
        !array_key_exists('e_project_name_c', $fields) && read_back(render($fields, $bundles))['project'] === '');
    $GLOBALS['bd_render_errors'] = array();
    $fields['e_project_name_c'] = 'Lobby Casework';
    $q = read_back(render($fields, $bundles));
    check("I1 the Project row prints the quote's Project Name", $q['project'] === 'Lobby Casework',
        json_encode($q['project']));
    check('I2 ...and the render raised nothing Sugar would not mute', $GLOBALS['bd_render_errors'] === array(),
        implode('; ', $GLOBALS['bd_render_errors']));
    $fields['e_project_name_c'] = "Smith &amp; Sons<br />\nPhase 2";
    $q = read_back(render($fields, $bundles));
    check('I3 a Project Name on two lines prints on two lines, its ampersand as one', $q['project']
        === "Smith & Sons\nPhase 2", json_encode($q['project']));
    $fields['e_project_name_c'] = '';
    $q = read_back(render($fields, $bundles));
    check('I4 an empty Project Name prints an empty row', $q['project'] === '', json_encode($q['project']));

    @array_map('unlink', glob(sys_get_temp_dir() . '/bd_quote_pdf_render_' . getmypid() . '/c/*') ?: array());

    echo "\n" . count($checks) . " checks, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
