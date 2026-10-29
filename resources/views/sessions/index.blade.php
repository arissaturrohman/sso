@extends('layouts.app')
@section('title', 'Perangkat & Sesi Aktif')

@section('content')
<div class="page">
    <div class="page-head">
        <div>
            <h1 class="page-head__title">Perangkat &amp; Sesi</h1>
            <p class="page-head__sub">Kelola sesi portal dan aplikasi yang terhubung ke akun Anda.</p>
        </div>
    </div>

    <div class="card">
        @if (session('status'))
            <div class="alert alert--success" role="status">
                <span class="alert__icon">@include('partials.icon', ['name' => 'check'])</span>
                <div class="alert__body">{{ session('status') }}</div>
            </div>
        @endif

        <div class="alert alert--info">
            <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
            <div class="alert__body">
                Sesi IdP = perangkat Anda pada portal ini. Sesi Client = aplikasi yang terhubung ke akun Anda via SSO.
                Memutus sesi client akan mencabut token-nya dan memberi tahu aplikasi tersebut.
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Aplikasi / Perangkat</th>
                        <th scope="col">IP</th>
                        <th scope="col">Terakhir aktif</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="cell-actions">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $s)
                        <tr>
                            <td>
                                <span class="cell-strong">{{ $s->client_name }}</span>
                                <div class="cell-muted">{{ $s->device_name }} &middot; {{ Str::limit($s->user_agent, 60) }}</div>
                            </td>
                            <td class="cell-nowrap">{{ $s->ip_address ?? '—' }}</td>
                            <td class="cell-nowrap">{{ $s->last_activity?->format('d M H:i') ?? '—' }}</td>
                            <td>
                                @if ($s->active)
                                    <span class="badge badge--success">Aktif</span>
                                @else
                                    <span class="badge badge--danger">Berakhir ({{ $s->logged_out_at?->format('d M H:i') }})</span>
                                @endif
                            </td>
                            <td class="cell-actions">
                                @if ($s->active && ! $s->is_idp)
                                    <form method="POST" action="{{ route('sessions.destroy', $s->id) }}" class="inline-form">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn--danger-outline btn--sm" onclick="return confirm('Putus sesi ini?')">Putus</button>
                                    </form>
                                @elseif ($s->active && $s->is_idp)
                                    <span class="cell-muted">perangkat ini</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <span class="empty__title">Belum ada sesi</span>
                                    Sesi akan muncul setelah Anda masuk melalui portal atau aplikasi klien.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
