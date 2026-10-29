# SSO Identity Provider — OAuth 2.0 / OpenID Connect (Laravel 12 + Passport 13)

Sistem **Single Sign-On (SSO)** lengkap dengan **Single Logout (SLO)**:

| Komponen | Peran | URL | Database |
|---|---|---|---|
| **IdP** (proyek ini) | Identity Provider — OAuth 2.0 / OIDC server | `https://sso.ukpbj.demakkab.go.id` | MySQL `sso_idp` |
| **Aplikasi klien** | Service Provider yang mengintegrasikan SSO (diarahkan via panduan integrasi di README ini) | milik masing-masing aplikasi | — |

---

## ✨ Fitur

- **Login terpusat** dengan **email ATAU NIP** + password (deteksi otomatis via `FILTER_VALIDATE_EMAIL`)
- **OAuth 2.0 Authorization Code flow + PKCE (S256 wajib)** — implicit grant dilarang
- **Refresh token rotation** — token lama otomatis dicabut setelah dipakai (`Passport::$revokeRefreshTokenAfterUse = true`); reuse refresh token lama → `400 invalid_grant`
- **Token revocation** — `POST /oauth/revoke` (RFC 7009)
- **Single Logout (SLO)** — back-channel logout dengan **logout_token JWT RS256** (spec OIDC Back-Channel Logout 1.0):
  - Dicabut semua access & refresh token user
  - Semua mapping sesi `sso_sessions` ditandai berakhir
  - IdP mengirim `POST logout_token=<JWT>` ke setiap client app terkait
  - Client app memverifikasi JWT via **JWKS**, anti-replay (`jti`), lalu menghancurkan sesi lokalnya
  - Audit pengiriman tersimpan di `sso_logout_audit`
