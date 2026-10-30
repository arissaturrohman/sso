# 04 — Checklist Verifikasi Pasca-Implementasi

Jalankan setelah deploy perubahan dari `01-audit.md` (P0/P1).

## 0. Tabel ringkasan verifikasi

| # | Tool / Service | Yang diperiksa | URL / Aksi | Kriteria lulus |
|---|----------------|----------------|------------|----------------|
| 1 | Google Search Console | Sitemap terdaftar | Properti `https://sso.ukpbj.demakkab.go.id` → Sitemaps | *Success*, *Discovered URLs: 2* |
| 2 | Google Search Console | Indexasi `/` & `/login` | URL Inspection → Request Indexing | *URL can be indexed* |
| 3 | Google Search Console | Privat TIDAK terindeks | Inspection `/dashboard` | *Blocked by robots.txt* / *noindex* |
| 4 | Google Search Console | Core Web Vitals | Reports → Core Web Vitals (Mobile) | `/` & `/login` = *Good* |
| 5 | Bing Webmaster Tools | Sitemap terdaftar | Sitemaps → kirim URL sitemap | *Success*, 2 URL |
| 6 | Bing Webmaster Tools | Indexasi & spam | URL Submission → `/`, `/login` | *Indexed*; privat tidak ada |
| 7 | Rich Results Test | JSON-LD valid | search.google.com/test/rich-results → `/` | Lolos **Organization**, **WebSite**, **WebApplication** |
| 8 | Schema.org Validator | Markup tanpa error | validator.schema.org → tempel JSON-LD | Tanpa error |
| 9 | Mobile-Friendly Test | Render mobile | Lighthouse Mobile / URL Inspection → `/login` | *Page is mobile friendly* (viewport ✅, tanpa overflow) |
| 10 | PageSpeed Insights | Skor SEO & performa | pagespeed.web.dev → `/` dan `/login` | SEO = 100, Performance ≥ 95 |
| 11 | Facebook Sharing Debugger | OG tag | developers.facebook.com/tools/debug | Preview title/description benar |
| 12 | Twitter Card Validator | Card | cards-dev.twitter.com/validator | Card *summary* tampil |

> Catatan: tool "Mobile-Friendly Test" mandiri Google sudah dipensiunkan —
> penggantinya: **URL Inspection di GSC** (bagian "Mobile Usability") dan
> **Lighthouse audit Mobile**. Keduanya dicakup baris 3 dan 9.

## A. Pre-flight lokal / staging

- [ ] `php artisan view:clear && php artisan config:clear` (bila ada cache view lama)
- [ ] Buka `/` → view-source: ada `<title>` 50–60 char, meta description, canonical, JSON-LD
- [ ] Buka `/login` → title = `Login Terpadu SSO — UKPBJ Demak | Akses Semua Aplikasi`
- [ ] Buka `/dashboard` tanpa login → redirect ke `/login` (status **302**, bukan 301)
- [ ] Buka URL palsu (`/halaman-tidak-ada`) → status **404**, meta `noindex, follow`
- [ ] `curl -I https://sso.ukpbj.demakkab.go.id/login` → header: `content-type: text/html`, tidak ada `X-Robots-Tag` yang memblokir
- [ ] `curl -s https://sso.ukpbj.demakkab.go.id/robots.txt` → disallow sesuai §02-technical
- [ ] `curl -s https://sso.ukpbj.demakkab.go.id/sitemap.xml` → valid XML, hanya 2 `<loc>`
- [ ] `curl -sI https://sso.ukpbj.demakkab.go.id/login` di produksi → `content-encoding: gzip` (atau `br`)
- [ ] `curl -sI https://sso.ukpbj.demakkab.go.id/css/app.css` → `cache-control: … immutable`
- [ ] `curl -sI http://sso.ukpbj.demakkab.go.id/` → **301** ke HTTPS
- [ ] Favicon: buka `/favicon.ico` → bukan 0 byte, content-type `image/x-icon`
- [ ] `APP_URL` produksi = `https://sso.ukpbj.demakkab.go.id` (canonical & sitemap tidak salah domain)

## B. Google Search Console

