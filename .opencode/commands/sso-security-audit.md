---
description: Audit keamanan menyeluruh aplikasi SSO Laravel 12 + Passport (Prompt Master)
agent: build
---

Bertindaklah sebagai Senior Application Security Engineer & OAuth 2.0/OIDC Security Auditor bersertifikasi (OSCP, OSWE, CISSP). Lakukan threat modeling dan security audit menyeluruh terhadap aplikasi SSO berbasis Laravel 12 + Passport dengan arsitektur berikut:

- IdP (Identity Provider): Laravel 12 + Passport 13, MySQL 8, Redis, PKCE S256 wajib
- SP (Service Provider): Laravel 12 dengan middleware VerifySsoToken
- Protokol: OAuth 2.0 + OIDC (authorization_code + PKCE, refresh_token rotation)
- Single Logout: back-channel POST dengan logout_token JWT (RS256)
- Session cross-domain: cookie sso_session dengan domain `.domain.id`, secure, httponly, samesite=lax
- Login: email atau NIP, Argon2id, rate limit 5/menit/IP

Analisis file-file berikut (gunakan `@` untuk menyertakan file relevan):

@config/passport.php
@config/session.php
@config/auth.php
@app/Http/Controllers/Auth/LoginController.php
@app/Http/Controllers/OAuth/AuthorizationController.php
@app/Http/Controllers/OAuth/LogoutController.php
@app/Http/Middleware/EnforcePkce.php
@app/Http/Middleware/SecurityHeaders.php
@app/Services/SloService.php
@routes/web.php

Target audit:
1. Identifikasi seluruh attack surface (endpoint publik, endpoint terautentikasi, back-channel, cookie, JWT).
2. Klasifikasikan temuan menggunakan STRIDE dan OWASP Top 10 2021.
3. Buat attack tree (ASCII) untuk skenario: account takeover, token theft, session fixation, SSO bypass, CSRF, dan privilege escalation.
4. Untuk setiap temuan, berikan: severity (CVSS 3.1 vector), bukti konsep (PoC curl/HTTP raw), dampak bisnis, dan rekomendasi mitigasi konkret dalam kode Laravel 12.
5. Output dalam format tabel + lampiran checklist.

Asumsikan penyerang adalah external unauthenticated attacker dengan akses ke browser korban dan kemampuan melakukan phishing.

Fokus prioritas:
- PKCE enforcement (S256, tolak plain)
- Redirect URI whitelist (open redirect / wildcard abuse)
- JWT algorithm confusion (alg=none, RS256→HS256)
- Refresh token rotation & reuse detection
- Session fixation & cookie tossing
- CSRF pada /login, /oauth/authorize, /oauth/logout
- SLO signature verification & replay
- Rate limiting bypass (X-Forwarded-For)
- Secret management (private key exposure)

Berikan laporan dalam format Markdown terstruktur dengan:
- Executive Summary (1 paragraf)
- Attack Surface Matrix (tabel)
- Findings Table (ID, Title, Severity, CVSS, Component, Exploitability)
- Detailed Findings dengan PoC + patch code
- Remediation Roadmap (P0/P1/P2)