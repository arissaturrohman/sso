@extends('layouts.app')
@section('title', 'Ubah Password')

@section('content')
<div class="page page--narrow">
    <div class="card card--auth">
        <div class="auth-brand">
            <span class="auth-brand__mark">@include('partials.icon', ['name' => 'key'])</span>
            <h1 class="auth-title">Ubah Password</h1>
            <p class="auth-sub">Password baru langsung berlaku untuk seluruh aplikasi SSO.</p>
        </div>

        @if (session('warning'))
            <div class="alert alert--warning" role="alert">
                <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
                <div class="alert__body">{{ session('warning') }}</div>
            </div>
        @endif

        @if (session('status'))
            <div class="alert alert--success" role="status">
                <span class="alert__icon">@include('partials.icon', ['name' => 'check'])</span>
                <div class="alert__body">{{ session('status') }}</div>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert--danger" role="alert">
                <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
                <div class="alert__body">
                    <strong class="alert__title">Periksa kembali data yang Anda masukkan.</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @if ($forced)
            <p class="alert alert--info">
                <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
                <span class="alert__body">Akun Anda memakai password sementara dari admin. Demi keamanan, buat password baru sebelum melanjutkan.</span>
            </p>
        @else
            <p class="field-help mb-4">
                Gunakan formulir ini kapan saja untuk mengganti password akun IdP Anda.
                Password baru langsung berlaku untuk semua aplikasi yang memakai SSO.
            </p>
        @endif

        <form method="POST" action="{{ route('password.change.update') }}">
            @csrf @method('PUT')

            <div class="form-group">
                <label class="label" for="current_password">Password Saat Ini</label>
                <input class="input" id="current_password" type="password" name="current_password" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label class="label" for="password">Password Baru <span class="hint">(min. 8 karakter)</span></label>
                <input class="input" id="password" type="password" name="password" required autocomplete="new-password">
            </div>

            <div class="form-group">
                <label class="label" for="password_confirmation">Konfirmasi Password Baru</label>
                <input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button class="btn btn--block" type="submit">Simpan Password Baru</button>
        </form>

        <hr class="divider">

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn--secondary btn--block">Keluar dari akun</button>
        </form>

        @unless ($forced)
            <p class="auth-note auth-note--plain">
                <a href="{{ route('dashboard') }}">&larr; Kembali ke Dashboard</a>
            </p>
        @endunless
    </div>
</div>
@endsection
