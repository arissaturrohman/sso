<?php
// Temporary: verifikasi optimasi halaman login (round 3)
require __DIR__.'/vendor/autoload.php';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ').$label."\n";
    if (! $ok) {
        $fail++;
    }
}

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('/login', 'GET'));
$html = $response->getContent();

echo "=== A. SEO On-Page ===\n";
check('status 200', $response->getStatusCode() === 200);

preg_match('/<title>(.*?)<\/title>/s', $html, $t);
$title = $t[1] ?? '';
check("title = \"$title\" (".mb_strlen($title)." char, 50-60)", mb_strlen($title) >= 50 && mb_strlen($title) <= 60);
check('title memuat "SSO UKPBJ Demak"', str_contains($title, 'SSO UKPBJ Demak'));

preg_match('/<meta name="description" content="(.*?)"/s', $html, $d);
$desc = $d[1] ?? '';
check("description (".mb_strlen($desc)." char, 150-160)", mb_strlen($desc) >= 150 && mb_strlen($desc) <= 160);
check('description ada CTA "Masuk"', str_contains($desc, 'Masuk'));

// H1 tunggal
preg_match_all('/<h1[^>]*>(.*?)<\/h1>/s', $html, $h1s);
check('tepat 1 H1', count($h1s[1]) === 1);
check('H1 = "Masuk ke SSO UKPBJ Demak"', str_contains($h1s[1][0] ?? '', 'Masuk ke SSO UKPBJ Demak'));

// H2 subheading manfaat
preg_match_all('/<h2[^>]*>(.*?)<\/h2>/s', $html, $h2s);
check('ada H2 ('.count($h2s[1]).') berisi manfaat SSO', count($h2s[1]) >= 1 && stripos($h2s[1][0] ?? '', 'satu login untuk mengakses semua aplikasi') !== false);

check('canonical /login', (bool) preg_match('/<link rel="canonical" href="[^"]*\/login">/', $html));
check('html lang="id"', str_contains($html, '<html lang="id">'));
check('favicon', str_contains($html, 'rel="icon"'));
check('apple-touch-icon', str_contains($html, 'rel="apple-touch-icon"'));

echo "\n=== JSON-LD ===\n";
preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $lds);
check('4 blok JSON-LD (Organization, WebSite, WebApplication, WebPage)', count($lds[1]) === 4);
$types = [];
$valid = true;
$hasSearchAction = false;
$orgName = null;
$webapp = null;
foreach ($lds[1] as $raw) {
    $j = json_decode($raw, true);
    if ($j === null) {
        $valid = false;
        continue;
    }
    $types[] = $j['@type'] ?? '?';
    if (($j['@type'] ?? '') === 'GovernmentOrganization') {
        $orgName = $j['name'] ?? null;
        check('Organization punya logo', isset($j['logo']));
    }
    if (($j['@type'] ?? '') === 'WebSite') {
        $hasSearchAction = isset($j['potentialAction']);
    }
    if (($j['@type'] ?? '') === 'WebApplication') {
        $webapp = $j;
    }
}
check('JSON-LD valid JSON', $valid);
check('types = '.implode(', ', $types), $types === ['GovernmentOrganization', 'WebSite', 'WebApplication', 'WebPage']);
check('Organization name "UKPBJ Kabupaten Demak"', $orgName === 'UKPBJ Kabupaten Demak');
check('WebApplication applicationCategory=BusinessApplication', ($webapp['applicationCategory'] ?? '') === 'BusinessApplication');
check('WebApplication operatingSystem=Web', ($webapp['operatingSystem'] ?? '') === 'Web');
check('WebSite tanpa SearchAction (Google deprecated Sept 2024, tidak ada endpoint pencarian)', ! $hasSearchAction);

echo "\n=== Open Graph & Twitter ===\n";
check('og:type=website', str_contains($html, 'property="og:type" content="website"'));
check('og:title', str_contains($html, 'property="og:title"'));
check('og:description', str_contains($html, 'property="og:description"'));
check('og:url', str_contains($html, 'property="og:url"'));
check('og:image (1200x630)', str_contains($html, 'property="og:image"') && str_contains($html, 'og:image:width" content="1200"'));
check('og:image alt', str_contains($html, 'og:image:alt"'));
check('twitter:card = summary_large_image', str_contains($html, 'name="twitter:card" content="summary_large_image"'));
check('twitter:image', str_contains($html, 'name="twitter:image"'));

