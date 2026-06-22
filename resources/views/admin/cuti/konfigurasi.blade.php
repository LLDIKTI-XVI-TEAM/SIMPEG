<x-layouts.app title="Konfigurasi Approval Cuti">
    @php
        $approvers = [
            ['name' => 'Dra. Merlina Rahman',       'role' => 'Kabag Tata Usaha',        'unit' => 'Bagian Umum'],
            ['name' => 'Riza Hamzah',                'role' => 'Verifikator Kepegawaian', 'unit' => 'Bagian SDM'],
            ['name' => 'Dr. Abdul Kadir',            'role' => 'Pimpinan / PYBMC',        'unit' => 'LLDIKTI Wilayah XVI'],
            ['name' => 'Nurarningsih Dumbea, S.P.', 'role' => 'Koordinator Kepegawaian', 'unit' => 'Bagian SDM'],
        ];

        $auditRows = [
            ['time' => '22 Jun 2026, 16:12', 'actor' => 'Super Admin', 'field' => 'Stage 2',       'before' => 'Riza Hamzah',     'after' => 'Dra. Merlina Rahman'],
            ['time' => '20 Jun 2026, 09:40', 'actor' => 'Super Admin', 'field' => 'Stage 3',       'before' => 'Dr. Abdul Kadir', 'after' => 'Dr. Abdul Kadir'],
            ['time' => '18 Jun 2026, 14:25', 'actor' => 'Super Admin', 'field' => 'Skip duplikat', 'before' => 'Tidak aktif',     'after' => 'Aktif'],
        ];

        $badgeClass = [
            'Read-only'    => 'bg-info/10 text-info',
            'Configurable' => 'bg-primary/10 text-primary',
            'Aktif'        => 'bg-success/10 text-success',
        ];
    @endphp

    {{-- ================================================================ --}}
    {{-- Alpine.js state --}}
    {{-- ================================================================ --}}
    <div
        class="space-y-6"
        x-data="{
            kabag:       'Dra. Merlina Rahman',
            pimpinan:    'Dr. Abdul Kadir',
            reason:      '',
            showConfirm: false,
            saved:       false,

            get isDuplikat() { return this.kabag === this.pimpinan; },

            openConfirm() {
                if (this.reason.trim() === '') return;
                this.showConfirm = true;
            },
            confirmSave() {
                this.showConfirm = false;
                this.saved = true;
            }
        }"
    >

        {{-- ================================================================ --}}
        {{-- PAGE HEADER --}}
        {{-- ================================================================ --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Konfigurasi Approval</span>
                </nav>
                <h2 class="text-2xl font-semibold text-ink">Konfigurasi Approval Cuti</h2>
                <p class="mt-1.5 max-w-2xl text-sm text-muted">
                    Kelola approver default untuk setiap stage dalam alur persetujuan cuti.
                    Perubahan berlaku untuk pengajuan cuti baru dan wajib tercatat sebagai audit trail.
                </p>
            </div>
            <span class="inline-flex h-fit shrink-0 items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary">
                {{-- heroicon: shield-check --}}
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
                Super Admin only
            </span>
        </div>

        {{-- ================================================================ --}}
        {{-- VISUAL APPROVAL FLOW --}}
        {{-- ================================================================ --}}
        <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-base font-semibold text-ink">Alur Approval Aktif</h3>
                <p class="mt-0.5 text-sm text-muted">Preview urutan persetujuan untuk setiap pengajuan cuti baru.</p>
            </div>
            <div class="overflow-x-auto px-6 py-5">
                <div class="flex min-w-max items-start gap-3">

                    {{-- Stage 1 --}}
                    <div class="flex flex-col items-center gap-2">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-soft ring-2 ring-border">
                            <svg class="h-5 w-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                            </svg>
                        </div>
                        <div class="text-center">
                            <p class="text-xs font-semibold text-muted">Stage 1</p>
                            <p class="text-sm font-semibold text-ink">Atasan Langsung</p>
                            <p class="text-xs text-muted">Otomatis dari data pegawai</p>
                        </div>
                    </div>

                    {{-- Arrow --}}
                    <div class="mt-5 flex shrink-0 items-center">
                        <div class="h-px w-8 bg-border"></div>
                        <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </div>

                    {{-- Stage 2 --}}
                    <div class="flex flex-col items-center gap-2">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 ring-2 ring-primary/30">
                            <svg class="h-5 w-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <div class="text-center">
                            <p class="text-xs font-semibold text-primary">Stage 2</p>
                            <p class="text-sm font-semibold text-ink" x-text="kabag"></p>
                            <p class="text-xs text-muted">Kabag / Verifikator</p>
                        </div>
                    </div>

                    {{-- Arrow dinamis: skip duplikat --}}
                    <div class="mt-5 flex shrink-0 flex-col items-center gap-1">
                        <div class="flex items-center">
                            <div class="h-px w-8" :class="isDuplikat ? 'bg-warning' : 'bg-border'"></div>
                            <svg class="h-4 w-4" :class="isDuplikat ? 'text-warning' : 'text-muted'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </div>
                        <p x-show="isDuplikat" class="text-[10px] font-bold text-warning" x-transition>Skip</p>
                    </div>

                    {{-- Stage 3 --}}
                    <div class="flex flex-col items-center gap-2">
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full ring-2 transition-all"
                            :class="isDuplikat
                                ? 'bg-warning/10 ring-warning/30 opacity-60'
                                : 'bg-primary/10 ring-primary/30'"
                        >
                            <svg class="h-5 w-5" :class="isDuplikat ? 'text-warning' : 'text-primary'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <div class="text-center" :class="isDuplikat ? 'opacity-60' : ''">
                            <p class="text-xs font-semibold" :class="isDuplikat ? 'text-warning' : 'text-primary'">Stage 3</p>
                            <p class="text-sm font-semibold text-ink" x-text="pimpinan"></p>
                            <p class="text-xs text-muted">Pimpinan / PYBMC</p>
                        </div>
                    </div>

                    {{-- Arrow --}}
                    <div class="mt-5 flex shrink-0 items-center">
                        <div class="h-px w-8 bg-border"></div>
                        <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </div>

                    {{-- Final --}}
                    <div class="flex flex-col items-center gap-2">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-success/10 ring-2 ring-success/30">
                            <svg class="h-5 w-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                            </svg>
                        </div>
                        <div class="text-center">
                            <p class="text-xs font-semibold text-success">Final</p>
                            <p class="text-sm font-semibold text-ink">Disetujui</p>
                            <p class="text-xs text-muted">Saldo cuti dikurangi</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Warning skip duplikat --}}
            <div
                x-show="isDuplikat"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="border-t border-warning/20 bg-warning/5 px-6 py-3"
            >
                <div class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <p class="text-sm font-semibold text-warning">
                        Stage 3 akan dilewati otomatis karena approver Stage 2 dan Stage 3 sama.
                        Pengajuan langsung diteruskan ke tahap final setelah Stage 2 menyetujui.
                    </p>
                </div>
            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- MAIN CONTENT --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">

            {{-- ============================================================ --}}
            {{-- LEFT — Tabel config + Mode info + Audit --}}
            {{-- ============================================================ --}}
            <div class="space-y-6">

                {{-- Approval Config Table --}}
                <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-ink">Approval Config Aktif</h3>
                            <p class="mt-0.5 text-sm text-muted">
                                Stage 1 bersumber dari data pegawai. Stage 2 & 3 disimpan di
                                <code class="rounded bg-soft px-1 py-0.5 font-mono text-xs">approval_config</code>.
                            </p>
                        </div>
                        <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-semibold text-success">
                            <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                            Aktif
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-soft">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Stage</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Role</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Approver</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Source</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr class="transition-colors hover:bg-soft/60">
                                    <td class="px-4 py-4 font-mono text-sm font-semibold text-muted">1</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-ink">Atasan Langsung</td>
                                    <td class="px-4 py-4 text-sm italic text-muted">Dinamis per pegawai</td>
                                    <td class="px-4 py-4">
                                        <code class="rounded bg-soft px-2 py-0.5 font-mono text-xs text-muted">employees.atasan_langsung_id</code>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass['Read-only'] }}">Read-only</span>
                                    </td>
                                </tr>
                                <tr class="transition-colors hover:bg-soft/60">
                                    <td class="px-4 py-4 font-mono text-sm font-semibold text-muted">2</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-ink">Kabag / Verifikator</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-ink" x-text="kabag">Dra. Merlina Rahman</td>
                                    <td class="px-4 py-4">
                                        <code class="rounded bg-soft px-2 py-0.5 font-mono text-xs text-muted">approval_config.approver_id</code>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass['Aktif'] }}">Aktif</span>
                                    </td>
                                </tr>
                                <tr class="transition-colors hover:bg-soft/60" :class="isDuplikat ? 'opacity-50' : ''">
                                    <td class="px-4 py-4 font-mono text-sm font-semibold text-muted">3</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-ink">Pimpinan / PYBMC</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-ink" x-text="isDuplikat ? pimpinan + ' (akan diskip)' : pimpinan">Dr. Abdul Kadir</td>
                                    <td class="px-4 py-4">
                                        <code class="rounded bg-soft px-2 py-0.5 font-mono text-xs text-muted">approval_config.approver_id</code>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold transition-colors"
                                            :class="isDuplikat ? 'bg-warning/10 text-warning' : '{{ $badgeClass['Aktif'] }}'"
                                            x-text="isDuplikat ? 'Skip duplikat' : 'Aktif'"
                                        ></span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Mode Konfigurasi --}}
                <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-ink">Mode Konfigurasi</h3>
                    <p class="mt-1 text-sm text-muted">Status dan rencana pengembangan skema approval chain.</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {{-- Mode aktif --}}
                        <div class="flex items-start gap-3 rounded-lg border border-primary/20 bg-primary/5 p-4">
                            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                <svg class="h-4 w-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-primary">Mode Aktif</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink">Default Global</p>
                                <p class="mt-1 text-xs text-muted">Satu konfigurasi approver berlaku untuk semua pegawai dan unit.</p>
                            </div>
                        </div>
                        {{-- Pengembangan lanjutan --}}
                        <div class="flex items-start gap-3 rounded-lg border border-border bg-soft p-4">
                            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-border">
                                <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-muted">Pengembangan Lanjutan</p>
                                <p class="mt-0.5 text-sm font-semibold text-muted">Per Unit / Per Pegawai</p>
                                <p class="mt-1 text-xs text-muted">Konfigurasi dinamis berbeda-beda per unit kerja atau per pegawai.</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Audit Trail --}}
                <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                    <div class="border-b border-border px-6 py-4">
                        <h3 class="text-base font-semibold text-ink">Audit Perubahan</h3>
                        <p class="mt-0.5 text-sm text-muted">Riwayat setiap perubahan konfigurasi approval chain.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-soft">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Waktu</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Actor</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Field</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Sebelum</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Sesudah</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($auditRows as $row)
                                    <tr class="transition-colors hover:bg-soft/60">
                                        <td class="px-4 py-3.5 text-xs text-muted">{{ $row['time'] }}</td>
                                        <td class="px-4 py-3.5 text-sm font-semibold text-ink">{{ $row['actor'] }}</td>
                                        <td class="px-4 py-3.5 text-sm text-ink">{{ $row['field'] }}</td>
                                        <td class="px-4 py-3.5 text-sm text-muted">{{ $row['before'] }}</td>
                                        <td class="px-4 py-3.5 text-sm font-semibold text-ink">{{ $row['after'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">
                                            Belum ada perubahan konfigurasi tercatat.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- RIGHT — Form Konfigurasi + Ketentuan --}}
            {{-- ============================================================ --}}
            <div class="space-y-6">

                {{-- Form Edit Konfigurasi --}}
                <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-ink">Edit Konfigurasi Default</h3>
                    <p class="mt-1 text-sm text-muted">Perubahan berlaku untuk pengajuan cuti baru.</p>

                    <div class="mt-5 space-y-5">

                        {{-- Dropdown 1: Kabag / Verifikator --}}
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-ink" for="cfg-kabag">
                                Approver Default — Kabag / Verifikator
                            </label>
                            <p class="text-xs text-muted">Stage 2: approver verifikasi awal pengajuan cuti.</p>
                            <select
                                id="cfg-kabag"
                                x-model="kabag"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            >
                                @foreach($approvers as $ap)
                                    <option value="{{ $ap['name'] }}">{{ $ap['name'] }} — {{ $ap['unit'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Dropdown 2: Pimpinan / PYBMC --}}
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-ink" for="cfg-pimpinan">
                                Approver Default — Pimpinan / PYBMC
                            </label>
                            <p class="text-xs text-muted">Stage 3: approver final pemberi keputusan akhir.</p>
                            <select
                                id="cfg-pimpinan"
                                x-model="pimpinan"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                :class="isDuplikat ? 'border-warning ring-1 ring-warning/30' : ''"
                            >
                                @foreach($approvers as $ap)
                                    <option value="{{ $ap['name'] }}">{{ $ap['name'] }} — {{ $ap['unit'] }}</option>
                                @endforeach
                            </select>
                            {{-- Inline warning duplikat --}}
                            <p
                                x-show="isDuplikat"
                                x-transition
                                class="text-xs font-semibold text-warning"
                            >
                                ⚠ Sama dengan Stage 2 — Stage 3 akan diskip otomatis.
                            </p>
                        </div>

                        {{-- Alasan --}}
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-ink" for="cfg-reason">
                                Alasan Perubahan <span class="text-danger">*</span>
                            </label>
                            <textarea
                                id="cfg-reason"
                                x-model="reason"
                                rows="4"
                                placeholder="Contoh: penyesuaian struktur verifikator cuti tahun anggaran baru"
                                class="w-full resize-y rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                :class="saved && reason.trim() === '' ? 'border-danger ring-1 ring-danger/30' : ''"
                            ></textarea>
                            <p
                                x-show="saved && reason.trim() === ''"
                                x-transition
                                class="text-xs font-semibold text-danger"
                            >
                                Alasan wajib diisi agar perubahan tercatat di audit log.
                            </p>
                        </div>
                    </div>

                    {{-- Success state --}}
                    <div
                        x-show="saved && reason.trim() !== ''"
                        x-transition
                        class="mt-5 rounded-lg border border-success/20 bg-success/10 px-4 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 shrink-0 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <p class="text-sm font-semibold text-success">Konfigurasi tersimpan dan tercatat di audit log.</p>
                        </div>
                    </div>

                    {{-- Tombol simpan --}}
                    <button
                        type="button"
                        @click="openConfirm()"
                        class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 active:opacity-80 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="reason.trim() === ''"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Simpan Konfigurasi
                    </button>
                    <p class="mt-2 text-center text-xs text-muted">Akan muncul konfirmasi sebelum perubahan diterapkan.</p>
                </div>

                {{-- Ketentuan Sistem --}}
                <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-ink">Ketentuan Sistem</h3>
                    <ul class="mt-4 space-y-3">
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Akses terbatas</p>
                                <p class="text-xs text-muted">Hanya Super Admin yang dapat mengubah konfigurasi ini.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-info"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Efek perubahan</p>
                                <p class="text-xs text-muted">Berlaku mulai pengajuan berikutnya, tidak retroaktif.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Audit wajib</p>
                                <p class="text-xs text-muted">Setiap perubahan tercatat lengkap dengan actor dan alasan.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warning"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Skip duplikat otomatis</p>
                                <p class="text-xs text-muted">Jika Stage 2 dan 3 sama, Stage final dilewati sistem.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-muted"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Monitoring saja</p>
                                <p class="text-xs text-muted">Admin Kepegawaian hanya dapat melihat, tidak mengubah.</p>
                            </div>
                        </li>
                    </ul>
                </div>

            </div>
        </div>

        {{-- ================================================================ --}}
        {{-- CONFIRMATION MODAL --}}
        {{-- ================================================================ --}}
        <div
            x-show="showConfirm"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4"
            style="display: none;"
        >
            <div
                x-show="showConfirm"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                @click.outside="showConfirm = false"
                class="w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl"
            >
                {{-- Modal header --}}
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-warning/10">
                        <svg class="h-5 w-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-ink">Konfirmasi Perubahan Konfigurasi</h3>
                        <p class="mt-1 text-sm text-muted">
                            Perubahan ini akan diterapkan untuk semua pengajuan cuti baru dan dicatat sebagai audit trail.
                        </p>
                    </div>
                </div>

                {{-- Ringkasan perubahan --}}
                <div class="mt-5 rounded-lg border border-border bg-soft p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-muted">Ringkasan Perubahan</p>
                    <dl class="mt-3 space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-sm text-muted">Kabag / Verifikator</dt>
                            <dd class="text-sm font-semibold text-ink" x-text="kabag"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-sm text-muted">Pimpinan / PYBMC</dt>
                            <dd class="text-sm font-semibold text-ink" x-text="isDuplikat ? pimpinan + ' (diskip)' : pimpinan"></dd>
                        </div>
                        <div class="border-t border-border pt-2">
                            <dt class="text-xs font-semibold text-muted">Alasan</dt>
                            <dd class="mt-1 text-sm text-ink" x-text="reason"></dd>
                        </div>
                    </dl>
                </div>

                {{-- Aksi modal --}}
                <div class="mt-5 flex justify-end gap-3">
                    <button
                        type="button"
                        @click="showConfirm = false"
                        class="rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-muted transition-colors hover:bg-soft"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="confirmSave()"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Ya, Terapkan Perubahan
                    </button>
                </div>
            </div>
        </div>

    </div>
</x-layouts.app>
