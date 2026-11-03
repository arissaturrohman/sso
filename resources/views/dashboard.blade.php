@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="page">
    <div class="page-head">
        <div>
            <h1 class="page-head__title">Dashboard</h1>
            <p class="page-head__sub">Ringkasan akun dan akses SSO Anda.</p>
        </div>
        <div class="page-head__actions">
            <a href="{{ route('sessions.index') }}" class="btn btn--secondary btn--sm">Perangkat &amp; Sesi</a>
            <a href="{{ route('password.change') }}" class="btn btn--secondary btn--sm">Ubah Password</a>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert--success" role="status">
            <span class="alert__icon">@include('partials.icon', ['name' => 'check'])</span>
            <div class="alert__body">{{ session('status') }}</div>
        </div>
    @endif

    <div class="card">
        <div class="welcome">
            <span class="avatar avatar--lg">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
            <div>
                <h2 class="welcome__title">Selamat datang, {{ auth()->user()->name }}</h2>
                <p class="welcome__sub">
                    <span class="badge badge--success">Terautentikasi via IdP</span>
                    <span>{{ auth()->user()->email }}</span>
                </p>
            </div>
        </div>

        <div class="data-list">
            <div class="data-list__item">
                <span class="data-list__label">Email</span>
                <span class="data-list__value">{{ auth()->user()->email }}</span>
            </div>
            <div class="data-list__item">
                <span class="data-list__label">NIP</span>
                <span class="data-list__value">{{ auth()->user()->nip ?? '—' }}</span>
            </div>
            <div class="data-list__item">
                <span class="data-list__label">Login terakhir</span>
                <span class="data-list__value">
                    {{ auth()->user()->last_login_at?->format('d M Y H:i') ?? '—' }}
                    <span class="cell-muted">({{ auth()->user()->last_login_ip ?? '—' }})</span>
                </span>
            </div>
            <div class="data-list__item">
                <span class="data-list__label">Password terakhir diubah</span>
                <span class="data-list__value">{{ auth()->user()->password_changed_at?->format('d M Y H:i') ?? '—' }}</span>
            </div>
        </div>

        <div class="form-actions">
            <a href="{{ route('password.change') }}" class="btn btn--secondary">Ubah Password</a>
            <a href="{{ route('sessions.index') }}" class="btn">Lihat Perangkat &amp; Sesi Aktif</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn--danger-outline">Logout (semua aplikasi)</button>
            </form>
        </div>
    </div>

    @if ($apps->isNotEmpty())
        <div class="card">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Aplikasi Terhubung</h2>
                    <p class="card__sub">Buka aplikasi tanpa login ulang melalui Single Sign-On.</p>
                </div>
            </div>

            <div class="app-grid">
                @foreach ($apps as $app)
                    <a href="{{ $app->landing_url }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="app-tile">
                        <span class="app-tile__icon">@include('partials.icon', ['name' => 'building'])</span>
                        <span class="app-tile__body">
                            <span class="app-tile__name">{{ $app->name }}</span>
                            <span class="app-tile__meta">Buka di tab baru</span>
                        </span>
                        <span class="app-tile__arrow">@include('partials.icon', ['name' => 'external'])</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
