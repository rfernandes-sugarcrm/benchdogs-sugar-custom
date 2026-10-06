<?php
// MLP002 fixture. Every line below is a case whose verdict was taken from the
// REAL SugarCloud scanner (scripts/tests/scanner_oracle.php) and recorded in
// expected.json. Do not edit a line without regenerating that file: run
// test_mlp_lint.py with a SugarEnt tree present and TestAgainstTheRealScanner
// prints the fresh expectations.
namespace A\B {
    use function curl_init;
    use function Other\{stream_get_contents as sgc, fsockopen};
    use Foo\{Bar, Baz as Qux};
    use SugarAutoLoader as SAL;
    $x = curl_init();
    $y = sgc($fp);
    $z = fsockopen('h');
    $w = <<<EOT
    heredoc {$obj->m(stream_is_local('x'))} and curl_exec(\$ch) text
    EOT;
    $v = <<<'NOW'
    nowdoc {$obj->m(stream_is_local('x'))}
    NOW;
    $u = "dq {$a["k"]} {$b->c(socket_create(1,2,3))} end";
    $t = `ls {$dir}`;
    $s = SAL::put('a', 'b');
    $r = \SugarAutoLoader::unlink('a');
    $q = SugarAutoLoader::put('a','b');
    $p = $cls::setLevel(1);
    $o = (new Logger)->setLevel(1);
    $n = $obj?->setLevel(1);
    $m = stream_get_meta_data(...);
    $l = foo(get: 1);
    $k = new class(1) extends \Smarty {};
    $j = '/* not a comment'; $i2 = stream_select($a, $b, $c, 0); // */
    $h = "#"; $g = curl_multi_init();
    $f = '//'; $e = socket_bind($s, 'x');
    // comment ?> <?php $d = stream_socket_client('x');
    $c2 = match(true) { default => stream_set_timeout($s, 1) };
    $b2 = array_map(fn($x) => $x, []);
    $a2 = static fn() => curl_close($h);
}
namespace {
    $aa = new ZipArchive();
    $bb = new Reflectionclass('x');
    class Q extends SplFileObject {}
    interface I extends Reflector {}
    $cc = SugarMin::minify('x');
    $dd = new \Symfony\Component\Filesystem\Filesystem();
    #[Get('/x'), Stream_is_local('y')]
    function f() {}
    $ee = <<<"DQ"
      stream_context_create() {$z->q(stream_copy_to_stream($a, $b))}
      DQ;
    $ff = 1;
    curl_init();
}
