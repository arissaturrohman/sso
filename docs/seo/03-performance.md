# 03 — Performance (Core Web Vitals): SSO IdP UKPBJ Demak

Analisis berbasis kode sumber (halaman `/login` dan `/`): HTML server-rendered, satu stylesheet 33 KB, tanpa webfont, tanpa gambar eksternal, JS inline ~4 KB.

---

## 1. Estimasi Core Web Vitals (mobile, 4G throttled)

| Metrik | Ambang | Estimasi saat ini | Analisis |
|---|---|---|---|
| **LCP** | < 2.5 dtk | **~1.4–1.8 dtk** ✅ | Elemen LCP = kartu login (HTML server-rendered). Tidak ada gambar hero, tidak ada webfont blocking. Dominan TTFB + 1 CSS blocking |
| **INP** | < 200 ms | **< 50 ms** ✅ | JS hanya event listener drawer menu (vanilla, ~4 KB) |
| **CLS** | < 0.1 | **~0** ✅ | Tidak ada elemen async (iklan/embed/lazy image) yang menggeser layout; input form fixed height |

**TTFB**: Laravel + MySQL perlu `php-fpm` opcache aktif + `CACHE_STORE` produksi. Estimasi TTFB 150–400 ms wajar; ini komponen terbesar LCP di halaman tanpa aset berat.

## 2. Detail pemeriksaan

| # | Item | Kondisi | Severity | Rekomendasi |
|---|---|---|---|---|
| C1 | Minify CSS | `public/css/app.css` **33 KB plain** dengan komentar & indentasi (est. 25% lebih besar) | Medium | Minify → ~24 KB; jalankan manual atau lewat nginx filter (lihat §3) |
| C2 | Build pipeline | `package.json` punya Vite+Tailwind, tapi layout memuat `asset('css/app.css')` langsung (tanpa `@vite`) | Low | Pilih salah satu; **disarankan**: tetap plain CSS + minify, tidak perlu Tailwind untuk IdP |
| C3 | Render-blocking CSS | 1 `<link rel=stylesheet>` di `<head>` — wajib blocking, tapi kecil & satu file | Low | Setelah minify+kompress, blocking ini negligible |
| C4 | Webfont | **System font stack** (`system-ui, Segoe UI, Roboto…`) | ✅ Lulus | Tidak ada FOUT/CLS font. Jangan menambah webfont tanpa kebutuhan |
| C5 | Preload font | Tidak ada font → tidak perlu preload | ✅ N/A | — |
| C6 | Lazy load gambar | Tidak ada `<img>` di halaman publik (semua ikon SVG inline) | ✅ Lulus | Bila menambah `og:image`, itu hanya untuk crawler — tidak memengaruhi CWV |
| C7 | Cache-Control aset | Belum ada di konfigurasi repo (query `?v=filemtime` sudah benar sebagai cache-buster) | Medium | Header nginx: `public, max-age=31536000, immutable` (snippet 02-technical §7) |
| C8 | ETag | Default nginx mengirim ETag untuk file statis — verifikasi di produksi | Info | Pastikan `etag on;` (default) |
| C9 | Compress gzip/brotli | Tidak terverifikasi di repo | Medium | Aktifkan `gzip` (+ `brotli` bila modul tersedia) — snippet 02-technical §7 |
| C10 | HTTP/2 | nginx 1.18 mendukung `listen 443 ssl http2` | Medium | Aktifkan; upgrade nginx ≥1.25 untuk sintaks `http2 on;` |
| C11 | Asset versioning | `filemtime()` via `@php` di layout | ✅ Lulus | Berfungsi; pasangkan dengan header `immutable` agar revalidasi hilang |
| C12 | JS blocking | JS drawer diletakkan di `<body>` akhir, inline | ✅ Lulus | Tidak ada request tambahan; tidak menghalangi render |
| C13 | OPcache / PHP | Belum terverifikasi | Medium | Wajib di produksi (lihat §4) |
| C14 | Third-party | Tidak ada tracker/CDN eksternal | ✅ Lulus | CSP `default-src 'self'` konsisten |

## 3. Perbaikan performa yang direkomendasikan

### 3.1 Minify CSS (sekali jalan, tanpa dependensi Node)

```bash
# butuh tersedianya tool, mis. minify (Go) atau tersenyum — alternatif sederhana:
php -r '$c=file_get_contents("public/css/app.css");
$c=preg_replace(["/\s*\n\s*/","/\s{2,}/","/\s*([{}:;,])\s*/"],["$1"," ","$1"],$c);
file_put_contents("public/css/app.css",$c);'
```

> Jalankan saat deploy; pertahankan `public/css/app.css` sebagai sumber minify agar query `?v=filemtime` ikut berubah otomatis.

### 3.2 OPcache PHP 8.3 (`/etc/php/8.3/fpm/conf.d/10-opcache.ini`)

```ini
opcache.enable=1
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0        ; produksi: matikan revalidasi → TTFB turun
opcache.save_comments=1              ; wajib untuk Blade/Laravel
```

### 3.3 Arahkan Lighthouse Anda ke produksi

Halaman login tanpa gambar membuat Lighthouse didominasi **TTFB + kompresi + cache**. Setelah snippet Nginx (02-technical §7) diterapkan, skor Performance diproyeksikan naik **~92 → ~98**.

## 4. Batasan analisis

Audit ini statis — untuk angka riil jalankan PageSpeed Insights / Lighthouse CI terhadap `https://sso.ukpbj.demakkab.go.id/login` (mobile & desktop), lalu bandingkan dengan estimasi di §1. Langkah verifikasi lengkap ada di `04-checklist.md`.
