@extends('layouts.app')
@section('title', 'Login SSO UKPBJ Demak')

{{-- Skip link spesifik halaman login: langsung ke input pertama --}}
@section('skip_link_text', 'Lewati ke form login')
@section('skip_link_target', '#identifier')

@section('content')
<div class="page page--narrow">
    <div class="card card--auth">
        <div class="auth-brand">
            <span class="auth-brand__mark">@include('partials.icon', ['name' => 'key'])</span>
            {{-- H1 tunggal halaman, memuat keyword utama "SSO UKPBJ Demak" --}}
            <h1 class="auth-title">Masuk ke SSO UKPBJ Demak</h1>
            {{-- H2: manfaat SSO dalam satu kalimat --}}
            <h2 class="auth-sub">Satu login untuk mengakses semua aplikasi layanan pengadaan Kabupaten Demak.</h2>
        </div>

        @if (session('status'))
            <div class="alert alert--success" role="status">
                <span class="alert__icon">@include('partials.icon', ['name' => 'check'])</span>
                <div class="alert__body">{{ session('status') }}</div>
            </div>
        @endif

        @php
            // Nilai aria-describedby dihitung di PHP: Blade tidak mengenali
            // "@enderror" yang menempel langsung pada teks dalam atribut.
            $identifierErrors = $errors->first('identifier');
            $passwordErrors = $errors->first('password');
            $identifierDescribedBy = trim('identifier-hint'.($identifierErrors ? ' identifier-error' : ''));
            $passwordDescribedBy = $passwordErrors ? 'password-error' : null;
        @endphp

        <form method="POST" action="{{ route('login') }}" data-login-form>
            @csrf

            <div class="form-group">
                <label class="label" for="identifier">Email atau NIP</label>
                <input class="input" id="identifier" type="text" name="identifier"
                       value="{{ old('identifier') }}" required autofocus
                       autocomplete="username"
                       aria-describedby="{{ $identifierDescribedBy }}"
                       @if ($identifierErrors) aria-invalid="true" @endif>
                {{-- Hint konversi: bantu user memilih identifier yang benar --}}
                <p class="auth-hint" id="identifier-hint">Gunakan email atau NIP yang terdaftar.</p>
            </div>

            <div class="form-group">
                <label class="label" for="password">Password</label>
                <input class="input" id="password" type="password" name="password" required
                       autocomplete="current-password"
                       @if ($passwordDescribedBy) aria-describedby="{{ $passwordDescribedBy }}" aria-invalid="true" @endif>

                {{-- Opsi pemulihan akun. Aplikasi ini tidak punya reset mandiri
                     (password direset admin), jadi arahkan ke admin — tanpa link mati. --}}
                <p class="auth-hint auth-hint--end">Lupa password? Hubungi administrator UKPBJ Demak untuk reset.</p>
            </div>

            <div class="form-group">
                <label class="check" for="remember">
                    <input id="remember" type="checkbox" name="remember" @checked(old('remember'))>
                    <span class="check__text">Ingat saya di perangkat ini</span>
                </label>
            </div>

            <button class="btn btn--block" type="submit" data-login-submit>
                <span data-login-label>Masuk</span>
            </button>
        </form>

        @error('identifier')
            <div class="alert alert--danger mt-3" role="alert" id="identifier-error">
                <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
                <div class="alert__body">{{ $message }}</div>
            </div>
        @enderror

        @error('password')
            <div class="alert alert--danger mt-3" role="alert" id="password-error">
                <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
                <div class="alert__body">{{ $message }}</div>
            </div>
        @enderror

        <p class="auth-note">Login ini berlaku untuk <strong>semua aplikasi</strong> yang menggunakan SSO sistem.</p>

        {{-- Trust badge: sinyal keamanan untuk menurunkan keberatan user --}}
        <p class="trust-badge">
            <span class="trust-badge__icon">@include('partials.icon', ['name' => 'lock'])</span>
            Koneksi aman dengan enkripsi SSL
        </p>
    </div>
</div>

{{-- Loading state: cegah double submit, beri umpan balik visual --}}
<script>
(function () {
    var form = document.querySelector('[data-login-form]');

    if (!form) {
        return;
    }

    var button = form.querySelector('[data-login-submit]');
    var label = form.querySelector('[data-login-label]');

    function setLoading(loading) {
        if (!button) {
            return;
        }

        button.disabled = loading;
        button.classList.toggle('is-loading', loading);
        button.setAttribute('aria-busy', loading ? 'true' : 'false');

        if (label) {
            label.textContent = loading ? 'Memproses…' : 'Masuk';
        }
    }

    form.addEventListener('submit', function (event) {
        if (button && button.disabled) {
            event.preventDefault();
            return;
        }

        setLoading(true);
    });

    // Kembali dari bfcache (tombol Back): aktifkan kembali tombolnya.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            setLoading(false);
        }
    });
})();
</script>
@endsection
