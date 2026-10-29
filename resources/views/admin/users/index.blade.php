@extends('admin.layouts.admin')
@section('title', 'Kelola User')

@section('admin-content')
@php($generated = session('generated_password'))

@if ($generated)
    <div class="card card--warning mb-4">
        <div class="credential-head">
            <span class="credential-head__icon">@include('partials.icon', ['name' => 'alert'])</span>
            <div>
                <h2 class="card__title">Kredensial baru — tampil sekali</h2>
                <p class="card__sub">
                    WhatsApp gagal terkirim. Simpan atau teruskan data berikut ke user sekarang,
                    atau tekan <strong>Kirim WA</strong> untuk mencoba lagi (password baru akan dibuat).
                </p>
            </div>
        </div>

        <div class="data-list mt-4">
            <div class="data-list__item">
                <span class="data-list__label">Nama</span>
                <span class="data-list__value cell-strong">{{ $generated['name'] }}</span>
            </div>
            <div class="data-list__item">
                <span class="data-list__label">Email</span>
                <span class="data-list__value">{{ $generated['email'] }}</span>
            </div>
            <div class="data-list__item">
                <span class="data-list__label">NIP</span>
                <span class="data-list__value">{{ $generated['nip'] }}</span>
            </div>
            <div class="data-list__item">
                <span class="data-list__label">WhatsApp</span>
                <span class="data-list__value">{{ $generated['whatsapp'] ?? '—' }}</span>
            </div>
            <div class="data-list__item">
                <span class="data-list__label">Password</span>
                <span class="data-list__value">
                    <code id="generated-password" class="code code--highlight">{{ $generated['password'] }}</code>
                    <button type="button"
                            class="btn btn--secondary btn--sm ml-2"
                            onclick="navigator.clipboard.writeText(document.getElementById('generated-password').innerText).then(() => this.innerText = 'Tersalin')">Copy</button>
                </span>
            </div>
        </div>
    </div>
@endif

<div class="page-head">
    <div>
        <h1 class="page-head__title">Kelola User</h1>
        <p class="page-head__sub">Cari, filter, dan kelola akun pengguna SSO.</p>
    </div>
    <div class="page-head__actions">
        <a href="{{ route('admin.users.create') }}" class="btn">+ Tambah User</a>
    </div>
</div>

<div class="card">
    <form method="GET" class="toolbar">
        <input class="input" type="text" name="q" placeholder="Cari nama / email / NIP / WhatsApp..." value="{{ request('q') }}">
        <select class="select" name="role">
            <option value="">Semua Role</option>
            <option value="admin" @selected(request('role') === 'admin')>Admin</option>
            <option value="user" @selected(request('role') === 'user')>User</option>
        </select>
        <select class="select" name="status">
            <option value="">Semua Status</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
        </select>
        <button class="btn" type="submit">Cari</button>
    </form>

    <div class="table-wrap">
        <table class="table table--wide">
            <thead>
                <tr>
                    <th scope="col">Nama</th>
                    <th scope="col">Email</th>
                    <th scope="col">NIP</th>
                    <th scope="col">WhatsApp</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col">Login Terakhir</th>
                    <th scope="col" class="cell-actions">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                    <tr>
                        <td>
                            <span class="cell-strong">{{ $u->name }}</span>
                            @if ($u->must_change_password)
                                <div class="mt-1">
                                    <span class="badge badge--warning">wajib ganti password</span>
                                </div>
                            @endif
                        </td>
                        <td>{{ $u->email }}</td>
                        <td>{{ $u->nip ?? '—' }}</td>
                        <td class="cell-nowrap">
                            {{ $u->whatsappDisplay() ?? '—' }}
                            @if ($u->password_sent_at)
                                <div class="cell-muted" title="Password terakhir dikirim via WhatsApp">WA &middot; {{ $u->password_sent_at->format('d M H:i') }}</div>
                            @endif
                        </td>
                        <td><span class="badge {{ $u->role === 'admin' ? 'badge--info' : 'badge--neutral' }}">{{ $u->role }}</span></td>
                        <td>
                            @if ($u->status === 'active')
                                <span class="badge badge--success">active</span>
                            @else
                                <span class="badge badge--danger">{{ $u->status }}</span>
                            @endif
                        </td>
                        <td class="cell-muted cell-nowrap">{{ $u->last_login_at?->format('d M H:i') ?? '—' }}</td>
                        <td class="cell-actions">
                            <div class="row-actions">
                                <a href="{{ route('admin.users.edit', $u) }}" class="link-action">Edit</a>

                                @if ($u->whatsapp)
                                    <form method="POST" action="{{ route('admin.users.send-password', $u) }}" class="inline-form"
                                           onsubmit="return confirm('Buat password BARU untuk {{ $u->email }} lalu kirim ke WhatsApp {{ $u->whatsappDisplay() }}?')">
                                        @csrf
                                        <button type="submit" class="link-action link-action--success">Kirim WA</button>
                                    </form>
                                @endif

                                @unless ($u->id === auth()->id())
                                    <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="inline-form"
                                           onsubmit="return confirm('Hapus user {{ $u->email }}? Token & sesi terkait juga dicabut.')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="link-action link-action--danger">Hapus</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty">
                                <span class="empty__title">Tidak ada user ditemukan</span>
                                Coba ubah kata kunci atau filter pencarian.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links('pagination.enterprise') }}
    </div>
</div>
@endsection
