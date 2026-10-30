<?php

namespace App\Console\Commands;

use App\Http\Middleware\SeoMeta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Hasilkan public/sitemap.xml berisi HANYA halaman publik.
 *
 * Sumber kebenaran tunggal: kunci SeoMeta::PUBLIC_PAGES (= nama route
 * yang diizinkan index). Route yang tidak ada di konfigurasi itu tidak
 * akan pernah masuk sitemap.
 *
 * Pemakaian:
 *   php artisan sitemap:generate                      # base dari APP_URL
 *   php artisan sitemap:generate --url=https://sso.ukpbj.demakkab.go.id
 *
 * Jalankan harian lewat scheduler (lihat routes/console.php) atau pasang
 * cron:  * * * * * cd /path && php artisan schedule:run
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate
        {--url= : Base URL absolut (default: config app.url)}
        {--path=public/sitemap.xml : Lokasi file output}';

    protected $description = 'Generate sitemap.xml berisi hanya halaman publik (SeoMeta::PUBLIC_PAGES)';

    /** Prioritas & frekuensi per nama route (halaman di luar ini tetap ikut, pakai default). */
    private const FREQ = [
        'home' => ['changefreq' => 'weekly', 'priority' => '1.0'],
        'login' => ['changefreq' => 'monthly', 'priority' => '0.8'],
    ];

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');

        $urls = [];

        foreach (array_keys(SeoMeta::PUBLIC_PAGES) as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            if ($route === null) {
                $this->warn("Route [{$routeName}] tidak ditemukan — dilewati.");
                continue;
            }

            $path = '/'.ltrim($route->uri(), '/');   // 'home' uri '/' → '/'
            $meta = self::FREQ[$routeName] ?? ['changefreq' => 'monthly', 'priority' => '0.6'];

            $urls[] = [
                'loc' => $base.($path === '//' ? '/' : $path),
                'lastmod' => now()->toDateString(),
                'changefreq' => $meta['changefreq'],
                'priority' => $meta['priority'],
            ];
        }

        if ($urls === []) {
            $this->error('Tidak ada halaman publik — sitemap tidak dibuat.');

            return self::FAILURE;
        }

        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>'
        );

        foreach ($urls as $u) {
            $url = $xml->addChild('url');
            // htmlspecialchars: keamanan XML (URL dari konfigurasi)
            $url->addChild('loc', htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $url->addChild('lastmod', $u['lastmod']);
            $url->addChild('changefreq', $u['changefreq']);
            $url->addChild('priority', $u['priority']);
        }

        $target = base_path($this->option('path'));
        $dir = dirname($target);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($target, $xml->asXML());

        $this->info("sitemap.xml ditulis: {$target} (".count($urls).' URL, base: '.$base.')');

        foreach ($urls as $u) {
            $this->line('  - '.$u['loc']);
        }

        return self::SUCCESS;
    }
}
