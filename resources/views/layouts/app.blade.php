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
                ['label' => 'Dashboard',    'route' => 'dashboard',    'icon' => 'squares-2x2'],
                ['label' => 'Data Pegawai', 'route' => 'data-pegawai', 'icon' => 'users'],
                ['label' => 'Hari Libur',   'route' => 'hari-libur',   'icon' => 'calendar-days'],
                ['label' => 'Cuti',         'route' => 'cuti',         'icon' => 'document-text'],
                ['label' => 'Dokumen',      'route' => 'dokumen',      'icon' => 'folder'],
                ['label' => 'Audit Log',    'route' => 'audit-log',    'icon' => 'clipboard-document-list'],
            ];
            $iconMap = [
                'squares-2x2'           => 'squares-2x2',
                'users'                 => 'users',
                'calendar-days'         => 'calendar-days',
                'document-text'         => 'document-text',
                'folder'                => 'folder',
                'clipboard-document-list' => 'clipboard-document-list',
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
                    $iconClass   = $isActive ? 'text-white' : 'text-muted';
                @endphp
                <a
                    href="{{ $href }}"
                    class="flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm transition-colors {{ $itemClass }}"
                >
                    @if($menu['icon'] === 'squares-2x2')
                        <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>
                    @elseif($menu['icon'] === 'users')
                        <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                    @elseif($menu['icon'] === 'calendar-days')
                        <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H18v-.008Zm0 2.25h.008v.008H18V15Z" /></svg>
                    @elseif($menu['icon'] === 'document-text')
                        <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    @elseif($menu['icon'] === 'folder')
                        <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                    @elseif($menu['icon'] === 'clipboard-document-list')
                        <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
                    @endif
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
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
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
                            <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-primary hover:underline">Lihat semua notifikasi</a>
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
                            <svg class="w-5 h-5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        </div>
                        <div class="hidden text-left md:block">
                            <p class="text-sm font-semibold leading-tight text-ink font-sans">
                                {{ auth()->user()->name ?? 'Pengguna' }}
                            </p>
                            <p class="text-[11px] leading-tight text-muted font-sans">Administrator</p>
                        </div>
                        <svg class="w-4 h-4 text-muted shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
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
                            <a href="{{ route('profil') }}" id="profile-link" class="flex items-center gap-2.5 rounded-lg px-4 py-2 text-sm text-ink transition-colors hover:bg-soft font-sans font-medium">
                                {{-- heroicon: user-circle (outline) --}}
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                Profil Saya
                            </a>
                            <a href="{{ route('pengaturan') }}" id="settings-link" class="flex items-center gap-2.5 rounded-lg px-4 py-2 text-sm text-ink transition-colors hover:bg-soft font-sans font-medium font-semibold font-semibold">
                                {{-- heroicon: cog-6-tooth (outline) --}}
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
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
                                    {{-- heroicon: arrow-right-on-rectangle (outline) --}}
                                    <svg class="w-4 h-4 text-danger shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25" />
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
                    <svg class="h-4 w-4 shrink-0 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    <p class="text-sm font-medium text-success font-sans">{{ session('success') }}</p>
                </div>
            @endif
            @if(session('error') || session('auth_error'))
                <div class="flex items-center gap-3 rounded-lg border border-danger/20 bg-danger/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    <p class="text-sm font-medium text-danger font-sans">{{ session('error') ?? session('auth_error') }}</p>
                </div>
            @endif
            @if(session('warning'))
                <div class="flex items-center gap-3 rounded-lg border border-warning/20 bg-warning/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    <p class="text-sm font-medium text-warning font-sans">{{ session('warning') }}</p>
                </div>
            @endif
            @if(session('info'))
                <div class="flex items-center gap-3 rounded-lg border border-info/20 bg-info/10 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-info" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
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
