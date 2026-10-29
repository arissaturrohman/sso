---
description: Uji keamanan JWT access token, refresh token, dan logout token (RS256)
agent: build
---

Lakukan JWT security assessment menyeluruh terhadap access token, refresh token, dan logout token aplikasi SSO ini. Baca:

@config/passport.php
@app/Providers/AppServiceProvider.php
@app/Http/Middleware/VerifySsoToken.php
@app/Services/SloService.php

## A. Algorithm Attacks
- alg=none bypass
- RS256 → HS256 confusion (sign dengan public key sebagai HMAC secret)
- alg whitespace/case anomaly: "none", "None", "NONE", "nOnE"

## B. Claim Manipulation
- Ubah `sub` ke user lain → diterima server?
- Ubah `aud` → SP menerima token audience lain?
- Manipulasi `exp`, `iat`, `nbf` → clock skew tolerance > 60 detik?
- Hapus `iss` → divalidasi?
- `jti` collision / replay

## C. Header Injection
- `kid` path traversal: ../../../../etc/passwd
- `kid` SQL injection: ' OR 1=1--
- `jku` / `x5u` menunjuk attacker URL
- `typ` mismatch: JWT vs at+jwt

## D. Crypto
- Nonce reuse pada ID token (OIDC)
- Key confusion signing vs encryption
- Weak RSA key (< 2048 bits)

## E. Token Storage & Leakage
1. Access token di localStorage atau HttpOnly cookie?
2. Refresh token: HttpOnly + Secure + SameSite=Strict?
3. Token muncul di URL (Referer leak)?
4. Token muncul di log (storage/logs, nginx access log)?
5. Token plaintext di database (oauth_access_tokens)?
6. CORS misconfiguration (Access-Control-Allow-Origin: * pada /oauth/token)?
7. Token leak di error page (Ignition/Whoops)?

## F. JWKS Cache Poisoning (Client Side)
1. Cache key idp:jwks bisa di-poison?
2. Host header injection pada config('sso.idp_url')?
3. TOCTOU: token revoked di IdP tapi masih valid di cache SP (TTL 1 jam)?
4. JWK parseKeySet bypass dengan alg=HS256?
5. Exception handling — stack trace leak?

Output: tabel attack matrix (payload → result → severity → mitigation) + patch code untuk VerifySsoToken.