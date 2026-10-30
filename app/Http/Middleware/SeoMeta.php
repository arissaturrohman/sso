<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEO per-route: title, meta description, canonical, meta robots, Open Graph
 * dan structured data (JSON-LD) di-share ke view sebagai array `seo`.
 *
 * Dipasang di group `web` (lihat bootstrap/app.php) sehingga route sudah
 * dikenali saat middleware berjalan. Partial `partials.seo-head` membaca
 * array ini dan menyediakan fallback bila view dirender tanpa middleware
 * (mis. halaman error 404 custom).
 *
 * Kebijakan indexasi:
 *  - Hanya route di PUBLIC_PAGES (home, login) → index, follow
 *  - Route lain (dashboard, admin, oauth/authorize, sesi, ganti password,
 *    logout) → noindex, nofollow (proteksi berlapis; crawler sebenarnya
 *    sudah dihadang redirect 302 ke /login)
 *  - Route tidak dikenal (404) → noindex, follow
 */
class SeoMeta
{
    /**
     * Halaman publik yang boleh masuk indeks Google.
     *
     * Title 50–60 karakter, memuat brand "UKPBJ Demak" + keyword
     * "SSO" / "Login Terpadu". Description 150–160 karakter + CTA.
     */
    public const PUBLIC_PAGES = [
        'home' => [
            'title' => 'Beranda SSO UKPBJ Demak — Portal Login Terpadu Aplikasi',
            'description' => 'Portal resmi Single Sign-On (SSO) UKPBJ Demak. Masuk sekali untuk mengakses semua aplikasi layanan pengadaan Kabupaten Demak. Klik Masuk untuk login terpadu.',
            'json_ld' => 'home',
        ],
        'login' => [
            'title' => 'Login Terpadu SSO — UKPBJ Demak | Akses Semua Aplikasi',
            'description' => 'Masuk ke SSO UKPBJ Demak dengan email atau NIP Anda untuk mengakses seluruh aplikasi terhubung. Klik tombol Masuk untuk melanjutkan ke login terpadu sekarang.',
            'json_ld' => 'login',
        ],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // D. Konsistensi trailing slash: GET /login/ → 301 /login
        // Tanpa ini, 1 halaman bisa punya 2 URL (duplikasi konten).
        // Query string dipertahankan (mis. ?redirect=… pada /login).
        if ($request->isMethod('GET')) {
            $path = $request->getPathInfo();

            if ($path !== '/' && str_ends_with($path, '/')) {
                $target = rtrim($path, '/');

                if (($query = $request->getQueryString()) !== null && $query !== '') {
                    $target .= '?'.$query;
                }

                return redirect()->to($target, 301);
            }
        }

        View::share('seo', static::resolve($request));

        $response = $next($request);

        // H. Pertahanan berlapis: kirim header X-Robots-Tag HANYA untuk
        // halaman noindex (privat/endpoint). Halaman publik (/ dan /login)
        // TIDAK PERNAH mendapat header ini — request awal memastikan
        // tidak ada X-Robots-Tag yang memblokir /login.
        $robots = View::shared('seo')['robots'] ?? '';

        if (str_starts_with($robots, 'noindex')) {
            $response->headers->set('X-Robots-Tag', $robots);
        }

        return $response;
    }

    /**
     * Bangun array SEO untuk request berjalan. Dipanggil juga sebagai
     * fallback dari partial seo-head (render halaman error 404, dsb.).
     */
    public static function resolve(Request $request): array
    {
        $route = $request->route();
        $routeName = $route?->getName();

        // Route tidak dikenal → halaman error (404/500). Noindex tapi
        // tetap follow agar link internal di dalamnya masih ditempuh.
        if ($route === null) {
            return [
                'title' => 'Halaman Tidak Ditemukan — '.config('app.name'),
                'description' => 'Halaman yang Anda tuju tidak ditemukan. Kembali ke beranda SSO UKPBJ Demak atau masuk ke halaman login terpadu.',
                'robots' => 'noindex, follow',
                'canonical' => null,
                'json_ld' => [],
            ];
        }

        // Halaman publik yang diizinkan diindeks.
        $page = $routeName !== null ? (self::PUBLIC_PAGES[$routeName] ?? null) : null;

        if ($page !== null) {
            return [
                'title' => $page['title'],
                'description' => $page['description'],
                'robots' => 'index, follow',
                'canonical' => url()->current(),
                'json_ld' => static::jsonLd($page['json_ld']),
            ];
        }

        // Halaman privat / endpoint teknis: noindex. Title & description
        // dibiarkan null agar @section('title') tiap view tetap berlaku.
        return [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
            'canonical' => url()->current(),
            'json_ld' => [],
        ];
    }

    /**
     * Structured data JSON-LD.
     *  - home  : GovernmentOrganization + WebSite + WebApplication
     *  - login : WebPage yang menjadi bagian WebSite
     */
    protected static function jsonLd(string $kind): array
    {
        $orgId = url('/').'#organization';

        $organization = [
            '@context' => 'https://schema.org',
            '@type' => 'GovernmentOrganization',
            '@id' => $orgId,
            'name' => 'UKPBJ Demak',
            'alternateName' => 'Unit Kerja Pengadaan Barang/Jasa Kabupaten Demak',
            'description' => 'Unit Kerja Pengadaan Barang/Jasa (UKPBJ) Kabupaten Demak — penyelenggara layanan pengadaan barang/jasa pemerintah.',
            'url' => url('/'),
            'areaServed' => [
                '@type' => 'AdministrativeArea',
                'name' => 'Kabupaten Demak, Jawa Tengah, Indonesia',
            ],
            'parentOrganization' => [
                '@type' => 'GovernmentOrganization',
                'name' => 'Pemerintah Kabupaten Demak',
            ],
        ];

        if (is_file(public_path('img/logo.png'))) {
            $organization['logo'] = url('img/logo.png');
        }

        if ($kind === 'login') {
            return [[
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                '@id' => url('/login').'#webpage',
                'name' => self::PUBLIC_PAGES['login']['title'],
                'description' => self::PUBLIC_PAGES['login']['description'],
                'url' => url('/login'),
                'inLanguage' => 'id',
                'isPartOf' => [
                    '@type' => 'WebSite',
                    '@id' => url('/').'#website',
                    'name' => config('app.name'),
                    'url' => url('/'),
                ],
            ]];
        }

        return [
            $organization,
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                '@id' => url('/').'#website',
                'name' => config('app.name'),
                'alternateName' => 'SSO UKPBJ Demak',
                'url' => url('/'),
                'inLanguage' => 'id',
                'publisher' => ['@id' => $orgId],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebApplication',
                'name' => config('app.name'),
                'description' => self::PUBLIC_PAGES['home']['description'],
                'url' => url('/login'),
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'inLanguage' => 'id',
                'browserRequirements' => 'Mendukung JavaScript dan cookie',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '0',
                    'priceCurrency' => 'IDR',
                ],
                'author' => ['@id' => $orgId],
            ],
        ];
    }
}
