
---

## 5️⃣ File: `.opencode/commands/seo-content.md`

```markdown
---
description: Optimasi konten & copywriting halaman publik SSO (login, forgot password, consent)
agent: build
---

Optimalkan konten dan copywriting halaman publik aplikasi SSO. Baca:

@resources/views/auth/login.blade.php
@resources/views/auth/forgot-password.blade.php
@resources/views/oauth/authorize.blade.php

## Target Audience
- **ASN/PPK**: pegawai pemerintah yang mengurus pengadaan
- **Penyedia barang/jasa**: vendor yang mendaftar lewat LPSE/SIRUP
- **Masyarakat umum**: yang mengakses layanan pengadaan publik

## A. Copywriting Halaman Login
1. **Headline H1**: "Masuk ke SSO UKPBJ Demak" (jelas, mengandung brand + keyword)
2. **Subheadline**: "Satu akun untuk semua layanan pengadaan di lingkungan UKPBJ Kabupaten Demak."
3. **Label input**: "Email atau NIP" (bukan "Username")
4. **Placeholder**: "contoh: nama@email.com atau 199001012020011001"
5. **Hint di bawah input**: "Gunakan email atau NIP yang terdaftar di sistem UKPBJ"
6. **Tombol**: "Masuk" (bukan "Login" atau "Submit")
7. **Link bantuan**: "Lupa password?" · "Belum punya akun? Hubungi admin"
8. **Trust badge**: "🔒 Koneksi aman dengan enkripsi SSL"
9. **Footer**: "© 2026 UKPBJ Kabupaten Demak. Semua hak dilindungi." + link Kebijakan Privasi + Syarat Penggunaan

## B. Halaman Lupa Password
1. H1: "Reset Password SSO UKPBJ Demak"
2. Instruksi jelas: "Masukkan email yang terdaftar. Kami akan mengirim tautan reset."
3. Tombol: "Kirim Tautan Reset"
4. Setelah submit: "Jika email terdaftar, tautan reset telah dikirim. Cek inbox atau spam."

## C. Halaman Consent OAuth
1. H1: "Izinkan akses ke akun Anda?"
2. Daftar scope dengan bahasa manusia: "Melihat profil Anda (nama, email, NIP)" — bukan `openid profile email`
3. Tombol: "Izinkan" (primary) dan "Batal" (secondary)
4. Link: "Pelajari lebih lanjut tentang izin ini"

## D. Halaman Error
1. 404: "Halaman tidak ditemukan" + CTA "Kembali ke Login"
2. 500: "Terjadi kesalahan" + CTA "Coba lagi" + ID error untuk support
3. 403: "Akses ditolak" + CTA "Kembali ke Dashboard"
4. Semua error page: meta robots `noindex`

## E. FAQ Section (opsional di halaman login)
Tambahkan accordion FAQ di bawah form login (bagus untuk SEO + user experience):
- "Apa itu SSO UKPBJ Demak?"
- "Bagaimana cara mendapatkan akun SSO?"
- "Lupa password, apa yang harus dilakukan?"
- "Apakah data saya aman?"
- "Aplikasi apa saja yang terhubung dengan SSO ini?"

Gunakan **FAQPage schema** JSON-LD agar muncul di Google rich results.

## F. Keyword Research (untuk referensi)
Target keyword utama:
- "login sso ukpbj demak"
- "sso pengadaan demak"
- "login lpse demak"
- "akun sso ukpbj"
- "reset password sso demak"

Target keyword long-tail:
- "cara login sso ukpbj demak"
- "lupa password akun sso ukpbj"
- "sso untuk penyedia barang jasa demak"

## Output
1. Draft copywriting lengkap per halaman (siap copy-paste ke Blade)
2. Snippet JSON-LD FAQPage
3. Tabel keyword → halaman target → title/meta yang dioptimalkan
4. Checklist on-page SEO per halaman (title, meta, H1, alt, canonical, schema)