@extends('admin.layouts.admin')
@section('title', 'Edit Aplikasi Klien')

@section('admin-content')
<div class="page-head">
    <div>
        <h1 class="page-head__title">Edit Aplikasi Klien</h1>
        <p class="page-head__sub">{{ $client->name }}</p>
    </div>
    <div class="page-head__actions">
        <a href="{{ route('admin.clients.index') }}" class="btn btn--secondary btn--sm">&larr; Kembali</a>
    </div>
</div>

<div class="card card--form">
    <form method="POST" action="{{ route('admin.clients.update', $client) }}">
        @csrf @method('PUT')

        <div class="form-group">
            <label class="label" for="name">Nama Aplikasi</label>
            <input class="input" id="name" type="text" name="name" value="{{ old('name', $client->name) }}" required>
        </div>

        <div class="form-group">
            <span class="label">Redirect URIs <span class="hint">(satu URI per baris)</span></span>
            @foreach (old('redirect_uris', $client->redirect_uris ?? []) as $i => $uri)
                <textarea class="textarea textarea--code textarea--single mb-0 mt-2" id="redirect_uris_{{ $i }}" name="redirect_uris[]" rows="1">{{ $uri }}</textarea>
            @endforeach
            <textarea class="textarea textarea--code textarea--single mt-2" name="redirect_uris[]" rows="1" placeholder="URI tambahan (opsional)"></textarea>
            <p class="field-help">Gunakan satu baris untuk setiap URI callback OAuth.</p>
        </div>

        <div class="form-group">
            <label class="label" for="landing_url">Landing URL <span class="hint">(halaman awal aplikasi — tampil di dashboard user)</span></label>
            <input class="input" id="landing_url" type="url" name="landing_url" value="{{ old('landing_url', $client->landing_url) }}">
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="label" for="backchannel_logout_uri">Back-Channel Logout URI</label>
                <input class="input" id="backchannel_logout_uri" type="url" name="backchannel_logout_uri" value="{{ old('backchannel_logout_uri', $client->backchannel_logout_uri) }}">
            </div>
            <div class="form-group">
                <label class="label" for="frontchannel_logout_uri">Front-Channel Logout URI</label>
                <input class="input" id="frontchannel_logout_uri" type="url" name="frontchannel_logout_uri" value="{{ old('frontchannel_logout_uri', $client->frontchannel_logout_uri) }}">
            </div>
        </div>

        <div class="form-group">
            <label class="check" for="trusted">
                <input id="trusted" type="checkbox" name="trusted" value="1" @checked(old('trusted', $client->trusted))>
                <span class="check__text">Trusted (lewati halaman consent)</span>
            </label>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Simpan Perubahan</button>
            <a href="{{ route('admin.clients.index') }}" class="btn btn--secondary">Batal</a>
        </div>
    </form>

    <hr class="divider">

    <details class="danger-zone">
        <summary>Zona Bahaya</summary>
        <div class="danger-zone__body">
            <p class="field-help mb-4">Revoke akan menonaktifkan client dan mencabut semua token-nya. Secret tidak berubah.</p>
            @unless ($client->revoked)
                <form method="POST" action="{{ route('admin.clients.destroy', $client) }}"
                      onsubmit="return confirm('Cabut (revoke) client {{ $client->name }}?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn--danger">Revoke Client Ini</button>
                </form>
            @endunless
        </div>
    </details>
</div>
@endsection
