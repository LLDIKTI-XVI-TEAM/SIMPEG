<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description ?? 'Sistem Informasi Manajemen Kepegawaian LLDIKTI Wilayah XVI' }}">
    <title>{{ $title ?? 'Dashboard' }} — SIMPEG</title>

    <!-- Poppins Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-page font-sans">

<div class="flex min-h-screen" x-data="{ sidebarOpen: false }">

    {{-- ============================================================ --}}
    {{-- MOBILE OVERLAY --}}
    {{-- ============================================================ --}}
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

    {{-- ============================================================ --}}
    {{-- SIDEBAR --}}
    {{-- ============================================================ --}}
    <aside
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-surface border-r border-border transition-transform duration-200 ease-in-out lg:static lg:translate-x-0 lg:z-auto"
    >
        {{-- Brand --}}
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-border px-6">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary shadow-sm">
                <span class="text-sm font-bold text-white">S</span>
            </div>
            <div>
                <p class="text-sm font-bold text-primary leading-tight">SIMPEG</p>
                <p class="text-xs text-muted leading-tight">Kepegawaian</p>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 overflow-y-auto px-3 py-3.5 space-y-0.5">
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
                    try {
                        $routeExists = \Route::has($menu['route']);
                    } catch (\Exception $e) {
                        $routeExists = false;
                    }
                    $isActive = $routeExists && request()->routeIs($menu['route'] . '*');
                    $href = $routeExists ? route($menu['route']) : '#';
                    $itemClass = $isActive
                        ? 'bg-soft text-primary font-semibold border-l-4 border-primary pl-3 pr-4 rounded-r-lg'
                        : 'text-muted hover:bg-soft hover:text-ink font-medium px-4 rounded-lg';
                @endphp
                <a href="{{ $href }}"
                   class="flex items-center py-1.5 text-sm transition-colors {{ $itemClass }}">
                    <span>{{ $menu['label'] }}</span>
                </a>
            @endforeach
        </nav>

        {{-- User Footer --}}
        <div class="shrink-0 border-t border-border px-4 py-4">
            <div class="flex items-center gap-3 rounded-lg px-3 py-2">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                    <span class="text-xs font-bold text-primary">
                        {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                    </span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink">{{ auth()->user()->name ?? 'Pengguna' }}</p>
                    <p class="truncate text-xs text-muted font-mono">{{ auth()->user()->email ?? '' }}</p>
                </div>
            </div>
        </div>
    </aside>

    {{-- ============================================================ --}}
    {{-- MAIN COLUMN --}}
    {{-- ============================================================ --}}
    <div class="flex flex-1 flex-col min-w-0">

        {{-- NAVBAR --}}
        <header class="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between border-b border-border bg-surface px-4 lg:px-6">

            {{-- Kiri: Hamburger + Page Title --}}
            <div class="flex items-center gap-3">
                {{-- Hamburger Mobile --}}
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
                <div class="min-w-0">
                    <h1 class="text-sm font-bold text-ink lg:text-base leading-tight">
                        {{ $title ?? 'Dashboard' }}
                    </h1>
                    <p class="text-[10px] text-muted leading-tight font-normal mt-0.5">
                        {{ $subtitle ?? 'Sistem Informasi Kepegawaian Wilayah XVI' }}
                    </p>
                </div>
            </div>

            {{-- Kanan: Notif + User + Logout --}}
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
                        @php $unreadCount = 3; @endphp
                        @if($unreadCount > 0)
                            <span class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-xs font-bold text-white">
                                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                            </span>
                        @endif
                    </button>

                    {{-- Dropdown Notif --}}
                    <div
                        x-show="notifOpen"
                        @click.outside="notifOpen = false"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute right-0 top-full mt-2 w-80 origin-top-right rounded-lg border border-border bg-surface shadow-md"
                        style="display:none;"
                    >
                        <div class="border-b border-border px-4 py-3">
                            <p class="text-sm font-semibold text-ink">Notifikasi</p>
                        </div>
                        <div class="divide-y divide-border">
                            <div class="px-4 py-3 hover:bg-soft transition-colors">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-2 w-2 shrink-0 rounded-full bg-danger"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">Dokumen kadaluarsa H-30</p>
                                        <p class="text-xs text-muted mt-0.5 font-sans">SK Pengangkatan — Budi Santoso</p>
                                        <span class="mt-1 inline-block rounded-full bg-danger/10 text-danger px-2 py-0.5 text-xs font-semibold">H-30</span>
                                    </div>
                                </div>
                            </div>
                            <div class="px-4 py-3 hover:bg-soft transition-colors">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-2 w-2 shrink-0 rounded-full bg-warning"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">Masa pensiun mendekat H-60</p>
                                        <p class="text-xs text-muted mt-0.5 font-sans">Siti Rahayu — Pensiun Februari 2026</p>
                                        <span class="mt-1 inline-block rounded-full bg-warning/10 text-warning px-2 py-0.5 text-xs font-semibold">H-60</span>
                                    </div>
                                </div>
                            </div>
                            <div class="px-4 py-3 hover:bg-soft transition-colors">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-2 w-2 shrink-0 rounded-full bg-info"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">Pengajuan cuti baru</p>
                                        <p class="text-xs text-muted mt-0.5 font-sans">Ahmad Fauzi — Cuti tahunan 5 hari</p>
                                        <span class="mt-1 inline-block rounded-full bg-info/10 text-info px-2 py-0.5 text-xs font-semibold">Menunggu Persetujuan</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="border-t border-border px-4 py-3">
                            <a href="#" class="text-xs font-semibold text-primary hover:underline">Lihat semua notifikasi</a>
                        </div>
                    </div>
                </div>

                {{-- Divider --}}
                <div class="h-6 w-px bg-border"></div>

                {{-- User Profile --}}
                <div class="hidden items-center gap-2 sm:flex cursor-pointer">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 shrink-0">
                        <svg class="h-4.5 w-4.5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <div class="hidden md:block">
                        <p class="text-sm font-semibold text-ink leading-tight font-sans">{{ auth()->user()->name ?? 'Pengguna' }}</p>
                        <p class="text-xs text-muted leading-tight font-sans">Administrator</p>
                    </div>
                    <svg class="h-4 w-4 text-muted shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>

                {{-- Logout --}}
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        id="logout-btn"
                        class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium text-muted transition-colors hover:bg-soft hover:text-danger font-sans"
                    >
                        <svg class="h-4 w-4 sm:mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        <span class="hidden sm:inline">Keluar</span>
                    </button>
                </form>

            </div>
        </header>

        {{-- FLASH MESSAGES --}}
        @if(session('success') || session('error') || session('warning') || session('info') || session('auth_error'))
        <div class="border-b border-border px-4 py-3 lg:px-6">
            @if(session('success'))
                <div class="flex items-center gap-3 rounded-lg border border-success/20 bg-success/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm font-medium text-success font-sans">{{ session('success') }}</p>
                </div>
            @endif
            @if(session('error') || session('auth_error'))
                <div class="flex items-center gap-3 rounded-lg border border-danger/20 bg-danger/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm font-medium text-danger font-sans">{{ session('error') ?? session('auth_error') }}</p>
                </div>
            @endif
            @if(session('warning'))
                <div class="flex items-center gap-3 rounded-lg border border-warning/20 bg-warning/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-sm font-medium text-warning font-sans">{{ session('warning') }}</p>
                </div>
            @endif
            @if(session('info'))
                <div class="flex items-center gap-3 rounded-lg border border-info/20 bg-info/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm font-medium text-info font-sans">{{ session('info') }}</p>
                </div>
            @endif
        </div>
        @endif

        {{-- PAGE CONTENT --}}
        <main class="flex-1 overflow-y-auto bg-page">
            <div class="mx-auto max-w-7xl px-4 py-6 lg:px-6">
                @yield('content')
            </div>
        </main>

    </div>{{-- end main column --}}
</div>{{-- end app shell --}}

@stack('scripts')
</body>
</html>