- [ ] Tambahkan properti `https://sso.ukpbj.demakkab.go.id` (verifikasi via DNS TXT atau meta tag)
- [ ] **Sitemaps** → kirim `https://sso.ukpbj.demakkab.go.id/sitemap.xml` → status *Success*, *Discovered URLs: 2*
- [ ] **URL Inspection** `/` → *URL can be indexed*; kirim Request Indexing
- [ ] **URL Inspection** `/login` → *URL can be indexed*; kirim Request Indexing
- [ ] **URL Inspection** `/dashboard` (atau `/oauth/authorize`) → konfirmasi *blocked by robots.txt* atau *noindex* — tidak boleh *indexed*
- [ ] **Pages (Laporan Indeks)** → pastikan tidak ada URL privat dalam *Indexed*; 404 masuk *Not found* (bukan *Soft 404*)
- [ ] **Core Web Vitals** → laporan Mobile: `/` dan `/login` status *Good* (LCP < 2.5s, INP < 200ms, CLS < 0.1)
- [ ] **URL Inspection `/login/`** (trailing slash) → harus menunjukkan redirect 301 ke `/login`, bukan halaman duplikat

## B2. Bing Webmaster Tools

- [ ] Tambahkan properti `https://sso.ukpbj.demakkab.go.id` (verifikasi DNS TXT / meta tag / GSC sync)
- [ ] **Sitemaps** → kirim `https://sso.ukpbj.demakkab.go.id/sitemap.xml` → status sukses, 2 URL
- [ ] **URL Submission** → kirim `/` dan `/login` untuk perayapan cepat
- [ ] **Site Scan / URL Inspection** → pastikan `/dashboard`, `/admin`, `/oauth/*` tidak terindeks
- [ ] **Index → Pages** → hanya `/` dan `/login` yang *Indexed*
- [ ] **Mobile Friendliness** (Bing: Site Scan → Mobile) → lolos

## B3. Mobile-Friendly / Render mobile

- [ ] Lighthouse mobile (`--preset=perf` + kategori accessibility): tanpa *horizontal scrolling*
- [ ] GSC URL Inspection `/login` → bagian *Mobile Usability* tanpa error
- [ ] Uji manual 360×640 & 390×844: form login, tombol Masuk, dan footer terlihat penuh
- [ ] `<meta name="viewport" content="width=device-width, initial-scale=1">` ada di semua halaman (sudah via `seo-head`) — konfirmasi view-source
- [ ] `manifest.json` → `https://sso.ukpbj.demakkab.go.id/manifest.json` ter-valid JSON, ikon 192/512 bisa diakses 200

## C. Rich Results Test & Schema validator

- [ ] https://search.google.com/test/rich-results → masukkan `/` → lolos validasi **Organization** / **WebSite**
- [ ] https://validator.schema.org/ → tempel JSON-LD `/` → tanpa error
- [ ] Facebook Sharing Debugger (https://developers.facebook.com/tools/debug/) → `/login` menampilkan title, description, dan og:image benar
- [ ] Twitter Card Validator (https://cards-dev.twitter.com/validator) → card *summary* tampil benar
- [ ] Validator W3C untuk `sitemap.xml` (opsional) → tanpa error

## D. PageSpeed Insights / Lighthouse

- [ ] PSI mobile `https://sso.ukpbj.demakkab.go.id/` → Performance ≥ 95, SEO = 100
- [ ] PSI mobile `https://sso.ukpbj.demakkab.go.id/login` → Performance ≥ 95, SEO = 100
- [ ] PSI desktop `https://sso.ukpbj.demakkab.go.id/login` → Performance ≥ 98
- [ ] Lighthouse lokal: `npx lighthouse https://sso.ukpbj.demakkab.go.id/login --only-categories=seo,performance --view`
- [ ] Periksa item SEO Lighthouse: *Document has a meta description* ✅, *Canonical is valid* ✅, *Page has successful HTTP status code* ✅, * robots.txt is not blocking page indexing* ✅

## E. Crawler & indexasi jangka panjang

- [ ] `robots.txt` tetap mengizinkan `/login` (uji: Google Rich Results → robots.txt tester atau Inspection URL)
- [ ] Setelah 1–2 minggu: cek *Coverage* — hanya `/` dan `/login` yang terindeks
- [ ] Submit HSTS preload (https://hstspreload.org) **hanya setelah** seluruh subdomain `*.demakkab.go.id` siap HTTPS (`includeSubDomains` sudah aktif)
- [ ] Pastikan tidak ada subdomain/port lain menyajikan konten sama (cek `http://…` dan `www.…` → 301 ke canonical)
- [ ] Bandingkan hasil dengan estimasi skor di `01-audit.md` §1