- **Monitoring perangkat & sesi aktif** — halaman `/sessions` + putus sesi per-perangkat
- **Rate limiting** — `POST /login` 5x/menit per IP & per identifier; `POST /oauth/token` 15x/menit per client+IP
- **Security headers** — CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS (saat HTTPS)
- **Session fixation protection** — session regenerate saat login & logout
- **Anti open-redirect** — `post_logout_redirect_uri` divalidasi terhadap `redirect_uris` client
- **Panel Admin (role `admin`)** — CRUD user & aplikasi klien OAuth: registrasi client dengan secret ditampilkan sekali, regenerate secret, revoke client (token ikut dicabut) — lihat [Cara Menambahkan Aplikasi Klien](#-cara-menambahkan--membuat-aplikasi-klien-baru)
- **Password akun baru dikirim via WhatsApp (Fonnte)** — admin cukup mengisi nomor WhatsApp; password dibuat otomatis & dikirim ke user, dengan fallback tampil-sekali bila pengiriman gagal
- **Wajib ganti password pada login pertama** — password sementara dari admin harus diganti sebelum user bisa membuka halaman lain (termasuk `/oauth/authorize`); semua halaman dialihkan ke `/password/change`
- **Ubah password mandiri kapan saja** — tombol **Ubah Password** di dashboard (semua role: admin & user) dan link di header; wajib verifikasi password lama
- **Aplikasi Terhubung di dashboard** — client dengan Landing URL tampil sebagai kartu di dashboard user; diklik membuka aplikasi di tab baru dengan SSO otomatis

---

## 🚀 Menjalankan

### Prasyarat
- PHP 8.2+, Composer, MySQL (Laragon: jalankan MySQL dulu)
- OAuth keys: `storage/oauth-private.key` & `storage/oauth-public.key`

### Setup IdP
```bash
# buat database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS sso_idp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan migrate --force
php artisan db:seed --class=SsoDemoSeeder --force   # opsional: users demo + client demo
php artisan serve --port=8000                        # dev saja; produksi pakai PHP-FPM (lihat Nginx di bawah)
```

> **Catatan:** command `passport:keys` bermasalah di Passport 13.0.0 (bug `$keyPath` belum terinisialisasi).
> Workaround sudah dipasang: `Passport::loadKeysFrom(storage_path())` di `AppServiceProvider`.
> Jika keys belum ada, generate manual via `openssl_pkey_new()` (skrip contoh terdokumentasi di git history).

> **Catatan dev-server (Windows):** `php artisan serve` berjalan **single-worker** — env var
> `PHP_CLI_SERVER_WORKERS` **tidak didukung di Windows**. Saat IdP mengirim back-channel logout,
> worker-nya sibuk dan tidak bisa melayani request lain (termasuk fetch JWKS dari Client App) →
> deadlock sirkular hingga timeout. Aplikasi klien tetap tahan terhadap kondisi ini berkat
> *stale JWKS fallback* di `LogoutTokenVerifier` (lihat Langkah 4 panduan integrasi klien).
> Di produksi (PHP-FPM/Octane multi-worker atau queue worker terpisah) kondisi ini tidak muncul.

### Coba IdP di browser
1. Buka `https://sso.ukpbj.demakkab.go.id/login` (atau `http://localhost:8000` saat dev)
   → login dengan email ATAU NIP (`admin@sso.test` / `password123` atau NIP `199900001`)
2. **Dashboard** — profil + kartu **Aplikasi Terhubung** (klik → aplikasi klien terbuka di tab baru)
3. **Perangkat & Sesi** (`/sessions`) — monitoring sesi aktif + putus per-perangkat
4. **Manajemen** (admin) — CRUD user & aplikasi klien
5. Tombol **Logout (semua aplikasi)** di dashboard IdP → global logout:
   semua token & sesi dicabut, IdP mengirim back-channel ke setiap aplikasi klien

> Folder demo-client (aplikasi SP contoh) beserta script test E2E sudah dihapus
> dari repo ini. Panduan integrasi aplikasi klien tetap tersedia di bawah —
> contoh alur di sana menggunakan port 8010 sebagai ilustrasi.

---

## 🔑 Kredensial Demo

| User | Email | NIP | Password |
|---|---|---|---|
| Admin SSO | `admin@sso.test` | `199900001` | `password123` |
| Budi Setiawan | `budi@sso.test` | `1995000002` | `password123` |

> `SsoDemoSeeder` juga bisa membuat **client demo** (`Aplikasi Demo (Client App)`)
> mengarah ke `http://localhost:8010` — berguna saat mengikuti panduan integrasi
> di bawah. Client nyata (mis. `ARJUNA`) didaftarkan melalui Panel Admin;
> lihat [Cara Menambahkan Aplikasi Klien](#-cara-menambahkan--membuat-aplikasi-klien-baru).

---

## 📡 Endpoint IdP

| Endpoint | Method | Keterangan |
|---|---|---|
| `/oauth/authorize` | GET | Authorization endpoint (PKCE S256 wajib) |
| `/oauth/token` | POST | Token endpoint (code + refresh_token grant) |
| `/oauth/userinfo` | GET | OIDC UserInfo (bearer access token) |
| `/oauth/revoke` | POST | RFC 7009 revocation |
| `/oauth/jwks` | GET | JSON Web Key Set (kunci publik RS256) |
| `/sso/logout` | GET | Single Logout SP-initiated (`client_id`, `post_logout_redirect_uri`) |
| `/login`, `/logout` | GET/POST | Login & global logout IdP |
| `/sessions` | GET | Daftar perangkat & sesi aktif |
| `/api/user` | GET | Profil user via bearer token |

---

## 🔄 Alur Single Logout

```
[User] --Logout SSO--> [Client App]
                         | 1. revoke token di IdP (RFC 7009)
                         | 2. redirect ke GET /sso/logout?client_id=...&post_logout_redirect_uri=...
                       [IdP]
                         | 3. cabut SEMUA access + refresh token user
                         | 4. tandai SEMUA sso_sessions berakhir
                         | 5. POST logout_token (JWT RS256) ke setiap client
                         |    --> job SendBackChannelLogout (queue, retry 3x)
                         | 6. hancurkan sesi IdP
                         | 7. redirect ke post_logout_redirect_uri (terverifikasi)
                       [Client App]
                         | 8. terima POST /sso/backchannel-logout
                         | 9. verifikasi JWT via JWKS + anti-replay jti
                         | 10. hancurkan sesi lokal (sub -> users.sso_sub)
```

---

## 🧪 Testing

## 🧪 Testing

Script test E2E (test-*.php) telah dihapus bersama folder demo-client.
Verifikasi manual yang disarankan setelah deploy:

1. `GET /up` dan `GET /health` → 200
2. Login via email **dan** via NIP → dashboard tampil
3. `/admin` dengan role `admin` → 200; dengan role `user` → 403
4. Registrasi client baru via Panel Admin → secret tampil sekali
5. Logout di dashboard IdP → semua token revoked (`sso_logout_audit` tercatat)
6. Buat user baru → login dengan password dari WhatsApp → **dipaksa ganti password**;
   setelah ganti, akses `/dashboard` & `/oauth/authorize` berjalan normal
7. Login sebagai **admin** dan **user** → tombol **Ubah Password** di dashboard berfungsi
   dan hanya bisa dipakai setelah memverifikasi password lama

---

## 🏗️ Struktur Kode Penting

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Auth/LoginController.php        # login email/NIP
│   │   ├── Auth/PasswordChangeController.php # ubah password + wajib ganti
│   │   ├── Auth/SsoLogoutController.php    # inti Single Logout
│   │   ├── OAuth/{Revoke,UserInfo,Jwks}Controller.php
│   │   ├── Admin/UserController.php         # CRUD user + kirim password via WhatsApp
│   │   ├── SessionsController.php          # monitoring perangkat
│   │   └── Api/UserController.php
│   └── Middleware/
│       ├── ForcePasswordChange.php         # blokir halaman sampai password diganti
│       ├── EnforcePkce.php                 # PKCE S256 wajib di /oauth/authorize
│       ├── TrackAuthorizeRequest.php       # mapping sesi IdP<->client + url.intended
│       └── SecurityHeaders.php
├── Jobs/SendBackChannelLogout.php          # kirim logout_token + audit
├── Models/{Client,SsoSession,SsoLogoutAudit}.php
├── Rules/WhatsAppNumber.php                # validasi nomor WA
└── Services/
    ├── SsoSessionService.php               # tracking sesi
    ├── LogoutTokenBuilder.php              # JWT logout token RS256 (kid: passport-key-1)
    ├── FonnteService.php                   # kirim WhatsApp (kredensial akun baru)
    └── PasswordGenerator.php               # password acak 12 karakter (tanpa karakter ambigu)
```

---

## 🛡️ Checklist Produksi

- [ ] **HTTPS** di IdP & semua client (`APP_URL=https://...`) — HSTS otomatis aktif
- [ ] `SESSION_DOMAIN=.domain.com` agar cookie konsisten lintas subdomain
- [ ] `SESSION_ENCRYPT=true`, cookie `secure`, `httpOnly`, `sameSite=lax`
- [ ] Ganti kredensial client; simpan secret di secret manager (jangan `.env` di git)
- [ ] `QUEUE_CONNECTION=redis` + worker terpisah (`php artisan queue:work`) — back-channel logout tetap async & retry saat IdP sibuk
- [ ] Rotasi logging: kirim log ke agregator (Sentry/ELK); monitor `sso_logout_audit` status `failed`
- [ ] Jadwalk housekeeping: `Schedule::call(fn () => SsoSessionService::purgeExpired())` harian
- [ ] Tambahkan MFA / lockout progresif jika exposure tinggi
- [ ] `APP_DEBUG=false`, disable telementry debug
- [ ] Verifikasi `post_logout_redirect_uri` per client saat registrasi client baru
- [ ] Pertimbangkan front-channel logout (iframe) untuk browser yang memblokir server-to-server
- [ ] Isi `FONNTE_TOKEN` + pastikan kuota Fonnte cukup; uji kirim password ke nomor admin sendiri
- [ ] Monitor `storage/logs/laravel.log` untuk entri `Fonnte:` (pengiriman gagal)

---

## ⚙️ Konfigurasi Penting (`.env` IdP)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sso.ukpbj.demakkab.go.id
DB_DATABASE=sso_idp
SESSION_DOMAIN=null           # null = host-only (paling aman)
SESSION_ENCRYPT=true
QUEUE_CONNECTION=sync         # ganti ke redis di produksi

# WhatsApp gateway (Fonnte) — kirim password akun baru
FONNTE_TOKEN=xxxxxxxxxxxx
FONNTE_API_URL=https://api.fonnte.com/send
FONNTE_TIMEOUT=15
FONNTE_COUNTRY_CODE=62
```

> Setelah mengubah `.env` di server produksi, jalankan `php artisan config:clear`
> (atau `config:cache` bila memakai cache konfigurasi) agar `FONNTE_TOKEN` terbaca.

### Deployment di belakang reverse proxy (Nginx/Apache)

IdP sudah dikonfigurasi untuk produksi HTTPS:

1. **`URL::forceScheme('https')` + `forceRootUrl()`** otomatis aktif saat `APP_ENV=production` (di `AppServiceProvider`) — semua redirect/OIDC URL di-generate pakai `https://sso.ukpbj.demakkab.go.id`
2. **`TrustProxies(at: '*')`** di `bootstrap/app.php` — Laravel membaca header `X-Forwarded-Proto/Host/Port` dari proxy

Contoh konfigurasi Nginx:

```nginx
server {
    listen 443 ssl;
    server_name sso.ukpbj.demakkab.go.id;
    root /var/www/sso/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Menjalankan IdP lokal (dengan `.env` produksi)

`.env` berisi konfigurasi produksi. Untuk testing lokal, jalankan dev server dengan
**override env** (dotenv Laravel tidak menimpa env asli):

```powershell
$env:APP_ENV='local'; $env:APP_URL='http://localhost:8000'; php artisan serve --port=8000
```

---

## 💬 Kirim Password Akun Baru via WhatsApp (Fonnte)

Saat admin menambah user di **Manajemen → Kelola User → + Tambah User**, password
**tidak diisi manual**. Alurnya:

1. Admin mengisi nama, email, NIP, nomor WhatsApp, role, dan status
2. Sistem membuat password acak 12 karakter (tanpa karakter ambigu seperti `0/O`, `1/l/I`)
3. Akun disimpan (password di-hash bcrypt), lalu kredensial dikirim ke WhatsApp user
4. Setelah berhasil, `password_sent_at` dicatat dan panel menampilkan nomor tujuan

Isi pesan WhatsApp:

```
*Akun SSO <APP_NAME>*

Halo <nama>, akun Anda sudah dibuat.

Email: user@example.com
NIP: 199900001
Password: Xk7mPq2Rt9Za

Masuk di: https://sso.ukpbj.demakkab.go.id/login

Demi keamanan, segera ganti password setelah login pertama. ...
```

### Konfigurasi Fonnte

1. Daftar di [fonnte.com](https://fonnte.com), hubungkan perangkat WhatsApp,
   lalu salin **Token** dari menu *Device*
2. Isi `.env`:

```env
FONNTE_TOKEN=token-dari-fonnte
FONNTE_API_URL=https://api.fonnte.com/send   # opsional
FONNTE_TIMEOUT=15                            # opsional, detik
FONNTE_COUNTRY_CODE=62                       # opsional
```

3. `php artisan config:clear`

Pengiriman memakai `POST {FONNTE_API_URL}` dengan header `Authorization: <token>`
dan field form `target`, `message`, `countryCode`.

### Format nomor

Nomor dinormalisasi otomatis ke format internasional tanpa `+`:

| Input | Disimpan |
|---|---|
| `0812-3456-7890` | `6281234567890` |
| `+62 812 3456 7890` | `6281234567890` |
| `81234567890` | `6281234567890` |

Nomor yang tidak valid (terlalu pendek/panjang) ditolak validasi form.

### Bila pengiriman gagal

Akun **tetap dibuat** (jangan sampai admin kehilangan input). Yang terjadi:

- Panel menampilkan notifikasi berisi alasan kegagalan (token kosong, timeout, kuota habis, dll.)
- Password ditampilkan **sekali** dalam kartu *Kredensial baru — tampil sekali* di halaman
  daftar user, lengkap dengan tombol **Copy**, agar bisa diteruskan manual
- Kegagalan juga tercatat di `storage/logs/laravel.log` (level `warning`/`error`)

### Kirim ulang / reset password

- **Daftar user** → tombol **Kirim WA** pada baris user
- **Edit user** → bagian *Kirim Password via WhatsApp*

Keduanya membuat **password baru** (password lama langsung tidak berlaku) lalu
mengirimkannya ke WhatsApp. Jika gagal, password baru ditampilkan sekali seperti di atas.

> Catatan keamanan: `password_sent_at` hanya menandai waktu pengiriman terakhir —
> isi password **tidak pernah** disimpan dalam bentuk plaintext.

### Wajib ganti password pada login pertama

Setiap password yang dibuat sistem (user baru) atau direset admin ditandai
`must_change_password = true`. Konsekuensinya:

1. Login berhasil → user **langsung diarahkan** ke `/password/change`
2. Setiap halaman lain (`/dashboard`, `/sessions`, `/admin`, `/oauth/authorize`, …)
   dialihkan kembali ke `/password/change` oleh middleware `ForcePasswordChange`
3. Halaman ubah password meminta **password saat ini**, password baru (min. 8 karakter,
   berbeda dari yang lama), dan konfirmasi
4. Setelah berhasil: `must_change_password = false`, `password_changed_at` diisi,
   session di-regenerate, lalu user dikembalikan ke halaman yang tadi ingin dibuka
   (mis. `/oauth/authorize` → lanjut ke aplikasi klien tanpa login ulang)

Yang tetap boleh diakses saat dipaksa: halaman ubah password itu sendiri dan **logout**
(agar user bisa keluar kapan saja). Request JSON/API dijawab `403` dengan pesan
`Anda harus mengubah password terlebih dahulu.`

Admin dapat menandai/membatalkan paksa ganti password lewat checkbox
**"Wajib ganti password saat login berikutnya"** di halaman Edit User.
Mengisi password manual di form edit juga otomatis menandai user wajib ganti password.

> User yang sedang login akan langsung terblokir begitu admin menekan **Kirim WA**
> (reset password), karena middleware memeriksa flag pada setiap request.

### Ubah password mandiri (semua role)

Halaman `/password/change` juga bisa dibuka **kapan saja** oleh user dengan role
apa pun (`admin` maupun `user`) untuk mengganti password sendiri:

- Tombol **Ubah Password** di dashboard IdP
- Link **Ubah Password** di header (sebelah nama user)
- Verifikasi password saat ini → password baru (min. 8, beda dari lama) → konfirmasi
- Setelah sukses: session di-regenerate, `password_changed_at` diperbarui, dan
  dashboard menampilkan notifikasi **"Password berhasil diubah"**
- Password baru langsung berlaku untuk login berikutnya dan seluruh aplikasi SSO

Halaman ini tidak dibatasi role — user biasa tidak mendapat 403.

---

## 📝 Cara Menambahkan / Membuat Aplikasi Klien Baru

Setiap aplikasi (SP) yang ingin memakai SSO harus **diregistrasikan dulu di IdP** agar
mendapat `Client ID` & `Client Secret`. Registrasi dilakukan oleh **admin** melalui
Panel Admin IdP.

### Langkah 1 — Login sebagai Admin

1. Buka IdP: `https://sso.ukpbj.demakkab.go.id/login`
2. Login dengan akun ber-role `admin` (contoh demo: `admin@sso.test` / `password123`)
3. Klik menu **Manajemen** di navigasi atas (hanya tampil untuk admin)

> Akun dengan role `user` akan ditolak dengan **403** di seluruh area `/admin`.

### Langkah 2 — Buka Menu Aplikasi Klien

1. Di sidebar Manajemen, pilih **Kelola Aplikasi Klien** (`/admin/clients`)
2. Klik tombol **+ Registrasi Client**

### Langkah 3 — Isi Form Registrasi

| Field | Contoh | Wajib? | Keterangan |
|---|---|---|---|
| **Nama Aplikasi** | `SIMDESA Kabupaten Demak` | ✅ | Ditampilkan di halaman consent IdP |
| **Redirect URIs** | `https://simdesa.ukpbj.demakkab.go.id/auth/callback` | ✅ | URI ke-1 = callback OAuth. URI ke-2 (opsional) = halaman post-logout. Kosongkan URI kedua jika tidak dipakai |
| **Landing URL** | `https://simdesa.ukpbj.demakkab.go.id/` | Untuk dashboard | Halaman awal aplikasi — jika diisi, aplikasi tampil sebagai kartu **"Aplikasi Terhubung"** di dashboard user (dibuka di tab baru) |
| **Back-Channel Logout URI** | `https://simdesa.ukpbj.demakkab.go.id/sso/backchannel-logout` | Untuk SLO | **Wajib** agar aplikasi ikut ter-logout saat Single Logout |
| **Front-Channel Logout URI** | — | Opsional | Alternatif SLO via iframe browser |
| **Trusted** | ✔ centang | Opsional | Skip halaman consent (langsung authorize) |

> ⚠️ `Redirect URIs` & `Back-Channel Logout URI` **harus sama persis** dengan
> `SSO_REDIRECT_URI` dan endpoint back-channel di aplikasi klien —
> termasuk skema `https` dan tanpa trailing slash. Ketidakcocokan akan
> ditolak OAuth (`redirect_uri` invalid) atau SLO tidak terkirim.

### Langkah 4 — Simpan Kredensial (Secret Tampil Sekali!)

Setelah klik **Registrasi**, IdP langsung mengarahkan ke halaman **Client Secret**:

- 🟨 `Client ID` dan `Client Secret` ditampilkan **HANYA PADA HALAMAN INI**
- Klik tombol **Copy** untuk masing-masing nilai, lalu simpan ke secret manager / tempat aman
- Jika halaman ditutup tanpa menyimpan, secret tidak bisa dilihat lagi —
  satu-satunya jalan adalah **Regenerate Secret** (secret lama hangus)

### Langkah 5 — Pasang Kredensial di Aplikasi Klien

Isi `.env` aplikasi klien (lihat detail di [panduan integrasi](#-integrasi-aplikasi-klien-laravel-12-tanpa-socialite)):

```env
SSO_ISSUER=https://sso.ukpbj.demakkab.go.id
SSO_CLIENT_ID=<Client ID dari Langkah 4>
SSO_CLIENT_SECRET=<Client Secret dari Langkah 4>
SSO_REDIRECT_URI=https://simdesa.ukpbj.demakkab.go.id/auth/callback
SSO_POST_LOGOUT_URI=https://simdesa.ukpbj.demakkab.go.id/goodbye
```

### Langkah 6 — Uji Alur

1. Akses halaman terproteksi aplikasi klien → harus redirect ke halaman login IdP
2. Login → kembali ke dashboard klien dengan sesi aktif
3. Tekan **Keluar (Aplikasi Ini)** di klien → sesi klien berakhir, diarahkan ke
   dashboard IdP, dan **sesi IdP tetap aktif** (masuk lagi tanpa login ulang)
4. Tekan **Logout Semua (SSO)** → global logout & back-channel terkirim ke semua
   aplikasi (cek tabel `sso_logout_audit` di IdP: status harus `delivered`)

### Aplikasi di Dashboard User

Client yang memiliki **Landing URL** akan muncul sebagai kartu di bagian
**"Aplikasi Terhubung"** pada dashboard user IdP (`/dashboard`). Saat user
mengklik kartu:

1. Aplikasi klien dibuka di **tab baru** (`target="_blank" rel="noopener"`)
2. Middleware aplikasi klien tidak menemukan sesi lokal → redirect ke IdP
3. User sudah login di IdP → langsung kembali ke aplikasi tanpa isi ulang password

> Client tanpa Landing URL tidak muncul di dashboard — isi melalui menu
> **Edit** pada daftar aplikasi klien.

### Mengelola Klien yang Sudah Ada

Dari daftar **Kelola Aplikasi Klien**:

| Aksi | Efek |
|---|---|
| **Edit** | Ubah nama, redirect URIs, back-channel/front-channel URI, trusted |
| **Regenerate Secret** | Secret baru dibuat & ditampilkan sekali; **secret lama hangus** — aplikasi klien wajib update `.env`-nya |
| **Revoke** | Client dinonaktifkan (soft delete `revoked=1`) dan **seluruh token-nya dicabut**. Redirect/token dari client ini akan ditolak |

---

## 🔌 Integrasi Aplikasi Klien Laravel 12 (Tanpa Socialite)

Aplikasi klien **tidak perlu** menggunakan Laravel Socialite. Integrasi dibangun sendiri dengan `Illuminate\Support\Facades\Http` untuk menangani **Authorization Code + PKCE S256**, pertukaran token, validasi, refresh token, serta **Single Logout via Back-Channel**.

### 1. Tambahkan Variabel `.env` Klien

Tambahkan konfigurasi berikut ke file `.env` aplikasi Laravel klien kamu:

```env
SSO_ISSUER=https://sso.ukpbj.demakkab.go.id
SSO_CLIENT_ID=01a1803a-470d-72a4-a0cd-e057e6fe41d4
SSO_CLIENT_SECRET=dU2MqR44CMIJgAIWQaIYachoTVU4khFWC90WxI41
SSO_REDIRECT_URI=http://localhost:8010/auth/callback
SSO_POST_LOGOUT_URI=http://localhost:8010/goodbye
SSO_SCOPES=*
```

> Untuk lingkungan produksi, ganti URL ke HTTPS, gunakan secret yang berbeda, dan **jangan pernah** commit `SSO_CLIENT_SECRET` ke repository.

### 2. Buat File Konfigurasi `config/sso.php`

Buat file `config/sso.php` untuk memusatkan seluruh endpoint dan kredensial IdP.

```php
<?php

return [
    // Identitas IdP
    'issuer' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id'),
    'authorize_endpoint' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id').'/oauth/authorize',
    'token_endpoint' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id').'/oauth/token',
    'userinfo_endpoint' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id').'/oauth/userinfo',
    'revoke_endpoint' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id').'/oauth/revoke',
    'jwks_endpoint' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id').'/oauth/jwks',
    'logout_endpoint' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id').'/sso/logout',
    // Dashboard IdP — tujuan setelah logout lokal aplikasi klien
    'dashboard_endpoint' => env('SSO_ISSUER', 'https://sso.ukpbj.demakkab.go.id').'/dashboard',

    // Kredensial OAuth Client (Service Provider)
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri' => env('SSO_REDIRECT_URI'),
    'post_logout_redirect_uri' => env('SSO_POST_LOGOUT_URI'),

    // Scope yang diminta ke IdP
    'scopes' => env('SSO_SCOPES', '*'),

    // TTL cache validasi token ke /oauth/userinfo (dalam detik)
    // Semakin kecil = lebih responsif saat revoke SLO, semakin besar = lebih ringan ke IdP
    'token_cache_ttl' => 30,
];
```

### 3. Buat Service `app/Services/SsoClient.php`

Service ini bertanggung jawab untuk generate PKCE, redirect ke IdP, tukar `authorization code`, refresh token, validasi token, serta revoke token saat logout.

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class SsoClient
{
    /**
     * Generate URL ke Authorization Endpoint IdP dengan PKCE S256
     */
    public function redirectUrl(): string
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        Session::put('sso_code_verifier', $verifier);
        Session::put('sso_state', $state);

        return config('sso.authorize_endpoint').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('sso.client_id'),
            'redirect_uri' => config('sso.redirect_uri'),
            'scope' => config('sso.scopes'),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Tukar authorization code + code_verifier menjadi access_token & refresh_token
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->timeout(10)->post(config('sso.token_endpoint'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('sso.client_id'),
            'client_secret' => config('sso.client_secret'),
            'redirect_uri' => config('sso.redirect_uri'),
            'code' => $code,
            'code_verifier' => Session::pull('sso_code_verifier'),
        ]);

        return $response->throw()->json();
    }

    /**
     * Refresh access token menggunakan refresh token
     * (IdP akan melakukan refresh token rotation)
     */
    public function refresh(string $refreshToken): ?array
    {
        $response = Http::asForm()->timeout(10)->post(config('sso.token_endpoint'), [
            'grant_type' => 'refresh_token',
            'client_id' => config('sso.client_id'),
            'client_secret' => config('sso.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        return $response->status() === 200 ? $response->json() : null;
    }

    /**
     * Validasi access token via /oauth/userinfo dengan caching
     */
    public function validateToken(string $accessToken): ?array
    {
        $cacheKey = 'sso_token_'.hash('sha256', $accessToken);

        $cached = Cache::get($cacheKey);
        if ($cached === 'revoked') {
            return null;
        }
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $res = Http::withToken($accessToken)->acceptJson()->timeout(5)->get(config('sso.userinfo_endpoint'));

            if ($res->status() === 401) {
                Cache::put($cacheKey, 'revoked', config('sso.token_cache_ttl'));
                return null;
            }

            $user = $res->throw()->json();
            Cache::put($cacheKey, $user, config('sso.token_cache_ttl'));
            return $user;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Revoke token di IdP dan bersihkan session klien
     */
    public function revokeAndForget(): void
    {
        if ($accessToken = Session::get('sso_access_token')) {
            try {
                Http::asForm()->timeout(5)->post(config('sso.revoke_endpoint'), [
                    'token' => $accessToken,
                ]);
            } catch (\Throwable) {
                // Lanjutkan logout meski koneksi ke IdP gagal
            }
        }

        Session::forget(['sso_access_token', 'sso_refresh_token', 'sso_user', 'sso_code_verifier', 'sso_state']);
    }

    public static function flushTokenCache(string $accessToken): void
    {
        Cache::forget('sso_token_'.hash('sha256', $accessToken));
    }
}
```

### 4. Middleware `app/Http/Middleware/SsoAuthenticate.php`

Middleware ini melindungi route aplikasi klien. Jika token tidak ada/invalid, akan diarahkan ke login SSO, dan akan mencoba `refresh` otomatis sebelum memaksa login ulang.

```php
<?php

namespace App\Http\Middleware;

use App\Services\SsoClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SsoAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = session('sso_access_token');

        if (! $accessToken) {
            return redirect()->to(url('/auth/redirect'));
        }

        $sso = app(SsoClient::class);
        $user = $sso->validateToken($accessToken);

        // Coba refresh jika access token sudah tidak valid
        if (is_null($user) && ($refreshToken = session('sso_refresh_token'))) {
            if ($new = $sso->refresh($refreshToken)) {
                session()->put('sso_access_token', $new['access_token']);
                session()->put('sso_refresh_token', $new['refresh_token'] ?? $refreshToken);
                SsoClient::flushTokenCache($accessToken);
                $user = $sso->validateToken($new['access_token']);
            }
        }

        if (is_null($user)) {
            session()->forget(['sso_access_token', 'sso_refresh_token', 'sso_user']);
            return redirect()->to(url('/auth/redirect'));
        }

        session()->put('sso_user', $user);
        return $next($request);
    }
}
```

### 5. Controller Auth & Back-Channel Logout

#### `app/Http/Controllers/Auth/AuthController.php`

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SsoClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return redirect()->to(app(SsoClient::class)->redirectUrl());
    }

    public function callback(Request $request, SsoClient $sso): RedirectResponse
    {
        abort_if(! $request->code, 400, 'Authorization code tidak ditemukan.');
        abort_unless(hash_equals((string) session('sso_state'), (string) $request->state), 400, 'State tidak valid.');

        $tokens = $sso->exchangeCode($request->code);
        $claims = $sso->validateToken($tokens['access_token']);

        session()->put('sso_access_token', $tokens['access_token']);
        session()->put('sso_refresh_token', $tokens['refresh_token'] ?? null);
        session()->put('sso_user', $claims);

        // Mapping user lokal berdasarkan sub IdP (penting untuk back-channel logout)
        $user = User::firstOrNew(['sso_sub' => (string) ($claims['sub'] ?? '')]);
        $user->fill([
            'sso_sub' => (string) ($claims['sub'] ?? ''),
            'name' => $claims['name'] ?? 'Pengguna IdP',
            'email' => $claims['email'] ?? 'unknown@idp.local',
            'nip' => $claims['nip'] ?? null,
        ]);

        if (! $user->exists) {
            $user->password = bcrypt(Str::random(32));
        }

        $user->save();
        Auth::login($user, true);

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        app(SsoClient::class)->revokeAndForget();

        // Logout LOKAL: sesi aplikasi ini berakhir, sesi IdP tetap aktif.
        // Redirect ke dashboard IdP; user bisa masuk lagi tanpa login ulang.
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(config('sso.dashboard_endpoint'));
    }

    public function logoutSso(Request $request): RedirectResponse
    {
        app(SsoClient::class)->revokeAndForget();

        // Logout GLOBAL (Single Logout): sesi IdP + semua aplikasi berakhir.
        $params = http_build_query([
            'client_id' => config('sso.client_id'),
            'post_logout_redirect_uri' => config('sso.post_logout_redirect_uri'),
        ]);

        return redirect()->to(config('sso.logout_endpoint').'?'.$params);
    }
}
```

#### `app/Services/LogoutTokenVerifier.php`

```php
<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LogoutTokenVerifier
{
    public function verify(string $logoutToken): object
    {
        $jwks = $this->fetchJwks();

        $keys = JWK::parseKeySet($jwks, 'RS256');
        $claims = JWT::decode($logoutToken, $keys);

        if ($claims->iss !== config('sso.issuer')) {
            throw new \RuntimeException('Invalid issuer');
        }

        if (! in_array(config('sso.client_id'), (array) $claims->aud)) {
            throw new \RuntimeException('Invalid audience');
        }

        if (! isset($claims->events->{'http://schemas.openid.net/event/backchannel-logout'})) {
            throw new \RuntimeException('Missing backchannel-logout event');
        }

        // Anti-replay protection
        if (Cache::get('logout_jti:'.$claims->jti)) {
            throw new \RuntimeException('Replay logout token detected');
        }
        Cache::put('logout_jti:'.$claims->jti, true, 600);

        return $claims;
    }

    /**
     * Ambil JWKS IdP dengan fallback stale-copy.
     *
     * PENTING: tanpa fallback ini bisa terjadi deadlock sirkular pada dev
     * server single-worker (Windows tidak mendukung PHP_CLI_SERVER_WORKERS):
     * IdP sedang sibuk mengirim back-channel logout -> SP fetch JWKS balik
     * ke IdP -> IdP tidak bisa melayani -> saling menunggu hingga timeout.
     * Solusinya: setiap fetch sukses disalin permanen ke file; jika fetch
     * gagal/timeout, verifikasi tetap jalan memakai salinan terakhir.
     */
    protected function fetchJwks(): array
    {
        return Cache::remember('sso_jwks', 3600, function () {
            try {
                $jwks = Http::timeout(3)->get(config('sso.jwks_endpoint'))->throw()->json();

                // Simpan salinan permanen untuk fallback
                if (! empty($jwks['keys'])) {
                    \Storage::disk('local')->put('sso_jwks.json', json_encode($jwks));
                }

                return $jwks;
            } catch (\Throwable $e) {
                // Fallback: pakai salinan JWKS terakhir yang sukses di-fetch
                $stale = \Storage::disk('local')->get('sso_jwks.json');

                if ($stale !== null) {
                    $decoded = json_decode($stale, true);

                    if (! empty($decoded['keys'])) {
                        report($e); // tetap tercatat, tapi tidak fatal

                        return $decoded;
                    }
                }

                throw $e;
            }
        });
    }
}
```

#### `app/Http/Controllers/Auth/BackChannelLogoutController.php`

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LogoutTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class BackChannelLogoutController extends Controller
{
    public function __invoke(Request $request, LogoutTokenVerifier $verifier): JsonResponse
    {
        $logoutToken = $request->input('logout_token');

        if (! $logoutToken) {
            return response()->json(['error' => 'missing logout_token'], 400);
        }

        try {
            $claims = $verifier->verify($logoutToken);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid_logout_token'], 400);
        }

        $user = User::where('sso_sub', $claims->sub)->first();

        if ($user) {
            DB::table('sessions')->where('user_id', $user->id)->pluck('id')->each(function ($id) {
                Session::getHandler()->destroy($id);
            });
        }

        return response()->json(['message' => 'sessions destroyed successfully']);
    }
}
```

### 6. Routes, Middleware & Bootstrap

#### `routes/web.php`

```php
<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\BackChannelLogoutController;
use Illuminate\Support\Facades\Route;

// Alur SSO
Route::get('/auth/redirect', [AuthController::class, 'redirect'])->name('sso.redirect');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('sso.callback');

// Logout LOKAL (sesi IdP tetap aktif)
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
// Logout GLOBAL / Single Logout
Route::post('/logout/sso', [AuthController::class, 'logoutSso'])->name('logout.sso');

// Endpoint Back-Channel Logout (dipanggil server-to-server oleh IdP)
Route::post('/sso/backchannel-logout', BackChannelLogoutController::class)->name('sso.backchannel-logout');

// Halaman publik setelah logout global
Route::get('/goodbye', fn () => view('goodbye'))->name('goodbye');

// Halaman yang dilindungi oleh SSO
Route::middleware('sso')->group(function () {
    Route::get('/', fn () => view('dashboard'))->name('dashboard');
});
```

#### `bootstrap/app.php`

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'sso' => \App\Http\Middleware\SsoAuthenticate::class,
        ]);

        // Exclude CSRF untuk endpoint back-channel logout (request dari server IdP)
        $middleware->validateCsrfTokens(except: [
            'sso/backchannel-logout',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

### 7. Update Model & Migrasi User Klien

Tambahkan kolom `sso_sub` dan `nip` ke tabel `users`. Karena login sepenuhnya via SSO, kolom `password` sebaiknya dibuat nullable.

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_sub')->nullable()->unique()->after('email');
            $table->string('nip', 50)->nullable()->after('email');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sso_sub', 'nip']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
```

Update `app/Models/User.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'nip',
        'sso_sub',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

### 8. Konfigurasi di Sisi IdP

Agar aplikasi klien dikenali IdP, **registrasikan client-nya lewat Panel Admin** —
lihat [Cara Menambahkan / Membuat Aplikasi Klien Baru](#-cara-menambahkan--membuat-aplikasi-klien-baru).
Alternatif (untuk seeding otomatis), daftarkan langsung di tabel `oauth_clients`:

| Kolom | Nilai | Keterangan |
| --- | --- | --- |
| `name` | `Nama Aplikasi Klien` | Nama yang ditampilkan di halaman consent IdP |
| `redirect_uris` | `["http://localhost:8010/auth/callback", "http://localhost:8010/goodbye"]` | Wajib sesuai dengan `SSO_REDIRECT_URI` & `SSO_POST_LOGOUT_URI` |
| `trusted` | `1` | Opsional, jika `1` halaman consent akan otomatis di-skip |
| `backchannel_logout_uri` | `http://localhost:8010/sso/backchannel-logout` | **Wajib** agar IdP mengirim logout token saat SLO |
| `revoked` | `0` | Pastikan client aktif |

### Quick Checklist Integrasi

- [ ] **Dependency**: `composer require firebase/php-jwt`
- [ ] **File wajib**: `config/sso.php`, `app/Services/SsoClient.php`, `app/Services/LogoutTokenVerifier.php`, `app/Http/Middleware/SsoAuthenticate.php`, `app/Http/Controllers/Auth/AuthController.php`, `app/Http/Controllers/Auth/BackChannelLogoutController.php`
- [ ] **Bootstrap**: Register middleware alias `sso` dan tambahkan `sso/backchannel-logout` ke `validateCsrfTokens(except: [])`
- [ ] **Database**: Tambah kolom `sso_sub`, `nip` ke tabel `users` & ubah `password` jadi nullable. Gunakan `SESSION_DRIVER=database`
- [ ] **IdP**: Daftarkan client dengan `redirect_uris` dan `backchannel_logout_uri` yang benar
- [ ] **Uji alur**: Akses route terproteksi → redirect ke IdP → login → callback kembali ke aplikasi klien
- [ ] **Uji logout lokal**: tombol "Keluar (Aplikasi Ini)" → sesi klien berakhir, redirect ke dashboard IdP, sesi IdP tetap aktif
- [ ] **Uji SLO**: tombol "Logout Semua (SSO)" → IdP melakukan global logout & mengirim back-channel ke klien → seluruh sesi klien terhancurkan otomatis
