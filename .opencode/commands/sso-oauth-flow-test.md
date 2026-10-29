---
description: Uji kerentanan OAuth 2.0 / OIDC flow (PKCE, redirect_uri, token endpoint)
agent: build
---

Lakukan pengujian keamanan mendalam terhadap OAuth 2.0 / OIDC flow pada aplikasi SSO ini. Baca kode berikut:

@routes/web.php
@app/Http/Middleware/EnforcePkce.php
@app/Http/Controllers/OAuth/AuthorizationController.php
@config/passport.php

## A. Authorization Code + PKCE Abuse

Buat skenario serangan dan PoC (curl/HTTP raw) untuk:
1. Menghilangkan parameter code_challenge → apakah IdP menolak dengan 400?
2. Menggunakan code_challenge_method=plain → apakah ditolak?
3. Mengirim code_verifier yang salah saat tukar token → apakah invalid_grant?
4. PKCE downgrade: client publik tanpa PKCE tapi kirim client_secret → lolos?
5. Authorization code interception (MITM pada redirect) → apakah code bisa ditukar tanpa verifier?
6. Authorization code replay: pakai code yang sama 2x → apakah auto-revoke?
7. Code lifetime > 60 detik?
8. Validasi state parameter di IdP?

## B. Redirect URI Manipulation

Buat minimal 15 payload uji open redirect / redirect_uri bypass:
- Subdomain takeover: https://app.domain.id.evil.com/callback
- Path traversal: https://app.domain.id/callback/../../evil
- Regex bypass: https://appXdomain.id/callback
- URL encoding: https%3A%2F%2Fevil.com
- Fragment injection: https://app.domain.id/callback#@evil.com
- Userinfo trick: https://app.domain.id@evil.com/callback
- Wildcard abuse: https://evil.app.domain.id/callback
- Case sensitivity, trailing slash, double-slash, CRLF injection

Berikan tabel: payload → diterima/tidak → dampak.

## C. Token Endpoint Attacks

Audit POST /oauth/token terhadap:
1. Client secret brute force (cek rate limit 10/menit/IP)
2. Grant type confusion (password grant ke client authorization_code only)
3. Scope escalation (minta admin scope)
4. Audience confusion (client A minta token audience B)
5. Token replay setelah logout
6. JWT alg=none attack
7. JWT RS256→HS256 confusion
8. JWT kid injection (path traversal, SQLi)

Output: tabel dengan severity CVSS 3.1 + patch code Laravel 12 untuk setiap temuan.