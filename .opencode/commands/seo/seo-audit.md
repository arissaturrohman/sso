---
description: Audit SEO menyeluruh aplikasi SSO Laravel 12 (on-page, technical, performance)
agent: build
---

Bertindaklah sebagai Senior SEO Engineer & Web Performance Specialist. Lakukan audit SEO menyeluruh terhadap aplikasi SSO Laravel 12 di https://sso.ukpbj.demakkab.go.id.

Konteks aplikasi:
- Ini adalah Identity Provider (IdP) SSO untuk lingkungan pemerintahan (UKPBJ Demak)
- Halaman publik: /login, /register (jika ada), /forgot-password, halaman consent OAuth, halaman error
- Halaman privat: /dashboard, /oauth/authorize (butuh login)
- Stack: Laravel 12, Nginx 1.18, PHP 8.3, MySQL, Passport

Baca file-file berikut:

@routes/web.php
@resources/views/auth/login.blade.php
@resources/views/layouts/app.blade.php
@public/robots.txt
@public/sitemap.xml
@config/app.php

## A. On-Page SEO
1. Title tag setiap halaman publik — unik, 50-60 karakter, mengandung brand "UKPBJ Demak" + keyword relevan ("SSO", "Login Terpadu")
2. Meta description — 150-160 karakter, mengandung CTA
3. Heading hierarchy (H1 hanya satu per halaman, H2/H3 berurutan)
4. Alt text gambar/logo
5. Internal linking yang wajar
6. Canonical URL (hindari duplikasi http/https, trailing slash)
7. Open Graph & Twitter Card (untuk share link login)
8. Structured data (JSON-LD) — WebSite, Organization, WebApplication

## B. Technical SEO
1. robots.txt — pastikan `/login` boleh di-index, `/oauth/*`, `/api/*`, `/dashboard` di-disallow
2. sitemap.xml — hanya halaman publik
3. Meta robots per halaman (`index,follow` vs `noindex,nofollow`)
4. HTTPS redirect (sudah ada via Certbot)
5. Hreflang (jika multi-bahasa: id/en)
6. Status code (404/410 untuk halaman tidak ada, 301 untuk redirect permanen)
7. Trailing slash consistency
8. Mobile-friendly (viewport meta)
9. Favicon & apple-touch-icon
10. RSS/Atom (jika ada changelog)

## C. Performance (Core Web Vitals)
1. LCP < 2.5s — analisis halaman login
2. INP < 200ms
3. CLS < 0.1
4. Preload font kritis, `font-display: swap`
5. Minify CSS/JS (Vite build)
6. Lazy load gambar
7. Cache header (Cache-Control, ETag)
8. Compress (gzip/brotli) di Nginx
9. HTTP/2 atau HTTP/3

## D. Security vs SEO
- Pastikan `X-Robots-Tag` tidak memblokir halaman publik tanpa sengaja
- CSP jangan sampai memblokir crawler (Googlebot)
- HSTS tidak memblokir first crawl

## Output
1. Tabel temuan: No, Kategori, Masalah, Severity (High/Medium/Low), Dampak SEO, Rekomendasi
2. Skor estimasi Lighthouse per kategori (Performance, Accessibility, Best Practices, SEO)
3. Priority matrix (P0/P1/P2)
4. Snippet kode perbaikan (Blade, Nginx, robots.txt, sitemap)
5. Checklist verifikasi (Google Search Console, Rich Results Test, PageSpeed Insights)