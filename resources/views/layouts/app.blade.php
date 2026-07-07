@props(['title' => 'Dashboard', 'description' => 'Sistem Informasi Manajemen Kepegawaian LLDIKTI Wilayah XVI'])

<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — SIMPEG</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="{{ asset('img/dikti16-favicon-blue-150x150.png') }}">

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
            $activeRole = auth()->user()?->role ?? 'pegawai';
            
            // Menu terlarang/dikunci untuk masing-masing role
            $lockedMenus = [
                'super_admin' => [],
                'admin_kepegawaian' => [
                    'pengaturan',
                    'user-management',
                    'rbac',
                    'data-master',
                    'hari-libur',
                    'ews.config',
                ],
                'pimpinan' => [
                    'audit-log',
                    'pengaturan',
                    'user-management',
                    'rbac',
                    'data-nonaktif',
                    'ews.config',
                ],
                'atasan_langsung' => [
                    'pegawai.import',
                    'hari-libur',
                    'dokumen',
                    'audit-log',
                    'pengaturan',
                    'user-management',
                    'rbac',
                    'data-nonaktif',
                    'data-master',
                    'laporan',
                    'laporan.pegawai',
                    'laporan.cuti',
                    'ews.config',
                ],
                'pegawai' => [
                    'data-pegawai',
                    'pegawai.import',
                    'data-nonaktif',
                    'dokumen',
                    'cuti.rekap',
                    'ews',
                    'ews.config',
                    'laporan',
                    'laporan.pegawai',
                    'laporan.cuti',
                    'user-management',
                    'rbac',
                    'data-master',
                    'hari-libur',
                    'pengaturan',
                    'audit-log',
                ],
            ];

            $myLockedMenus = $lockedMenus[$activeRole] ?? [];

            $menuGroups = [
                [
                    'group' => '',
                    'items' => [
                        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'squares-2x2'],
                    ]
                ],
                [
                    'group' => 'Kepegawaian',
                    'items' => [
                        ['label' => 'Data Pegawai', 'route' => 'data-pegawai', 'icon' => 'users'],
                        ['label' => 'Data Nonaktif', 'route' => 'data-nonaktif', 'icon' => 'user-minus'],
                        ['label' => 'Dokumen & SK', 'route' => 'dokumen', 'icon' => 'folder-open'],
                        ['label' => 'Export Pegawai', 'route' => 'laporan.pegawai', 'icon' => 'document-arrow-up'],
                    ]
                ],
                [
                    'group' => 'Cuti',
                    'items' => [
                        ['label' => 'Pengajuan Cuti', 'route' => 'cuti', 'icon' => 'calendar'],
                        ['label' => 'Rekap Cuti', 'route' => 'cuti.rekap', 'icon' => 'document-text'],
                        ['label' => 'Export Cuti', 'route' => 'laporan.cuti', 'icon' => 'document-arrow-down'],
                    ]
                ],
                [
                    'group' => 'EWS & Notifikasi',
                    'items' => [
                        ['label' => 'EWS Aktif', 'route' => 'ews', 'icon' => 'exclamation-triangle'],
                        ['label' => 'Notifikasi', 'route' => 'notifications.index', 'icon' => 'bell'],
                        ['label' => 'Konfigurasi EWS', 'route' => 'ews.config', 'icon' => 'cog-6-tooth'],
                    ]
                ],

                [
                    'group' => 'Administrasi Sistem',
                    'items' => [
                        ['label' => 'Kelola Akses User', 'route' => 'user-management', 'icon' => 'shield-check'],
                        ['label' => 'Role & Permission', 'route' => 'rbac', 'icon' => 'key'],
                        ['label' => 'Data Master', 'route' => 'data-master', 'icon' => 'table-cells'],
                        ['label' => 'Hari Libur', 'route' => 'hari-libur', 'icon' => 'calendar-days'],
                        ['label' => 'Pengaturan Sistem', 'route' => 'pengaturan', 'icon' => 'cog-6-tooth'],
                        ['label' => 'Audit Log', 'route' => 'audit-log', 'icon' => 'clipboard-document-list'],
                    ]
                ]
            ];

            $allMenuRoutes = [];
            foreach ($menuGroups as $g) {
                foreach ($g['items'] as $item) {
                    $allMenuRoutes[] = $item['route'];
                }
            }
            @endphp

            @foreach($menuGroups as $group)
                @if(!empty($group['group']))
                    <div class="px-4 pt-4 pb-1.5 text-[10px] font-bold uppercase tracking-wider text-muted/60 font-sans">
                        {{ $group['group'] }}
                    </div>
                @endif
                <div class="space-y-1">
                    @foreach($group['items'] as $menu)
                        @php
                            $routeExists = \Illuminate\Support\Facades\Route::has($menu['route']);
                            $isLocked    = in_array($menu['route'], $myLockedMenus);

                            if (!$routeExists || $isLocked) {
                                continue;
                            }

                            $isActive = false;
                            $currentRoute = request()->route() ? request()->route()->getName() : null;
                            if ($currentRoute === $menu['route']) {
                                $isActive = true;
                            } elseif ($currentRoute && str_starts_with($currentRoute, $menu['route'] . '.')) {
                                $hasMoreSpecific = false;
                                foreach ($allMenuRoutes as $otherRoute) {
                                    if ($otherRoute !== $menu['route'] &&
                                        str_starts_with($otherRoute, $menu['route'] . '.') &&
                                        ($currentRoute === $otherRoute || str_starts_with($currentRoute, $otherRoute . '.'))) {
                                        $hasMoreSpecific = true;
                                        break;
                                    }
                                }
                                if (!$hasMoreSpecific) {
                                    $isActive = true;
                                }
                            }

                            $href = route($menu['route']);
                            $itemClass = $isActive
                                ? 'bg-primary text-white font-semibold'
                                : 'text-muted hover:bg-soft hover:text-ink font-medium';
                            $iconClass = $isActive ? 'text-white' : 'text-muted';
                        @endphp
                        <a
                            href="{{ $href }}"
                            class="flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm transition-colors {{ $itemClass }}"
                        >
                            @if($menu['icon'] === 'squares-2x2')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>
                            @elseif($menu['icon'] === 'users')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                            @elseif($menu['icon'] === 'arrow-up-tray')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                            @elseif($menu['icon'] === 'calendar')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                            @elseif($menu['icon'] === 'check-badge')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" /></svg>
                            @elseif($menu['icon'] === 'document-text')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                            @elseif($menu['icon'] === 'exclamation-triangle')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            @elseif($menu['icon'] === 'bell')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                            @elseif($menu['icon'] === 'folder-open')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.008 1.24l.885 1.77a2.25 2.25 0 0 0 2.007 1.24h1.98a2.25 2.25 0 0 0 2.007-1.24l.885-1.77a2.25 2.25 0 0 1 2.007-1.24h3.86m-18 0h18a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v4.5m18 0V17.25a2.25 2.25 0 0 1-2.25 2.25H4.5a2.25 2.25 0 0 1-2.25-2.25V13.5" /></svg>
                            @elseif($menu['icon'] === 'document-arrow-up')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5h10.5a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 16.5 4.5H7.5A2.25 2.25 0 0 0 5.25 6.75v10.5A2.25 2.25 0 0 0 6.75 19.5Z" /></svg>
                            @elseif($menu['icon'] === 'document-arrow-down')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75v6.75m0 0-3-3m3 3 3-3M6.75 19.5h10.5a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 16.5 4.5H7.5A2.25 2.25 0 0 0 5.25 6.75v10.5A2.25 2.25 0 0 0 6.75 19.5Z" /></svg>
                            @elseif($menu['icon'] === 'document-chart-bar')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12h9m-9 3H12m1.5-4.5H18" /></svg>
                            @elseif($menu['icon'] === 'clipboard-document-list')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
                            @elseif($menu['icon'] === 'calendar-days')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H18v-.008Zm0 2.25h.008v.008H18V15Z" /></svg>
                            @elseif($menu['icon'] === 'cog-6-tooth')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            @elseif($menu['icon'] === 'shield-check')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                            @elseif($menu['icon'] === 'key')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                            @elseif($menu['icon'] === 'table-cells')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v16.5A2.25 2.25 0 0 0 6 21.75h12a2.25 2.25 0 0 0 2.25-2.25V3m-16.5 0h16.5M3.75 3h16.5M3.75 9h16.5M3.75 15h16.5M9.75 3v18M15.75 3v18" /></svg>
                            @elseif($menu['icon'] === 'adjustments-horizontal')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 13.5V3.75m0 9.75a1.5 1.5 0 0 1 0 3m0-3a1.5 1.5 0 0 0 0 3m0 3.75V16.5m12-3V3.75m0 9.75a1.5 1.5 0 1 1 0 3m0-3a1.5 1.5 0 1 0 0 3m0 3.75V16.5m-6-9V3.75m0 3.75a1.5 1.5 0 1 1 0 3m0-3a1.5 1.5 0 1 0 0 3m0 9.75V10.5" /></svg>
                            @elseif($menu['icon'] === 'user-minus')
                                <svg class="w-5 h-5 shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235A10.19 10.19 0 0 1 12.75 15c2.015 0 3.907.585 5.5 1.59m-14.25 2.645A9.903 9.903 0 0 1 12.75 18a9.903 9.903 0 0 1 6.002 2.235" /></svg>
                            @endif
                            <span>{{ $menu['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach

        </nav>

        {{-- Sidebar Footer — role dynamic info --}}
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
                    <p class="truncate text-xs text-muted">{{ ucwords(str_replace('_', ' ', $activeRole)) }}</p>
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

                <x-layouts.notification-bell />

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
                            <p class="text-[11px] leading-tight text-muted font-sans">{{ ucwords(str_replace('_', ' ', $activeRole)) }}</p>
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
                            @if($activeRole === 'super_admin')
                                <a href="{{ route('pengaturan') }}" id="settings-link" class="flex items-center gap-2.5 rounded-lg px-4 py-2 text-sm text-ink transition-colors hover:bg-soft font-sans font-medium font-semibold">
                                    {{-- heroicon: cog-6-tooth (outline) --}}
                                    <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                    Pengaturan
                                </a>
                            @endif
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
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" x-transition.opacity.duration.500ms class="shrink-0 border-b border-border px-4 py-3 lg:px-6 space-y-2">
            @if(session('success'))
                <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
            @endif
            @if(session('error') || session('auth_error'))
                <x-ui.alert variant="danger">{{ session('error') ?? session('auth_error') }}</x-ui.alert>
            @endif
            @if(session('warning'))
                <x-ui.alert variant="warning">{{ session('warning') }}</x-ui.alert>
            @endif
            @if(session('info'))
                <x-ui.alert variant="info">{{ session('info') }}</x-ui.alert>
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
