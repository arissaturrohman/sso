<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Issuer
    |--------------------------------------------------------------------------
    | Nilai `iss` pada logout token. Default mengikuti APP_URL. Klien (SP)
    | wajib membandingkan nilai ini secara exact-match.
    */
    'issuer' => rtrim((string) env('SSO_ISSUER', env('APP_URL', '')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Logout Token (OIDC Back-Channel Logout)
    |--------------------------------------------------------------------------
    */
    'logout_token' => [
        // Masa berlaku token. Spesifikasi menyarankan sangat pendek; nilai
        // di atas 120 detik tetap dipaksa menjadi 120 detik oleh builder.
        'ttl' => (int) env('SSO_LOGOUT_TOKEN_TTL', 120),

        // Toleransi clock skew saat verifikasi (detik).
        'leeway' => (int) env('SSO_LOGOUT_TOKEN_LEEWAY', 60),

        // kid eksplisit. Default dipertahankan 'passport-key-1' agar client
        // lama tidak putus; saat rotasi key, set SSO_JWK_KID baru.
        // Jika dikosongkan, kid = RFC 7638 JWK thumbprint dari public key.
        'kid' => env('SSO_JWK_KID', 'passport-key-1'),

        // Untuk implementasi sisi SP: URL JWKS IdP dan/atau public key PEM.
        'jwks_uri' => env('SSO_JWKS_URI'),
        'public_key' => env('SSO_LOGOUT_TOKEN_PUBLIC_KEY'),

        // Cache store untuk anti-replay `jti` di sisi SP. Null = default store.
        'jti_store' => env('SSO_JTI_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Back-Channel Delivery
    |--------------------------------------------------------------------------
    */
    'queue' => env('SSO_LOGOUT_QUEUE', 'slo'),

    'http' => [
        'timeout' => (int) env('SSO_BACKCHANNEL_TIMEOUT', 5),
        'connect_timeout' => (int) env('SSO_BACKCHANNEL_CONNECT_TIMEOUT', 3),
        'retry_until_minutes' => (int) env('SSO_BACKCHANNEL_RETRY_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post Logout Redirect
    |--------------------------------------------------------------------------
    | Bila null, HTTPS diwajibkan untuk semua host kecuali localhost/loopback
    | saat APP_ENV=local/testing.
    */
    'post_logout' => [
        'require_https' => env('SSO_POST_LOGOUT_REQUIRE_HTTPS'),
        'state_max_length' => 512,
    ],

    /*
    |--------------------------------------------------------------------------
    | CSP form-action
    |--------------------------------------------------------------------------
    | Chrome/Chromium menerapkan direktif `form-action` pada seluruh rantai
    | redirect yang dipicu submit form. Karena submit form login berakhir di
    | aplikasi client (origin berbeda), origin tersebut harus diizinkan —
    | jika tidak, browser memblokir login SSO.
    |
    | Isi dengan origin aplikasi client, mis. http://localhost:8001.
    */
    'form_action_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SSO_FORM_ACTION_ORIGINS', ''))
    ))),

];
