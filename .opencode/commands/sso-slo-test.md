---
description: Uji Single Logout (SLO) back-channel, token forgery, dan race condition
agent: build
---

Audit keamanan mekanisme Single Logout (SLO) back-channel dari IdP ke SP. Baca:

@app/Http/Controllers/OAuth/LogoutController.php
@app/Services/SloService.php
@app/Http/Controllers/Auth/SloController.php
@database/migrations/2025_01_01_000002_create_oauth_clients_table.php

## A. SLO Bypass & Forgery
1. Logout token forgery: kirim logout_token palsu (tanpa signature / signature palsu / alg=none) → sesi SP tetap hidup?
2. Logout token replay: kirim logout_token sama 2x → diterima? (harus ditolak via jti)
3. Logout token expired: exp di masa lalu → SP tolak?
4. Audience mismatch: aud=client_B ke SP client_A → ditolak?
5. Race condition: request paralel refresh token + logout → access token baru valid?
6. Partial SLO: satu SP timeout → apakah IdP revoke token user di SP lain? Retry queue?
7. SLO tanpa autentikasi → info disclosure user enumeration?

## B. Session Tracking Privacy
1. IDOR: user A akses /sso/sessions/{id} milik user B?
2. Session enumeration timing attack?
3. PII leakage (IP, user agent, device fingerprint) user lain?
4. Mass assignment: field revoked_at, user_id bisa di-set?
5. Session termination abuse via session ID guess?

## C. Business Logic
1. Account takeover via email change tanpa re-verifikasi
2. Deactivated user (is_active=0) masih pakai token valid 15 menit?
3. NIP collision setelah import
4. Scope escalation via consent screen
5. Impersonation admin "login as user" tercatat audit log?
6. Multi-tenant isolation

Output: mitigasi konkret (validasi jti di Redis, aud check, exp ≤ 120s, signature verify wajib) + patch SloService & SloController.