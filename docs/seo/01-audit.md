# 01 — Audit SEO & Performa: SSO IdP UKPBJ Demak

- **Target**: https://sso.ukpbj.demakkab.go.id
- **Stack**: Laravel 12, Nginx 1.18, PHP 8.3, MySQL, Laravel Passport
- **Peran situs**: Identity Provider (IdP) SSO untuk lingkungan pemerintahan — halaman publik terbatas (`/`, `/login`, halaman error), sisanya butuh autentikasi.
- **Tanggal audit**: 2026-10-30
- **Metode**: static analysis kode sumber (routes, blade, middleware, konfigurasi), tanpa akses langsung ke server produksi.

---

## 1. Ringkasan eksekutif

Sebagai IdP, volume halaman publik memang kecil — tapi justru itu membuat setiap sinyal SEO menjadi kritis: `/` dan `/login` adalah **satu-satunya** halaman yang bisa diindeks, dan keduanya mewakili entitas instansi (UKPBJ Demak) di hasil pencarian. Kondisi saat ini:

| Aspek | Kondisi | Status |
|---|---|---|
| Indexable pages | `/`, `/login` | ✅ ada |
| Meta description | Tidak ada satu pun | ❌ |
| Canonical URL | Tidak ada | ❌ |
| Open Graph / Twitter Card | Tidak ada | ❌ |
| Structured data (JSON-LD) | Tidak ada | ❌ |
| Title tag | `Login — SSO UKPBJ Demak` (23 char, generik) | ⚠️ |
| `robots.txt` | `User-agent: * / Disallow:` (allow-all, tanpa Sitemap) | ⚠️ |
| `sitemap.xml` | **Tidak ada file** | ❌ |
| `favicon.ico` | File ada tapi **0 byte**, dan dimatikan via `href="data:,"` | ❌ |
| Custom 404 | Tidak ada (pakai error page bawaan Laravel) | ⚠️ |
| HTTPS redirect | Via Certbot | ✅ |
| `lang="id"` | Sudah benar | ✅ |
| Viewport meta | Sudah ada | ✅ |
| System font (tanpa webfont) | LCP sangat ringan | ✅ |
| CSP / HSTS / X-Robots-Tag | Aman untuk Googlebot | ✅ |

**Estimasi skor Lighthouse saat ini (halaman `/login`, mobile):**

| Kategori | Skor | Catatan |
|---|---|---|
| Performance | ~92 | HTML kecil + 1 CSS 33 KB; tanpa webfont |
| Accessibility | ~95 | Skip link, label form, aria-label sudah ada |
| Best Practices | ~85 | Favicon 0 byte, HTTP/2 & kompresi tidak terverifikasi |
| SEO | ~70 | Meta description, canonical, OG, JSON-LD, sitemap hilang |

**Setelah implementasi (P0 + P1)**: Performance ~98 · Accessibility ~95 · Best Practices ~92 · SEO **100**.

---

## 2. Tabel temuan

