---
description: Optimasi SEO & konversi halaman login SSO
agent: build
---

Optimalkan halaman login SSO di aplikasi Laravel 12 ini agar ramah SEO dan meningkatkan konversi login. Baca:

@resources/views/auth/login.blade.php
@resources/views/layouts/app.blade.php
@routes/web.php

## Target
- Halaman: `GET /login` (publik)
- Brand: "SSO UKPBJ Demak" / "Login Terpadu UKPBJ Demak"
- Audience: ASN, penyedia barang/jasa, dan stakeholder UKPBJ
- Tujuan: user login cepat, tidak bingung, tidak meninggalkan halaman

## A. SEO On-Page Halaman Login
1. **Title tag**: 50-60 karakter. Contoh: "Login SSO UKPBJ Demak — Akses Terpadu Layanan Pengadaan"
2. **Meta description**: 150-160 karakter, ajakan aksi. Contoh: "Masuk ke portal SSO UKPBJ Demak untuk mengakses layanan pengadaan terpadu. Login aman dengan email atau NIP."
3. **H1**: satu saja, mengandung keyword utama: "Masuk ke SSO UKPBJ Demak"
4. **Subheading H2**: penjelasan singkat manfaat SSO
5. **Structured data JSON-LD**:
   - `WebSite` (name, url, potentialAction SearchAction)
   - `Organization` (name: "UKPBJ Kabupaten Demak", url, logo)
   - `WebApplication` (name, applicationCategory: "BusinessApplication", operatingSystem: "Web")
6. **Open Graph**: `og:title`, `og:description`, `og:image` (logo 1200x630), `og:type=website`, `og:url`
7. **Twitter Card**: `summary_large_image`
8. **Canonical**: `https://sso.ukpbj.demakkab.go.id/login`
9. **Language**: `<html lang="id">`
10. **Favicon & apple-touch-icon** lengkap

## B. Accessibility (bagus untuk SEO)
1. `<label for="...">` untuk setiap input
2. `aria-describedby` untuk hint/error
3. Kontras warna WCAG AA minimal
4. Fokus keyboard terlihat
5. Skip link "Lewati ke form login"
6. `autocomplete="username"` dan `autocomplete="current-password"` untuk password manager

## C. Konversi
1. Copywriting tombol: "Masuk" (bukan "Submit")
2. Tampilkan opsi "Lupa password?"
3. Hint singkat: "Gunakan email atau NIP yang terdaftar"
4. Pesan error jelas, tidak bocorkan info (anti-enumeration)
5. Loading state saat submit (hindari double submit)
6. Trust badge: "Koneksi aman dengan enkripsi SSL"

## D. Multi-Bahasa (jika perlu)
- `hreflang="id"` dan `hreflang="en"` untuk versi Inggris
- Link language switcher di footer

## Output
1. File Blade lengkap yang sudah dioptimalkan (siap copy-paste)
2. Snippet JSON-LD (letakkan di `<head>`)
3. Checklist verifikasi: Google Rich Results Test, Lighthouse SEO score target ≥ 95
4. Perbandingan before/after (title, meta, H1, structured data)