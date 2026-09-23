<?php
// MLP002 fixture. Every line below is a case whose verdict was taken from the
// REAL SugarCloud scanner (scripts/tests/scanner_oracle.php) and recorded in
// expected.json. Do not edit a line without regenerating that file: run
// test_mlp_lint.py with a SugarEnt tree present and TestAgainstTheRealScanner
// prints the fresh expectations.
namespace Acme\Probe;
use function Other\curl_init as myinit;
// L4 comment: stream_resolve_include_path('x');
/* L5 block: curl_init(); */
# L6 hash: fsockopen('h', 80);
$a = 'stream_resolve_include_path("x")'; // L7 single-quoted string
$b = "curl_exec($ch)"; // L8 double-quoted string
$c = <<<EOT
stream_get_contents(\$fp)
EOT; // L9-11 heredoc
$d = stream_resolve_include_path('x'); // L12 bare unqualified in namespace
$e = \stream_context_create([]); // L13 fully qualified
$f = STREAM_resolve_include_path('x'); // L14 upper case
$g = $obj->stream_get_contents(); // L15 method
$h = Foo::curl_init(); // L16 static method
$i = function_exists('curl_init'); // L17 string arg
$j = Sub\curl_init(); // L18 qualified namespaced
$k = new stream_filter(); // L19 new with same name
function socket_create() {} // L20 declaration
$l = stream_resolve_include_path // L21 split call
    ('x');
$m = 'stream_get_contents'('x'); // L23 string callee
$n = myinit(); // L24 aliased import
$o = $obj?->curl_init(); // L25 nullsafe method
$p = "{$x->y} " . curl_init(); // L26 after interpolated string
$q = 'http://example.com'; $r = curl_init($q); // L27 url before call
$s = CURL_INIT; // L28 constant
$t = fn() => socket_create(1,1,1); // L29 arrow fn
#[Attr(stream_is_local('x'))] // L30 attribute
class Z { const X = 1; }
$u = unlink('x'); // L32 blackList control
$v = new \ZipArchive(); // L33 class blacklist
$w = new ZipArchive(); // L34 class unqualified in namespace
$x2 = $obj->unserialize('x'); // L35 method blacklist
$y = \SugarAutoLoader::put('a','b'); // L36 static method-of-class
$z = unserialize('x'); // L37 function unserialize
$aa = $obj->setLevel('x'); // L38 method blacklist camel case
class Y extends \Smarty {} // L39 extends smarty
$bb = get('x'); // L40 blacklisted "get" as function
$cc = $o->get('x'); // L41 get as method
$dd = eval('1;'); // L42 eval
$ee = `ls`; // L43 backtick
$ff = include 'foo.tpl'; // L44 include non-php
$gg = new \Sugar_Smarty(); // L45 SecureSmarty class
