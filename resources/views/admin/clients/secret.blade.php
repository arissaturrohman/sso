@extends('admin.layouts.admin')
@section('title', 'Client Secret')

@section('admin-content')
<div class="card card--limited">
    <div class="credential-head">
        <span class="credential-head__icon">@include('partials.icon', ['name' => 'alert'])</span>
        <div>
            <h1 class="card__title text-danger">Simpan Secret Ini Sekarang</h1>
            <p class="card__sub">
                Client secret <strong>tidak akan ditampilkan lagi</strong> setelah halaman ini ditutup.
                Jika hilang, lakukan <em>Regenerate Secret</em> dari daftar aplikasi klien.
            </p>
        </div>
    </div>

    <div class="data-list mt-4">
        <div class="data-list__item">
            <span class="data-list__label">Nama</span>
            <span class="data-list__value">{{ $client->name }}</span>
        </div>
        <div class="data-list__item">
            <span class="data-list__label">Client ID</span>
            <span class="data-list__value">
                <code class="code">{{ $client->id }}</code>
                <button type="button" class="btn btn--secondary btn--sm ml-2"
                        onclick="navigator.clipboard.writeText('{{ $client->id }}'); this.textContent='Tersalin!'">Copy</button>
            </span>
        </div>
        <div class="data-list__item">
            <span class="data-list__label">Client Secret</span>
            <span class="data-list__value">
                <code class="code code--highlight">{{ $plainSecret }}</code>
                <button type="button" class="btn btn--secondary btn--sm ml-2"
                        onclick="navigator.clipboard.writeText('{{ $plainSecret }}'); this.textContent='Tersalin!'">Copy</button>
            </span>
        </div>
        <div class="data-list__item">
            <span class="data-list__label">Redirect URIs</span>
            <span class="data-list__value">{{ implode(', ', $client->redirect_uris ?? []) }}</span>
        </div>
        <div class="data-list__item">
            <span class="data-list__label">Landing URL</span>
            <span class="data-list__value">{{ $client->landing_url ?? '— (atur di menu Edit agar tampil di dashboard user)' }}</span>
        </div>
        @if ($client->backchannel_logout_uri)
            <div class="data-list__item">
                <span class="data-list__label">Back-Channel URI</span>
                <span class="data-list__value">{{ $client->backchannel_logout_uri }}</span>
            </div>
        @endif
    </div>

    <a href="{{ route('admin.clients.index') }}" class="btn btn--block mt-4">Saya Sudah Menyimpan — Kembali ke Daftar</a>
</div>
@endsection
