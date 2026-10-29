@extends('admin.layouts.admin')
@section('title', 'Dashboard Admin')

@section('admin-content')
<div class="page-head">
    <div>
        <h1 class="page-head__title">Dashboard Admin</h1>
        <p class="page-head__sub">Ringkasan pengguna, aplikasi klien, dan sesi SSO.</p>
    </div>
    <div class="page-head__actions">
        <a href="{{ route('admin.users.create') }}" class="btn btn--secondary btn--sm">+ Tambah User</a>
        <a href="{{ route('admin.clients.create') }}" class="btn btn--sm">+ Registrasi Client</a>
    </div>
</div>

<div class="stat-grid">
    @foreach ([
        'Total User' => [$stats['users'], 'bg'],
        'User Aktif' => [$stats['users_active'], 'ok'],
        'Suspended/Nonaktif' => [$stats['users_suspended'], 'off'],
        'Aplikasi Klien' => [$stats['clients'], 'bg'],
        'Sesi SSO Aktif' => [$stats['sessions_active'], 'ok'],
        'Admin' => [$stats['admins'], 'bg'],
    ] as $label => [$value, $class])
        <div class="stat-card">
            <div class="stat-card__label">
                <span class="stat-card__dot stat-card__dot--{{ $class }}"></span>
                {{ $label }}
            </div>
            <div class="stat-card__value">{{ $value }}</div>
        </div>
    @endforeach
</div>

<div class="card mt-4">
    <div class="card__head">
        <div>
            <h2 class="card__title">User Terbaru</h2>
            <p class="card__sub">5 akun yang terakhir dibuat.</p>
        </div>
        <a href="{{ route('admin.users.index') }}" class="link-action">Lihat semua</a>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Nama</th>
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="cell-actions">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentUsers as $u)
                    <tr>
                        <td class="cell-strong">{{ $u->name }}</td>
                        <td>{{ $u->email }}</td>
                        <td><span class="badge {{ $u->role === 'admin' ? 'badge--info' : 'badge--neutral' }}">{{ $u->role }}</span></td>
                        <td>
                            @if ($u->status === 'active')
                                <span class="badge badge--success">active</span>
                            @else
                                <span class="badge badge--danger">{{ $u->status }}</span>
                            @endif
                        </td>
                        <td class="cell-actions">
                            <a href="{{ route('admin.users.edit', $u) }}" class="link-action">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="empty">
                                <span class="empty__title">Belum ada user</span>
                                User yang dibuat akan tampil di sini.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <div>
            <h2 class="card__title">Aplikasi Klien Terbaru</h2>
            <p class="card__sub">5 klien OAuth yang terakhir didaftarkan.</p>
        </div>
        <a href="{{ route('admin.clients.index') }}" class="link-action">Lihat semua</a>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Nama</th>
                    <th scope="col">Client ID</th>
                    <th scope="col">Trusted</th>
                    <th scope="col" class="cell-actions">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentClients as $c)
                    <tr>
                        <td class="cell-strong">
                            {{ $c->name }}
                            @if ($c->revoked)
                                <span class="badge badge--danger">revoked</span>
                            @endif
                        </td>
                        <td><code class="code">{{ $c->id }}</code></td>
                        <td>
                            @if ($c->trusted)
                                <span class="badge badge--success">trusted</span>
                            @else
                                <span class="badge badge--neutral">—</span>
                            @endif
                        </td>
                        <td class="cell-actions">
                            <a href="{{ route('admin.clients.edit', $c) }}" class="link-action">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <div class="empty">
                                <span class="empty__title">Belum ada client</span>
                                Daftarkan aplikasi klien untuk memulai integrasi SSO.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
