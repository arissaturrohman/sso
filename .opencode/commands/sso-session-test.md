---
description: Uji keamanan session lintas domain, CSRF, dan brute-force login
agent: build
---

Uji keamanan session lintas domain (cross-domain SSO) dan autentikasi aplikasi ini. Baca:

@config/session.php
@app/Http/Controllers/Auth/LoginController.php
@routes/web.php
@app/Providers/AppServiceProvider.php

## A. Session Hijacking & Fixation

1. Session fixation: apakah session()->regenerate() dipanggil setelah login?
2. Session cookie tampering: uji dekripsi + modifikasi payload
3. Session ID entropy: analisis 1000 session ID (uji randomness)
4. Cookie scope leakage dari evil.domain.id (XSS subdomain)
5. SameSite=Lax bypass via top-level POST cross-site
6. Cookie tossing / session shadowing (path berbeda)
7. Concurrent session abuse (unlimited device?)

## B. CSRF pada OAuth Flow

1. POST /login — cek @csrf token
2. POST /oauth/authorize (approval) — login CSRF?
3. POST /oauth/token — validasi Origin header?
4. OAuth state parameter bypass (hapus/kosong)?
5. POST /oauth/logout — bisa di-CSRF untuk force logout?
6. POST /sso/logout back-channel — validasi signature sebelum invalidate?

Berikan PoC HTML/JS untuk setiap vektor CSRF.

## C. Brute Force & Enumeration

1. Brute force 1000 req dari IP berbeda → apakah RateLimiter efektif?
2. Credential stuffing (100 email/password dari breach list)
3. Username enumeration: timing attack email valid vs invalid (target < 100ms delta)
4. NIP enumeration via error message berbeda
5. Password spray: 1 password ke 1000 akun
6. X-Forwarded-For bypass rate limit (cek TrustProxies)

## D. Password Security

1. Verifikasi Argon2id (memory 64MB, time 4, threads 1) — cek via query DB
2. Password policy (min 12, cek HaveIBeenPwned)
3. remember_token lifetime
4. Password history saat reset
5. Reset token TTL ≤ 15 menit & one-time use

Output: tabel severity + patch code + unit test Pest untuk regression.