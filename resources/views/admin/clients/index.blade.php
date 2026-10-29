@extends('admin.layouts.admin')
@section('title', 'Kelola Aplikasi Klien')

@section('admin-content')
<div class="page-head">
    <div>
        <h1 class="page-head__title">Aplikasi Klien</h1>
        <p class="page-head__sub">Kelola klien OAuth yang terhubung dengan Identity Provider.</p>
    </div>
    <div class="page-head__actions">
        <a href="{{ route('admin.clients.create') }}" class="btn">+ Registrasi Client</a>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table table--wide">
            <thead>
                <tr>
                    <th scope="col">Nama</th>
                    <th scope="col">Client ID</th>
                    <th scope="col">Trusted</th>
                    <th scope="col">Landing URL</th>
                    <th scope="col">Back-channel URI</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="cell-actions">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $c)
                    <tr>
                        <td class="cell-strong">{{ $c->name }}</td>
                        <td class="cell-nowrap">
                            <code class="code">{{ $c->id }}</code>
                            <button type="button" class="btn btn--secondary btn--sm ml-2"
                                    onclick="navigator.clipboard.writeText('{{ $c->id }}'); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Copy',1200)">Copy</button>
                        </td>
                        <td>
                            @if ($c->trusted)
                                <span class="badge badge--success">trusted</span>
                            @else
                                <span class="badge badge--neutral">—</span>
                            @endif
                        </td>
                        <td class="cell-muted cell-truncate" title="{{ $c->landing_url }}">
                            {{ $c->landing_url ?? '—' }}
                            @unless ($c->landing_url)
                                <div class="mt-1">
                                    <span class="badge badge--warning" title="Link aplikasi tidak tampil di dashboard user">belum diatur</span>
                                </div>
                            @endunless
                        </td>
                        <td class="cell-muted cell-truncate" title="{{ $c->backchannel_logout_uri }}">{{ $c->backchannel_logout_uri ?? '—' }}</td>
                        <td>
                            @if ($c->revoked)
                                <span class="badge badge--danger">revoked</span>
                            @else
                                <span class="badge badge--success">aktif</span>
                            @endif
                        </td>
                        <td class="cell-actions">
                            <div class="row-actions">
                                <a href="{{ route('admin.clients.edit', $c) }}" class="link-action">Edit</a>

                                @unless ($c->revoked)
                                    <form method="POST" action="{{ route('admin.clients.regenerate-secret', $c) }}" class="inline-form"
                                          onsubmit="return confirm('Regenerate secret? Aplikasi klien harus pakai secret baru.')">
                                        @csrf
                                        <button type="submit" class="link-action" title="Buat ulang client secret">Regenerate</button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.clients.destroy', $c) }}" class="inline-form"
                                          onsubmit="return confirm('Cabut (revoke) client {{ $c->name }}? Semua token-nya juga dicabut.')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="link-action link-action--danger">Revoke</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty">
                                <span class="empty__title">Belum ada aplikasi klien</span>
                                Registrasikan client OAuth pertama Anda untuk memulai integrasi SSO.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
