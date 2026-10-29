@extends('admin.layouts.admin')
@section('title', 'Tambah User')

@section('admin-content')
<div class="page-head">
    <div>
        <h1 class="page-head__title">Tambah User Baru</h1>
        <p class="page-head__sub">Akun dibuat dengan password otomatis dan dikirim via WhatsApp.</p>
    </div>
    <div class="page-head__actions">
        <a href="{{ route('admin.users.index') }}" class="btn btn--secondary btn--sm">&larr; Kembali</a>
    </div>
</div>

<div class="card card--form">
    <p class="field-help mb-4">
        Password dibuat otomatis dan dikirim ke nomor WhatsApp user melalui gateway
        <strong>Fonnte</strong>. User dapat langsung login memakai email atau NIP, lalu
        <strong>wajib mengganti password</strong> sebelum bisa mengakses halaman lain.
    </p>

    @unless ($whatsappConfigured)
        <div class="alert alert--warning">
            <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
            <div class="alert__body">
                <strong class="alert__title">FONNTE_TOKEN belum diset.</strong>
                Pesan WhatsApp tidak akan terkirim — setelah akun dibuat, password akan ditampilkan
                <strong>sekali</strong> di halaman daftar user agar bisa Anda teruskan manual.
            </div>
        </div>
    @endunless

    <form method="POST" action="{{ route('admin.users.store') }}">
        @csrf

        <div class="form-group">
            <label class="label" for="name">Nama Lengkap</label>
            <input class="input" id="name" type="text" name="name" value="{{ old('name') }}" required>
        </div>

        <div class="form-group">
            <label class="label" for="email">Email</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required>
        </div>

        <div class="form-group">
            <label class="label" for="nip">NIP</label>
            <input class="input" id="nip" type="text" name="nip" value="{{ old('nip') }}" required>
        </div>

        <div class="form-group">
            <label class="label" for="whatsapp">Nomor WhatsApp</label>
            <input class="input" id="whatsapp" type="tel" name="whatsapp" value="{{ old('whatsapp') }}" placeholder="081234567890" required>
            <p class="field-help">Password akun akan dikirim ke nomor ini. Format bebas: 08xx, +628xx, atau 628xx.</p>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="label" for="role">Role</label>
                <select class="select" id="role" name="role">
                    <option value="user" @selected(old('role') === 'user')>User</option>
                    <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label class="label" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                    <option value="suspended" @selected(old('status') === 'suspended')>Suspended</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">Simpan &amp; Kirim WhatsApp</button>
            <a href="{{ route('admin.users.index') }}" class="btn btn--secondary">Batal</a>
        </div>
    </form>
</div>
@endsection
