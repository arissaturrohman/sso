@extends('admin.layouts.admin')
@section('title', 'Registrasi Aplikasi Klien')

@section('admin-content')
<div class="page-head">
    <div>
        <h1 class="page-head__title">Registrasi Aplikasi Klien</h1>
        <p class="page-head__sub">Daftarkan aplikasi OAuth baru beserta URL callback-nya.</p>
    </div>
    <div class="page-head__actions">
        <a href="{{ route('admin.clients.index') }}" class="btn btn--secondary btn--sm">&larr; Kembali</a>
    </div>
</div>

<div class="card card--form">
    <form method="POST" action="{{ route('admin.clients.store') }}">
        @csrf

        <div class="form-group">
            <label class="label" for="name">Nama Aplikasi</label>
            <input class="input" id="name" type="text" name="name" value="{{ old('name') }}" required>
        </div>

        <div class="form-group">
            <label class="label" for="redirect_uris_0">Redirect URIs <span class="hint">(satu URI per baris)</span></label>
            <textarea class="textarea textarea--code" id="redirect_uris_0" name="redirect_uris[]" rows="3" required>{{ old('redirect_uris.0', 'https://aplikasi.example.com/auth/callback') }}</textarea>
            <textarea class="textarea textarea--code mt-2" name="redirect_uris[]" rows="2" placeholder="URI kedua (opsional), mis. halaman post-logout">{{ old('redirect_uris.1') }}</textarea>
            <p class="field-help">URI pertama = callback OAuth. URI kedua (opsional) dipakai sebagai post_logout_redirect_uri.</p>
        </div>

        <div class="form-group">
            <label class="label" for="landing_url">Landing URL <span class="hint">(halaman awal aplikasi — tampil di dashboard user)</span></label>
            <input class="input" id="landing_url" type="url" name="landing_url" value="{{ old('landing_url') }}" placeholder="https://aplikasi.example.com/">
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="label" for="backchannel_logout_uri">Back-Channel Logout URI <span class="hint">(wajib untuk Single Logout)</span></label>
                <input class="input" id="backchannel_logout_uri" type="url" name="backchannel_logout_uri" value="{{ old('backchannel_logout_uri') }}" placeholder="https://aplikasi.example.com/sso/backchannel-logout">
            </div>
            <div class="form-group">
                <label class="label" for="frontchannel_logout_uri">Front-Channel Logout URI <span class="hint">(opsional)</span></label>
                <input class="input" id="frontchannel_logout_uri" type="url" name="frontchannel_logout_uri" value="{{ old('frontchannel_logout_uri') }}">
            </div>
        </div>

        <div class="form-group">
            <label class="check" for="trusted">
                <input id="trusted" type="checkbox" name="trusted" value="1" @checked(old('trusted'))>
                <span class="check__text">Trusted (lewati halaman consent)</span>
            </label>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Registrasi</button>
            <a href="{{ route('admin.clients.index') }}" class="btn btn--secondary">Batal</a>
        </div>
    </form>
</div>
@endsection
