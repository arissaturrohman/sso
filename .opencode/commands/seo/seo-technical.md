---
description: Audit technical SEO — robots, sitemap, meta, canonical, status code
agent: build
---

Lakukan audit technical SEO untuk aplikasi SSO Laravel 12 di https://sso.ukpbj.demakkab.go.id. Baca:

@public/robots.txt
@public/sitemap.xml
@routes/web.php
@config/app.php
@app/Http/Middleware/SecurityHeaders.php
@app/Providers/AppServiceProvider.php

## A. robots.txt
Buat robots.txt yang benar:
- Allow: `/`, `/login`, `/forgot-password`
- Disallow: `/dashboard`, `/oauth/`, `/api/`, `/admin`, `/sso/`, `/user/`, `/settings`
- Disallow: query string sensitif (misal `?token=`, `?code=`)
- Sitemap: `Sitemap: https://sso.ukpbj.demakkab.go.id/sitemap.xml`
- Jangan blokir Googlebot, Bingbot
- Jangan pakai `Disallow: /` untuk semua (fatal)

## B. sitemap.xml
- Hanya berisi halaman publik
- Format XML valid dengan `<lastmod>`, `<changefreq>`, `<priority>`
- Generate otomatis via Laravel (spatie/laravel-sitemap atau manual)
- Daftarkan di Google Search Console & Bing Webmaster

## C. Meta robots per halaman
Middleware atau directive Blade:
- Halaman publik: `<meta name="robots" content="index,follow">`
- Halaman login: `index,follow` (boleh di-index)
- Halaman privasi/dashboard: `noindex,nofollow`
- Halaman error: `noindex`

## D. Canonical URL
- Setiap halaman publik punya `<link rel="canonical" href="...">`
- Hindari duplikasi: `http://` vs `https://`, trailing slash vs tanpa, `www.` vs non-`www`
- Redirect 301 yang konsisten

## E. Status code
- 200 untuk halaman ada
- 301 untuk redirect permanen (http→https)
- 302 untuk redirect sementara (login)
- 404 untuk halaman tidak ada (bukan redirect ke login)
- 410 untuk resource yang dihapus permanen
- 503 saat maintenance (dengan Retry-After header)

## F. Hreflang & Language
- `<html lang="id">`
- Jika multi-bahasa: `<link rel="alternate" hreflang="id" href="...">` dan `hreflang="en"`

## G. Mobile & PWA
- Viewport meta: `<meta name="viewport" content="width=device-width, initial-scale=1">`
- Responsive design
- Manifest PWA (opsional): `manifest.json` dengan `name`, `short_name`, `icons`, `theme_color`, `background_color`
- Apple touch icon

## H. Security & SEO
- Pastikan `X-Robots-Tag: noindex` tidak terpasang di `/login`
- Pastikan middleware `noindex` tidak berlaku di halaman publik
- Cek `Cache-Control` tidak memblokir crawler

## Output
1. File `public/robots.txt` final
2. File `public/sitemap.xml` (atau route generator)
3. Middleware `SeoMeta` (contoh kode) untuk set title/description/robots per route
4. Snippet Blade `<head>` lengkap dengan meta, OG, Twitter, JSON-LD, canonical
5. Tabel checklist verifikasi (GSC, Bing, Rich Results, Mobile-Friendly Test)