<?php
// Temporary: kompilasi login.blade.php untuk melihat syntax error
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$compiler = new Illuminate\View\Compilers\BladeCompiler(
    new Illuminate\Filesystem\Filesystem,
    __DIR__.'/storage/framework/views'
);
$src = file_get_contents(__DIR__.'/resources/views/auth/login.blade.php');
$compiled = $compiler->compileString($src);
$tmp = __DIR__.'/storage/framework/views/_probe_compiled.php';
file_put_contents($tmp, $compiled);
passthru(PHP_BINARY.' -l '.escapeshellarg($tmp));
