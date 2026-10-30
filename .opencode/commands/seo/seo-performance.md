---
description: Audit Core Web Vitals & performance untuk halaman publik SSO
agent: build
---

Audit performance dan Core Web Vitals halaman login SSO Laravel 12. Baca:

@resources/views/auth/login.blade.php
@resources/views/layouts/app.blade.php
@vite.config.js
@package.json
@/etc/nginx/conf.d/vhost/sso-pbj.conf (jika bisa diakses)

## Target
- LCP < 2.5s (Good)
- INP < 200ms (Good)
- CLS < 0.1 (Good)
- Lighthouse Performance ≥ 90
- Time to First Byte (TTFB) < 800ms

## A. Analisis Bottleneck
1. Query database N+1 di halaman login
2. Asset besar (JS/CSS bundle, gambar, font)
3. Render-blocking resource
4. Layout shift dari iklan/embed (jarang di SSO)
5. Server response time (PHP-FPM, opcache, MySQL slow query)

## B. Optimasi Asset
- Vite: `npm run build` → minify + hashing + code splitting
- Preload font utama: `<link rel="preload" as="font" href="/fonts/inter.woff2" crossorigin>`
- `font-display: swap`
- Lazy load gambar non-kritis: `loading="lazy"` + `decoding="async"`
- Critical CSS inline (untuk above-the-fold)
- Defer non-critical JS: `<script defer>` atau `type="module"`
- Gunakan WebP untuk gambar (dengan fallback PNG)

## C. Optimasi Server (Nginx)
Tambahkan di config `sso-pbj.conf`:
```nginx
gzip on;
gzip_vary on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml image/svg+xml;
gzip_min_length 256;

brotli on;  # jika modul brotli terpasang
brotli_types text/plain text/css application/json application/javascript text/xml application/xml image/svg+xml;
brotli_min_length 256;

# Cache static asset
location ~* \.(jpg|jpeg|png|gif|webp|svg|woff2|woff|ttf|css|js)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}

# Cache HTML statis (login bisa cache singkat)
location = /login {
    add_header Cache-Control "public, max-age=60";
}

# PHP-FPM tuning (di php-fpm pool)
# pm = dynamic
# pm.max_children = 25
# pm.start_servers = 5