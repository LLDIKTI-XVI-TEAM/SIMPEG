@props(['title' => 'Dashboard', 'description' => 'Sistem Informasi Manajemen Kepegawaian LLDIKTI Wilayah XVI'])

<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <title>{{ $title }} — SIMPEG</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-page font-sans">

<div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }">

    {{-- ================================================================== --}}
    {{-- MOBILE OVERLAY --}}
    {{-- ================================================================== --}}
    <div
        x-show="sidebarOpen"
        x-transition:enter="transition-opacity ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="sidebarOpen = false"
        class="fixed inset-0 z-20 bg-ink/40 lg:hidden"
        style="display: none;"
    ></div>

    {{-- ================================================================== --}}
    {{-- SIDEBAR — stays fixed, scrolls internally --}}
    {{-- ================================================================== --}}
    <aside
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-30 flex h-full w-64 shrink-0 flex-col border-r border-border bg-surface transition-transform duration-200 ease-in-out lg:static lg:translate-x-0 lg:z-auto"
    >
        {{-- Brand --}}
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-border px-5">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-primary shadow-sm">
                <img
                    src="{{ asset('img/dikti16-favicon-blue-150x150.png') }}"
                    alt="LLDIKTI XVI"
                    class="h-full w-full object-cover"
                >
            </div>
            <div class="min-w-0">
                <p class="text-sm font-bold leading-tight text-primary">SIMPEG</p>
                <p class="truncate text-xs leading-tight text-muted">LLDIKTI Wilayah XVI</p>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
            @php
            $menus = [
                ['label' => 'Dashboard',    'route' => 'dashboard'],
                ['label' => 'Data Pegawai', 'route' => 'pegawai.index'],
                ['label' => 'Hari Libur',   'route' => 'hari-libur.index'],
                ['label' => 'Cuti',         'route' => 'cuti.index'],
                ['label' => 'Dokumen',      'route' => 'dokumen.index'],
                ['label' => 'Audit Log',    'route' => 'audit.index'],
                ['label' => 'Pengaturan',   'route' => 'settings.index'],
            ];
            @endphp

            @foreach($menus as $menu)
                @php
                    $routeExists = \Illuminate\Support\Facades\Route::has($menu['route']);
                    $isActive    = $routeExists && request()->routeIs($menu['route'] . '*');
                    $href        = $routeExists ? route($menu['route']) : '#';
                    $itemClass   = $isActive
                        ? 'bg-primary text-white font-semibold'
                        : 'text-muted hover:bg-soft hover:text-ink font-medium';
                @endphp
                <a
                    href="{{ $href }}"
                    class="flex items-center rounded-lg px-4 py-2.5 text-sm transition-colors {{ $itemClass }}"
                >
                    <span>{{ $menu['label'] }}</span>
                </a>
            @endforeach
        </nav>

        {{-- Sidebar Footer — static user info only --}}
        <div class="shrink-0 border-t border-border px-4 py-4">
            <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10">
                    <span class="text-sm font-bold text-primary">
                        {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                    </span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink">
                        {{ auth()->user()->name ?? 'Pengguna' }}
                    </p>
                    <p class="truncate text-xs text-muted">Administrator</p>
                </div>
            </div>
        </div>
    </aside>

    {{-- ================================================================== --}}
    {{-- MAIN COLUMN — scrolls independently --}}
    {{-- ================================================================== --}}
    <div class="flex flex-1 flex-col min-w-0 overflow-hidden">

        {{-- NAVBAR --}}
        <header class="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between border-b border-border bg-surface px-4 lg:px-6">

            {{-- Left: hamburger + page title --}}
            <div class="flex items-center gap-3">
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    id="sidebar-toggle"
                    class="inline-flex items-center justify-center rounded-lg p-2 text-muted transition-colors hover:bg-soft hover:text-ink lg:hidden"
                    aria-label="Toggle sidebar"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <h1 class="text-base font-semibold text-ink lg:text-lg">{{ $title }}</h1>
            </div>

            {{-- Right: notif bell + profile dropdown --}}
            <div class="flex items-center gap-2">

                {{-- Notification Bell --}}
                <div class="relative" x-data="{ notifOpen: false }">
                    <button
                        @click="notifOpen = !notifOpen"
                        id="notif-btn"
                        class="relative rounded-lg p-2 text-muted transition-colors hover:bg-soft"
                        aria-label="Notifikasi"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <span class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-[10px] font-bold text-white">3</span>
                    </button>

                    <div
                        x-show="notifOpen"
                        @click.outside="notifOpen = false"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute right-0 top-full mt-2 w-80 origin-top-right rounded-lg border border-border bg-surface shadow-lg"
                        style="display: none;"
                    >
                        <div class="border-b border-border px-4 py-3">
                            <p class="text-sm font-semibold text-ink">Notifikasi</p>
                        </div>
                        <div class="divide-y divide-border">
                            <div class="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-soft">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-danger"></span>
                                <div>
                                    <p class="text-sm font-medium text-ink">Dokumen kadaluarsa H-30</p>
                                    <p class="mt-0.5 text-xs text-muted font-sans">SK Pengangkatan — Budi Santoso</p>
                                    <span class="mt-1 inline-block rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-semibold text-danger">H-30</span>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-soft">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warning"></span>
                                <div>
                                    <p class="text-sm font-medium text-ink">Masa pensiun mendekat H-60</p>
                                    <p class="mt-0.5 text-xs text-muted font-sans">Siti Rahayu — Februari 2026</p>
                                    <span class="mt-1 inline-block rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-semibold text-warning">H-60</span>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-soft">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-info"></span>
                                <div>
                                    <p class="text-sm font-medium text-ink">Pengajuan cuti baru</p>
                                    <p class="mt-0.5 text-xs text-muted font-sans">Ahmad Fauzi — Cuti tahunan 5 hari</p>
                                    <span class="mt-1 inline-block rounded-full bg-info/10 px-2 py-0.5 text-[10px] font-semibold text-info">Menunggu</span>
                                </div>
                            </div>
                        </div>
                        <div class="border-t border-border px-4 py-3">
                            <a href="#" class="text-xs font-semibold text-primary hover:underline">Lihat semua notifikasi</a>
                        </div>
                    </div>
                </div>

                <div class="h-6 w-px bg-border"></div>

                {{-- Profile Dropdown --}}
                <div class="relative" x-data="{ open: false }">
                    <button
                        @click="open = !open"
                        id="profile-btn"
                        class="flex items-center gap-2.5 rounded-lg border border-border px-3 py-2 transition-colors hover:bg-soft"
                    >
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
                            <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div class="hidden text-left md:block">
                            <p class="text-sm font-semibold leading-tight text-ink font-sans">
                                {{ auth()->user()->name ?? 'Pengguna' }}
                            </p>
                            <p class="text-[11px] leading-tight text-muted font-sans">Administrator</p>
                        </div>
                        <svg class="h-4 w-4 text-muted shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div
                        x-show="open"
                        @click.outside="open = false"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute right-0 top-full mt-2 w-56 origin-top-right overflow-hidden rounded-lg border border-border bg-surface shadow-lg"
                        style="display: none;"
                    >
                        <div class="border-b border-border px-4 py-3">
                            <p class="text-xs font-semibold text-ink font-sans">{{ auth()->user()->name ?? 'Pengguna' }}</p>
                            <p class="mt-0.5 text-xs text-muted font-sans font-mono">{{ auth()->user()->email ?? '' }}</p>
                        </div>
                        <div class="p-1.5 space-y-0.5">
                            <a href="#" id="profile-link" class="flex items-center rounded-lg px-4 py-2 text-sm text-ink transition-colors hover:bg-soft font-sans font-medium">
                                Profil Saya
                            </a>
                            <a href="#" id="settings-link" class="flex items-center rounded-lg px-4 py-2 text-sm text-ink transition-colors hover:bg-soft font-sans font-medium">
                                Pengaturan
                            </a>
                        </div>
                        <div class="border-t border-border p-1.5">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button
                                    type="submit"
                                    id="logout-btn"
                                    class="flex w-full items-center gap-2.5 rounded-lg px-4 py-2 text-sm font-medium text-danger transition-colors hover:bg-danger/10 font-sans"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    Keluar dari Sistem
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </header>

        {{-- FLASH MESSAGES --}}
        @if(session('success') || session('error') || session('warning') || session('info') || session('auth_error'))
        <div class="shrink-0 border-b border-border px-4 py-3 lg:px-6 space-y-2">
            @if(session('success'))
                <div class="flex items-center gap-3 rounded-lg border border-success/20 bg-success/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-sm font-medium text-success font-sans">{{ session('success') }}</p>
                </div>
            @endif
            @if(session('error') || session('auth_error'))
                <div class="flex items-center gap-3 rounded-lg border border-danger/20 bg-danger/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-sm font-medium text-danger font-sans">{{ session('error') ?? session('auth_error') }}</p>
                </div>
            @endif
            @if(session('warning'))
                <div class="flex items-center gap-3 rounded-lg border border-warning/20 bg-warning/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p class="text-sm font-medium text-warning font-sans">{{ session('warning') }}</p>
                </div>
            @endif
            @if(session('info'))
                <div class="flex items-center gap-3 rounded-lg border border-info/20 bg-info/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="text-sm font-medium text-info font-sans">{{ session('info') }}</p>
                </div>
            @endif
        </div>
        @endif

        {{-- PAGE CONTENT --}}
        <main class="flex-1 overflow-y-auto bg-page">
            <div class="mx-auto max-w-7xl px-4 py-6 lg:px-6">
                {{ $slot }}
            </div>
        </main>

    </div>
</div>

@stack('scripts')
</body>
</html>
