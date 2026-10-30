<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Meta SEO lengkap (title, description, canonical, robots, OG, JSON-LD)
         dihitung per-route oleh App\Http\Middleware\SeoMeta --}}
    @include('partials.seo-head')
    @php
        $assetVersion = file_exists(public_path('css/app.css')) ? filemtime(public_path('css/app.css')) : '1';
    @endphp
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ $assetVersion }}">
</head>
<body>

<a class="skip-link" href="#main">Lewati ke konten</a>

<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="{{ auth()->check() ? route('dashboard') : route('home') }}">
            <span class="brand__mark">@include('partials.icon', ['name' => 'logo'])</span>
            <span class="brand__text">{{ config('app.name') }}</span>
            <span class="badge badge--info brand__badge">IdP</span>
        </a>

        @auth
            <button class="menu-toggle" type="button" data-menu-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Buka menu navigasi">
                @include('partials.icon', ['name' => 'menu'])
            </button>
        @endauth

        <div class="header-spacer"></div>

        <div class="header-actions">
            @auth
                <div class="user-chip">
                    <span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    <span class="user-chip__meta">
                        <span class="user-chip__name">
                            {{ auth()->user()->name }}
                            <span class="badge {{ auth()->user()->isAdmin() ? 'badge--success' : 'badge--neutral' }}">{{ auth()->user()->role }}</span>
                        </span>
                        <span class="user-chip__email">{{ auth()->user()->email }}</span>
                    </span>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="logout-form">
                    @csrf
                    <button type="submit" class="btn btn--secondary btn--sm" aria-label="Logout">
                        @include('partials.icon', ['name' => 'logout'])
                        <span class="btn__label">Logout</span>
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn btn--sm">Masuk</a>
            @endauth
        </div>
    </div>
</header>

@auth
    @php
        $navMain = [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'pattern' => 'dashboard', 'icon' => 'home'],
            ['label' => 'Perangkat & Sesi', 'route' => 'sessions.index', 'pattern' => 'sessions.*', 'icon' => 'devices'],
            ['label' => 'Ubah Password', 'route' => 'password.change', 'pattern' => 'password.change*', 'icon' => 'key'],
        ];
        $navAdmin = [
            ['label' => 'Dashboard Admin', 'route' => 'admin.dashboard', 'pattern' => 'admin.dashboard', 'icon' => 'chart'],
            ['label' => 'Kelola User', 'route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'icon' => 'users'],
            ['label' => 'Kelola Aplikasi Klien', 'route' => 'admin.clients.index', 'pattern' => 'admin.clients.*', 'icon' => 'plug'],
        ];
    @endphp

    <div class="app-shell">
        <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
            <div class="sidebar__head">
                <span class="sidebar__title">Menu</span>
                <button class="menu-toggle" type="button" data-menu-close aria-label="Tutup menu navigasi">
                    @include('partials.icon', ['name' => 'close'])
                </button>
            </div>

            <nav>
                <div class="nav-section">
                    <div class="nav-label">Menu</div>
                    @foreach ($navMain as $item)
                        <a href="{{ route($item['route']) }}"
                           class="nav-link {{ request()->routeIs($item['pattern']) ? 'active' : '' }}"
                           @if (request()->routeIs($item['pattern'])) aria-current="page" @endif>
                            @include('partials.icon', ['name' => $item['icon']]) {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>

                @if (auth()->user()->isAdmin())
                    <div class="nav-section">
                        <div class="nav-label">Manajemen</div>
                        @foreach ($navAdmin as $item)
                            <a href="{{ route($item['route']) }}"
                               class="nav-link {{ request()->routeIs($item['pattern']) ? 'active' : '' }}"
                               @if (request()->routeIs($item['pattern'])) aria-current="page" @endif>
                                @include('partials.icon', ['name' => $item['icon']]) {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </nav>

            <div class="sidebar__foot">
                OAuth 2.0 / OpenID Connect<br>
                Identity Provider
            </div>
        </aside>

        <div class="drawer-overlay" data-drawer-overlay aria-hidden="true"></div>

        <main class="main-content" id="main">
            @yield('content')
        </main>
    </div>
@else
    <main class="main-content main-content--guest" id="main">
        @yield('content')
    </main>
@endauth

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>&copy; {{ date('Y') }} {{ config('app.name') }}</span>
        <nav class="site-footer__links" aria-label="Tautan situs">
            <a href="{{ url('/') }}">Beranda</a>
            <a href="{{ route('login') }}">Login Terpadu</a>
        </nav>
        <span>OAuth 2.0 &middot; OpenID Connect Identity Provider</span>
    </div>
</footer>

<script>
(function () {
    var body = document.body;
    var toggle = document.querySelector('[data-menu-toggle]');
    var sidebar = document.getElementById('sidebar');
    var closeBtn = document.querySelector('[data-menu-close]');
    var overlay = document.querySelector('[data-drawer-overlay]');

    if (!toggle || !sidebar) {
        return;
    }

    var lastFocused = null;

    function setOpen(open, restoreFocus) {
        if (open) {
            lastFocused = document.activeElement;
        }

        body.classList.toggle('drawer-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            if (closeBtn) {
                closeBtn.focus();
            }
        } else if (restoreFocus && lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    toggle.addEventListener('click', function () {
        setOpen(!body.classList.contains('drawer-open'), false);
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            setOpen(false, true);
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            setOpen(false, true);
        });
    }

    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 991) {
                setOpen(false, false);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && body.classList.contains('drawer-open')) {
            setOpen(false, true);
        }
    });

    var desktop = window.matchMedia('(min-width: 992px)');
    var handleViewportChange = function (event) {
        if (event.matches) {
            body.classList.remove('drawer-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    };

    if (typeof desktop.addEventListener === 'function') {
        desktop.addEventListener('change', handleViewportChange);
    } else if (typeof desktop.addListener === 'function') {
        desktop.addListener(handleViewportChange);
    }
})();
</script>
</body>
</html>
