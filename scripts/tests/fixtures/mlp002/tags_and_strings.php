<?php
// MLP002 fixture. Every line below is a case whose verdict was taken from the
// REAL SugarCloud scanner (scripts/tests/scanner_oracle.php) and recorded in
// expected.json. Do not edit a line without regenerating that file: run
// test_mlp_lint.py with a SugarEnt tree present and TestAgainstTheRealScanner
// prints the fresh expectations.
$x = `whoami`;
/* multi
line curl_init() */ $y = curl_init();
$z = "a\"b" . curl_exec($c);
$q = 'it\'s' . socket_read($s, 1);
?>
text fsockopen()
<?= stream_get_line($f, 1) ?>
<?php
$w = 1
    +stream_bucket_new($a, "x");
