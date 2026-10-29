@extends('layouts.app')
@section('title', 'Login')

@section('content')
<div class="page page--narrow">
    <div class="card card--auth">
        <div class="auth-brand">
            <span class="auth-brand__mark">@include('partials.icon', ['name' => 'key'])</span>
            <h1 class="auth-title">Login Terpusat</h1>
            <p class="auth-sub">Masuk sekali untuk mengakses semua aplikasi yang terhubung.</p>
        </div>

        @if (session('status'))
            <div class="alert alert--success" role="status">
                <span class="alert__icon">@include('partials.icon', ['name' => 'check'])</span>
                <div class="alert__body">{{ session('status') }}</div>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="form-group">
                <label class="label" for="identifier">Email <span class="hint">atau</span> NIP</label>
                <input class="input" id="identifier" type="text" name="identifier" value="{{ old('identifier') }}" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label class="label" for="password">Password</label>
                <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label class="check" for="remember">
                    <input id="remember" type="checkbox" name="remember" @checked(old('remember'))>
                    <span class="check__text">Ingat saya di perangkat ini</span>
                </label>
            </div>

            <button class="btn btn--block" type="submit">Masuk</button>
        </form>

        @error('identifier')
            <div class="alert alert--danger mt-3" role="alert">
                <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
                <div class="alert__body">{{ $message }}</div>
            </div>
        @enderror

        <p class="auth-note">Login ini berlaku untuk <strong>semua aplikasi</strong> yang menggunakan SSO sistem.</p>
    </div>
</div>
@endsection
