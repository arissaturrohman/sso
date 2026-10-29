@extends('layouts.app')

@section('title', $title ?? 'Admin')

@section('content')
<div class="page">
    @if (session('status'))
        <div class="alert alert--success" role="status">
            <span class="alert__icon">@include('partials.icon', ['name' => 'check'])</span>
            <div class="alert__body">{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert--danger" role="alert">
            <span class="alert__icon">@include('partials.icon', ['name' => 'alert'])</span>
            <div class="alert__body">
                <strong class="alert__title">Periksa kembali data yang Anda masukkan.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @yield('admin-content')
</div>
@endsection
