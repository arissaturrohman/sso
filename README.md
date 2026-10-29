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
- **Security headers** — CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS (saat HTTPS); `form-action` memakai allowlist origin aplikasi klien (`SSO_FORM_ACTION_ORIGINS`) agar login dari alur redirect tidak diblokir browser
- **Session fixation protection** — session regenerate saat login & logout
- **Anti open-redirect** — `post_logout_redirect_uri` divalidasi terhadap `redirect_uris` client
- **Panel Admin (role `admin`)** — CRUD user & aplikasi klien OAuth: registrasi client dengan secret ditampilkan sekali, regenerate secret, revoke client (token ikut dicabut) — lihat [Cara Menambahkan Aplikasi Klien](#-cara-menambahkan--membuat-aplikasi-klien-baru)
- **Password akun baru dikirim via WhatsApp (Fonnte)** — admin cukup mengisi nomor WhatsApp; password dibuat otomatis & dikirim ke user, dengan fallback tampil-sekali bila pengiriman gagal
- **Wajib ganti password pada login pertama** — password sementara dari admin harus diganti sebelum user bisa membuka halaman lain (termasuk `/oauth/authorize`); semua halaman dialihkan ke `/password/change`
- **Ubah password mandiri kapan saja** — tombol **Ubah Password** di dashboard (semua role: admin & user) dan link di header; wajib verifikasi password lama
- **Aplikasi Terhubung di dashboard** — client dengan Landing URL tampil sebagai kartu di dashboard user; diklik membuka aplikasi di tab baru dengan SSO otomatis

---

## 🚀 Menjalankan

### Prasyarat & Dependency

#### Yang wajib di server IdP

| Kebutuhan | Versi | Keterangan |
|---|---|---|
| **PHP** | 8.2+ (diuji 8.3) | Ekstensi wajib: `openssl`, `pdo_mysql`, `mbstring`, `curl`, `json`, `ctype`, `fileinfo`, `tokenizer`, `xml`, `bcmath`, `sodium` |
| **Composer** | 2.x | Install dependency PHP |
| **MySQL / MariaDB** | 8.0+ / 10.4+ | Database `sso_idp` |
| **Queue worker** | — | **Wajib saat `QUEUE_CONNECTION` ≠ `sync`**; queue `slo` dipakai mengirim back-channel logout |
| **Passport keys (RS256)** | — | `storage/oauth-private.key` & `storage/oauth-public.key` (dipakai juga untuk menandatangani `logout_token`) |
| **Node.js + npm** | 20+ / 10+ | **Opsional** — hanya bila ingin `npm run build` (aset Vite; UI utama IdP memakai CSS inline, `public/build` tidak wajib) |

Paket Composer yang dipakai IdP (sudah tercantum di `composer.json`):

| Paket | Versi | Fungsi |
|---|---|---|
| `laravel/framework` | `^12.0` | Framework |
| `laravel/passport` | `13.0` | OAuth 2.0 server: authorization code, token, revoke |
| `firebase/php-jwt` | `6.10` | Menandatangani `logout_token` (RS256) + verifikasi di sisi klien |
| `laravel/tinker` | `^2.10` | Registrasi client & operasi manual via CLI |

Dependency pengembangan (tidak perlu di produksi): `phpunit/phpunit ^11.5`, `nunomaduro/collision ^8.6`, `mockery/mockery ^1.6`, `fakerphp/faker ^1.23`, `laravel/pint ^1.13`, `laravel/pail ^1.2`, `laravel/sail ^1.41`.

Frontend (`devDependencies`, opsional): `vite ^6`, `laravel-vite-plugin ^1.2`, `tailwindcss ^4`, `@tailwindcss/vite ^4`, `axios`, `concurrently`.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force

# Jalankan hanya jika QUEUE_CONNECTION bukan "sync"
php artisan queue:work --queue=slo
```

> **Penting:** dengan `QUEUE_CONNECTION=sync`, job back-channel dijalankan inline saat
> request logout — cukup untuk dev, tetapi **tidak** memberi retry/backoff. Untuk
> produksi gunakan `database`/`redis` + worker `--queue=slo` seperti di atas.

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

Script test E2E (test-*.php) telah dihapus bersama folder demo-client.
Verifikasi manual yang disarankan setelah deploy:

1. `GET /up` dan `GET /health` → 200
2. Login via email **dan** via NIP → dashboard tampil
3. `/admin` dengan role `admin` → 200; dengan role `user` → 403
4. Registrasi client baru via Panel Admin → secret tampil sekali
5. Login dari aplikasi klien **di browser** (bukan curl) → form login IdP bisa
   disubmit, callback kembali ke aplikasi (bukti `SSO_FORM_ACTION_ORIGINS` benar)
6. Logout di dashboard IdP → semua token revoked (`sso_logout_audit` tercatat)
7. Buat user baru → login dengan password dari WhatsApp → **dipaksa ganti password**;
   setelah ganti, akses `/dashboard` & `/oauth/authorize` berjalan normal
8. Login sebagai **admin** dan **user** → tombol **Ubah Password** di dashboard berfungsi
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

# CSP `form-action`: origin aplikasi klien (dipisah koma).
# Wajib diisi agar form login IdP bisa disubmit saat alur dimulai dari aplikasi klien.
SSO_FORM_ACTION_ORIGINS=https://simdesa.ukpbj.demakkab.go.id,https://simukpbj.demakkab.go.id

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
Panel Admin IdP. Setelah terdaftar, aplikasi klien wajib memakai
**Authorization Code + PKCE (S256)** dan (agar ikut ter-logout) menyediakan
**Back-Channel Logout**.

### Ringkasan Nilai yang Harus Disiapkan

Siapkan 4 URL aplikasi klien **sebelum** membuka form registrasi. Nilainya harus
final karena `redirect_uris` dibandingkan **exact-match** oleh IdP.

| Kebutuhan | Contoh | Wajib | Dipakai untuk |
|---|---|---|---|
| Callback OAuth | `https://simdesa.ukpbj.demakkab.go.id/auth/callback` | ✅ | `redirect_uri` saat authorize & tukar token |
| Halaman setelah logout | `https://simdesa.ukpbj.demakkab.go.id/` | ⬜ (disarankan) | `post_logout_redirect_uri` |
| Endpoint back-channel logout | `https://simdesa.ukpbj.demakkab.go.id/sso/backchannel-logout` | ✅ untuk SLO | menerima `logout_token` dari IdP (server-to-server) |
| Halaman awal aplikasi | `https://simdesa.ukpbj.demakkab.go.id/` | ⬜ | kartu **Aplikasi Terhubung** di dashboard IdP |

> Aturan umum: **HTTPS wajib di produksi**, tanpa trailing slash tambahan, tanpa
> query string, dan `http://` ≠ `https://` ≠ `http://localhost` — semuanya dianggap
> URI berbeda.

### Langkah 0 — Dependency Aplikasi Klien

Sebelum mengubah kode, pastikan aplikasi klien memenuhi dependency berikut:

| Kebutuhan | Wajib? | Keterangan |
|---|---|---|
| **PHP 8.2+** | ✅ | Ekstensi: `curl`, `openssl`, `mbstring`, `json`, `fileinfo`, `pdo` + driver DB, `tokenizer`, `xml`, `ctype` |
| **Laravel 10/11/12** (`Illuminate\Support\Facades\Http`) | ✅ | Dipakai untuk tukar token, `/oauth/userinfo`, revoke, dan fetch JWKS |
| **`firebase/php-jwt` `^6.10`** | ✅ | Verifikasi `logout_token` RS256 + parsing JWKS (`JWK::parseKeySet`) |
| **`guzzlehttp/guzzle` `^7.8`** | ✅ | Backend HTTP client Laravel. Tanpa ini `Http::post(...)` melempar *“Guzzle HTTP client is not installed”* |
| **`SESSION_DRIVER=database`** | ✅ untuk SLO | Back-channel menghapus baris di tabel `sessions` berdasarkan `user_id` |
| **Kolom `users.sso_sub` (unik) + `nip`** | ✅ | Mapping `sub` IdP → user lokal; **tanpa ini back-channel tidak menemukan user** |
| **`laravel/passport`, Socialite** | ❌ | **Tidak diperlukan** di aplikasi klien |
| **Filament** (`filament/filament`) | ⬜ | Hanya jika aplikasi klien memakai panel Filament; butuh Node.js/npm untuk aset panel |
| **Node.js + npm** | ⬜ | Hanya bila aplikasi klien punya aset Vite/Tailwind sendiri |

Install:

```bash
composer require firebase/php-jwt:^6.10 guzzlehttp/guzzle:^7.8

# setelah menambahkan kolom sso_sub & nip ke tabel users
php artisan migrate
```

Dependency minimum `composer.json` aplikasi klien (contoh):

```json
{
    "require": {
        "php": "^8.2",
        "laravel/framework": "^12.0",
        "firebase/php-jwt": "^6.10",
        "guzzlehttp/guzzle": "^7.8"
    }
}
```

> Aplikasi klien **tidak** memerlukan `laravel/passport` — token OAuth diterbitkan IdP.
> Yang wajib hanya HTTP client + `firebase/php-jwt` untuk memverifikasi logout token.

### Langkah 1 — Login sebagai Admin

1. Buka IdP: `https://sso.ukpbj.demakkab.go.id/login`
2. Login dengan akun ber-role `admin` (contoh demo: `admin@sso.test` / `password123`)
3. Klik menu **Manajemen** di navigasi atas (hanya tampil untuk admin)

> Akun dengan role `user` akan ditolak dengan **403** di seluruh area `/admin`.

### Langkah 2 — Buka Menu Aplikasi Klien

1. Di sidebar Manajemen, pilih **Kelola Aplikasi Klien** (`/admin/clients`)
2. Klik tombol **+ Registrasi Client**

### Langkah 3 — Isi Form Registrasi

Buka **`/admin/clients/create`** (tombol **+ Registrasi Client**), lalu isi:

| Field | Contoh | Wajib? | Aturan validasi & keterangan |
|---|---|---|---|
| **Nama Aplikasi** | `SIMDESA Kabupaten Demak` | ✅ | Maks. 255 karakter. Ditampilkan di halaman consent IdP. |
| **Redirect URIs** (kolom 1) | `https://simdesa.ukpbj.demakkab.go.id/auth/callback` | ✅ | Minimal 1 URI, tiap URI harus URL valid & maks. 255 karakter. Nilai ini yang dipakai sebagai `redirect_uri`. |
| **Redirect URIs** (kolom 2) | `https://simdesa.ukpbj.demakkab.go.id/` | ⬜ | Hanya dipakai sebagai `post_logout_redirect_uri`. Kosongkan bila tidak perlu. |
| **Landing URL** | `https://simdesa.ukpbj.demakkab.go.id/` | ⬜ | Jika diisi, aplikasi muncul sebagai kartu **Aplikasi Terhubung** di dashboard user IdP (dibuka di tab baru). |
| **Back-Channel Logout URI** | `https://simdesa.ukpbj.demakkab.go.id/sso/backchannel-logout` | ⬜\* | \*Tanpa ini SLO tidak terkirim. Wajib **HTTPS** (kecuali `localhost`/loopback saat `APP_ENV=local`), host **publik** (bukan IP privat/link-local/metadata), tanpa `user`/`pass`/`fragment`, maks. 255 karakter. |
| **Front-Channel Logout URI** | — | ⬜ | Hanya disimpan sebagai metadata; IdP saat ini **belum** mengirim front-channel (iframe). |
| **Trusted** | ✔ centang | ⬜ | Melewati halaman consent (langsung authorize). Cocok untuk aplikasi internal/first-party. |

Hal yang sering salah:

- **`redirect_uris` disimpan sebagai array.** URI ke-1 = callback OAuth, URI ke-2 =
  tujuan post-logout. Daftar yang sama dipakai untuk memvalidasi
  `post_logout_redirect_uri`, jadi nilai yang dikirim saat logout **harus persis**
  salah satu isi daftar ini.
- **Jangan ada trailing slash tambahan** — `.../auth/callback` ≠ `.../auth/callback/`.
- **`http` vs `https` dianggap berbeda.** Di produksi `http` ditolak untuk
  `post_logout_redirect_uri` (kecuali loopback).
- **`backchannel_logout_uri` divalidasi anti-SSRF** saat disimpan *dan* setiap akan
  dikirim: host di-resolve DNS, alamat privat/reserved ditolak. Untuk pengujian
  lokal `http://localhost:8001/...` tetap diizinkan selama `APP_ENV` = `local`/`testing`.
- **Bila `Trusted` tidak dicentang**, user akan melihat halaman consent IdP yang
  menampilkan daftar scope (`openid`, `profile`, `email`, atau `*`) dan harus menyetujuinya
  sebelum diarahkan kembali ke aplikasi.
- **`Client Secret` jangan pernah ditaruh di frontend/JavaScript** — hanya dipakai
  server-to-server saat tukar token.
- **Client yang dicabut (`revoked=1`) tidak bisa dipakai lagi** — token ditolak dan
  back-channel tidak dikirim. Daftarkan client baru bila sudah di-revoke.

> ⚠️ `Redirect URIs` & `Back-Channel Logout URI` **harus sama persis** dengan
> `SSO_REDIRECT_URI` dan endpoint back-channel di aplikasi klien —
> termasuk skema `https` dan tanpa trailing slash. Ketidakcocokan akan
> ditolak OAuth (`redirect_uri` invalid) atau SLO tidak terkirim.

### Langkah 4 — Simpan Kredensial (Secret Tampil Sekali!)

Setelah klik **Registrasi**, IdP langsung mengarahkan ke halaman **Client Secret**
(`/admin/clients/{id}/secret`):

- 🟨 `Client ID` dan `Client Secret` ditampilkan **HANYA PADA HALAMAN INI**
- Klik tombol **Copy** untuk masing-masing nilai, lalu simpan ke secret manager / tempat aman
- Jika halaman ditutup tanpa menyimpan, halaman ini akan `404` bila dibuka lagi —
  satu-satunya jalan adalah **Regenerate Secret** (secret lama langsung hangus)
- `Client Secret` adalah string acak 40 karakter; perlakukan seperti password

### Langkah 5 — Pasang Kredensial di Aplikasi Klien

Isi `.env` aplikasi klien (lihat detail di [panduan integrasi](#-integrasi-aplikasi-klien-laravel-12-tanpa-socialite)):

```env
SSO_ISSUER=https://sso.ukpbj.demakkab.go.id
SSO_CLIENT_ID=<Client ID dari Langkah 4>
SSO_CLIENT_SECRET=<Client Secret dari Langkah 4>
SSO_REDIRECT_URI=https://simdesa.ukpbj.demakkab.go.id/auth/callback
SSO_POST_LOGOUT_URI=https://simdesa.ukpbj.demakkab.go.id/goodbye
```

### Langkah 6 — Penuhi Kontrak Teknis IdP

Registrasi saja belum cukup: aplikasi klien harus berperilaku sesuai kontrak berikut.
Semuanya sudah ditegakkan IdP, jadi penyimpangan akan menghasilkan `400`.

| Kontrak | Detail |
|---|---|
| **Grant** | Hanya **Authorization Code**. Implicit grant (`response_type=token`) diblokir. |
| **PKCE** | **Wajib S256**. `code_challenge` 43–128 karakter, `code_challenge_method=S256`. Tanpa itu `/oauth/authorize` → `400`. |
| **Client type** | Client bersifat **confidential** → tukar token wajib mengirim `client_secret`. |
| **Swap token** | `POST /oauth/token` (`application/x-www-form-urlencoded`) dengan `grant_type=authorization_code`, `code`, `redirect_uri`, `code_verifier`, `client_id`, `client_secret`. |
| **Tidak ada `id_token`** | Passport di sini **tidak** menerbitkan `id_token`. Identitas diambil dari `GET /oauth/userinfo` dengan header `Authorization: Bearer <access_token>`. |
| **Klaim profil** | `sub` (ID user IdP, **string**), `name`, `email`, `email_verified`, `nip`, `updated_at`. |
| **Scope** | Default `openid profile email` bila parameter `scope` tidak dikirim. `*` = akses penuh (harus diminta eksplisit). |
| **Umur token** | Access token **15 menit**, refresh token **14 hari**. Refresh token **di-rotasi**: token lama dicabut setelah dipakai, reuse → `400 invalid_grant`. |
| **Rate limit** | `POST /oauth/token` 15×/menit per `client_id`+IP; `POST /login` 5×/menit per IP & identifier. |
| **Mapping user lokal** | Simpan `sub` ke kolom unik (mis. `users.sso_sub`). Back-channel logout menghapus sesi berdasarkan `sub` ini. |

### Langkah 7 — Izinkan Origin Aplikasi pada CSP IdP

Chrome/Chromium menerapkan direktif `form-action` **pada seluruh rantai redirect**
yang dipicu submit form login. Karena rantai itu berakhir di aplikasi klien (origin
berbeda), origin klien harus didaftarkan — kalau tidak, browser memblokir login
dengan pesan *“Sending form data ... violates ... form-action”*.

Tambahkan origin aplikasi ke `.env` IdP (dipisah koma bila lebih dari satu), lalu bersihkan cache:

```env
SSO_FORM_ACTION_ORIGINS=https://simdesa.ukpbj.demakkab.go.id,https://simukpbj.demakkab.go.id
```

```bash
php artisan config:clear   # atau config:cache bila memakai cache konfigurasi
```

Nilai ini dibaca `config/sso.php` → `form_action_origins` dan dipakai
`app/Http/Middleware/SecurityHeaders.php`. Hasilnya header menjadi:

```
Content-Security-Policy: ...; form-action 'self' https://simdesa.ukpbj.demakkab.go.id; ...
```

> Origin = **skema + host + port**, tanpa path. Contoh benar: `http://localhost:8001`.
> Contoh salah: `http://localhost:8001/auth/callback`.

### Langkah 8 — Uji Alur

| # | Uji | Hasil yang diharapkan |
|---|---|---|
| 1 | Buka route terproteksi aplikasi klien | `302` ke `/oauth/authorize` IdP (dengan `state` + `code_challenge`) |
| 2 | Login di IdP | Kembali ke callback klien → sesi klien aktif, user langsung masuk aplikasi |
| 3 | Buka aplikasi klien lagi saat sesi IdP masih hidup | Tidak diminta password lagi |
| 4 | Tunggu > 15 menit lalu gunakan aplikasi | Access token di-refresh otomatis (`grant_type=refresh_token`), user tidak terganggu |
| 5 | Logout lokal (opsional) | Sesi klien berakhir, diarahkan ke IdP, **sesi IdP tetap aktif** |
| 6 | Logout SSO / SLO | IdP mencabut semua token & sesi, mengirim back-channel ke aplikasi klien |
| 7 | Cek `sso_logout_audit` di IdP | `status = delivered`, `http_status = 200` untuk client tersebut |
| 8 | Setelah SLO, buka aplikasi klien lagi | Diminta login ulang (sesi klien benar-benar hancur) |
| 9 | Buka aplikasi di **browser** (bukan curl) | Form login IdP bisa disubmit (bukti Langkah 7 sudah benar) |

Query string SLO yang dikirim aplikasi klien ke IdP:

```
GET /sso/logout?client_id=<CLIENT_ID>&post_logout_redirect_uri=<URL terdaftar>&state=<opsional>
```

- `post_logout_redirect_uri` **harus** salah satu isi `redirect_uris` — jika tidak,
  IdP menjawab `400 Permintaan logout tidak valid.`
- `state` dikembalikan IdP dengan menempelkannya sebagai query `state=` pada URL tujuan.
- Endpoint ini tidak butuh autentikasi; bila sesi IdP sudah tidak ada, IdP hanya
  melakukan redirect tervalidasi.

### Kontrak Endpoint Back-Channel Logout

IdP mengirim logout token **server-to-server** (bukan lewat browser), jadi endpoint
ini wajib benar-benar berfungsi.

**Request dari IdP**

```http
POST /sso/backchannel-logout HTTP/1.1
Content-Type: application/x-www-form-urlencoded

logout_token=<JWT>
```

- Timeout: connect 3 dtk, total 5 dtk. **Redirect tidak diikuti** (`withoutRedirecting`).
- Tidak ada header otorisasi — keaslian dibuktikan oleh tanda tangan JWT.
- CSRF middleware **harus dikecualikan** untuk path ini.

**Klaim di dalam `logout_token` (RS256, `kid=passport-key-1` — default)**

| Klaim | Isi |
|---|---|
| `iss` | `config('sso.issuer')` IdP (mis. `https://sso.ukpbj.demakkab.go.id`) — bandingkan **exact-match** |
| `aud` | Array berisi `client_id` aplikasi ini |
| `iat`, `exp` | `exp` maksimal 120 detik dari `iat`; sediakan toleransi clock skew (mis. 60 dtk) |
| `jti` | Unik per pengiriman → wajib dipakai untuk **anti-replay** (cache ≥ 10 menit) |
| `events` | `{"http://schemas.openid.net/event/backchannel-logout": {}}` — wajib ada |
| `sub` | ID user IdP sebagai **string** → cocokkan dengan `users.sso_sub` |
| `sid` | `session_hash` sesi IdP↔client (opsional dipakai, berguna untuk logout per-perangkat) |

Urutan verifikasi di aplikasi klien: **tanda tangan (JWKS) → `iss` → `aud` → `events` →
`exp`/`iat` → `jti` (anti-replay) → hapus sesi user dengan `sub` tersebut.**

**Respons yang diharapkan IdP**

| Respons | Status audit | Perilaku IdP |
|---|---|---|
| `2xx` (mis. `200 {"status":"ok"}`) | `delivered` | Selesai |
| `400`/`401`/`403`/`404`/`422` | `failed_permanent` | Tidak di-retry — token Anda anggap ditolak |
| `408`, `425`, `429`, `5xx`, timeout, connection error | `failed` | Di-retry: 5 percobaan, backoff 5s → 30s → 2m → 10m → 30m (maks. 15 menit) |

> `3xx` tidak diikuti (redirect dianggap gagal), jadi **balas `2xx` langsung** dari endpoint.

> Endpoint harus **idempoten**: boleh menerima token yang sama berulang kali tanpa error.
> Jika sedang sibuk/tidak bisa memverifikasi (mis. JWKS belum tersedia), balas `503`
> agar IdP mencoba lagi — jangan balas `200` palsu, karena sesi di aplikasi klien
> tidak akan pernah hancur.

**JWKS & jebakan dev-server**

- Ambil kunci publik di `GET {SSO_ISSUER}/oauth/jwks`, cache ±1 jam.
- Simpan salinan terakhir yang sukses ke file dan pakai sebagai **stale fallback** bila
  fetch gagal. Tanpa ini, `php artisan serve` (single-worker, Windows) bisa **deadlock**:
  IdP sibuk mengirim back-channel → klien mem-fetch JWKS ke IdP → IdP tidak bisa melayani.
- Praktik yang dipakai SIM-UKPBJ: **prewarm JWKS saat callback login** (IdP sedang tidak
  sibuk) sehingga saat logout verifikasi tidak perlu HTTP ke IdP.

### Registrasi Tanpa Panel Admin (Seeder / Tinker)

Untuk seeding otomatis atau lingkungan CI, client bisa dibuat lewat kode:

```bash
php artisan tinker
```

```php
use Laravel\Passport\ClientRepository;

$client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
    name: 'SIMDESA Kabupaten Demak',
    redirectUris: [
        'https://simdesa.ukpbj.demakkab.go.id/auth/callback', // callback OAuth
        'https://simdesa.ukpbj.demakkab.go.id/',              // tujuan post-logout
    ],
    confidential: true,
);

$client->forceFill([
    'trusted' => true,                                                   // lewati consent
    'landing_url' => 'https://simdesa.ukpbj.demakkab.go.id/',
    'backchannel_logout_uri' => 'https://simdesa.ukpbj.demakkab.go.id/sso/backchannel-logout',
])->save();

echo "CLIENT_ID={$client->id}\n";
echo "CLIENT_SECRET={$client->plainSecret}\n";   // hanya tersedia saat pembuatan
```

Regenerate secret (secret lama langsung hangus):

```php
$client->forceFill(['secret' => \Illuminate\Support\Str::random(40)])->save();
```

Kolom penting pada tabel `oauth_clients`:

| Kolom | Nilai | Keterangan |
| --- | --- | --- |
| `name` | `Nama Aplikasi Klien` | Nama yang ditampilkan di halaman consent IdP |
| `redirect_uris` | `["https://app/auth/callback", "https://app/"]` | JSON array; wajib sesuai `SSO_REDIRECT_URI` & `SSO_POST_LOGOUT_URI` |
| `trusted` | `1` | Opsional, jika `1` halaman consent otomatis di-skip |
| `landing_url` | `https://app/` | Opsional, memunculkan kartu di dashboard user IdP |
| `backchannel_logout_uri` | `https://app/sso/backchannel-logout` | **Wajib** agar IdP mengirim logout token saat SLO |
| `revoked` | `0` | Pastikan client aktif |

> Secret disimpan sebagai **hash** (cast `hashed` Passport), bukan plaintext. Nilai
> aslinya hanya tersedia lewat `$client->plainSecret` tepat setelah pembuatan.

### Troubleshooting Registrasi & Integrasi

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| `400 invalid_client` saat tukar token | `client_secret` salah, sudah di-regenerate, atau client `revoked=1` | Salin ulang secret; pastikan client aktif |
| `400 invalid_grant` | Code sudah dipakai/kedaluwarsa, `redirect_uri` beda, `code_verifier` salah, atau refresh token lama dipakai ulang | Satu code satu kali; `redirect_uri` exact-match; simpan `code_verifier` di sesi; pakai refresh token terbaru |
| `400 Parameter code_challenge dan code_challenge_method wajib ada` | PKCE belum dikirim | Kirim `code_challenge` + `code_challenge_method=S256` |
| `400 Hanya code_challenge_method=S256 yang didukung` | Memakai `plain` | Ganti ke S256 (SHA-256 + base64url) |
| Login di browser diblokir: *violates ... form-action* | Origin klien belum diizinkan CSP IdP | Set `SSO_FORM_ACTION_ORIGINS`, lalu `php artisan config:clear` |
| `/sso/logout` → `400 Permintaan logout tidak valid.` | `post_logout_redirect_uri` tidak terdaftar, client revoked, atau `http` di produksi | Daftarkan URI itu di `redirect_uris`; pakai HTTPS |
| Back-channel tidak pernah diterima, `sso_logout_audit` kosong | `backchannel_logout_uri` kosong, client revoked, atau user belum pernah menyelesaikan authorize (tidak ada baris `sso_sessions`) | Isi URI back-channel; login ulang agar sesi ter-track |
| Back-channel dibalas `419` | CSRF middleware aktif | Tambahkan path ke `validateCsrfTokens(except: [...])` |
| Back-channel dibalas `302` | Endpoint melakukan redirect | Kembalikan `200` JSON langsung |
| Audit `failed_permanent` (400) | Token ditolak klien: `iss`/`aud`/`events` salah, JWKS beda, `jti` dianggap replay | Periksa `SSO_ISSUER` & `SSO_CLIENT_ID`; bersihkan cache JWKS; jangan menolak `jti` yang belum pernah dilihat |
| Audit `failed` lalu retry | Endpoint `5xx`/timeout/`429` | Perbaiki endpoint; IdP retry otomatis maks. 15 menit |
| Logout di IdP tapi user masih login di aplikasi | Back-channel gagal / tidak ada | Cek audit & log IdP; uji endpoint dengan token asli |
| `429 Too Many Requests` di `/oauth/token` | Batas 15×/menit per client+IP | Cache access token sampai mendekati 15 menit |
| Dev Windows macet/timeout saat logout | Deadlock JWKS pada `artisan serve` single-worker | Prewarm JWKS saat login + stale fallback, atau jalankan multi-worker/PHP-FPM |
| Alert **“This page has expired”** (HTTP 419) di aplikasi klien Filament | Polling Livewire berjalan saat sesi sudah dihapus (logout/back-channel) | Hook `Livewire.hook('request', ...)` → `preventDefault()` untuk 419; arahkan ke halaman login SSO |

### Contoh Nyata: SIM-UKPBJ (ARJUNA)

Integrasi nyata yang sudah berjalan: **SIM-UKPBJ / ARJUNA** (aplikasi Filament
terpisah) — memakai access token + `/oauth/userinfo`, PKCE S256, dan back-channel logout.

| Item | Nilai |
|---|---|
| IdP | `http://localhost:8000` (dev) |
| Aplikasi klien | `http://localhost:8001` (panel Filament `/admin`) |
| Redirect URI (callback) | `http://localhost:8001/sso/callback` |
| Post-logout URI | `http://localhost:8001/` |
| Back-Channel Logout URI | `http://localhost:8001/sso/backchannel-logout` |
| Trusted | `1` (tanpa halaman consent) |
| Cek SLO | `sso_logout_audit` → `delivered` / HTTP 200 |

Alur yang dipakai: root `/` tidak menampilkan halaman perantara — sudah login langsung
`/admin`, belum login langsung memulai SSO. Logout panel (`POST /admin/logout`)
diteruskan ke `/sso/logout` IdP (RP-initiated SLO) sehingga sesi IdP + aplikasi lain ikut
berakhir.

### Aplikasi di Dashboard User

Client yang memiliki **Landing URL** akan muncul sebagai kartu di bagian
**"Aplikasi Terhubung"** pada dashboard user IdP (`/dashboard`). Saat user
mengklik kartu:

1. Aplikasi klien dibuka di **tab baru** (`target="_blank" rel="noopener"`)
2. Middleware aplikasi klien tidak menemukan sesi lokal → redirect ke IdP
3. User sudah login di IdP → langsung kembali ke aplikasi tanpa isi ulang password

> Client tanpa Landing URL tidak muncul di dashboard — isi melalui menu
> **Edit** pada daftar aplikasi klien.
>
> Jika halaman awal aplikasi bersifat **publik** (root tidak memaksa login),
> jangan isi Landing URL dengan `/`. Arahkan ke endpoint auto-login aplikasi
> (contoh: `http://rup-app.test/sso/auto`) supaya klik kartu langsung membuat
> sesi login, bukan sekadar membuka halaman publik dalam keadaan belum login.

### Mengelola Klien yang Sudah Ada

Dari daftar **Kelola Aplikasi Klien** (`/admin/clients`):

| Aksi | Efek |
|---|---|
| **Edit** | Ubah nama, redirect URIs, landing URL, back-channel/front-channel URI, trusted. Perubahan langsung berlaku |
| **Regenerate Secret** | Secret baru dibuat & ditampilkan **sekali**; **secret lama langsung hangus** — aplikasi klien wajib update `.env`-nya |
| **Revoke** | Client dinonaktifkan (`revoked=1`), **seluruh access & refresh token-nya dicabut**, dan back-channel tidak lagi dikirim. Redirect/token dari client ini akan ditolak |

Catatan saat mengubah client yang sudah berjalan:

- Menambah/mengubah `redirect_uris` **tidak** memutus sesi yang sedang aktif, tetapi
  `post_logout_redirect_uri` lama langsung tidak valid.
- Mengubah `backchannel_logout_uri` aman: job yang masih di queue akan **skip** bila
  URI-nya sudah berbeda (mencegah token dikirim ke endpoint lama).
- Client yang sudah di-revoke tidak bisa "diaktifkan" lewat Edit — daftarkan client baru
  lalu perbarui `.env` aplikasi klien.

---

## 🔌 Integrasi Aplikasi Klien Laravel 12 (Tanpa Socialite)

Aplikasi klien **tidak perlu** menggunakan Laravel Socialite. Integrasi dibangun sendiri dengan `Illuminate\Support\Facades\Http` untuk menangani **Authorization Code + PKCE S256**, pertukaran token, validasi, refresh token, serta **Single Logout via Back-Channel**.

> **Dependency yang wajib dipasang:** `firebase/php-jwt` + `guzzlehttp/guzzle`
> (dan `SESSION_DRIVER=database`). Daftar lengkap & perintah install ada di
> [Langkah 0 — Dependency Aplikasi Klien](#langkah-0--dependency-aplikasi-klien).

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
Registrasi lewat **Seeder/Tinker** beserta tabel kolom `oauth_clients` ada di
[Registrasi Tanpa Panel Admin](#registrasi-tanpa-panel-admin-seeder--tinker).

Dua hal yang mudah terlewat setelah client terdaftar:

1. **CSP IdP** — tambahkan origin aplikasi klien ke `SSO_FORM_ACTION_ORIGINS`
   (lihat [Langkah 7](#langkah-7--izinkan-origin-aplikasi-pada-csp-idp)).
   Tanpa ini login berhasil di curl tetapi **diblokir di browser**.
2. **Kontrak back-channel** — endpoint harus membalas `2xx`, bebas CSRF, dan
   memverifikasi `iss`/`aud`/`events`/`exp`/`jti` (lihat
   [Kontrak Endpoint Back-Channel Logout](#kontrak-endpoint-back-channel-logout)).

### Quick Checklist Integrasi

- [ ] **Dependency**: `composer require firebase/php-jwt:^6.10 guzzlehttp/guzzle:^7.8` (wajib); `laravel/passport` **tidak** dipakai di klien
- [ ] **Ekstensi PHP**: `openssl`, `curl`, `mbstring`, `json`, `fileinfo`, `pdo` (+ driver DB)
- [ ] **File wajib**: `config/sso.php`, `app/Services/SsoClient.php`, `app/Services/LogoutTokenVerifier.php`, `app/Http/Middleware/SsoAuthenticate.php`, `app/Http/Controllers/Auth/AuthController.php`, `app/Http/Controllers/Auth/BackChannelLogoutController.php`
- [ ] **Bootstrap**: Register middleware alias `sso` dan tambahkan `sso/backchannel-logout` ke `validateCsrfTokens(except: [])`
- [ ] **Database**: Tambah kolom `sso_sub`, `nip` ke tabel `users` & ubah `password` jadi nullable. Gunakan `SESSION_DRIVER=database`
- [ ] **IdP**: Daftarkan client dengan `redirect_uris` dan `backchannel_logout_uri` yang benar
- [ ] **IdP**: Tambahkan origin aplikasi ke `SSO_FORM_ACTION_ORIGINS` lalu `php artisan config:clear`
- [ ] **JWKS**: Cache `/oauth/jwks` + stale fallback (dan prewarm saat login) agar SLO tidak deadlock di dev server
- [ ] **Uji alur**: Akses route terproteksi → redirect ke IdP → login → callback kembali ke aplikasi klien
- [ ] **Uji logout lokal**: tombol "Keluar (Aplikasi Ini)" → sesi klien berakhir, redirect ke dashboard IdP, sesi IdP tetap aktif
- [ ] **Uji SLO**: tombol "Logout Semua (SSO)" → IdP melakukan global logout & mengirim back-channel ke klien → seluruh sesi klien terhancurkan otomatis
- [ ] **Uji audit**: `sso_logout_audit.status = delivered` & `http_status = 200` untuk client tersebut