| No | Kategori | Masalah | Severity | Dampak SEO | Rekomendasi |
|----|----------|---------|----------|------------|-------------|
| 1 | On-Page | Title `/login` = "Login — SSO UKPBJ Demak" (23 karakter), generik, tanpa keyword "Login Terpadu" | Medium | CTR rendah di SERP; brand + intent tidak jelas | Title 50–60 char: `Login Terpadu SSO — UKPBJ Demak \| Akses Semua Aplikasi` via `SeoMeta` middleware |
| 2 | On-Page | **Meta description kosong di semua halaman** | **High** | Google membuat snippet otomatis yang sering tidak relevan; CTR turun | Isi 150–160 char + CTA di `partials/seo-head.blade.php` |
| 3 | On-Page | **Tidak ada canonical URL**. `/login?redirect=…` menghasilkan URL duplikat tak terbatas | **High** | Duplikasi konten; otoritas terpecah | `<link rel="canonical">` = `url()->current()` (otomatis membuang query string) |
| 4 | On-Page | Tidak ada Open Graph & Twitter Card | Medium | Preview share di WA/Teams/Slack tidak terkontrol | Tambah `og:*` + `twitter:*` di partial seo-head |
| 5 | On-Page | Tidak ada structured data JSON-LD | Medium | Entity instansi lemah; tidak ada peluang sitelinks/knowledge panel | `GovernmentOrganization` + `WebSite` + `WebApplication` (home), `WebPage` (login) |
| 6 | On-Page | Heading: H1 home hanya nama brand; tidak ada H2 deskriptif | Low | Struktur semantik kurang kaya | Pertahankan 1 H1; tambah blok H2 di home bila konten bertambah |
| 7 | On-Page | Internal linking tipis (link brand + tombol "Masuk") | Low | Crawl depth sangat dangkal — wajar untuk IdP | Tambah link footer `Beranda` · `Login` |
| 8 | On-Page | Alt/dekorasi | Low (Lulus) | — | SVG `aria-hidden="true"` + teks brand tersendiri sudah benar — pertahankan |
| 9 | Technical | **`robots.txt` allow-all** — `/oauth/*`, `/admin/*`, `/dashboard`, `/api/*`, `/up` tidak di-disallow; tidak ada baris `Sitemap:` | **High** | Crawl budget terbuang ke endpoint privat; sinyal sitemap lemah | Ganti dengan `public/robots.txt` final (lihat 02-technical.md) |
| 10 | Technical | **`sitemap.xml` tidak ada** (file hilang) | **High** | Tidak ada peta eksplisit halaman publik untuk Googlebot | Buat `public/sitemap.xml` statis berisi `/` dan `/login` saja |
| 11 | Technical | Tidak ada meta robots `noindex` untuk halaman privat (`/dashboard`, `/admin/*`, `/oauth/authorize`, `/sessions`, `/password/change`) | Medium | Proteksi berlapis; mencegah indeks bila crawl aneh | `SeoMeta` middleware set `noindex, follow` per route |
| 12 | Technical | Tidak ada custom view 404 — error page bawaan Laravel tanpa noindex & tanpa branding | Medium | Soft-404; pengalaman buruk | Buat `resources/views/errors/404.blade.php` extend layout + `noindex` |
| 13 | Technical | **`favicon.ico` berukuran 0 byte** dan dimatikan layout via `<link rel="icon" href="data:,">` | Medium | Favicon gagal tampil di tab & SERP; sinyal brand hilang; skor Best Practices turun | Regenerasi ICO (16/32/48 px) + ganti link menjadi `/favicon.ico` |
| 14 | Technical | `APP_LOCALE=en` di `.env` sementara konten & `lang="id"` | Low | Minor (HTML sudah benar) | Set `APP_LOCALE=id` untuk konsistensi |
| 15 | Technical | Endpoint health `/up` publik | Low | Noise di crawl log | `Disallow: /up` di robots.txt |
| 16 | Technical | Hreflang | Info (Lulus) | Situs single-bahasa (Indonesia) — hreflang **tidak diperlukan**; `lang="id"` sudah cukup | Tidak ada aksi |
| 17 | Performance | CSS 33 KB **plain (belum minify)**, render-blocking; kompresi gzip/brotli tidak terverifikasi di repo | Medium | LCP meningkat di jaringan lambat | Minify + precompress (.gz/.br) + blok `gzip`/`brotli` Nginx |
| 18 | Performance | Tidak ada header `Cache-Control` immutable untuk aset ber-version (`?v=filemtime`) | Medium | Repeat view harus revalidasi | Nginx: `Cache-Control: public, max-age=31536000, immutable` untuk `/css/*` |
| 19 | Performance | Vite & Tailwind terpasang di `package.json` tetapi **tidak dipakai** — layout memuat `asset('css/app.css')` langsung | Low | Inkonsistensi tooling; risiko konflik | Putuskan salah satu: plain CSS + nginx minify (disarankan), atau migrasi penuh ke Vite |
| 20 | Performance | Font: system-ui stack | Low (Lulus) | Tidak ada webfont = tanpa FOUT/FOIT, LCP cepat | Pertahankan; jangan tambah webfont tanpa kebutuhan |
| 21 | Performance | Gambar & CLS | Low (Lulus) | Tidak ada gambar eksternal; layout stabil → CLS ~0 | Pertahankan; bila menambah `og:image`, buat 1200×630 statis |
| 22 | Security vs SEO | Tidak ada `X-Robots-Tag` yang memblokir halaman publik | Low (Lulus) | — | SeoMeta hanya memakai meta robots HTML |
| 23 | Security vs SEO | CSP ketat (`default-src 'self'`) — aman untuk Googlebot; JSON-LD berupa *data block* non-eksekusi **tidak** diblokir `script-src` | Low (Lulus) | — | Dokumentasikan; jangan tambahkan domain eksternal ke `script-src` tanpa kebutuhan |
| 24 | Security vs SEO | HSTS `max-age=31536000; includeSubDomains; preload` | Info | Tidak memblokir crawl (HTTPS valid); tapi berkomitmen untuk seluruh subdomain | Pastikan semua subdomain `*.demakkab.go.id` yang relevan siap HTTPS sebelum submit preload |
| 25 | Informational | `/register` & `/forgot-password` **tidak ada route-nya** di aplikasi (password direset admin) | Info | Jangan cantumkan di sitemap/robots sebagai halaman yang ada | Tidak ada aksi; catat agar tidak dijanjikan di dokumentasi publik |

