@extends('layouts.app')
@section('title', 'Beranda')

@section('content')
<div class="page page--home">
    <div class="card card--auth">
        <div class="auth-brand">
            <span class="auth-brand__mark">@include('partials.icon', ['name' => 'logo'])</span>
            <h1 class="auth-title">{{ config('app.name') }}</h1>
            <p class="auth-sub">Identity Provider terpusat untuk seluruh aplikasi Anda.</p>
        </div>

        <div class="chip-row">
            <span class="badge badge--info">OAuth 2.0</span>
            <span class="badge badge--info">OpenID Connect</span>
            <span class="badge badge--neutral">PKCE S256</span>
        </div>

        <div class="endpoint-list">
            <div class="endpoint">
                <span class="endpoint__label">Authorization Endpoint</span>
                <code class="endpoint__code">GET {{ url('/oauth/authorize') }}</code>
            </div>
            <div class="endpoint">
                <span class="endpoint__label">Token Endpoint</span>
                <code class="endpoint__code">POST {{ url('/oauth/token') }}</code>
            </div>
            <div class="endpoint">
                <span class="endpoint__label">UserInfo</span>
                <code class="endpoint__code">GET {{ url('/oauth/userinfo') }}</code>
            </div>
        </div>

        <a class="btn btn--block" href="{{ route('login') }}">Masuk ke Portal</a>
    </div>
</div>
@endsection
