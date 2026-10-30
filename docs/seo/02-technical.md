# 02 — Technical SEO: SSO IdP UKPBJ Demak

Dokumen technical SEO (audit round 1 + round 2 "technical verification").
Semua snippet di sini **sudah diimplementasikan** kecuali ditandai opsional.

- Target: https://sso.ukpbj.demakkab.go.id
- Tanggal pembaruan: 2026-10-30

---

## 1. Peta URL & keputusan indexasi

| URL | Metode | Akses | Status HTTP (crawler) | robots | Alasan |
|-----|--------|-------|------------------------|--------|--------|
| `/` | GET | publik | 200 | `index,follow` + X-Robots-Tag tidak dikirim | Landing IdP, entitas instansi |
| `/login` | GET | publik (guest) | 200 | `index,follow` + X-Robots-Tag tidak dikirim | Halaman konversi utama |
| `/login/` (trailing slash) | GET | publik | **301** → `/login` | — | Anti-duplikasi URL (SeoMeta) |
| `/login?redirect=…` | GET | publik | 200 | `index,follow` | Canonical tanpa query → variasi tidak terindeks |
| `/dashboard`, `/sessions`, `/password/change`, `/admin/*` | GET | auth | **302** → `/login` | `noindex,nofollow` + Disallow + X-Robots-Tag | Privat; 302 = redirect sementara |
| `/oauth/authorize` | GET | auth | 302 → `/login` | `noindex,nofollow` + Disallow | Consent berisi data sesi |
| `/oauth/jwks`, `/oauth/userinfo`, `/oauth/revoke`, `/oauth/token` | GET/POST | teknis | 200/401 JSON | Disallow + `X-Robots-Tag: noindex, nofollow` | Bukan konten HTML |
| `/api/*` | GET | auth:api | JSON | Disallow | Bukan HTML |
| `/sso/logout` | GET | mixed | — | Disallow (`/sso/`) | Aksi, bukan konten |
| `/up` | GET | publik | 200 | Disallow | Health check — noise crawl |
| *(rute tak dikenal)* | GET | — | **404** (bukan redirect login) | `noindex,follow` via meta | Custom `errors/404.blade.php` |

**`/register` & `/forgot-password` tidak ada** di aplikasi (password direset admin).
`/forgot-password` tetap di-`Allow:` di robots.txt sebagai persiapan (saat ini 404 jika dicrawl — aman, tidak masuk sitemap).

---

## 2. `public/robots.txt` (final — sudah ditulis)

```
User-agent: *

# --- Halaman publik (boleh diindeks) ---
Allow: /
Allow: /login
Allow: /forgot-password        # disiapkan untuk masa depan; route belum ada

# --- Area privat / teknis (jangan diindeks) ---
Disallow: /dashboard
Disallow: /oauth/
Disallow: /admin
Disallow: /api/
Disallow: /sso/
Disallow: /user/
Disallow: /settings
Disallow: /sessions
Disallow: /password/
Disallow: /logout
Disallow: /up

# --- Query string sensitif (kode & token OAuth 2.0) ---
Disallow: /*?token=
Disallow: /*&token=
Disallow: /*?code=
Disallow: /*&code=

# --- Peta situs ---
Sitemap: https://sso.ukpbj.demakkab.go.id/sitemap.xml
```

Kepatuhan pada spesifikasi:
- ✅ `Allow: /`, `/login`, `/forgot-password` — tanpa blokir crawler
- ✅ Disallow semua area privat yang diminta (`/dashboard`, `/oauth/`, `/api/`, `/admin`, `/sso/`, `/user/`, `/settings`)
- ✅ Query sensitif `?token=`/`?code=` memakai wildcard `*` (dukungan Google/Bing)
- ✅ **Tanpa** `Disallow: /` tunggal (fatal — memblokir seluruh situs)
- ✅ Tidak ada blok `User-agent: Googlebot`/`Bingbot` yang membatasi
- ⚠️ Perbaikan round 1: `Disallow: /admin/` → `Disallow: /admin` (tanpa slash; route `/admin` tanpa trailing slash tidak cocok dengan pola bergaris miring — RFC 9309 cocok *prefix*)

> Catatan: blokir robots mencegah **crawl**, bukan **indeks**. URL telanjur terindeks
> dinetralkan oleh canonical + meta robots (lapis pertahanan kedua).

