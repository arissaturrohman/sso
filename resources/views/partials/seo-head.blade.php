{{--
    resources/views/partials/seo-head.blade.php
    Meta SEO lengkap untuk seluruh halaman. Sumber data:
      - $seo (di-share oleh App\Http\Middleware\SeoMeta per-route), atau
      - fallback resolve() bila view dirender tanpa middleware (mis. 404).

    Isi: title unik, meta description, meta robots, canonical, Open Graph,
    Twitter Card, favicon, theme-color, dan structured data JSON-LD.
--}}
@php
    $seo = $seo ?? \App\Http\Middleware\SeoMeta::resolve(request());

    // Title: prioritas dari SeoMeta (halaman publik); selain itu pakai
    // @section('title') view lalu fallback ke nama aplikasi.
    $sectionTitle = trim($__env->yieldContent('title'));
    $pageTitle = $seo['title']
        ?? ($sectionTitle !== '' ? $sectionTitle.' — '.config('app.name') : config('app.name'));

    // Description: dari SeoMeta; halaman privat tetap dapat deskripsi netral.
    $pageDescription = $seo['description']
        ?? 'Portal Single Sign-On (SSO) resmi UKPBJ Demak untuk masuk ke seluruh aplikasi layanan pengadaan barang/jasa Kabupaten Demak.';

    $pageRobots = $seo['robots'] ?? 'noindex, nofollow';
    $pageCanonical = $seo['canonical'] ?? null;
    $pageJsonLd = $seo['json_ld'] ?? [];
@endphp
<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDescription }}">
<meta name="robots" content="{{ $pageRobots }}">
@isset($pageCanonical)
    <link rel="canonical" href="{{ $pageCanonical }}">
@endisset
<meta name="theme-color" content="#1d4ed8">

{{-- Open Graph (preview share: WhatsApp, Teams, Slack, dsb.) --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:locale" content="id_ID">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
@isset($pageCanonical)
    <meta property="og:url" content="{{ $pageCanonical }}">
@endisset
@if (is_file(public_path('img/og-image.png')))
    <meta property="og:image" content="{{ url('img/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $pageTitle }}">
@endif

{{-- Twitter / X Card --}}
<meta name="twitter:card" content="{{ is_file(public_path('img/og-image.png')) ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $pageTitle }}">
<meta name="twitter:description" content="{{ $pageDescription }}">
@if (is_file(public_path('img/og-image.png')))
    <meta name="twitter:image" content="{{ url('img/og-image.png') }}">
@endif

{{-- Favicon (public/favicon.ico — jangan dimatikan dengan data-URI) --}}
<link rel="icon" href="/favicon.ico" sizes="any">
@if (is_file(public_path('apple-touch-icon.png')))
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
@endif
@if (is_file(public_path('manifest.json')))
    <link rel="manifest" href="/manifest.json">
@endif

{{-- Structured data: GovernmentOrganization + WebSite + WebApplication (home), WebPage (login) --}}
@foreach ($pageJsonLd as $ld)
    <script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}</script>
@endforeach