---

## 3. Priority matrix

### P0 — Coretan indeksasi & snippet (kerjakan segera)

| Aksi | File |
|---|---|
| Meta title unik 50–60 char + meta description 150–160 char + canonical + robots per route | `app/Http/Middleware/SeoMeta.php`, `resources/views/partials/seo-head.blade.php` |
| `robots.txt` final: disallow `/oauth/`, `/admin/`, `/dashboard`, `/sessions`, `/password/`, `/logout`, `/sso/logout`, `/api/`, `/up` + baris `Sitemap:` | `public/robots.txt` |
| `sitemap.xml` statis: hanya `/` dan `/login` | `public/sitemap.xml` |
| Perbaiki favicon: regenerasi `favicon.ico` (0 byte → valid) + hapus `href="data:,"` | `public/favicon.ico`, `layouts/app.blade.php` |

### P1 — Kualitas & performa (minggu pertama)

| Aksi | File |
|---|---|
| JSON-LD `GovernmentOrganization` + `WebSite` + `WebApplication` | partial seo-head (otomatis via SeoMeta) |
| OG image 1200×630 (`/img/og-sso.png`) + `og:image` | aset baru + seo-head |
| Custom 404 extend layout, `noindex` | `resources/views/errors/404.blade.php` |
| Minify CSS + gzip/brotli + HTTP/2 + cache immutable di Nginx | `docs/seo/03-performance.md` (snippet siap pakai) |
| Set `APP_LOCALE=id` | `.env` produksi |

### P2 — Perapian (backlog)

| Aksi | Catatan |
|---|---|
| Link footer Beranda · Login | internal linking wajar |
| Konsolidasi tooling aset (Vite vs plain CSS) | pilih satu jalur |
| `theme-color` sudah diset di seo-head | minor polish |
| RSS/Atom changelog | **Tidak diterapkan** — tidak ada konten changelog di IdP |
| Hreflang | **Tidak diterapkan** — single-bahasa |

---

## 4. Catatan arsitektur SEO untuk aplikasi IdP

1. **Hanya 2 halaman publik**: `/` dan `/login`. Semua route lain di balik `auth` → redirect 302 ke `/login`, sehingga Google tidak pernah melihat kontennya. Risiko kebocoran indeks kecil, tetap di-hardening dengan `noindex` (temuan #11).
2. **`/oauth/*` bersifat teknis**: `/oauth/jwks` dan `/oauth/userinfo` bisa diakses tanpa halaman HTML (401/JSON). Google bisa mengindeks "halaman kosong" 401 — wajib `Disallow` di robots.txt.
3. **Query string `?redirect=`**: login sering diakses dengan `?redirect=/oauth/authorize?...`. Canonical tanpa query mencegah puluhan varian URL terindeks (temuan #3).
4. **CSP & JSON-LD**: `<script type="application/ld+json">` adalah *data block* yang tidak dieksekusi sebagai JS — **tidak diblokir** oleh `script-src`. Tidak perlu melonggarkan CSP.
5. **HSTS**: header sudah ada via `SecurityHeaders` middleware dan hanya dikirim saat `$request->isSecure()` — aman untuk first crawl (Certbot me-redirect HTTP→HTTPS sebelum HSTS pernah dikirim).

---

## 5. Berkas terkait

- `docs/seo/02-technical.md` — detail technical SEO + snippet perbaikan (robots, sitemap, Nginx, error page)
- `docs/seo/03-performance.md` — Core Web Vitals & optimasi server
- `docs/seo/04-checklist.md` — checklist verifikasi (GSC, Rich Results Test, PageSpeed Insights)

Implementasi kode yang dibuat dari audit ini:

- `public/robots.txt` (final)
- `public/sitemap.xml` (statis)
- `resources/views/partials/seo-head.blade.php`
- `app/Http/Middleware/SeoMeta.php` (terdaftar global di `bootstrap/app.php`)
- `resources/views/layouts/app.blade.php` (menggunakan partial seo-head)
