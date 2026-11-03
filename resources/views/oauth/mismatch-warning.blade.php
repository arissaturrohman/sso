@extends('layouts.app')
@section('title', 'Peringatan: Aplikasi Tidak Sesuai')

@section('content')
<div class="page page--narrow">
    <div class="card card--auth">
        <div class="consent-head">
            <span class="app-tile__icon" style="background:#fef3c7;color:#b45309">@include('partials.icon', ['name' => 'alert'])</span>
            <div>
                <h1 class="auth-title">Peringatan: Aplikasi Tidak Sesuai</h1>
                <p class="auth-sub">Aplikasi ini tidak untuk peran Anda.</p>
            </div>
        </div>

        <div class="consent-client">
            <span class="consent-client__name">{{ $clientName }}</span>
            <span class="badge badge--warning">{{ $clientTypeLabel }}</span>
        </div>

        <div class="alert alert--warning mt-4">
            <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
            <div class="alert__body">
                <strong class="alert__title">Jenis aplikasi tidak cocok</strong>
                <p class="mt-2">
                    Anda login sebagai <strong>{{ $userTypeLabel }}</strong>, tetapi aplikasi
                    <strong>{{ $clientName }}</strong> diperuntukkan untuk
                    <strong>{{ $clientTypeLabel }}</strong>.
                </p>
                <p class="mt-2 text-sm">
                    Mengakses aplikasi yang tidak sesuai bisa menyebabkan akses ditolak,
                    data tidak lengkap, atau fitur tidak berfungsi sebagaimana mestinya.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('oauth.mismatch-continue') }}" class="mt-4">
            @csrf
            <button type="submit" class="btn btn--block">
                @include('partials.icon', ['name' => 'alert']) Lanjutkan (Saya Mengerti Risikonya)
            </button>
        </form>

        <form method="POST" action="{{ route('oauth.mismatch-cancel') }}" class="mt-2">
            @csrf
            <button type="submit" class="btn btn--secondary btn--block">
                @include('partials.icon', ['name' => 'arrow-left']) Kembali & Batalkan
            </button>
        </form>

        <p class="text-center text-sm text-muted mt-4">
            Pilih "Lanjutkan" hanya jika Anda yakin perlu mengakses aplikasi ini.
            Jika ragu, pilih "Kembali" dan hubungi administrator.
        </p>
    </div>
</div>
@endsection