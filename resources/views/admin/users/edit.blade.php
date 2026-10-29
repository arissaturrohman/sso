@extends('admin.layouts.admin')
@section('title', 'Edit User')

@section('admin-content')
<div class="page-head">
    <div>
        <h1 class="page-head__title">Edit User</h1>
        <p class="page-head__sub">{{ $user->name }} &middot; {{ $user->email }}</p>
    </div>
    <div class="page-head__actions">
        <a href="{{ route('admin.users.index') }}" class="btn btn--secondary btn--sm">&larr; Kembali</a>
    </div>
</div>

<div class="card card--form">
    <form method="POST" action="{{ route('admin.users.update', $user) }}">
        @csrf @method('PUT')

        <div class="form-group">
            <label class="label" for="name">Nama Lengkap</label>
            <input class="input" id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required>
        </div>

        <div class="form-group">
            <label class="label" for="email">Email</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required>
        </div>

        <div class="form-group">
            <label class="label" for="nip">NIP</label>
            <input class="input" id="nip" type="text" name="nip" value="{{ old('nip', $user->nip) }}" required>
        </div>

        <div class="form-group">
            <label class="label" for="whatsapp">Nomor WhatsApp</label>
            <input class="input" id="whatsapp" type="tel" name="whatsapp" value="{{ old('whatsapp', $user->whatsappDisplay()) }}" placeholder="081234567890">
            <p class="field-help">
                Dipakai untuk mengirim password baru via Fonnte.
                @if ($user->password_sent_at)
                    Terakhir dikirim: {{ $user->password_sent_at->format('d M Y H:i') }}.
                @endif
            </p>
        </div>

        <div class="form-group">
            <label class="label" for="password">Password Baru <span class="hint">(kosongkan jika tidak diubah)</span></label>
            <input class="input" id="password" type="password" name="password">
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="label" for="role">Role</label>
                <select class="select" id="role" name="role" @disabled($user->id === auth()->id())>
                    <option value="user" @selected(old('role', $user->role) === 'user')>User</option>
                    <option value="admin" @selected(old('role', $user->role) === 'admin')>Admin</option>
                </select>
                @if ($user->id === auth()->id())
                    <p class="field-help">Role akun sendiri tidak dapat diubah.</p>
                @endif
            </div>
            <div class="form-group">
                <label class="label" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <option value="active" @selected(old('status', $user->status) === 'active')>Active</option>
                    <option value="suspended" @selected(old('status', $user->status) === 'suspended')>Suspended</option>
                    <option value="inactive" @selected(old('status', $user->status) === 'inactive')>Inactive</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="check" for="must_change_password">
                <input id="must_change_password" type="checkbox" name="must_change_password" value="1"
                       @checked(old('must_change_password', $errors->any() ? 0 : $user->must_change_password))>
                <span class="check__text">Wajib ganti password saat login berikutnya</span>
            </label>
            <p class="field-help">
                User tidak bisa membuka halaman mana pun (termasuk aplikasi klien) sebelum
                mengganti password. Otomatis aktif bila Anda mengisi password baru di atas.
            </p>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Simpan Perubahan</button>
            <a href="{{ route('admin.users.index') }}" class="btn btn--secondary">Batal</a>
        </div>
    </form>

    <hr class="divider">

    <h2 class="card__title">Kirim Password via WhatsApp</h2>
    <p class="field-help mb-4">
        Password baru dibuat otomatis lalu dikirim ke nomor WhatsApp user melalui Fonnte.
        Password lama langsung tidak berlaku.
    </p>

    @if ($user->whatsapp)
        <form method="POST" action="{{ route('admin.users.send-password', $user) }}"
              onsubmit="return confirm('Buat password baru untuk {{ $user->email }} dan kirim ke WhatsApp {{ $user->whatsappDisplay() }}?')">
            @csrf
            <button type="submit" class="btn btn--success">Kirim Password Baru via WhatsApp</button>
        </form>
    @else
        <p class="field-help text-warning">Belum ada nomor WhatsApp. Simpan nomor terlebih dahulu.</p>
    @endif

    <hr class="divider">

    <details class="danger-zone">
        <summary>Zona Bahaya</summary>
        <div class="danger-zone__body">
            <p class="field-help mb-4">Menghapus user akan mencabut seluruh token dan mengakhiri sesi SSO-nya.</p>
            @unless ($user->id === auth()->id())
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                      onsubmit="return confirm('Hapus user {{ $user->email }} secara permanen?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn--danger">Hapus User Ini</button>
                </form>
            @endunless
        </div>
    </details>
</div>
@endsection
