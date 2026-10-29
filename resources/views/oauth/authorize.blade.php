@extends('layouts.app')
@section('title', 'Otorisasi Aplikasi')

@section('content')
<div class="page page--narrow">
    <div class="card card--auth">
        <div class="consent-head">
            <span class="app-tile__icon">@include('partials.icon', ['name' => 'plug'])</span>
            <div>
                <h1 class="auth-title">Otorisasi Aplikasi</h1>
                <p class="auth-sub">Tinjau akses sebelum melanjutkan.</p>
            </div>
        </div>

        <div class="consent-client">
            <span class="consent-client__name">{{ $client->name }}</span>
            <span class="badge badge--neutral">Aplikasi eksternal</span>
        </div>

        <p class="text-muted text-sm mt-4">
            Aplikasi ini meminta akses ke informasi akun Anda:
        </p>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="authToken" value="{{ $authToken }}">
            <ul class="scope-list">
                @foreach ($scopes as $scope)
                    <li class="scope-item">
                        <label class="check">
                            <input type="checkbox" name="scopes[]" value="{{ $scope->id }}" @checked(request()->input('scopes') === null ? true : in_array($scope->id, (array) request()->input('scopes')))>
                            <span class="check__text">
                                <strong>{{ $scope->id }}</strong>
                                <span class="scope-desc">{{ $scope->description }}</span>
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>
            <button type="submit" name="approve" value="1" class="btn btn--block mt-4">Beri Akses</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            <input type="hidden" name="authToken" value="{{ $authToken }}">
            <button type="submit" name="cancel" value="1" class="btn btn--secondary btn--block mt-2">Tolak</button>
        </form>
    </div>
</div>
@endsection
