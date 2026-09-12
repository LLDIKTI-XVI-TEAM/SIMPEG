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
    @livewireStyles
    @stack('head')
</head>
<body class="h-full bg-page font-sans overflow-hidden">

<div
    class="flex h-screen overflow-hidden"
    x-data="{
        sidebarOpen: false,
        isMobileNavigation: window.innerWidth < 1024,
        openSidebar() {
            this.sidebarOpen = true;
            this.$nextTick(() => this.$refs.sidebarNav?.querySelector('a[href]')?.focus());
        },
        closeSidebar({ restoreFocus = true } = {}) {
            if (!this.sidebarOpen) return;

            this.sidebarOpen = false;

            if (restoreFocus) {
                this.$nextTick(() => this.$refs.sidebarToggle?.focus());
            }
        }
    }"
    @keydown.escape.window="closeSidebar()"
    @resize.window="isMobileNavigation = window.innerWidth < 1024; if (!isMobileNavigation) sidebarOpen = false"
>

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
        @click="closeSidebar()"
        class="fixed inset-0 z-20 bg-ink/40 lg:hidden"
        style="display: none;"
    ></div>

    {{-- ================================================================== --}}
    {{-- SIDEBAR — stays fixed, scrolls internally --}}
    {{-- ================================================================== --}}
    <aside
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        :inert="isMobileNavigation && !sidebarOpen"
        :aria-hidden="(isMobileNavigation && !sidebarOpen).toString()"
        class="fixed inset-y-0 left-0 z-30 flex h-full w-64 shrink-0 flex-col border-r border-border bg-surface transition-transform duration-200 ease-in-out lg:static lg:z-auto"
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
        <nav id="sidebar-nav" x-ref="sidebarNav" aria-label="Navigasi utama" class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
            @php
            $currentUser = auth()->user();
            $authUser = $currentUser;
            $activeRole = ($authUser && method_exists($authUser, 'getEffectiveRole'))
                ? ($authUser->getEffectiveRole() ?? 'pegawai')
                : ($authUser?->role ?? 'pegawai');
            $canAdministerLeaveBalance = ($layoutCapabilities['cuti.balance.reconcile'] ?? false)
                || ($layoutCapabilities['cuti.manual.manage'] ?? false);
            $canViewEmployeeStatistics = $layoutCapabilities['employees.read'] ?? false;
            $canReadAudit = $layoutCapabilities['audit_logs.read'] ?? false;
            $canMonitorLeaves = $layoutCapabilities['cuti.read_all'] ?? false;
            // Lifecycle dijaga middleware; menu antrean tidak membaca assignment atau counter dari Blade.
            $canUseLeaveQueue = $authUser?->employee_id !== null
                && in_array($activeRole, ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'], true);
            $canManageLeaveCancellations = $layoutCapabilities['cuti.cancellation.manage'] ?? false;

            // Menu terlarang/dikunci untuk masing-masing role
            $lockedMenus = [
                'super_admin' => [],
                'admin_kepegawaian' => [
                    'user-management',
                    'rbac',
                    'data-master',
                    'hari-libur',
                    'ews.config',
                ],
                'pimpinan' => [
                    'user-management',
                    'rbac',
                    'ews.config',
                ],
                'kepala_bagian' => [
                    'data-pegawai',
                    'pegawai.import',
                    'hari-libur',
                    'dokumen',
                    'user-management',
                    'rbac',
                    'data-master',
                    'laporan',
                    'laporan.pegawai',
                    'cuti.laporan',
                    'cuti.rekap',
                    'ews',
                    'ews.config',
                ],

                'pegawai' => [
                    'data-pegawai',
                    'pegawai.import',
                    'dokumen',
                    'cuti.rekap',
                    'ews',
                    'ews.config',
                    'laporan',
                    'laporan.pegawai',
                    'cuti.laporan',
                    'user-management',
                    'rbac',
                    'data-master',
                    'hari-libur',
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
                    'items' => array_filter([
                        ['label' => 'Data Pegawai', 'route' => 'data-pegawai', 'icon' => 'users', 'permissions' => ['employees.read']],
                        $activeRole === 'kepala_bagian' ? ['label' => 'Daftar Bawahan', 'route' => 'kepala-bagian.bawahan.index', 'icon' => 'users'] : null,
                        ['label' => 'Dokumen & SK', 'route' => 'dokumen', 'icon' => 'folder-open', 'permissions' => ['employees.read']],
                        ['label' => 'Export Pegawai', 'route' => 'laporan.pegawai', 'icon' => 'document-arrow-up'],
                        ['label' => 'Statistik Kepegawaian', 'route' => 'reporting.employee-statistics', 'icon' => 'chart-bar', 'permissions' => ['employees.read']],
                    ])
                ],
                [
                    'group' => 'Cuti',
                    'items' => array_filter([
                        $activeRole === 'kepala_bagian' && $canMonitorLeaves ? ['label' => 'Cuti Bawahan', 'route' => 'kepala-bagian.cuti.index', 'icon' => 'check-badge'] : null,
                        $canMonitorLeaves ? ['label' => 'Monitoring Cuti', 'route' => 'cuti', 'icon' => 'calendar'] : null,
                        ['label' => 'Pengajuan Cuti Saya', 'route' => 'cuti', 'parameters' => ['scope' => 'own'], 'icon' => 'calendar'],
                        ['label' => 'Rekap Cuti', 'route' => 'cuti.rekap', 'icon' => 'document-text'],
                        ['label' => 'Administrasi Pemakaian Cuti', 'route' => 'cuti.saldo.administrasi', 'icon' => 'adjustments-horizontal', 'permissions' => ['cuti.balance.reconcile', 'cuti.manual.manage']],
                        ['label' => 'Export Cuti', 'route' => 'cuti.laporan', 'icon' => 'document-arrow-down'],
                        $activeRole === 'super_admin' && ($layoutCapabilities['cuti.configure'] ?? false)
                            ? ['label' => 'Konfigurasi Approval Cuti', 'route' => 'cuti.config', 'icon' => 'cog-6-tooth', 'permissions' => ['cuti.configure']]
                            : null,
                    ])
                ],
                [
                    'group' => 'EWS & Notifikasi',
                    'items' => array_filter([
                        $activeRole === 'kepala_bagian' ? ['label' => 'EWS Bawahan', 'route' => 'kepala-bagian.ews.index', 'icon' => 'exclamation-triangle'] : null,
                        ['label' => 'Notifikasi', 'route' => 'notifications.index', 'icon' => 'bell', 'permissions' => ['notifications.read']],
                        $activeRole === 'pegawai' ? ['label' => 'EWS Saya', 'route' => 'ews.saya', 'icon' => 'exclamation-triangle'] : null,
                        ['label' => 'EWS Aktif', 'route' => 'ews', 'icon' => 'exclamation-triangle'],
                        ['label' => 'Konfigurasi EWS', 'route' => 'ews.config', 'icon' => 'cog-6-tooth'],
                        $activeRole === 'super_admin' ? ['label' => 'Channel Notifikasi', 'route' => 'data-master.channel-notifikasi.index', 'icon' => 'adjustments-horizontal'] : null,
                    ])
                ],
                [
                    'group' => 'Administrasi Sistem',
                    'items' => [
                        ['label' => 'Kelola Akses User', 'route' => 'user-management', 'icon' => 'shield-check'],
                        ['label' => 'Role & Permission', 'route' => 'rbac', 'icon' => 'key'],
                        ['label' => 'Data Master', 'route' => 'data-master', 'icon' => 'table-cells'],
                        ['label' => 'Hari Libur', 'route' => 'hari-libur', 'icon' => 'calendar-days', 'permissions' => ['hari_libur.read']],
                        ['label' => 'Audit Log', 'route' => 'audit-log', 'icon' => 'clipboard-document-list', 'permissions' => ['audit_logs.read']],
                    ]
                ]
            ];

            if ($activeRole === 'pimpinan') {
                $menuGroups = [
                    [
                        'group' => '',
                        'items' => [
                            ['label' => 'Dashboard', 'route' => 'pimpinan.dashboard', 'icon' => 'squares-2x2'],
                        ]
                    ],
                    [
                        'group' => 'Kepegawaian',
                        'items' => [
                            ['label' => 'Data Pegawai', 'route' => 'pimpinan.pegawai.index', 'icon' => 'users', 'permissions' => ['employees.read']],
                        ]
                    ],
                    [
                        'group' => 'Cuti',
                        'items' => array_filter([
                            $canMonitorLeaves ? ['label' => 'Monitoring Cuti', 'route' => 'pimpinan.cuti.index', 'icon' => 'check-badge'] : null,
                            ['label' => 'Pengajuan Cuti Saya', 'route' => 'cuti', 'parameters' => ['scope' => 'own'], 'icon' => 'calendar'],
                            $canAdministerLeaveBalance
                                ? ['label' => 'Administrasi Pemakaian Cuti', 'route' => 'cuti.saldo.administrasi', 'icon' => 'adjustments-horizontal']
                                : null,
                        ])
                    ],
                    [
                        'group' => 'EWS & Notifikasi',
                        'items' => [
                            ['label' => 'EWS', 'route' => 'pimpinan.ews.index', 'icon' => 'exclamation-triangle'],
                            ['label' => 'Notifikasi', 'route' => 'notifications.index', 'icon' => 'bell', 'permissions' => ['notifications.read']],
                        ]
                    ],
                    [
                        'group' => 'Laporan',
                        'items' => [
                            ['label' => 'Export Pegawai', 'route' => 'laporan.pegawai', 'icon' => 'clipboard-document-list'],
                            ['label' => 'Statistik Kepegawaian', 'route' => 'reporting.employee-statistics', 'icon' => 'chart-bar', 'permissions' => ['employees.read']],
                            ['label' => 'Nominatif Pegawai', 'route' => 'pimpinan.laporan.nominatif', 'icon' => 'document-text'],
                            ['label' => 'Export Cuti', 'route' => 'cuti.laporan', 'icon' => 'clipboard-document-list'],
                            ['label' => 'Riwayat Kepangkatan', 'route' => 'pimpinan.laporan.kepangkatan', 'icon' => 'document-chart-bar'],
                        ]
                    ]
                ];
            }

            if ($activeRole === 'kepala_bagian') {
                $menuGroups = [
                    [
                        'group' => '',
                        'items' => [
                            ['label' => 'Dashboard', 'route' => 'kepala-bagian.dashboard', 'icon' => 'squares-2x2'],
                        ],
                    ],
                    [
                        'group' => 'Kepegawaian',
                        'items' => [
                            ['label' => 'Daftar Bawahan', 'route' => 'kepala-bagian.bawahan.index', 'icon' => 'users'],
                        ],
                    ],
                    [
                        'group' => 'Cuti',
                        'items' => array_filter([
                            $canMonitorLeaves ? ['label' => 'Cuti Bawahan', 'route' => 'kepala-bagian.cuti.index', 'icon' => 'check-badge'] : null,
                            ['label' => 'Pengajuan Cuti Saya', 'route' => 'cuti', 'parameters' => ['scope' => 'own'], 'icon' => 'calendar'],
                            $canAdministerLeaveBalance
                                ? ['label' => 'Administrasi Pemakaian Cuti', 'route' => 'cuti.saldo.administrasi', 'icon' => 'adjustments-horizontal']
                                : null,
                        ]),
                    ],
                    [
                        'group' => 'EWS & Notifikasi',
                        'items' => [
                            ['label' => 'EWS Bawahan', 'route' => 'kepala-bagian.ews.index', 'icon' => 'exclamation-triangle'],
                            ['label' => 'Notifikasi', 'route' => 'notifications.index', 'icon' => 'bell', 'permissions' => ['notifications.read']],
                        ],
                    ],
                    [
                        'group' => 'Laporan',
                        'items' => [
                            ['label' => 'Statistik Kepegawaian', 'route' => 'reporting.employee-statistics', 'icon' => 'chart-bar', 'permissions' => ['employees.read']],
                        ],
                    ],
                ];
            }

            // Capability pembatalan tetap tersedia setelah override menu role; scope data dijaga backend.
            if ($canUseLeaveQueue || $canManageLeaveCancellations) {
                foreach ($menuGroups as &$menuGroup) {
                    if ($menuGroup['group'] === 'Cuti') {
                        if ($canUseLeaveQueue) {
                            array_unshift($menuGroup['items'], [
                                'label' => 'Menunggu Tindakan Saya', 'route' => 'cuti.approval', 'icon' => 'check-badge',
                            ]);
                        }
                        if ($canManageLeaveCancellations) {
                            $menuGroup['items'][] = [
                                'label' => 'Permohonan Pembatalan Cuti',
                                'route' => 'cuti.cancellations.index',
                                'icon' => 'check-badge',
                                'permissions' => ['cuti.cancellation.manage'],
                            ];
                        }
                        break;
                    }
                }
                unset($menuGroup);
            }

            // Detail kanonis menyorot asal yang tersedia, bukan seluruh menu dengan prefix cuti.
            $currentNavigationRoute = request()->route()?->getName();
            $ownLeaveNavigation = request()->query('scope') === 'own' || !$canMonitorLeaves;
            if ($currentNavigationRoute === 'cuti.show') {
                $from = request()->query('from');
                $currentNavigationRoute = match ($from) {
                    'approval' => $canUseLeaveQueue ? 'cuti.approval' : 'cuti',
                    'pimpinan' => $canMonitorLeaves && $activeRole === 'pimpinan' ? 'pimpinan.cuti.index' : 'cuti',
                    'bawahan' => $canMonitorLeaves && $activeRole === 'kepala_bagian' ? 'kepala-bagian.cuti.index' : 'cuti',
                    default => 'cuti',
                };
                $ownLeaveNavigation = !$canMonitorLeaves
                    || ($currentNavigationRoute === 'cuti' && $from !== 'monitoring');
            } elseif ($currentNavigationRoute === 'cuti.create') {
                $ownLeaveNavigation = true;
            }
            if ($currentNavigationRoute === 'cuti' && !$ownLeaveNavigation && $canMonitorLeaves) {
                $currentNavigationRoute = match ($activeRole) {
                    'pimpinan' => 'pimpinan.cuti.index',
                    'kepala_bagian' => 'kepala-bagian.cuti.index',
                    default => 'cuti',
                };
            }

            // Override menu per role tidak boleh menghilangkan capability yang didelegasikan.
            // Entry ini hanya membuka konfigurasi cuti, bukan menu administrasi lain.
            if ($layoutCapabilities['cuti.configure'] ?? false) {
                $hasCutiConfigMenu = collect($menuGroups)
                    ->flatMap(fn (array $group) => $group['items'])
                    ->contains(fn (array $item) => $item['route'] === 'cuti.config');

                if (! $hasCutiConfigMenu) {
                    foreach ($menuGroups as &$menuGroup) {
                        if ($menuGroup['group'] === 'Cuti') {
                            $menuGroup['items'][] = [
                                'label' => 'Konfigurasi Approval Cuti',
                                'route' => 'cuti.config',
                                'icon' => 'cog-6-tooth',
                            ];
                            break;
                        }
                    }
                    unset($menuGroup);
                }
            }

            $allMenuRoutes = [];
            foreach ($menuGroups as $g) {
                foreach ($g['items'] as $item) {
                    $allMenuRoutes[] = $item['route'];
                }
            }
            if ($canReadAudit && !in_array('audit-log', $allMenuRoutes, true)) {
                $menuGroups[] = [
                    'group' => 'Administrasi Sistem',
                    'items' => [['label' => 'Audit Log', 'route' => 'audit-log', 'icon' => 'clipboard-document-list']],
                ];
            }
            @endphp

            @foreach($menuGroups as $group)
                @php
                    $visibleItems = [];
                    foreach ($group['items'] as $menu) {
                        if ($menu['route'] === 'audit-log' && !$canReadAudit) {
                            continue;
                        }
                        $routeExists = \Illuminate\Support\Facades\Route::has($menu['route']);
                        $isLocked    = in_array($menu['route'], $myLockedMenus);
                        $requiredPermissions = $menu['permissions'] ?? [];
                        $hasRequiredPermission = $requiredPermissions === [];

                        foreach ($requiredPermissions as $permission) {
                            if ($layoutCapabilities[$permission] ?? false) {
                                $hasRequiredPermission = true;
                                break;
                            }
                        }

                        if ($routeExists && !$isLocked && $hasRequiredPermission) {
                            $visibleItems[] = $menu;
                        }
                    }
                @endphp
                
                @if(count($visibleItems) > 0)
                    @if(!empty($group['group']))
                        <div class="px-4 pt-4 pb-1.5 text-xs font-bold uppercase tracking-wider text-muted font-sans">{{ $group['group'] }}</div>
                    @endif
                    <div class="space-y-1">
                        @foreach($visibleItems as $menu)
                            @php
                                $isActive = false;
                            $currentRoute = $currentNavigationRoute;
                            $isOwnLeaveMenu = ($menu['parameters']['scope'] ?? null) === 'own';
                            if ($currentRoute === $menu['route']) {
                                $isActive = $menu['route'] !== 'cuti' || $isOwnLeaveMenu === $ownLeaveNavigation;
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
                                    $isActive = $menu['route'] !== 'cuti' || $isOwnLeaveMenu === $ownLeaveNavigation;
                                }
                            }

                            $href = route($menu['route'], $menu['parameters'] ?? []);
                            $itemClass = $isActive
                                ? 'bg-primary text-white font-semibold'
                                : 'text-muted hover:bg-soft hover:text-ink font-medium';
                            $iconClass = $isActive ? 'text-white' : 'text-muted';
                        @endphp
                        @include('components.layouts.partials.sidebar-item')
                    @endforeach
                </div>
                @endif
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
                        {{ preg_replace('/\s*\(.*?\)/', '', auth()->user()->name ?? 'Pengguna') }}
                    </p>
                    <p class="truncate text-xs text-muted">{{ ucwords(str_replace('_', ' ', $activeRole)) }}</p>
                </div>
            </div>
        </div>
    </aside>

    {{-- ================================================================== --}}
    {{-- MAIN COLUMN — scrolls independently --}}
    {{-- ================================================================== --}}
    <div class="flex flex-1 flex-col min-w-0 min-h-0 overflow-hidden">

        {{-- NAVBAR --}}
        <header class="sticky top-0 z-40 flex h-16 shrink-0 items-center justify-between border-b border-border bg-surface/80 backdrop-blur-md px-4 lg:px-6">

            {{-- Left: Hamburger (mobile only) + Search --}}
                @php
                    $searchWidth = '';
                    $searchPlaceholder = 'Cari pegawai, NIP, dokumen, cuti, unit kerja.';
                    if ($activeRole === 'kepala_bagian') {
                        $searchWidth = 'max-width: 300px;';
                        $searchPlaceholder = 'Cari bawahan, NIP, atau cuti.';
                    } elseif ($activeRole === 'pimpinan') {
                        $searchWidth = 'max-width: 370px;';
                        $searchPlaceholder = 'Cari pegawai, NIP, cuti, atau laporan.';
                    }
                    $searchEndpoint = $activeRole === 'kepala_bagian'
                        ? route('kepala-bagian.search')
                        : route('global.search');
                @endphp
            <div class="flex items-center gap-4 w-full max-w-sm" style="{{ $searchWidth }}">
                <button
                    @click="sidebarOpen ? closeSidebar() : openSidebar()"
                    id="sidebar-toggle"
                    x-ref="sidebarToggle"
                    class="inline-flex items-center justify-center rounded-lg border border-border p-2 text-muted transition-colors hover:bg-soft hover:text-ink lg:hidden"
                    :aria-expanded="sidebarOpen.toString()"
                    :aria-label="sidebarOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi'"
                    aria-controls="sidebar-nav"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                </button>

                @if (in_array($activeRole, ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian'], true))
                {{-- Search Bar --}}
                <div
                    class="relative hidden w-full sm:block"
                    data-search-url="{{ $searchEndpoint }}"
                    x-data="globalSearch($el.dataset.searchUrl)"
                >
                    <input
                        type="text"
                        id="global-search"
                        x-model="searchQuery"
                        @input="handleInput"
                        @click.outside="showDropdown = false"
                        @focus="if(searchQuery.length > 1) showDropdown = true"
                        @keydown.enter="handleEnter"
                        class="w-full rounded-lg border border-border bg-surface pl-10 pr-10 py-2 text-sm text-ink focus:border-primary focus:bg-surface focus:outline-none focus:ring-1 focus:ring-primary font-sans transition-colors"
                        placeholder="{{ $searchPlaceholder }}"
                        aria-label="Pencarian global"
                    >
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                    </div>

                    {{-- Loader --}}
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3" x-show="isSearching" style="display: none;">
                        <x-ui.loading size="md" color="primary" />
                    </div>

                    {{-- Search Dropdown --}}
                    <div
                        x-show="showDropdown && Object.keys(searchResults).length > 0"
                        class="absolute top-full left-0 mt-1 w-full max-h-96 overflow-y-auto rounded-lg border border-border bg-surface shadow-lg z-50 p-2"
                        style="display: none;"
                    >
                        <div>
                            <template x-for="(group, title) in searchResults" :key="title">
                                <div class="mb-2 last:mb-0">
                                    <div class="px-3 py-1 text-xs font-bold uppercase tracking-wider text-muted" x-text="title"></div>
                                    <div class="space-y-1">
                                        <template x-for="item in group" :key="item.url">
                                            <a :href="item.url" class="block rounded-md px-3 py-2 text-sm text-ink hover:bg-soft transition-colors">
                                                <div class="font-medium" x-text="item.title"></div>
                                                <div class="text-xs text-muted mt-0.5" x-show="item.subtitle" x-text="item.subtitle"></div>
                                            </a>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            {{-- Right: notif bell + profile dropdown --}}
            <div class="flex items-center gap-2">

                <x-layouts.notification-bell />

                <div class="h-6 w-px bg-border"></div>

                {{-- Indikator Simulasi Role aktif (tampil di desktop dan mobile) --}}
                @if(auth()->check() && auth()->user()->temporary_role)
                    <div class="flex items-center gap-1.5 sm:gap-2 rounded-lg border border-warning/40 bg-warning/10 px-2 sm:px-3 py-1 text-xs font-semibold text-warning">
                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <span class="hidden sm:inline">Simulasi: <strong>{{ ucwords(str_replace('_', ' ', auth()->user()->temporary_role)) }}</strong></span>
                        <form method="POST" action="{{ route('revert-role') }}" class="ml-0.5 sm:ml-1">
                            @csrf
                            <button type="submit" class="rounded-md bg-warning/20 px-1.5 sm:px-2 py-0.5 text-xs sm:text-xs font-semibold text-warning hover:bg-warning/30 transition-colors">
                                Revert
                            </button>
                        </form>
                    </div>
                @endif

                {{-- Profile Dropdown --}}
                <div
                    class="relative"
                    x-data="{
                        open: false,
                        close({ restoreFocus = false } = {}) {
                            this.open = false;

                            if (restoreFocus) {
                                this.$nextTick(() => this.$refs.profileButton?.focus());
                            }
                        },
                        focusMenuItem(direction = 'first') {
                            const menuItems = [...(this.$refs.profileMenu?.querySelectorAll('[role=menuitem]') ?? [])]
                                .filter((item) => item.offsetParent !== null);
                            if (menuItems.length === 0) return;

                            const currentIndex = menuItems.indexOf(document.activeElement);
                            let nextIndex = 0;

                            if (direction === 'last') {
                                nextIndex = menuItems.length - 1;
                            } else if (direction === 'next') {
                                nextIndex = currentIndex === -1 ? 0 : (currentIndex + 1) % menuItems.length;
                            } else if (direction === 'previous') {
                                nextIndex = currentIndex <= 0 ? menuItems.length - 1 : currentIndex - 1;
                            }

                            menuItems[nextIndex]?.focus();
                        }
                    }"
                    @keydown.escape.stop="close({ restoreFocus: true })"
                >
                    <button
                        @click="open = !open"
                        @keydown.arrow-down.prevent="open = true; $nextTick(() => focusMenuItem())"
                        @keydown.arrow-up.prevent="open = true; $nextTick(() => focusMenuItem('last'))"
                        id="profile-btn"
                        x-ref="profileButton"
                        :aria-expanded="open.toString()"
                        aria-controls="profile-menu"
                        aria-haspopup="menu"
                        class="flex items-center gap-2.5 rounded-lg border border-border px-3 py-2 transition-colors hover:bg-soft"
                    >
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
                            <svg class="w-5 h-5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        </div>
                        <div class="hidden text-left md:block">
                            <p class="text-sm font-semibold leading-tight text-ink font-sans">
                                {{ preg_replace('/\s*\(.*?\)/', '', auth()->user()->name ?? 'Pengguna') }}
                            </p>
                            <p class="text-xs leading-tight text-muted font-sans">{{ ucwords(str_replace('_', ' ', $activeRole)) }}</p>
                        </div>
                        
                    </button>

                    <div
                        x-show="open"
                        @click.outside="close()"
                        @keydown.arrow-down.prevent="focusMenuItem('next')"
                        @keydown.arrow-up.prevent="focusMenuItem('previous')"
                        @keydown.home.prevent="focusMenuItem()"
                        @keydown.end.prevent="focusMenuItem('last')"
                        @keydown.tab="close()"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        id="profile-menu"
                        x-ref="profileMenu"
                        role="menu"
                        aria-label="Menu akun"
                        class="absolute right-0 top-full mt-2 w-64 origin-top-right overflow-hidden rounded-lg border border-border bg-surface shadow-lg z-50"
                        style="display: none;"
                    >
                        <div class="border-b border-border px-4 py-3">
                            <p class="text-xs font-semibold text-ink font-sans">{{ preg_replace('/\s*\(.*?\)/', '', auth()->user()->name ?? 'Pengguna') }}</p>
                            <p class="mt-0.5 text-xs text-muted font-sans truncate">{{ auth()->user()->email ?? '' }}</p>
                        </div>
                        <div class="p-1.5 space-y-0.5">
                            <a href="{{ route('profil') }}" wire:navigate id="profile-link" role="menuitem" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink transition-colors hover:bg-soft font-sans font-medium">
                                {{-- heroicon: user-circle (outline) --}}
                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <span>Profil Saya</span>
                            </a>
                            {{-- Switch Role Menu (hanya Super Admin ber-permission yang belum dalam simulasi dapat
                                 switch; saat simulasi aktif, hanya aksi revert yang tampil) --}}
                            @if(auth()->check() && ((auth()->user()->role === 'super_admin' && auth()->user()->hasPermission('users.switch_role')) || auth()->user()->temporary_role))
                                {{-- Submenu switch hanya untuk Super Admin original yang TIDAK sedang dalam simulasi:
                                     selama simulasi role efektif sudah menurun, permission switch_role tidak dimiliki
                                     role tujuan dan backend menolak switch beruntun; guard eksplisit ini mencegah UI
                                     yang menyesatkan dan memastikan aksi hanya tampil bagi Super Admin asli. --}}
                                @if(auth()->user()->role === 'super_admin' && auth()->user()->hasPermission('users.switch_role') && ! auth()->user()->temporary_role)
                                    <div x-data="{ switchRoleOpen: false }" class="pt-0.5">
                                        <button
                                            type="button"
                                            role="menuitem"
                                            @click="switchRoleOpen = !switchRoleOpen"
                                            :aria-expanded="switchRoleOpen.toString()"
                                            aria-controls="role-switch-menu"
                                            aria-haspopup="menu"
                                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm text-ink transition-colors hover:bg-soft font-sans font-medium"
                                            :class="{ 'bg-soft text-primary': switchRoleOpen }"
                                        >
                                            <div class="flex items-center gap-2.5">
                                                {{-- heroicon: arrows-right-left (outline) --}}
                                                <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                                                </svg>
                                                <span>Simulasi Role</span>
                                            </div>
                                            <svg
                                                class="w-4 h-4 text-muted transition-transform duration-200 shrink-0"
                                                :class="{ 'rotate-180 text-primary': switchRoleOpen }"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                                stroke-width="1.5"
                                            >
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>

                                        <div
                                            x-show="switchRoleOpen"
                                            x-transition:enter="transition ease-out duration-150"
                                            x-transition:enter-start="opacity-0 -translate-y-1"
                                            x-transition:enter-end="opacity-100 translate-y-0"
                                            x-transition:leave="transition ease-in duration-100"
                                            x-transition:leave-start="opacity-100 translate-y-0"
                                            x-transition:leave-end="opacity-0 -translate-y-1"
                                            id="role-switch-menu"
                                            role="menu"
                                            aria-label="Pilih role simulasi"
                                            class="mt-1 space-y-0.5 rounded-lg bg-soft/60 p-1 border border-border/50"
                                            style="display: none;"
                                        >
                                            @foreach(['admin_kepegawaian' => 'Admin Kepegawaian', 'pimpinan' => 'Pimpinan', 'kepala_bagian' => 'Kepala Bagian', 'pegawai' => 'Pegawai'] as $roleKey => $roleLabel)
                                                @if(auth()->user()->role !== $roleKey && auth()->user()->temporary_role !== $roleKey)
                                                    <form method="POST" action="{{ route('switch-role') }}">
                                                        @csrf
                                                        <input type="hidden" name="target_role" value="{{ $roleKey }}">
                                                        <button
                                                            type="submit"
                                                            role="menuitem"
                                                            class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-xs text-ink hover:bg-surface hover:text-primary transition-colors font-sans text-left group"
                                                        >
                                                            <span class="h-1.5 w-1.5 rounded-full bg-muted/60 group-hover:bg-primary shrink-0 transition-colors"></span>
                                                            <span class="truncate">Switch ke {{ $roleLabel }}</span>
                                                        </button>
                                                    </form>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                @if(auth()->user()->temporary_role)
                                    <div class="border-t border-border/60 my-1 pt-1">
                                        <form method="POST" action="{{ route('revert-role') }}">
                                            @csrf
                                            <button type="submit" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-xs font-semibold text-warning hover:bg-warning/10 transition-colors font-sans text-left">
                                                <svg class="w-4 h-4 text-warning shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                                                </svg>
                                                <span>Kembalikan Role Asli</span>
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            @endif
                        </div>
                        <div class="border-t border-border p-1.5">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button
                                    type="submit"
                                    id="logout-btn"
                                    role="menuitem"
                                    class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-danger transition-colors hover:bg-danger/10 font-sans text-left"
                                >
                                    {{-- heroicon: arrow-right-on-rectangle (outline) --}}
                                    <svg class="w-4 h-4 text-danger shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25" />
                                    </svg>
                                    <span>Keluar dari Sistem</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </header>



        {{-- PAGE CONTENT --}}
        <main class="flex-1 overflow-y-auto bg-page scrollbar-hide min-h-0">
            <div class="mx-auto max-w-7xl px-4 py-6 lg:px-6">
                @php($sessionTimeoutMessage = session()->pull('simpeg_session_timeout_message'))
                @if ($sessionTimeoutMessage)
                    <div class="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-sm font-semibold text-warning">
                        {{ $sessionTimeoutMessage }}
                    </div>
                @endif

                {{ $slot }}
            </div>
        </main>

    </div>
</div>

{{-- TOAST NOTIFICATIONS --}}
<div x-data="toastManager()" @notify.window="addToast($event.detail)" class="fixed bottom-4 right-4 z-[60] flex w-full max-w-sm flex-col gap-3 sm:bottom-6 sm:right-6 pointer-events-none">
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="toast.show"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-y-10 opacity-0 sm:translate-y-0 sm:translate-x-10"
            x-transition:enter-end="translate-y-0 opacity-100 sm:translate-x-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            :role="toast.type === 'error' ? 'alert' : 'status'"
            :aria-live="toast.type === 'error' ? 'assertive' : 'polite'"
            aria-atomic="true"
            :class="{
                'border-success/20 bg-surface': toast.type === 'success',
                'border-danger/20 bg-surface': toast.type === 'error',
                'border-warning/20 bg-surface': toast.type === 'warning',
                'border-info/20 bg-surface': toast.type === 'info',
            }"
            class="flex items-center gap-3 rounded-xl border p-4 shadow-xl pointer-events-auto"
        >
            <div
                :class="{
                    'text-success': toast.type === 'success',
                    'text-danger': toast.type === 'error',
                    'text-warning': toast.type === 'warning',
                    'text-info-dark': toast.type === 'info',
                }"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
            >
                <!-- Success -->
                <template x-if="toast.type === 'success'">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </template>
                <!-- Error -->
                <template x-if="toast.type === 'error'">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </template>
                <!-- Warning -->
                <template x-if="toast.type === 'warning'">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </template>
                <!-- Info -->
                <template x-if="toast.type === 'info'">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                </template>
            </div>
            <div class="flex-1">
                <p class="text-sm font-semibold text-ink" x-text="toast.title"></p>
                <p class="mt-0.5 text-xs text-muted" x-text="toast.message" x-show="toast.message"></p>
            </div>
            <button @click="removeToast(toast.id)" class="text-muted hover:text-ink" aria-label="Tutup notifikasi">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    </template>
