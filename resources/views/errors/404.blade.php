{{--
    Halaman 404 — resources/views/errors/404.blade.php
    Extends layout utama sehingga seo-head aktif; SeoMeta::resolve() akan
    men-set title "Halaman Tidak Ditemukan" + meta robots noindex, follow
    untuk route yang tidak dikenal.
--}}
@extends('layouts.app')
@section('title', 'Halaman Tidak Ditemukan')

@section('content')
<div class="page page--narrow">
    <div class="card card--auth">
        <div class="auth-brand">
            <span class="auth-brand__mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6"/><path d="M12 16.5v.5"/></svg>
            </span>
            <h1 class="auth-title">404 — Halaman Tidak Ditemukan</h1>
            <p class="auth-sub">Halaman yang Anda tuju tidak ada atau telah dipindahkan.</p>
        </div>

        <a class="btn btn--block" href="{{ url('/') }}">Kembali ke Beranda</a>
        <a class="btn btn--secondary btn--block mt-2" href="{{ route('login') }}">Ke Halaman Login</a>

        <p class="auth-note">Pastikan alamat yang Anda ketik benar, atau gunakan tautan di bawah untuk memulai.</p>
    </div>
</div>
@endsection
