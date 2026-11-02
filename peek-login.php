<?php
// Temporary: lihat status + isi respons /login
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$r = $kernel->handle(Illuminate\Http\Request::create('/login', 'GET'));
echo 'status: '.$r->getStatusCode().PHP_EOL;
echo 'location: '.($r->headers->get('Location') ?? '-').PHP_EOL;
echo mb_substr($r->getContent(), 0, 800).PHP_EOL;
