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
> dari repo ini. Panduan integrasi aplikasi klien tetap tersedia di bawah.
> Untuk contoh nyata yang sudah berjalan di lokal (port 8001), lihat
> [Contoh Nyata: SIM-UKPBJ](#contoh-nyata-sim-ukpbj-arjuna).

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

> Nama variabel mengikuti kode aplikasi klien. SIM-UKPBJ memakai
> `SSO_LOGOUT_URI` untuk tujuan `post_logout_redirect_uri`; pastikan nilainya
> terdaftar di `redirect_uris` client.

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
| 5 | Logout lokal (opsional) | Sesi klien berakhir. Jangan panggil `/sso/logout` bila ingin **sesi IdP tetap aktif**; endpoint itu melakukan SLO |
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
| `redirect_uris` | `["https://app/auth/callback", "https://app/"]` | JSON array; wajib sesuai `SSO_REDIRECT_URI` & URI post-logout (`SSO_POST_LOGOUT_URI`, atau `SSO_LOGOUT_URI` di SIM-UKPBJ) |
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

#### Nilai yang dipakai

| Item | Nilai |
|---|---|
| IdP lokal | `http://localhost:8000` |
| Aplikasi klien | `http://localhost:8001` (panel Filament `/admin`) |
| Client ID | `01a185a8-1769-71b8-ad00-1bda2f83100d` |
| Redirect URI (callback) | `http://localhost:8001/sso/callback` |
| Post-logout URI | `http://localhost:8001/` |
| Back-Channel Logout URI | `http://localhost:8001/sso/backchannel-logout` |
| Landing URL (kartu dashboard) | `http://localhost:8001/sso/login` |
| Trusted | `1` (tanpa halaman consent) |
| Cek SLO | `sso_logout_audit` → `delivered` / HTTP 200 |

#### Konfigurasi client di IdP

Daftarkan lewat `/admin/clients/create` (atau edit client yang sudah ada):

| Field | Nilai |
|---|---|
| Nama Aplikasi | `SIM-UKPBJ` |
| Redirect URIs | `http://localhost:8001/sso/callback` dan `http://localhost:8001/` |
| Landing URL | `http://localhost:8001/sso/login` |
| Back-Channel Logout URI | `http://localhost:8001/sso/backchannel-logout` |
| Trusted | dicentang |

> `http://localhost:8001/` **wajib** ada di `redirect_uris` karena dipakai sebagai
> `post_logout_redirect_uri`. Tanpa itu, `/sso/logout` akan menjawab
> `400 Permintaan logout tidak valid.`

#### `.env` aplikasi klien

```env
APP_URL=http://localhost:8001

SSO_URL=http://localhost:8000
SSO_ISSUER=http://localhost:8000
SSO_CLIENT_ID=01a185a8-1769-71b8-ad00-1bda2f83100d
SSO_CLIENT_SECRET=<secret dari panel IdP>
SSO_REDIRECT_URI=http://localhost:8001/sso/callback
SSO_LOGOUT_URI=http://localhost:8001/
SSO_ENABLED=true
```

> SIM-UKPBJ memakai nama variabel **`SSO_LOGOUT_URI`** (dibaca `config/sso.php`
> aplikasi klien). Contoh generik di README ini memakai `SSO_POST_LOGOUT_URI`;
> sesuaikan dengan nama variabel yang benar-benar dibaca kode klien.

Tambahkan juga origin klien ke `.env` IdP agar CSP `form-action` tidak memblokir
login di browser:

```env
# sesuaikan dengan origin client lain yang juga aktif
SSO_FORM_ACTION_ORIGINS=http://localhost:8001,http://rup-app.test
```

Lalu bersihkan cache konfigurasi IdP:

```bash
php artisan config:clear
```

#### Menjalankan lokal

```powershell
# Terminal 1 — IdP
cd D:\laragon\www\sso
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2 — SIM-UKPBJ
cd D:\laragon\www\sim-ukpbj
php artisan serve --host=127.0.0.1 --port=8001
```

Login IdP: `admin@sso.test` / `password123`.

#### Alur yang sudah diverifikasi

| # | Uji | Hasil |
|---|---|---|
| 1 | `GET http://localhost:8001/` | `200`, landing page tampil |
| 2 | `GET http://localhost:8001/admin` tanpa login | `302` → `/admin/login` |
| 3 | `GET http://localhost:8001/sso/login` | `302` → IdP `/oauth/authorize` |
| 4 | Login IdP + callback | `200` → `/admin` |
| 5 | `GET http://localhost:8001/sso/logout` | `302` → IdP `/sso/logout` → `http://localhost:8001/` |
| 6 | Logout dari dashboard IdP | Back-channel menghapus sesi SIM; `/admin` → login |

#### Catatan perilaku logout

- **`GET /sso/logout`** adalah RP-initiated SLO: sesi SIM dihapus, lalu browser
  diarahkan ke IdP `/sso/logout`. IdP menghapus sesi IdP dan mengirim back-channel
  ke semua client, jadi aplikasi SSO lain juga ikut logout.
- **`POST /admin/logout`** (tombol logout panel Filament) hanya menghapus sesi
  lokal Filament; tidak otomatis memanggil `/sso/logout`. Bila ingin tombol panel
  memicu SLO, tambahkan redirect khusus setelah logout ke `route('sso.logout')`.
- Untuk logout lokal tanpa menyentuh IdP, jangan arahkan browser ke `/sso/logout`;
  cukup `Auth::logout()` + `session()->invalidate()` lalu redirect ke halaman lokal.

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

Aplikasi klien **tidak perlu** menggunakan Laravel Socialite. Integrasi dibangun sendiri — SIM-UKPBJ memakai **Guzzle** (`guzzlehttp/guzzle`), aplikasi lain bisa memakai `Illuminate\Support\Facades\Http` — untuk menangani **Authorization Code + PKCE S256**, pertukaran token, validasi, refresh token, serta **Single Logout via Back-Channel**.

> **Dependency yang wajib dipasang:** `firebase/php-jwt` + `guzzlehttp/guzzle`
> (dan `SESSION_DRIVER=database`). Daftar lengkap & perintah install ada di
> [Langkah 0 — Dependency Aplikasi Klien](#langkah-0--dependency-aplikasi-klien).

### 1. Tambahkan Variabel `.env` Klien

Contoh berikut mengikuti implementasi nyata **SIM-UKPBJ**. Nama variabel di aplikasi
klien lain bisa berbeda — sesuaikan dengan yang benar-benar dibaca `config/sso.php`.

```env
APP_URL=http://localhost:8001

SSO_URL=http://localhost:8000
SSO_ISSUER=http://localhost:8000
SSO_CLIENT_ID=01a185a8-1769-71b8-ad00-1bda2f83100d
SSO_CLIENT_SECRET=<secret dari panel IdP>
SSO_REDIRECT_URI=http://localhost:8001/sso/callback
SSO_LOGOUT_URI=http://localhost:8001/
SSO_ENABLED=true

# Opsional
# SSO_HTTP_TIMEOUT=15
# SSO_CONNECT_TIMEOUT=5
# SSO_VERIFY_SSL=true
# SSO_JWKS_URI=http://localhost:8000/oauth/jwks
# SSO_LOGOUT_TOKEN_LEEWAY=60
# SSO_JTI_CACHE_STORE=database
```

> **Perbedaan nama variabel:** SIM-UKPBJ memakai **`SSO_LOGOUT_URI`** untuk tujuan
> `post_logout_redirect_uri`. Sebagian contoh/integrasi lain memakai
> **`SSO_POST_LOGOUT_URI`**. Yang penting: nilai URI harus **sama persis** dengan
> salah satu entri `redirect_uris` client di IdP.
>
> Untuk produksi, ganti URL ke HTTPS, gunakan secret berbeda per aplikasi, dan
> **jangan pernah** commit `SSO_CLIENT_SECRET` ke repository.

### 2. Buat File Konfigurasi `config/sso.php`

File ini memusatkan endpoint IdP, kredensial OAuth, dan pengaturan logout token.
Semua endpoint diturunkan dari `url`/`issuer` agar tidak ada URL yang tercecer.

```php
<?php

return [
    // Base URL IdP. Endpoint /oauth/* dan /sso/logout diturunkan dari sini.
    'url' => env('SSO_URL', 'https://sso.ukpbj.demakkab.go.id'),

    // Harus sama persis dengan APP_URL IdP. Dipakai memvalidasi klaim `iss`
    // pada logout token back-channel.
    'issuer' => rtrim((string) env('SSO_ISSUER', env('SSO_URL', 'https://sso.ukpbj.demakkab.go.id')), '/'),

    // HTTP client
    'http_timeout' => (int) env('SSO_HTTP_TIMEOUT', 15),
    'connect_timeout' => (int) env('SSO_CONNECT_TIMEOUT', 5),
    'verify_ssl' => (bool) env('SSO_VERIFY_SSL', true),

    // Kredensial OAuth client (Service Provider)
    'client_id' => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri' => env('SSO_REDIRECT_URI', 'http://localhost:8001/sso/callback'),

    // Tujuan setelah RP-initiated logout. WAJIB terdaftar di redirect_uris IdP.
    'logout_uri' => env('SSO_LOGOUT_URI', 'http://localhost:8001/'),

    'enabled' => env('SSO_ENABLED', true),
    'prefix' => 'sso',
    'middleware' => ['web'],
    'guard' => 'web',

    // OIDC Back-Channel Logout
    'logout_token' => [
        'leeway' => (int) env('SSO_LOGOUT_TOKEN_LEEWAY', 60),

        // null = gunakan {issuer}/oauth/jwks
        'jwks_uri' => env('SSO_JWKS_URI'),

        // null = gunakan cache store default aplikasi
        'jti_store' => env('SSO_JTI_CACHE_STORE'),
    ],

    'session' => [
        'key_prefix' => 'sso_',
        'lifetime' => 120,
        'expire_on_close' => true,
    ],
];
```

> File `config/sso.php` SIM-UKPBJ juga memuat `user_mapping`, `required_claims`,
> dan `token_validation`. Ketiganya dokumentasi/opsi tambahan; kode SSO utama
> memakai `url`, `issuer`, kredensial, `redirect_uri`, `logout_uri`, dan
> `logout_token` di atas.

### 3. Buat Service `app/Services/SsoClientService.php`

Service ini menangani PKCE S256, tukar `authorization code`, refresh token,
pengambilan profil dari `/oauth/userinfo`, validasi sesi, dan pembuatan URL
RP-initiated logout. Implementasi SIM-UKPBJ memakai **Guzzle** dan menyimpan
token di session.

Method utama:

| Method | Kegunaan |
|---|---|
| `getAuthorizationUrl()` | Buat URL `/oauth/authorize` + `state` + PKCE S256 |
| `exchangeCodeForTokens($code, $state)` | Validasi `state`, tukar code, simpan token |
| `getUserInfo()` | Ambil profil; refresh otomatis sekali bila access token `401` |
| `refreshToken()` | Refresh token rotation, simpan token baru |
| `validateSession()` | Cek sesi masih valid lewat UserInfo |
| `logoutUrl()` | URL `/sso/logout` + `post_logout_redirect_uri` + `state` |

```php
<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use RuntimeException;
use Throwable;

class SsoClientService
{
    protected Client $client;

    protected string $ssoUrl;

    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    protected string $logoutUri;

    public function __construct()
    {
        $this->ssoUrl = rtrim((string) config('sso.url'), '/');
        $this->clientId = (string) config('sso.client_id');
        $this->clientSecret = (string) config('sso.client_secret');
        $this->redirectUri = (string) config('sso.redirect_uri');
        $this->logoutUri = (string) config('sso.logout_uri');

        $this->client = new Client([
            'timeout' => (int) config('sso.http_timeout', 15),
            'connect_timeout' => (int) config('sso.connect_timeout', 5),
            'verify' => (bool) config('sso.verify_ssl', true),
            'http_errors' => false,
        ]);
    }

    /**
     * Bangun URL authorization OAuth 2.0 + PKCE S256.
     */
    public function getAuthorizationUrl(): string
    {
        $this->ensureConfigured();

        $state = bin2hex(random_bytes(16));
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        Session::put('sso_state', $state);
        Session::put('sso_code_verifier', $codeVerifier);
        Session::put('sso_authorization_started_at', time());

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $this->ssoUrl.'/oauth/authorize?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Tukar authorization code dengan access token.
     */
    public function exchangeCodeForTokens(string $code, string $state): array
    {
        $this->ensureConfigured();

        $sessionState = Session::pull('sso_state');
        $codeVerifier = Session::pull('sso_code_verifier');

        if (! is_string($sessionState) || $sessionState === '' || ! hash_equals($sessionState, $state)) {
            throw new RuntimeException('State OAuth tidak valid atau sudah kedaluwarsa.');
        }

        if (! is_string($codeVerifier) || $codeVerifier === '') {
            throw new RuntimeException('PKCE code verifier tidak ditemukan di sesi.');
        }

        $tokens = $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ]);

        $this->storeTokens($tokens);

        return $tokens;
    }

    /**
     * Refresh access token memakai refresh token yang tersimpan di sesi.
     */
    public function refreshToken(): array
    {
        $this->ensureConfigured();

        $refreshToken = Session::get('sso_refresh_token');

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new RuntimeException('Refresh token SSO tidak tersedia.');
        }

        $tokens = $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        $this->storeTokens($tokens, true);

        return $tokens;
    }

    /**
     * Ambil profil user dari endpoint OIDC UserInfo.
     */
    public function getUserInfo(): array
    {
        $accessToken = $this->getValidAccessToken();

        try {
            return $this->requestUserInfo($accessToken);
        } catch (RuntimeException $e) {
            // Access token bisa saja dicabut lebih awal (mis. logout global).
            // Coba sekali dengan refresh token, lalu ulangi.
            if ($e->getCode() !== 401) {
                throw $e;
            }

            $this->refreshToken();

            return $this->requestUserInfo($this->getValidAccessToken());
        }
    }

    /**
     * URL logout RP-initiated ke IdP.
     */
    public function logoutUrl(): string
    {
        $this->ensureConfigured();

        $params = [
            'client_id' => $this->clientId,
            'post_logout_redirect_uri' => $this->logoutUri,
            'state' => bin2hex(random_bytes(16)),
        ];

        return $this->ssoUrl.'/sso/logout?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Cek apakah sesi SSO masih valid (access token dapat dipakai ke UserInfo).
     */
    public function validateSession(): bool
    {
        try {
            $this->getUserInfo();

            return true;
        } catch (Throwable $e) {
            Log::warning('[SSO] Validasi sesi gagal', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getConfig(): array
    {
        return [
            'url' => $this->ssoUrl,
            'issuer' => rtrim((string) config('sso.issuer', $this->ssoUrl), '/'),
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'logout_uri' => $this->logoutUri,
        ];
    }

    protected function requestToken(array $params): array
    {
        try {
            $response = $this->client->post($this->ssoUrl.'/oauth/token', [
                'form_params' => $params,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Tidak dapat menghubungi server SSO.', 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($status >= 400 || ! is_array($data)) {
            $message = is_array($data)
                ? (string) ($data['error_description'] ?? $data['error'] ?? 'Respons token tidak valid.')
                : 'Respons token tidak valid.';

            throw new RuntimeException('SSO menolak permintaan token: '.$message);
        }

        if (empty($data['access_token']) || ! is_string($data['access_token'])) {
            throw new RuntimeException('Respons token SSO tidak mengandung access_token.');
        }

        return $data;
    }

    protected function requestUserInfo(string $accessToken): array
    {
        try {
            $response = $this->client->get($this->ssoUrl.'/oauth/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Tidak dapat mengambil profil dari server SSO.', 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($status === 401) {
            throw new RuntimeException('Access token SSO tidak valid atau sudah dicabut.', 401);
        }

        if ($status >= 400 || ! is_array($data)) {
            throw new RuntimeException('Server SSO gagal mengembalikan profil user (HTTP '.$status.').');
        }

        if (empty($data['sub']) || ! is_scalar($data['sub'])) {
            throw new RuntimeException('Profil SSO tidak mengandung klaim sub.');
        }

        return $data;
    }

    protected function storeTokens(array $tokens, bool $preserveRefreshToken = false): void
    {
        Session::put('sso_access_token', (string) $tokens['access_token']);

        if (! empty($tokens['refresh_token']) && is_string($tokens['refresh_token'])) {
            Session::put('sso_refresh_token', $tokens['refresh_token']);
        } elseif (! $preserveRefreshToken) {
            Session::forget('sso_refresh_token');
        }

        $expiresIn = max(30, (int) ($tokens['expires_in'] ?? 900));
        Session::put('sso_expires_at', time() + $expiresIn);
        Session::put('sso_token_type', (string) ($tokens['token_type'] ?? 'Bearer'));
        Session::put('sso_last_validated_at', time());
    }

    protected function getValidAccessToken(): string
    {
        $accessToken = Session::get('sso_access_token');
        $expiresAt = (int) Session::get('sso_expires_at', 0);

        if (is_string($accessToken) && $accessToken !== '' && $expiresAt > (time() + 30)) {
            return $accessToken;
        }

        if (is_string(Session::get('sso_refresh_token')) && Session::get('sso_refresh_token') !== '') {
            $this->refreshToken();

            $accessToken = Session::get('sso_access_token');

            if (is_string($accessToken) && $accessToken !== '') {
                return $accessToken;
            }
        }

        throw new RuntimeException('Sesi SSO tidak tersedia. Silakan login ulang.');
    }

    protected function ensureConfigured(): void
    {
        if ($this->ssoUrl === ''
            || $this->clientId === ''
            || $this->clientSecret === ''
            || $this->redirectUri === ''
            || $this->logoutUri === '') {
            throw new RuntimeException('Konfigurasi SSO belum lengkap. Periksa variabel SSO_* di .env.');
        }
    }
}
```

> Nama kelas di SIM-UKPBJ adalah **`SsoClientService`** (bukan `SsoClient`).
> Sesuaikan `use App\Services\SsoClientService;` di controller dan middleware.

### 4. Middleware `app/Http/Middleware/SsoMiddleware.php`

Middleware ini melindungi route aplikasi klien. Bila user sudah login lokal,
middleware memvalidasi sesi SSO ke IdP paling sering **sekali per 60 detik**
(`sso_last_validated_at`) agar tidak ada request UserInfo di setiap halaman.
Bila sesi SSO tidak valid, sesi lokal dihapus dan user diarahkan ke login SSO.

```php
<?php

namespace App\Http\Middleware;

use App\Services\SsoClientService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SsoMiddleware
{
    public function __construct(private readonly SsoClientService $ssoClient) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkipSso($request)) {
            return $next($request);
        }

        if (Auth::check()) {
            $lastValidatedAt = (int) $request->session()->get('sso_last_validated_at', 0);

            // Hindari request UserInfo ke IdP pada setiap request halaman.
            if ($lastValidatedAt > (time() - 60)) {
                return $next($request);
            }

            if ($this->ssoClient->validateSession()) {
                $request->session()->put('sso_last_validated_at', time());

                return $next($request);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if (! $request->expectsJson()) {
            // Simpan URL tujuan agar setelah login SSO user kembali ke
            // halaman yang tadi diminta (mis. /admin/pengajuan-pls).
            return redirect()->guest(route('sso.login'));
        }

        return response()->json(['error' => 'Authentication required'], 401);
    }

    protected function shouldSkipSso(Request $request): bool
    {
        if ($request->routeIs([
            'sso.login',
            'sso.callback',
            'sso.logout',
            'sso.backchannel-logout',
            'sso.session-status',
        ])) {
            return true;
        }

        if ($request->is('api/*')) {
            return true;
        }

        $publicRoutes = [
            '/',
            'login',
            'register',
            'password/reset',
            'password/email',
            'password/reset/*',
        ];

        $path = $request->path();

        foreach ($publicRoutes as $route) {
            if (fnmatch($route, $path)) {
                return true;
            }
        }

        return false;
    }
}
```

> Kelas ini bernama **`SsoMiddleware`** di SIM-UKPBJ (bukan `SsoAuthenticate`).
> Alias `sso` didaftarkan di `bootstrap/app.php`.

### 5. Controller SSO & Back-Channel Logout

#### `app/Http/Controllers/SsoController.php`

Di SIM-UKPBJ, login, callback, logout, back-channel logout, dan cek status sesi
berada dalam satu controller. Route name `filament.admin.auth.login` dan
`filament.admin.pages.dashboard` khusus Filament — ganti dengan route aplikasi
klien kamu.

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\JwksUnavailableException;
use App\Models\User;
use App\Services\LogoutTokenVerifier;
use App\Services\SsoClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SsoController extends Controller
{
    public function __construct(
        private readonly SsoClientService $ssoClient,
        private readonly LogoutTokenVerifier $logoutTokenVerifier,
    ) {}

    /**
     * Mulai login SSO.
     */
    public function login(): RedirectResponse
    {
        try {
            return redirect()->away($this->ssoClient->getAuthorizationUrl());
        } catch (Throwable $e) {
            Log::error('[SSO] Gagal membuat URL authorization', ['error' => $e->getMessage()]);

            return redirect()
                ->route('filament.admin.auth.login')
                ->with('error', 'Konfigurasi SSO belum siap. Hubungi administrator.');
        }
    }

    /**
     * Callback OAuth: tukar code, ambil profil, lalu login lokal.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            if ($request->filled('error')) {
                throw new RuntimeException('IdP mengembalikan error: '.$request->input('error'));
            }

            $code = (string) $request->input('code', '');
            $state = (string) $request->input('state', '');

            if ($code === '' || $state === '') {
                throw new RuntimeException('Parameter code/state tidak lengkap.');
            }

            $this->ssoClient->exchangeCodeForTokens($code, $state);
            $userInfo = $this->ssoClient->getUserInfo();
            $user = $this->createOrUpdateUser($userInfo);

            Auth::login($user);
            $request->session()->regenerate();

            // Ambil JWKS sekarang (IdP sedang tidak sibuk) agar back-channel
            // logout nanti tidak perlu HTTP ke IdP di tengah proses logout.
            $this->logoutTokenVerifier->warm();

            // Setelah SSO berhasil, user langsung masuk ke aplikasi asli
            // (panel Filament /admin), bukan halaman perantara.
            return redirect()->intended(route('filament.admin.pages.dashboard'));
        } catch (Throwable $e) {
            Log::error('[SSO] Callback gagal', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return redirect()
                ->route('filament.admin.auth.login')
                ->with('error', 'Login SSO gagal. Silakan coba lagi atau hubungi administrator.');
        }
    }

    /**
     * Logout lokal lalu lanjutkan ke Single Logout IdP.
     */
    public function logout(): RedirectResponse
    {
        $redirectUrl = route('home');

        try {
            $redirectUrl = $this->ssoClient->logoutUrl();
        } catch (Throwable $e) {
            Log::warning('[SSO] Gagal membuat URL logout IdP', ['error' => $e->getMessage()]);
        }

        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->away($redirectUrl);
    }

    /**
     * Terima OIDC Back-Channel Logout dari IdP.
     */
    public function backchannelLogout(Request $request, LogoutTokenVerifier $verifier): JsonResponse
    {
        $logoutToken = (string) $request->input('logout_token', '');

        if ($logoutToken === '') {
            return response()->json(['error' => 'invalid_request'], 400);
        }

        try {
            $claims = $verifier->verify($logoutToken);
        } catch (JwksUnavailableException $e) {
            // Infrastruktur (JWKS tidak bisa diambil) — biarkan IdP retry.
            Log::error('[SSO] Back-channel logout tertunda: JWKS tidak tersedia', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'temporarily_unavailable'], 503);
        } catch (Throwable $e) {
            Log::warning('[SSO] Back-channel logout ditolak', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_request'], 400);
        }

        $subject = isset($claims['sub']) ? (string) $claims['sub'] : '';

        if ($subject !== '') {
            $user = User::where('sso_sub', $subject)->first();

            if ($user) {
                $this->invalidateLocalSessions($user);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Cek status sesi SSO (dipakai opsional oleh frontend).
     */
    public function sessionStatus(): JsonResponse
    {
        try {
            $isValid = $this->ssoClient->validateSession();

            return response()->json([
                'valid' => $isValid,
                'user' => $isValid ? Auth::user() : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('[SSO] Cek status sesi gagal', ['error' => $e->getMessage()]);

            return response()->json(['valid' => false]);
        }
    }

    /**
     * Buat atau sinkronkan user lokal dari klaim UserInfo.
     */
    protected function createOrUpdateUser(array $userInfo): User
    {
        $subject = trim((string) ($userInfo['sub'] ?? ''));

        if ($subject === '') {
            throw new RuntimeException('Profil SSO tidak memiliki subjek (sub).');
        }

        $email = strtolower(trim((string) ($userInfo['email'] ?? '')));
        $nip = preg_replace('/[\s.\-]+/u', '', (string) ($userInfo['nip'] ?? '')) ?: '';
        $name = trim((string) ($userInfo['name'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Alamat email dari SSO tidak valid.');
        }

        if ($email === '' && $nip === '') {
            throw new RuntimeException('Profil SSO harus memiliki email atau NIP.');
        }

        $user = User::where('sso_sub', $subject)->first();

        if (! $user && $email !== '') {
            $user = User::where('email', $email)->first();
        }

        if (! $user && $nip !== '') {
            $user = User::where('nip', $nip)->first();
        }

        $isNew = ! $user;

        if ($isNew) {
            if ($email === '') {
                throw new RuntimeException('User baru dari SSO wajib memiliki email.');
            }

            $user = new User;
            $user->email = $email;
            $user->password = Str::random(40);
            $user->email_verified_at = now();
            $user->is_active = true;
        }

        $user->sso_sub = $subject;

        if ($name !== '') {
            $user->name = $name;
        } elseif ($isNew) {
            $user->name = 'Pengguna SSO';
        }

        if ($email !== '') {
            $user->email = $email;
        }

        if ($nip !== '') {
            $user->nip = $nip;
        }

        $user->save();

        return $user;
    }

    /**
     * Hapus semua sesi browser lokal milik user (session driver database).
     */
    protected function invalidateLocalSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        } catch (Throwable $e) {
            Log::error('[SSO] Gagal menghapus sesi lokal user', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

#### `app/Services/LogoutTokenVerifier.php`

Verifier SIM-UKPBJ memeriksa **algoritma RS256**, `kid`, signature via JWKS,
`iss`, `aud`, `iat`/`exp`, umur token maksimal 120 detik, klaim
`events` backchannel-logout, larangan `nonce`, `jti` anti-replay, dan `sub`/`sid`.
JWKS di-cache 1 jam dan **dipanaskan saat login** (`warm()`) untuk menghindari
deadlock pada dev server single-thread.

Verifier memakai `App\Exceptions\JwksUnavailableException` (turunan
`RuntimeException`) agar kegagalan mengambil JWKS dibedakan dari token yang
memang invalid. Dengan begitu endpoint back-channel bisa membalas `503`
(IdP retry) alih-alih `400` (gagal permanen).

```php
<?php

namespace App\Services;

use App\Exceptions\JwksUnavailableException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LogoutTokenVerifier
{
    public const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public const MAX_TTL_SECONDS = 120;

    /**
     * @return array<string, mixed>
     */
    public function verify(string $jwt): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new RuntimeException('Logout token bukan JWT yang valid.');
        }

        $header = json_decode($this->base64UrlDecode($segments[0]), true);

        if (! is_array($header)) {
            throw new RuntimeException('Header logout token tidak valid.');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('Algoritma logout token wajib RS256.');
        }

        if (empty($header['kid']) || ! is_string($header['kid'])) {
            throw new RuntimeException('Header logout token wajib memiliki kid.');
        }

        $leeway = max(0, (int) config('sso.logout_token.leeway', 60));
        JWT::$leeway = $leeway;

        $claims = $this->decode($jwt);

        $issuer = rtrim((string) config('sso.issuer'), '/');
        $claimIssuer = rtrim((string) ($claims['iss'] ?? ''), '/');

        if ($issuer === '' || ! hash_equals($issuer, $claimIssuer)) {
            throw new RuntimeException('Issuer logout token tidak cocok.');
        }

        $audiences = $claims['aud'] ?? [];
        $audiences = is_array($audiences) ? $audiences : [$audiences];

        if (! in_array((string) config('sso.client_id'), array_map('strval', $audiences), true)) {
            throw new RuntimeException('Audience logout token tidak cocok.');
        }

        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        $now = time();

        if ($iat <= 0 || $exp <= 0) {
            throw new RuntimeException('Logout token wajib memiliki iat dan exp.');
        }

        if ($exp <= ($now - $leeway)) {
            throw new RuntimeException('Logout token sudah kedaluwarsa.');
        }

        if ($iat > ($now + $leeway)) {
            throw new RuntimeException('iat logout token berada di masa depan.');
        }

        if (($exp - $iat) > self::MAX_TTL_SECONDS) {
            throw new RuntimeException('Umur logout token melebihi batas 120 detik.');
        }

        $events = (array) ($claims['events'] ?? []);

        if (! array_key_exists(self::EVENT, $events)) {
            throw new RuntimeException('Klaim events backchannel-logout tidak ada.');
        }

        if (array_key_exists('nonce', $claims)) {
            throw new RuntimeException('Logout token tidak boleh mengandung nonce.');
        }

        $jti = (string) ($claims['jti'] ?? '');

        if ($jti === '') {
            throw new RuntimeException('Logout token wajib memiliki jti.');
        }

        $sub = isset($claims['sub']) ? (string) $claims['sub'] : null;
        $sid = isset($claims['sid']) ? (string) $claims['sid'] : null;

        if ($sub === null && $sid === null) {
            throw new RuntimeException('Logout token wajib memiliki sub atau sid.');
        }

        $this->consumeJti($jti, max(1, $exp - $now + $leeway));

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(string $jwt): array
    {
        try {
            return (array) JWT::decode($jwt, JWK::parseKeySet($this->jwks(), 'RS256'));
        } catch (JwksUnavailableException $e) {
            // Masalah infrastruktur: jangan dianggap token invalid.
            throw $e;
        } catch (Throwable $e) {
            // Kemungkinan IdP merotasi key. Ambil JWKS terbaru sekali.
            try {
                return (array) JWT::decode($jwt, JWK::parseKeySet($this->jwks(true), 'RS256'));
            } catch (JwksUnavailableException $retry) {
                throw $retry;
            } catch (Throwable $retry) {
                throw new RuntimeException('Signature logout token tidak valid.', 0, $retry);
            }
        }
    }

    /**
     * @return array{keys: array<int, array<string, mixed>>}
     */
    protected function jwks(bool $force = false): array
    {
        $uri = (string) config('sso.logout_token.jwks_uri');

        if ($uri === '') {
            $uri = rtrim((string) config('sso.issuer'), '/').'/oauth/jwks';
        }

        $cacheKey = 'sso:jwks:'.sha1($uri);

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () use ($uri) {
            try {
                $response = Http::timeout((int) config('sso.http_timeout', 10))
                    ->acceptJson()
                    ->get($uri);

                if (! $response->successful()) {
                    throw new RuntimeException('JWKS endpoint mengembalikan HTTP '.$response->status());
                }

                $jwks = $response->json();

                if (! is_array($jwks) || empty($jwks['keys']) || ! is_array($jwks['keys'])) {
                    throw new RuntimeException('JWKS tidak valid.');
                }

                return $jwks;
            } catch (Throwable $e) {
                Log::error('[SSO] Gagal mengambil JWKS', [
                    'uri' => $uri,
                    'error' => $e->getMessage(),
                ]);

                throw new JwksUnavailableException('JWKS IdP tidak dapat diambil.', 0, $e);
            }
        });
    }

    /**
     * Panaskan cache JWKS di luar jalur back-channel.
     *
     * Back-channel logout dikirim saat IdP sedang memproses logout. Bila
     * JWKS belum pernah diambil, permintaan HTTP ke IdP dapat menunggu
     * proses logout selesai (deadlock pada server single-thread seperti
     * `php artisan serve`). Dengan memanggil ini ketika user login, token
     * logout dapat diverifikasi tanpa round-trip ke IdP.
     */
    public function warm(): void
    {
        try {
            $this->jwks();
        } catch (Throwable $e) {
            Log::warning('[SSO] Gagal memanaskan cache JWKS', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function consumeJti(string $jti, int $ttl): void
    {
        $store = Cache::store(config('sso.logout_token.jti_store'));

        try {
            $fresh = $store->add('sso:logout-jti:'.hash('sha256', $jti), 1, $ttl);
        } catch (Throwable $e) {
            Log::error('[SSO] Cache anti-replay jti tidak tersedia', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Anti-replay jti tidak tersedia.', 0, $e);
        }

        if (! $fresh) {
            throw new RuntimeException('Logout token sudah pernah dipakai (replay).');
        }
    }

    protected function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
```

#### Back-Channel Logout — `SsoController::backchannelLogout()`

SIM-UKPBJ **tidak** memakai controller terpisah `BackChannelLogoutController`.
Endpoint `POST /sso/backchannel-logout` ditangani method `backchannelLogout()`
di `SsoController` (kode lengkapnya ada di atas). Perilakunya:

| Kondisi | Respons |
|---|---|
| `logout_token` tidak ada | `400 {"error":"invalid_request"}` |
| JWKS tidak dapat diambil | `503 {"error":"temporarily_unavailable"}` → IdP retry |
| Token invalid/replay | `400 {"error":"invalid_request"}` |
| Token valid | `200 {"status":"ok"}` |

Saat token valid, semua baris `sessions` milik user dengan `sso_sub` tersebut
dihapus (session driver `database`). Endpoint ini dikecualikan dari CSRF karena
dipanggil server-to-server oleh IdP.

### 6. Routes, Middleware & Bootstrap

#### `routes/web.php`

SIM-UKPBJ memakai prefix dan middleware dari `config/sso.php`. Root `/` adalah
landing page publik; panel Filament `/admin` dilindungi middleware `auth` + `sso`.

```php
<?php

use App\Http\Controllers\CetakPengajuanController;
use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

// Titik masuk aplikasi.
// - Sudah login -> langsung panel Filament.
// - Belum login -> tampilkan landing page (bukan langsung dilempar ke SSO).
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->to(route('filament.admin.pages.dashboard'));
    }

    return view('welcome');
})->name('home');

// SSO routes
if (config('sso.enabled', true)) {
    Route::prefix(config('sso.prefix', 'sso'))
        ->middleware(config('sso.middleware', ['web']))
        ->group(function () {
            Route::get('login', [SsoController::class, 'login'])->name('sso.login');
            Route::get('callback', [SsoController::class, 'callback'])->name('sso.callback');
            Route::get('logout', [SsoController::class, 'logout'])->name('sso.logout');
            Route::post('backchannel-logout', [SsoController::class, 'backchannelLogout'])->name('sso.backchannel-logout');
            Route::get('session-status', [SsoController::class, 'sessionStatus'])->name('sso.session-status');
        });
}

// Route lain yang dilindungi SSO (panel utama ada di Filament /admin)
Route::middleware(['auth', 'sso'])->group(function () {
    Route::get('/cetak-pengajuan/{id}', [CetakPengajuanController::class, 'print'])
        ->name('cetak.pengajuan');
});
```

Perbedaan penting dari contoh generik:

- Login dimulai dari **`GET /sso/login`**, bukan `/auth/redirect`.
- Callback ada di **`/sso/callback`**.
- Logout memakai **`GET /sso/logout`** (RP-initiated SLO), bukan `POST /logout/sso`.
- Back-channel ada di **`POST /sso/backchannel-logout`**.
- Tidak ada route `/goodbye`; tujuan logout adalah `/` (`SSO_LOGOUT_URI`).

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
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias middleware SSO
        $middleware->alias([
            'sso' => \App\Http\Middleware\SsoMiddleware::class,
        ]);

        // Tamu yang membuka halaman terproteksi diarahkan ke login SSO.
        // URL tujuan disimpan agar setelah login user kembali ke halaman itu.
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return route('sso.login');
        });

        // Back-channel logout dikirim server-to-server oleh IdP, tanpa CSRF token.
        $middleware->validateCsrfTokens(except: [
            'sso/backchannel-logout',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
- [ ] **File wajib (SIM-UKPBJ)**: `config/sso.php`, `app/Services/SsoClientService.php`, `app/Services/LogoutTokenVerifier.php`, `app/Exceptions/JwksUnavailableException.php`, `app/Http/Middleware/SsoMiddleware.php`, `app/Http/Controllers/SsoController.php`, `routes/web.php`, `bootstrap/app.php`
- [ ] **Bootstrap**: Register middleware alias `sso` dan tambahkan `sso/backchannel-logout` ke `validateCsrfTokens(except: [])`
- [ ] **Database**: Tambah kolom `sso_sub`, `nip` ke tabel `users` & ubah `password` jadi nullable. Gunakan `SESSION_DRIVER=database`
- [ ] **IdP**: Daftarkan client dengan `redirect_uris` dan `backchannel_logout_uri` yang benar
- [ ] **IdP**: Tambahkan origin aplikasi ke `SSO_FORM_ACTION_ORIGINS` lalu `php artisan config:clear`
- [ ] **JWKS**: Cache `/oauth/jwks` + stale fallback (dan prewarm saat login) agar SLO tidak deadlock di dev server
- [ ] **Uji alur**: Akses route terproteksi → redirect ke IdP → login → callback kembali ke aplikasi klien
- [ ] **Uji logout lokal**: `Auth::logout()` + invalidate session **tanpa** memanggil `/sso/logout` → sesi klien berakhir, sesi IdP tetap aktif
- [ ] **Uji SLO**: `GET /sso/logout` → IdP melakukan global logout & mengirim back-channel ke klien → seluruh sesi klien terhancurkan otomatis
- [ ] **Uji audit**: `sso_logout_audit.status = delivered` & `http_status = 200` untuk client tersebut