## 3. `public/sitemap.xml` — generate otomatis via Laravel

**Implementasi**: command `php artisan sitemap:generate`
(`app/Console/Commands/GenerateSitemap.php`), tanpa dependensi baru.

- Sumber kebenaran tunggal: **kunci `SeoMeta::PUBLIC_PAGES`** — halaman yang
  tidak diizinkan index tidak akan pernah masuk sitemap.
- Opsi `--url=` (base absolut) dan `--path=` (output).
- Dijadwalkan harian `01:30` via `routes/console.php` → `Schedule::command('sitemap:generate')->dailyAt('01:30')`.
- **Butuh cron server**: `* * * * * cd /path/app && php artisan schedule:run`

Hasil saat ini (2 URL, XML tervalidasi `simplexml`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://sso.ukpbj.demakkab.go.id/</loc><lastmod>2026-10-30</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url><url><loc>https://sso.ukpbj.demakkab.go.id/login</loc><lastmod>2026-10-30</lastmod><changefreq>monthly</changefreq><priority>0.8</priority></url></urlset>
```

Daftarkan di **Google Search Console** (Sitemaps → `https://sso.ukpbj.demakkab.go.id/sitemap.xml`)
dan **Bing Webmaster Tools** (Sitemaps → URL yang sama) — langkah ada di `04-checklist.md`.

## 4. Meta robots per halaman (middleware SeoMeta)

`app/Http/Middleware/SeoMeta.php` (terdaftar di group `web`, urutan pertama — `bootstrap/app.php`):

| Kelompok route | `<meta name="robots">` | Header `X-Robots-Tag` |
|---|---|---|
| `home`, `login` | `index, follow` | **Tidak dikirim** (dipastikan oleh verifikasi) |
| Route lain (auth/admin/oauth teknis) | `noindex, nofollow` | `noindex, nofollow` (lapis kedua) |
| Route tak dikenal (404) | `noindex, follow` | — (render via view fallback) |

Poin keamanan SEO (bagian H spec):
- `/` dan `/login` **tidak pernah** menerima `X-Robots-Tag` — diverifikasi probe:
  `XRobots=(tidak ada)` untuk semua halaman publik.
- Header hanya dipasang bila `str_starts_with($robots, 'noindex')`.

## 5. Canonical URL & redirect 301 konsisten

- **Canonical** = `url()->current()` pada halaman publik → membuang query string
  (`/login?redirect=…` → canonical `/login`).
- **Trailing slash**: `SeoMeta::handle()` mengembalikan **301**
  `GET /login/` → `/login` (query string dipertahankan). Diverifikasi: status 301.
- **http → https**: 301 via Certbot + `URL::forceScheme('https')` + `forceRootUrl(APP_URL)`
  di `AppServiceProvider::boot()` (produksi) → canonical selalu `https://sso.ukpbj.demakkab.go.id`.
- **www → non-www**: di level Nginx (snippet §7) — canonical Laravel konsisten karena `forceRootUrl`.

## 6. Status code (bagian E spec)

| Skenario | Status | Status implementasi |
|---|---|---|
| Halaman ada (`/`, `/login`) | 200 | ✅ diverifikasi |
| Redirect permanen (trailing slash, http→https, www) | 301 | ✅ `/login/` → 301 diverifikasi |
| Redirect sementara (belum login) | 302 | ✅ `/dashboard`, `/admin` → 302 diverifikasi |
| Halaman tidak ada | **404** (bukan redirect ke login) | ✅ diverifikasi: `Location` kosong, view custom `errors/404.blade.php` |
| Resource dihapus permanen | 410 | Belum ada konten yang dihapus — pakai `abort(410)` bila perlu |
| Maintenance | 503 + `Retry-After` | ✅ bawaan Laravel: `php artisan down --retry=60` → header `Retry-After: 60` otomatis (`PreventRequestsDuringMaintenance`) |

## 7. Konfigurasi Nginx (kompresi, cache, canonical host)

```nginx
server {
    listen 443 ssl http2;               # nginx 1.18: sintaks lama; ≥1.25 pakai "http2 on;"
    server_name sso.ukpbj.demakkab.go.id;

    # www → non-www (301 konsisten dengan canonical Laravel)
    if ($host != "sso.ukpbj.demakkab.go.id") {
        return 301 https://sso.ukpbj.demakkab.go.id$request_uri;
    }

    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/javascript application/json application/xml image/svg+xml;

    # Aset ber-version (?v=filemtime) → cache permanen
    location ~* \.(?:css|js|ico|svg|png|jpg|webp|woff2|json)$ {
        expires 1y;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**HTML halaman publik** memakai `Cache-Control: no-cache, private` (bawaan Laravel) —
`no-cache` = boleh simpan dengan revalidasi, **tidak memblokir crawler**;
hindari `no-store` pada HTML publik. `/oauth/jwks` sendiri `public, max-age=3600`.

## 8. Hreflang & bahasa (bagian F spec)

- `<html lang="id">` ✅ (layout `app.blade.php`).
- **Situs single-bahasa** → hreflang **tidak diperlukan**. Jangan menambahkan
  `<link rel="alternate" hreflang="id">` tunggal (Google memperingatkannya).
- Bila kelak multi-bahasa, tambahkan di `partials/seo-head.blade.php`:

```blade
<link rel="alternate" hreflang="id" href="{{ url('/') }}">
<link rel="alternate" hreflang="en" href="{{ url('/en') }}">
<link rel="alternate" hreflang="x-default" href="{{ url('/') }}">
```

## 9. Mobile & PWA (bagian G spec)

- Viewport: `<meta name="viewport" content="width=device-width, initial-scale=1">` ✅
- Responsive: breakpoints 991/767/560px + `prefers-reduced-motion` ✅
- **`public/manifest.json`** ✅ dibuat: `name`, `short_name`, `description`, `lang`,
  `start_url: /login`, `scope: /`, `display: standalone`, `theme_color`/`background_color: #1d4ed8`,
  icons `192px` & `512px` (`purpose: any maskable`).
- `<link rel="manifest">` tertaut di partial seo-head (otomatis bila file ada).
- **Apple touch icon**: `public/apple-touch-icon.png` (180×180) ✅ tertaut.
- Ikon PNG: `public/icon-192.png`, `public/icon-512.png`.

## 10. Security vs SEO (bagian H spec)

| Header | Nilai | Verifikasi |
|---|---|---|
| `X-Robots-Tag` | Hanya `noindex,…` pada halaman privat/teknis | ✅ probe: `/` & `/login` = **tidak ada** |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'unsafe-inline'; …` | ✅ aman untuk Googlebot; JSON-LD = data block non-eksekusi, tidak diblokir |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` (hanya saat HTTPS) | ✅ tidak memblokir first crawl (Certbot 301 lebih dulu) |
| `Cache-Control` (HTML) | `no-cache, private` | ✅ tidak memblokir crawl/index; `X-Robots-Tag` tidak ada di `/login` |
| `X-Frame-Options` | `DENY` | ✅ netral untuk SEO |

## 11. Favicon & ikon

- `public/favicon.ico` — ICO multi-resolusi valid (16/32/48, PNG entries, 1902 byte).
- Layout **tidak lagi** mematikan favicon via `href="data:,"`.
- `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` — motif perisai+centang.

## 12. RSS/Atom

**Tidak diterapkan** — tidak ada konten changelog di IdP. Bila kelak ada, tambah
`<link rel="alternate" type="application/rss+xml">` di seo-head.

## 13. Ringkasan perubahan (dua round)

| File | Perubahan |
|---|---|
| `public/robots.txt` | Allow publik, disallow area privat + query sensitif + `Sitemap:` |
| `public/sitemap.xml` | Generate via `artisan sitemap:generate` (hanya halaman publik) |
| `app/Console/Commands/GenerateSitemap.php` | **Baru** — generator + `--url`/`--path` |
| `routes/console.php` | Scheduler harian untuk sitemap |
| `app/Http/Middleware/SeoMeta.php` | Title/desc/robots/canonical/JSON-LD per route + **301 trailing slash** + **X-Robots-Tag** berlapis |
| `resources/views/partials/seo-head.blade.php` | Meta lengkap + manifest link |
| `resources/views/layouts/app.blade.php` | include seo-head, favicon link, footer internal links |
| `resources/views/errors/404.blade.php` | Custom 404 (`noindex`) |
| `bootstrap/app.php` | `SeoMeta` di group `web` |
| `public/manifest.json`, `icon-192.png`, `icon-512.png`, `apple-touch-icon.png`, `favicon.ico` | PWA & favicon |