</div>

@stack('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('globalSearch', (searchUrl) => ({
            searchQuery: '',
            searchResults: {},
            isSearching: false,
            showDropdown: false,
            debounceTimer: null,

            handleInput() {
                if (this.searchQuery.length <= 1) {
                    this.showDropdown = false;
                    this.isSearching = false;
                    clearTimeout(this.debounceTimer);
                    return;
                }

                this.isSearching = true;
                this.showDropdown = true;

                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => {
                    this.fetchResults();
                }, 500);
            },

            fetchResults() {
                const url = new URL(searchUrl, window.location.origin);
                url.searchParams.set('q', this.searchQuery);

                // Pencarian AJAX tidak boleh mengganti URL sebelumnya untuk redirect validasi Laravel.
                fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(r => {
                        if (!r.ok) throw new Error('Pencarian gagal');

                        return r.json();
                    })
                    .then(data => {
                        this.searchResults = data;
                        this.isSearching = false;
                    })
                    .catch(() => {
                        this.isSearching = false;
                    });
            },

            handleEnter() {
                if (this.searchQuery.length > 1) {
                    let keys = Object.keys(this.searchResults);
                    if (keys.length === 0) {
                        window.dispatchEvent(new CustomEvent('notify', {
                            detail: { type: 'error', title: 'Pencarian Gagal', message: 'Data yang Anda cari tidak ditemukan.' }
                        }));
                    } else {
                        window.location.href = this.searchResults[keys[0]][0].url;
                    }
                }
            }
        }));
    });

    document.addEventListener('alpine:init', () => {
        Alpine.data('toastManager', () => ({
            toasts: [],
            addToast(toast) {
                // Prevent duplicate toasts (spam protection)
                if (this.toasts.some(t => t.title === toast.title && t.message === toast.message)) return;

                const id = Date.now() + Math.random().toString(36).substr(2, 9);
                this.toasts.push({ ...toast, id, show: false });

                // Trigger animation
                setTimeout(() => {
                    const index = this.toasts.findIndex(t => t.id === id);
                    if (index !== -1) this.toasts[index].show = true;
                }, 10);

                // Auto remove after 5s
                setTimeout(() => this.removeToast(id), 5000);
            },
            removeToast(id) {
                const index = this.toasts.findIndex(t => t.id === id);
                if (index !== -1) {
                    this.toasts[index].show = false;
                    setTimeout(() => {
                        this.toasts = this.toasts.filter(t => t.id !== id);
                    }, 300);
                }
            }
        }));
    });

    (() => {
        const initialiseSidebarLabelScrollers = () => {
            document.querySelectorAll('[data-sidebar-label-scroller]').forEach((menuItem) => {
                if (menuItem.dataset.sidebarLabelScrollerReady === 'true') return;

                const viewport = menuItem.querySelector('[data-sidebar-label-viewport]');
                const text = menuItem.querySelector('[data-sidebar-label-text]');

                if (!viewport || !text) return;

                let animationVersion = 0;

                const reset = () => {
                    animationVersion += 1;
                    text.style.transitionDuration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? '0ms' : '180ms';
                    text.style.transform = 'translateX(0)';
                };

                const start = () => {
                    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

                    const overflow = Math.ceil(text.scrollWidth - viewport.clientWidth);
                    if (overflow <= 0) return;

                    const currentVersion = ++animationVersion;
                    text.style.transitionDuration = '0ms';
                    text.style.transform = 'translateX(0)';

                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            if (currentVersion !== animationVersion) return;

                            text.style.transitionDuration = `${Math.min(5000, Math.max(1200, overflow * 24))}ms`;
                            text.style.transform = `translateX(-${overflow}px)`;
                        });
                    });
                };

                menuItem.addEventListener('pointerenter', start);
                menuItem.addEventListener('pointerleave', reset);
                menuItem.addEventListener('focus', start);
                menuItem.addEventListener('blur', reset);
                menuItem.dataset.sidebarLabelScrollerReady = 'true';
            });
        };

        initialiseSidebarLabelScrollers();
        document.addEventListener('livewire:navigated', initialiseSidebarLabelScrollers);
    })();

    // Handle Laravel Session Flashes -> Convert to Toasts
    document.addEventListener('livewire:navigated', () => {
        const flashes = [
            @if(session('success')) { type: 'success', title: 'Berhasil', message: @json(session('success')) }, @endif
            @if(session('login_success')) { type: 'success', title: 'Berhasil Masuk', message: @json(session('login_success')) }, @endif
            @if(session('error')) { type: 'error', title: 'Gagal', message: @json(session('error')) }, @endif
            @if(session('auth_error')) { type: 'error', title: 'Gagal', message: @json(session('auth_error')) }, @endif
            @if(session('warning')) { type: 'warning', title: 'Peringatan', message: @json(session('warning')) }, @endif
            @if(session('info')) { type: 'info', title: 'Informasi', message: @json(session('info')) }, @endif
        ];

        flashes.forEach((flash, index) => {
            setTimeout(() => {
                window.dispatchEvent(new CustomEvent('notify', { detail: flash }));
            }, 100 + (index * 200));
        });

        const sidebarNav = document.getElementById('sidebar-nav');
        if (sidebarNav) {
            // Restore scroll position
            const savedScrollPos = sessionStorage.getItem('sidebarScrollPos');
            if (savedScrollPos !== null) {
                sidebarNav.scrollTop = parseInt(savedScrollPos, 10);
            } else {
                // First load: scroll active item into view if exists and out of view
                const activeLink = sidebarNav.querySelector('.bg-primary.text-white');
                if (activeLink) {
                    activeLink.scrollIntoView({ behavior: 'auto', block: 'center' });
                }
            }

            // Save scroll position on scroll
            let isScrolling;
            sidebarNav.addEventListener('scroll', () => {
                window.clearTimeout(isScrolling);
                isScrolling = setTimeout(() => {
                    sessionStorage.setItem('sidebarScrollPos', sidebarNav.scrollTop);
                }, 66);
            });
        }
    });
</script>
@livewireScripts
</body>
</html>
