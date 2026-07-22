<x-layouts.app title="Detail Pegawai">
    @php
        $statusPensiunLabel = $p->statusPegawai?->nama ?? $p->status_aktif ?? '-';
        $isPensiun = stripos($statusPensiunLabel, 'pensiun') !== false;
        $statusVariant = match (true) {
            stripos($statusPensiunLabel, 'aktif') !== false  => ['bg' => 'bg-success/10 text-success', 'dot' => 'bg-success'],
            $isPensiun                                       => ['bg' => 'bg-danger/10 text-danger', 'dot' => 'bg-danger'],
            default                                          => ['bg' => 'bg-muted/10 text-muted', 'dot' => 'bg-muted'],
        };
    @endphp

    <div
        x-data="{
            activeTab: 'info',
            setTab(tab) { this.activeTab = tab; },
        }"
        class="space-y-6"
    >
        {{-- Header --}}
        <div>
            <h1 class="text-2xl font-semibold text-ink">Detail Pegawai</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
                ['label' => 'Data Pegawai', 'url' => route('pimpinan.pegawai.index')],
                ['label' => $p->nama_lengkap],
            ]" />
        </div>

        <div class="flex flex-col gap-6 lg:flex-row">

            {{-- ---- Sidebar Navigasi Vertikal ---- --}}
            <aside class="lg:w-56 shrink-0">
                <x-ui.card padding="none">
                    {{-- Profil Singkat --}}
                    <div class="flex flex-col items-center gap-2 border-b border-border p-5 text-center">
                        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-xl font-bold text-primary">
                            {{ strtoupper(substr($p->nama_lengkap ?? 'P', 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-ink leading-snug">{{ $p->nama_lengkap }}</p>
                            <p class="mt-0.5 text-xs text-muted">{{ $p->nip ?? '-' }}</p>
                        </div>
                        {{-- Status Badge --}}
                        <span class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium {{ $statusVariant['bg'] }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $statusVariant['dot'] }}"></span>
                            {{ $statusPensiunLabel }}
                        </span>
                    </div>

                    {{-- Navigasi Tab Vertikal --}}
                    <nav
                        aria-label="Navigasi detail pegawai"
                        aria-orientation="vertical"
                        role="tablist"
                        class="flex flex-col gap-0.5 p-2"
                        @keydown.down.prevent="
                            const tabs = ['info', 'keluarga', 'supervisor'];
                            const idx = tabs.indexOf(activeTab);
                            setTab(tabs[(idx + 1) % tabs.length]);
                        "
                        @keydown.up.prevent="
                            const tabs = ['info', 'keluarga', 'supervisor'];
                            const idx = tabs.indexOf(activeTab);
                            setTab(tabs[(idx - 1 + tabs.length) % tabs.length]);
                        "
                    >
                        <button
                            type="button"
                            role="tab"
                            id="pimpinan-tab-info"
                            aria-controls="pimpinan-panel-info"
                            :aria-selected="activeTab === 'info'"
                            @click="setTab('info')"
                            :class="activeTab === 'info'
                                ? 'bg-primary/10 text-primary font-semibold'
                                : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                            </svg>
                            Informasi Umum
                        </button>

                        <button
                            type="button"
                            role="tab"
                            id="pimpinan-tab-keluarga"
                            aria-controls="pimpinan-panel-keluarga"
                            :aria-selected="activeTab === 'keluarga'"
                            @click="setTab('keluarga')"
                            :class="activeTab === 'keluarga'
                                ? 'bg-primary/10 text-primary font-semibold'
                                : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                            </svg>
                            Data Keluarga
                        </button>

                        <button
                            type="button"
                            role="tab"
                            id="pimpinan-tab-supervisor"
                            aria-controls="pimpinan-panel-supervisor"
                            :aria-selected="activeTab === 'supervisor'"
                            @click="setTab('supervisor')"
                            :class="activeTab === 'supervisor'
                                ? 'bg-primary/10 text-primary font-semibold'
                                : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z" />
                            </svg>
                            Supervisor
                        </button>

                        <button
                            type="button"
                            role="tab"
                            @click="setTab('pangkat')"
                            :class="activeTab === 'pangkat' ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.307a11.95 11.95 0 0 1 5.814-5.519l2.74-1.22m0 0-5.94-2.28m5.94 2.28-2.28 5.94" /></svg>
                            Riwayat Kepangkatan
                        </button>

                        <button
                            type="button"
                            role="tab"
                            @click="setTab('jabatan')"
                            :class="activeTab === 'jabatan' ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25" /></svg>
                            Riwayat Jabatan
                        </button>

                        <button
                            type="button"
                            role="tab"
                            @click="setTab('kgb')"
                            :class="activeTab === 'kgb' ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33" /></svg>
                            Riwayat KGB
                        </button>

                        <button
                            type="button"
                            role="tab"
                            @click="setTab('disiplin')"
                            :class="activeTab === 'disiplin' ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                            Hukuman Disiplin
                        </button>

                        <button
                            type="button"
                            role="tab"
                            @click="setTab('pendidikan')"
                            :class="activeTab === 'pendidikan' ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-soft hover:text-ink'"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition text-left"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" /></svg>
                            Pendidikan
                        </button>
                    </nav>

                    {{-- Export history tidak tersedia untuk pimpinan --}}
                    <div class="border-t border-border p-3">
                        <p class="history-export-unavailable text-xs text-muted text-center">
                            Ekspor riwayat tidak tersedia
                        </p>
                    </div>
                </x-ui.card>
            </aside>

            {{-- ---- Konten Panel ---- --}}
            <div class="flex-1 min-w-0">

                {{-- Panel: Informasi Umum --}}
                <div
                    id="pimpinan-panel-info"
                    role="tabpanel"
                    aria-labelledby="pimpinan-tab-info"
                    x-show="activeTab === 'info'"
                >
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Informasi Umum</h2>
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">Nama Lengkap</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $p->nama_lengkap ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">NIP</dt>
                                <dd class="mt-1 text-sm text-ink font-mono">{{ $p->nip ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">Jabatan Terakhir</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $p->jabatan_terakhir ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">Golongan</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $p->golongan_terakhir ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">Status</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium {{ $statusVariant['bg'] }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $statusVariant['dot'] }}"></span>
                                        {{ $statusPensiunLabel }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">Tanggal Pensiun</dt>
                                <dd class="mt-1 text-sm text-ink">
                                    {{ $p->tanggal_pensiun?->translatedFormat('d F Y') ?? '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">Jenis Pegawai</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $p->jenisPegawai?->nama ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-muted uppercase tracking-wide">Pendidikan Terakhir</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $p->pendidikan_terakhir ?? '-' }}</dd>
                            </div>
                        </dl>
                    </x-ui.card>
                </div>

                {{-- Panel: Data Keluarga --}}
                <div
                    id="pimpinan-panel-keluarga"
                    role="tabpanel"
                    aria-labelledby="pimpinan-tab-keluarga"
                    x-show="activeTab === 'keluarga'"
                    x-cloak
                >
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Data Keluarga</h2>

                        @if($p->families->isEmpty())
                            <p class="text-sm text-muted">Belum ada data keluarga.</p>
                        @else
                            <div class="divide-y divide-border">
                                @foreach($p->families as $family)
                                    <div class="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                        <div>
                                            <p class="text-sm font-semibold text-ink">{{ $family->nama_anggota }}</p>
                                            <p class="text-xs text-muted">{{ $family->hubungan }}</p>
                                            @if($family->pekerjaan)
                                                <p class="text-xs text-muted">{{ $family->pekerjaan }}</p>
                                            @endif
                                        </div>
                                        <span class="inline-flex items-center rounded-md bg-soft/60 px-2 py-0.5 text-xs font-medium text-ink">
                                            {{ $family->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan' }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-ui.card>
                </div>

                {{-- Panel: Supervisor --}}
                <div
                    id="pimpinan-panel-supervisor"
                    role="tabpanel"
                    aria-labelledby="pimpinan-tab-supervisor"
                    x-show="activeTab === 'supervisor'"
                    x-cloak
                >
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Kepala Bagian/Supervisor Aktif</h2>

                        @php
                            $activeAssignments = $p->supervisorAssignments ?? collect();
                        @endphp

                        @if($activeAssignments->isEmpty())
                            <p class="text-sm text-muted">Belum ada data supervisor aktif.</p>
                        @else
                            <div class="space-y-3">
                                @foreach($activeAssignments as $assignment)
                                    @if($assignment->supervisor)
                                        <div class="rounded-lg border border-border bg-soft/30 p-4">
                                            <p class="text-sm font-semibold text-ink">
                                                {{ $assignment->supervisor->nama_lengkap }}
                                            </p>
                                            <p class="mt-0.5 text-xs text-muted">
                                                {{ $assignment->supervisor->jabatan_terakhir ?? '-' }}
                                            </p>
                                            @php
                                                $supervisorUnit = $assignment->supervisor->positionHistories->first()?->unitKerja?->nama;
                                            @endphp
                                            @if($supervisorUnit)
                                                <p class="mt-0.5 text-xs text-muted">{{ $supervisorUnit }}</p>
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </x-ui.card>
                {{-- Panel: Kepangkatan --}}
                <div x-show="activeTab === 'pangkat'" x-cloak>
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Riwayat Kepangkatan</h2>
                        @if(($p->rankHistories ?? collect())->isEmpty())
                            <p class="text-sm text-muted">Belum ada riwayat kepangkatan.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-ink font-sans">
                                    <thead class="bg-soft/40 text-xs text-muted uppercase">
                                        <tr>
                                            <th class="px-4 py-3">Golongan</th>
                                            <th class="px-4 py-3">No. SK</th>
                                            <th class="px-4 py-3">Tanggal SK</th>
                                            <th class="px-4 py-3">TMT</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @foreach($p->rankHistories as $r)
                                            <tr>
                                                <td class="px-4 py-3 font-semibold">{{ $r->golongan->nama ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $r->no_sk ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $r->tanggal_sk ? \Carbon\Carbon::parse($r->tanggal_sk)->translatedFormat('d M Y') : '-' }}</td>
                                                <td class="px-4 py-3">{{ $r->tmt_pangkat ? \Carbon\Carbon::parse($r->tmt_pangkat)->translatedFormat('d M Y') : '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-ui.card>
                </div>

                {{-- Panel: Jabatan --}}
                <div x-show="activeTab === 'jabatan'" x-cloak>
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Riwayat Jabatan</h2>
                        @if(($p->positionHistories ?? collect())->isEmpty())
                            <p class="text-sm text-muted">Belum ada riwayat jabatan.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-ink font-sans">
                                    <thead class="bg-soft/40 text-xs text-muted uppercase">
                                        <tr>
                                            <th class="px-4 py-3">Jabatan</th>
                                            <th class="px-4 py-3">Unit Kerja</th>
                                            <th class="px-4 py-3">No. SK</th>
                                            <th class="px-4 py-3">TMT</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @foreach($p->positionHistories as $j)
                                            <tr>
                                                <td class="px-4 py-3 font-semibold">{{ $j->jabatan?->nama ?? $j->nama_jabatan ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $j->unitKerja?->nama ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $j->no_sk ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $j->tmt_jabatan ? \Carbon\Carbon::parse($j->tmt_jabatan)->translatedFormat('d M Y') : '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-ui.card>
                </div>

                {{-- Panel: KGB --}}
                <div x-show="activeTab === 'kgb'" x-cloak>
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Riwayat KGB</h2>
                        @if(($p->salaryHistories ?? collect())->isEmpty())
                            <p class="text-sm text-muted">Belum ada riwayat KGB.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-ink font-sans">
                                    <thead class="bg-soft/40 text-xs text-muted uppercase">
                                        <tr>
                                            <th class="px-4 py-3">Gaji Pokok</th>
                                            <th class="px-4 py-3">No. SK</th>
                                            <th class="px-4 py-3">TMT KGB</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @foreach($p->salaryHistories as $s)
                                            <tr>
                                                <td class="px-4 py-3 font-semibold">Rp {{ number_format($s->gaji_pokok, 0, ',', '.') }}</td>
                                                <td class="px-4 py-3">{{ $s->no_sk ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $s->tmt_kgb ? \Carbon\Carbon::parse($s->tmt_kgb)->translatedFormat('d M Y') : '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-ui.card>
                </div>

                {{-- Panel: Hukuman Disiplin --}}
                <div x-show="activeTab === 'disiplin'" x-cloak>
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Hukuman Disiplin</h2>
                        @if(($p->disciplineRecords ?? collect())->isEmpty())
                            <p class="text-sm text-muted">Tidak ada riwayat hukuman disiplin.</p>
                        @else
                            <div class="divide-y divide-border">
                                @foreach($p->disciplineRecords as $d)
                                    <div class="py-3">
                                        <p class="text-sm font-semibold text-ink">{{ $d->jenis_hukuman }}</p>
                                        <p class="text-xs text-muted">{{ $d->deskripsi }}</p>
                                        <p class="mt-1 text-xs text-muted">No. SK: {{ $d->no_sk }} · Masa: {{ $d->tanggal_mulai ? \Carbon\Carbon::parse($d->tanggal_mulai)->format('d-m-Y') : '-' }} s/d {{ $d->tanggal_berakhir ? \Carbon\Carbon::parse($d->tanggal_berakhir)->format('d-m-Y') : 'Sekarang' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-ui.card>
                </div>

                {{-- Panel: Pendidikan --}}
                <div x-show="activeTab === 'pendidikan'" x-cloak>
                    <x-ui.card>
                        <h2 class="mb-4 text-base font-semibold text-ink">Riwayat Pendidikan</h2>
                        @if(($p->educationHistories ?? collect())->isEmpty())
                            <p class="text-sm text-muted">Belum ada riwayat pendidikan.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-ink font-sans">
                                    <thead class="bg-soft/40 text-xs text-muted uppercase">
                                        <tr>
                                            <th class="px-4 py-3">Jenjang</th>
                                            <th class="px-4 py-3">Institusi</th>
                                            <th class="px-4 py-3">Jurusan</th>
                                            <th class="px-4 py-3">Tahun Lulus</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @foreach($p->educationHistories as $edu)
                                            <tr>
                                                <td class="px-4 py-3 font-semibold">{{ $edu->jenjang?->urutan ?? $edu->tingkat ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $edu->nama_institusi ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $edu->jurusan ?? '-' }}</td>
                                                <td class="px-4 py-3">{{ $edu->tahun_lulus ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-ui.card>
                </div>
        </div>
    </div>
</x-layouts.app>