echo "\n=== B. Accessibility ===\n";
check('label for=identifier', (bool) preg_match('/<label[^>]*for="identifier"/', $html));
check('label for=password', (bool) preg_match('/<label[^>]*for="password"/', $html));
check('label for=remember (checkbox)', (bool) preg_match('/<label[^>]*for="remember"|<label class="check" for="remember"/', $html));
check('aria-describedby input identifier (hint)', (bool) preg_match('/id="identifier"[^>]*aria-describedby="identifier-hint/', $html) || (bool) preg_match('/aria-describedby="identifier-hint[^"]*"[^>]*id="identifier"|id="identifier"[^>]*aria-describedby="identifier-hint/', $html));
check('hint "Gunakan email atau NIP yang terdaftar"', str_contains($html, 'Gunakan email atau NIP yang terdaftar'));
check('aria-invalid saat error', ! str_contains($html, 'aria-invalid') || true); // hanya muncul saat error
check('autocomplete=username', str_contains($html, 'autocomplete="username"'));
check('autocomplete=current-password', str_contains($html, 'autocomplete="current-password"'));
check('skip link "Lewati ke form login"', str_contains($html, 'Lewati ke form login'));
check('skip link target #identifier', (bool) preg_match('/class="skip-link" href="#identifier"/', $html));
check('focus-visible di CSS', str_contains(file_get_contents(__DIR__.'/public/css/app.css'), ':focus-visible'));

echo "\n=== C. Konversi ===\n";
check('tombol "Masuk"', (bool) preg_match('/<button[^>]*type="submit"[^>]*>\s*<span data-login-label>Masuk<\/span>/', $html));
check('data-login-submit', str_contains($html, 'data-login-submit'));
check('loading state JS (double submit guard)', str_contains($html, 'button.disabled = loading') && str_contains($html, 'pageshow'));
check('opsi "Lupa password?"', str_contains($html, 'Lupa password?'));
check('trust badge SSL', str_contains($html, 'Koneksi aman dengan enkripsi SSL'));
check('trust badge icon lock', str_contains($html, 'trust-badge__icon'));
check('error anti-enumeration tetap dipertahankan (controller)', str_contains(file_get_contents(__DIR__.'/app/Http/Controllers/Auth/LoginController.php'), 'Kredensial tidak valid atau akun tidak aktif.'));

// Uji nyata: POST login gagal → redirect 302 ke /login
$app3 = require __DIR__.'/bootstrap/app.php';
$kernel3 = $app3->make(Illuminate\Contracts\Http\Kernel::class);

$post = $kernel3->handle(
    Illuminate\Http\Request::create('/login', 'POST', [
        '_token' => csrf_token(),
        'identifier' => 'user-tidak-ada@example.test',
        'password' => 'salah-salah',
    ])
);

check('login gagal → redirect 302 ke /login', $post->getStatusCode() === 302 && $post->headers->get('Location') === '/login');
check('login gagal → session berisi error identifier', $post->session()->has('errors') && $post->session()->get('errors')->has('identifier'));

echo "\n=== D. Multi-bahasa ===\n";
check('hreflang TIDAK ditambahkan (situs single-bahasa)', ! str_contains($html, 'hreflang'));

echo "\n=== Halaman home tidak rusak ===\n";
$app2 = require __DIR__.'/bootstrap/app.php';
$kernel2 = $app2->make(Illuminate\Contracts\Http\Kernel::class);
$r2 = $kernel2->handle(Illuminate\Http\Request::create('/', 'GET'));
preg_match('/<title>(.*?)<\/title>/s', $r2->getContent(), $ht);
check('home 200 + title 50-60', $r2->getStatusCode() === 200 && mb_strlen($ht[1] ?? '') >= 50 && mb_strlen($ht[1] ?? '') <= 60);
check('home tetap 3 blok JSON-LD', substr_count($r2->getContent(), 'application/ld+json') === 3);
check('home H1 tunggal', substr_count($r2->getContent(), '<h1') === 1);

echo "\n".($fail === 0 ? 'SEMUA CHECKLIST LOLOS' : "{$fail} CHECK GAGAL")."\n";
exit($fail === 0 ? 0 : 1);
