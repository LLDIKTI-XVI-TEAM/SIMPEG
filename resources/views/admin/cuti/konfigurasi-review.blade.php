<x-layouts.app title="Review Konfigurasi Approval">
    @php
        $approvers = [
            ['name' => 'Dra. Merlina Rahman',        'role' => 'Kabag Tata Usaha',           'unit' => 'Bagian Umum'],
            ['name' => 'Riza Hamzah',                 'role' => 'Verifikator Kepegawaian',    'unit' => 'Bagian SDM'],
            ['name' => 'Dr. Abdul Kadir',             'role' => 'Pimpinan / PYBMC',           'unit' => 'LLDIKTI Wilayah XVI'],
            ['name' => 'Nurarningsih Dumbea, S.P.',  'role' => 'Koordinator Kepegawaian',    'unit' => 'Bagian SDM'],
        ];

        $flowStages = [
            ['stage' => 'Stage 1', 'name' => 'Atasan Langsung',    'source' => 'employees.atasan_langsung_id', 'status' => 'Read-only',    'note' => 'Mengikuti atasan langsung pada data pegawai.'],
            ['stage' => 'Stage 2', 'name' => 'Kabag / Verifikator', 'source' => 'approval_config',             'status' => 'Configurable', 'note' => 'Dipilih sebagai approver default tahap verifikasi.'],
            ['stage' => 'Stage 3', 'name' => 'Pimpinan / PYBMC',   'source' => 'approval_config',             'status' => 'Configurable', 'note' => 'Dipilih sebagai approver final pemberi keputusan.'],
        ];

        $rules = [
            ['label' => 'Akses',             'value' => 'Super Admin only',              'tone' => 'primary'],
            ['label' => 'Efek perubahan',    'value' => 'Berlaku untuk pengajuan baru',  'tone' => 'info'],
            ['label' => 'Audit',             'value' => 'Perubahan wajib tercatat',      'tone' => 'success'],
            ['label' => 'Duplikat approver', 'value' => 'Stage sama dilewati otomatis',  'tone' => 'warning'],
        ];

        $auditRows = [
            ['time' => '22 Jun 2026, 16:12', 'actor' => 'Super Admin', 'field' => 'Stage 2',       'before' => 'Riza Hamzah',      'after' => 'Dra. Merlina Rahman'],
            ['time' => '20 Jun 2026, 09:40', 'actor' => 'Super Admin', 'field' => 'Stage 3',       'before' => 'Dr. Abdul Kadir',  'after' => 'Dr. Abdul Kadir'],
            ['time' => '18 Jun 2026, 14:25', 'actor' => 'Super Admin', 'field' => 'Skip duplikat', 'before' => 'Tidak aktif',      'after' => 'Aktif'],
        ];

        $toneText = [
            'primary' => 'text-primary',
            'info'    => 'text-info',
            'success' => 'text-success',
            'warning' => 'text-warning',
        ];

        $badgeClass = [
            'Read-only'   => 'bg-info/10 text-info',
            'Configurable'=> 'bg-primary/10 text-primary',
            'Aktif'       => 'bg-success/10 text-success',
            'Draft'       => 'bg-warning/10 text-warning',
        ];
    @endphp

    {{-- ================================================================ --}}
    {{-- PAGE HEADER --}}
    {{-- ================================================================ --}}
    <div class="space-y-8">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="max-w-2xl">
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Review Konfigurasi</span>
                </nav>
                <h2 class="text-2xl font-semibold text-ink" style="text-wrap: balance">
                    Review Konfigurasi Approval
                </h2>
                <p class="mt-1.5 text-sm text-muted">
                    Tiga alternatif tampilan untuk halaman konfigurasi approval chain cuti.
                    Semua layout ini adalah fitur eksklusif <strong class="font-semibold text-ink">Super Admin</strong>.
                </p>
            </div>
            <a
                href="{{ route('cuti.config') }}"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-ink shadow-sm transition-colors hover:bg-soft"
            >
                {{-- heroicon: arrow-left --}}
                <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                Kembali ke Konfigurasi
            </a>
        </div>

        {{-- ============================================================ --}}
        {{-- STAT CARDS --}}
        {{-- ============================================================ --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach($rules as $rule)
                <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ $rule['label'] }}</p>
                    <p class="mt-2 text-sm font-semibold {{ $toneText[$rule['tone']] }}">{{ $rule['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ============================================================ --}}
        {{-- TAB SWITCHER --}}
        {{-- ============================================================ --}}
        <div x-data="{ tab: 'a' }">

            {{-- Tab Navigation --}}
            <div class="flex items-center gap-1 overflow-x-auto rounded-xl border border-border bg-soft p-1">
                <button
                    type="button"
                    @click="tab = 'a'"
                    :class="tab === 'a'
                        ? 'bg-surface text-ink shadow-sm font-semibold'
                        : 'text-muted hover:text-ink font-medium'"
                    class="flex shrink-0 items-center gap-2 rounded-lg px-4 py-2 text-sm transition-all"
                >
                    <span
                        :class="tab === 'a' ? 'bg-success/10 text-success' : 'bg-border text-muted'"
                        class="rounded-full px-2 py-0.5 text-xs font-semibold transition-colors"
                    >Direkomendasikan</span>
                    Layout A — Control Center
                </button>
                <button
                    type="button"
                    @click="tab = 'b'"
                    :class="tab === 'b'
                        ? 'bg-surface text-ink shadow-sm font-semibold'
                        : 'text-muted hover:text-ink font-medium'"
                    class="flex shrink-0 items-center gap-2 rounded-lg px-4 py-2 text-sm transition-all"
                >
                    Layout B — Stage Builder
                </button>
                <button
                    type="button"
                    @click="tab = 'c'"
                    :class="tab === 'c'
                        ? 'bg-surface text-ink shadow-sm font-semibold'
                        : 'text-muted hover:text-ink font-medium'"
                    class="flex shrink-0 items-center gap-2 rounded-lg px-4 py-2 text-sm transition-all"
                >
                    Layout C — Config Table
                </button>
            </div>

            {{-- ========================================================== --}}
            {{-- LAYOUT A — Control Center --}}
            {{-- ========================================================== --}}
            <div x-show="tab === 'a'" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" class="mt-6">
                <div class="mb-4">
                    <p class="text-sm text-muted">
                        Tampilan paling seimbang — ringkas, jelas, semua kontrol utama terlihat di viewport awal.
                    </p>
                </div>

                <div
                    class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px]"
                    x-data="{ kabag: 'Dra. Merlina Rahman', pimpinan: 'Dr. Abdul Kadir', saved: false }"
                >
                    {{-- Left column --}}
                    <div class="space-y-6">

                        {{-- Approval Chain Preview --}}
                        <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h4 class="text-base font-semibold text-ink">Approval Chain Aktif</h4>
                                    <p class="mt-1 text-sm text-muted">Preview urutan approval untuk pengajuan cuti baru.</p>
                                </div>
                                <span class="w-fit rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Super Admin only</span>
                            </div>

                            <div class="mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                                {{-- Stage 1 --}}
                                <div class="rounded-lg bg-soft p-4">
                                    <p class="text-xs font-semibold text-muted">Stage 1</p>
                                    <p class="mt-1 text-sm font-semibold text-ink">Atasan Langsung</p>
                                    <p class="mt-2 text-xs text-muted">Dari data pegawai</p>
                                </div>
                                {{-- Stage 2 --}}
                                <div class="rounded-lg bg-primary/5 p-4 ring-1 ring-primary/20">
                                    <p class="text-xs font-semibold text-primary">Stage 2</p>
                                    <p class="mt-1 text-sm font-semibold text-ink" x-text="kabag"></p>
                                    <p class="mt-2 text-xs text-muted">Kabag / Verifikator</p>
                                </div>
                                {{-- Stage 3 --}}
                                <div class="rounded-lg bg-primary/5 p-4 ring-1 ring-primary/20">
                                    <p class="text-xs font-semibold text-primary">Stage 3</p>
                                    <p class="mt-1 text-sm font-semibold text-ink" x-text="pimpinan"></p>
                                    <p class="mt-2 text-xs text-muted">Pimpinan / PYBMC</p>
                                </div>
                                {{-- Final --}}
                                <div class="rounded-lg bg-success/5 p-4 ring-1 ring-success/20">
                                    <p class="text-xs font-semibold text-success">Final</p>
                                    <p class="mt-1 text-sm font-semibold text-ink">Disetujui</p>
                                    <p class="mt-2 text-xs text-muted">Saldo cuti dikurangi</p>
                                </div>
                            </div>
                        </div>

                        {{-- Struktur Stage Table --}}
                        <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                            <div class="border-b border-border px-6 py-4">
                                <h4 class="text-base font-semibold text-ink">Struktur Stage</h4>
                                <p class="mt-1 text-sm text-muted">Stage 1 dari atasan langsung; stage 2 & 3 disimpan ke <code class="rounded bg-soft px-1 py-0.5 font-mono text-xs">approval_config</code>.</p>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-soft">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Stage</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Role</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Source</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Status</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @foreach($flowStages as $stage)
                                            <tr class="transition-colors hover:bg-soft/60">
                                                <td class="px-4 py-3 font-mono text-sm font-semibold text-muted">{{ $stage['stage'] }}</td>
                                                <td class="px-4 py-3 text-sm font-semibold text-ink">{{ $stage['name'] }}</td>
                                                <td class="px-4 py-3 font-mono text-xs text-muted">{{ $stage['source'] }}</td>
                                                <td class="px-4 py-3">
                                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass[$stage['status']] }}">
                                                        {{ $stage['status'] }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-muted">{{ $stage['note'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- Right column --}}
                    <div class="space-y-6">

                        {{-- Form Konfigurasi --}}
                        <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                            <h4 class="text-base font-semibold text-ink">Form Konfigurasi Default</h4>
                            <p class="mt-1 text-sm text-muted">Perubahan berlaku untuk pengajuan cuti baru.</p>

                            <div class="mt-5 space-y-4">
                                <div class="space-y-1.5">
                                    <label class="text-sm font-semibold text-ink" for="la-kabag">Kabag / Verifikator</label>
                                    <select id="la-kabag" x-model="kabag" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        @foreach($approvers as $approver)
                                            <option value="{{ $approver['name'] }}">{{ $approver['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-semibold text-ink" for="la-pimpinan">Pimpinan / PYBMC</label>
                                    <select id="la-pimpinan" x-model="pimpinan" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        @foreach($approvers as $approver)
                                            <option value="{{ $approver['name'] }}">{{ $approver['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Warning duplikat --}}
                            <div
                                x-show="kabag === pimpinan"
                                x-transition
                                class="mt-4 rounded-lg border border-warning/20 bg-warning/10 px-4 py-3 text-sm font-medium text-warning"
                            >
                                Stage final akan dilewati otomatis karena approver stage 2 dan 3 sama.
                            </div>

                            {{-- Success saved --}}
                            <div
                                x-show="saved"
                                x-transition
                                class="mt-4 rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm font-medium text-success"
                            >
                                Draft konfigurasi tersimpan dan siap dicatat di audit log.
                            </div>

                            <button
                                type="button"
                                @click="saved = true"
                                class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                            >
                                {{-- heroicon: check --}}
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                                Simpan Konfigurasi
                            </button>
                        </div>

                        {{-- Ketentuan Sistem --}}
                        <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                            <h4 class="text-base font-semibold text-ink">Ketentuan Sistem</h4>
                            <ul class="mt-4 space-y-3">
                                <li class="flex items-start gap-3">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success"></span>
                                    <p class="text-sm text-muted">Tidak ada opsi tolak — hanya setujui atau tunda di halaman approver.</p>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success"></span>
                                    <p class="text-sm text-muted">Admin Kepegawaian hanya monitoring, bukan pengambil keputusan approval.</p>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success"></span>
                                    <p class="text-sm text-muted">Konfigurasi disiapkan agar nantinya bisa dinamis per unit atau pegawai.</p>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ========================================================== --}}
            {{-- LAYOUT B — Stage Builder --}}
            {{-- ========================================================== --}}
            <div x-show="tab === 'b'" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" class="mt-6">
                <div class="mb-4">
                    <p class="text-sm text-muted">
                        Tampilan naratif dan bertahap — cocok bila Super Admin perlu memahami konfigurasi secara step-by-step.
                    </p>
                </div>

                <div
                    class="grid grid-cols-1 gap-6 lg:grid-cols-[260px_minmax(0,1fr)]"
                    x-data="{ kabag: 'Riza Hamzah', pimpinan: 'Dr. Abdul Kadir' }"
                >
                    {{-- Sidebar panduan --}}
                    <aside class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                        <h4 class="text-base font-semibold text-ink">Langkah Konfigurasi</h4>
                        <p class="mt-2 text-sm text-muted">Fase 1 memakai alur seragam: atasan langsung → Kabag → Pimpinan.</p>
                        <ol class="mt-5 space-y-3">
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">1</span>
                                <div>
                                    <p class="text-sm font-semibold text-ink">Tentukan stage</p>
                                    <p class="mt-0.5 text-xs text-muted">Stage 1 tetap read-only.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">2</span>
                                <div>
                                    <p class="text-sm font-semibold text-ink">Pilih approver</p>
                                    <p class="mt-0.5 text-xs text-muted">Stage 2 & 3 dipilih dari daftar user.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">3</span>
                                <div>
                                    <p class="text-sm font-semibold text-ink">Validasi & audit</p>
                                    <p class="mt-0.5 text-xs text-muted">Skip duplikat dan catat perubahan.</p>
                                </div>
                            </li>
                        </ol>
                    </aside>

                    {{-- Main content --}}
                    <div class="space-y-6">
                        {{-- Stage cards --}}
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                            {{-- Stage 1 --}}
                            <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                                <span class="rounded-full bg-info/10 px-3 py-1 text-xs font-semibold text-info">Stage 1</span>
                                <h4 class="mt-4 text-sm font-semibold text-ink">Atasan Langsung</h4>
                                <p class="mt-2 text-sm text-muted">Diambil otomatis dari data pegawai.</p>
                                <code class="mt-4 block rounded-lg bg-soft px-3 py-2 font-mono text-xs text-muted">
                                    employees.atasan_langsung_id
                                </code>
                            </div>

                            {{-- Stage 2 --}}
                            <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Stage 2</span>
                                <h4 class="mt-4 text-sm font-semibold text-ink">Kabag / Verifikator</h4>
                                <div class="mt-4 space-y-1.5">
                                    <label class="text-xs font-semibold text-muted" for="lb-kabag">Approver default</label>
                                    <select id="lb-kabag" x-model="kabag" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        @foreach($approvers as $approver)
                                            <option value="{{ $approver['name'] }}">{{ $approver['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Stage 3 --}}
                            <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Stage 3</span>
                                <h4 class="mt-4 text-sm font-semibold text-ink">Pimpinan / PYBMC</h4>
                                <div class="mt-4 space-y-1.5">
                                    <label class="text-xs font-semibold text-muted" for="lb-pimpinan">Approver final</label>
                                    <select id="lb-pimpinan" x-model="pimpinan" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        @foreach($approvers as $approver)
                                            <option value="{{ $approver['name'] }}">{{ $approver['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        {{-- Validasi & Ringkasan --}}
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

                            {{-- Validasi --}}
                            <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                                <h4 class="text-sm font-semibold text-ink">Validasi Konfigurasi</h4>
                                <div class="mt-4 space-y-3">
                                    <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3">
                                        <p class="text-sm font-semibold text-success">Stage 1 valid</p>
                                        <p class="mt-0.5 text-xs text-muted">Atasan langsung tersedia sebagai sumber dinamis.</p>
                                    </div>
                                    <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3">
                                        <p class="text-sm font-semibold text-success">Approver default lengkap</p>
                                        <p class="mt-0.5 text-xs text-muted">Stage 2 dan 3 sudah memiliki approver.</p>
                                    </div>
                                    <div
                                        x-show="kabag === pimpinan"
                                        x-transition
                                        class="rounded-lg border border-warning/20 bg-warning/10 px-4 py-3"
                                    >
                                        <p class="text-sm font-semibold text-warning">Skip stage aktif</p>
                                        <p class="mt-0.5 text-xs text-muted">Approver duplikat akan dilewati otomatis.</p>
                                    </div>
                                </div>
                            </div>

                            {{-- Ringkasan Simpan --}}
                            <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                                <h4 class="text-sm font-semibold text-ink">Ringkasan Simpan</h4>
                                <dl class="mt-4 space-y-3">
                                    <div class="flex items-center justify-between gap-4 border-b border-border pb-3">
                                        <dt class="text-sm text-muted">Table</dt>
                                        <dd class="font-mono text-sm font-semibold text-ink">approval_config</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-4 border-b border-border pb-3">
                                        <dt class="text-sm text-muted">Stage 2</dt>
                                        <dd class="text-right text-sm font-semibold text-ink" x-text="kabag"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-sm text-muted">Stage 3</dt>
                                        <dd class="text-right text-sm font-semibold text-ink" x-text="pimpinan"></dd>
                                    </div>
                                </dl>
                                <button type="button" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    Simpan Approval Chain
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ========================================================== --}}
            {{-- LAYOUT C — Configuration Table --}}
            {{-- ========================================================== --}}
            <div x-show="tab === 'c'" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" class="mt-6">
                <div class="mb-4">
                    <p class="text-sm text-muted">
                        Tampilan paling padat untuk Super Admin yang terbiasa bekerja lewat tabel dan audit trail.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                    {{-- Left column --}}
                    <div class="space-y-6">

                        {{-- Approval Config Table --}}
                        <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                            <div class="border-b border-border px-6 py-4">
                                <h4 class="text-base font-semibold text-ink">Approval Config</h4>
                                <p class="mt-1 text-sm text-muted">Representasi data konfigurasi approval chain yang tersimpan.</p>
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
                                            <td class="px-4 py-3 font-mono text-sm font-semibold text-muted">1</td>
                                            <td class="px-4 py-3 text-sm font-semibold text-ink">Atasan Langsung</td>
                                            <td class="px-4 py-3 text-sm text-muted">Dinamis per pegawai</td>
                                            <td class="px-4 py-3 font-mono text-xs text-muted">employees.atasan_langsung_id</td>
                                            <td class="px-4 py-3">
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass['Read-only'] }}">Read-only</span>
                                            </td>
                                        </tr>
                                        <tr class="transition-colors hover:bg-soft/60">
                                            <td class="px-4 py-3 font-mono text-sm font-semibold text-muted">2</td>
                                            <td class="px-4 py-3 text-sm font-semibold text-ink">Kabag / Verifikator</td>
                                            <td class="px-4 py-3 text-sm text-ink">Dra. Merlina Rahman</td>
                                            <td class="px-4 py-3 font-mono text-xs text-muted">approval_config.approver_id</td>
                                            <td class="px-4 py-3">
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass['Aktif'] }}">Aktif</span>
                                            </td>
                                        </tr>
                                        <tr class="transition-colors hover:bg-soft/60">
                                            <td class="px-4 py-3 font-mono text-sm font-semibold text-muted">3</td>
                                            <td class="px-4 py-3 text-sm font-semibold text-ink">Pimpinan / PYBMC</td>
                                            <td class="px-4 py-3 text-sm text-ink">Dr. Abdul Kadir</td>
                                            <td class="px-4 py-3 font-mono text-xs text-muted">approval_config.approver_id</td>
                                            <td class="px-4 py-3">
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass['Aktif'] }}">Aktif</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {{-- Audit Trail Table --}}
                        <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                            <div class="border-b border-border px-6 py-4">
                                <h4 class="text-base font-semibold text-ink">Audit Perubahan</h4>
                                <p class="mt-1 text-sm text-muted">Setiap perubahan konfigurasi wajib tercatat sebagai audit trail.</p>
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
                                        @foreach($auditRows as $row)
                                            <tr class="transition-colors hover:bg-soft/60">
                                                <td class="px-4 py-3 text-xs text-muted">{{ $row['time'] }}</td>
                                                <td class="px-4 py-3 text-sm font-semibold text-ink">{{ $row['actor'] }}</td>
                                                <td class="px-4 py-3 text-sm text-ink">{{ $row['field'] }}</td>
                                                <td class="px-4 py-3 text-sm text-muted">{{ $row['before'] }}</td>
                                                <td class="px-4 py-3 text-sm font-semibold text-ink">{{ $row['after'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- Right column --}}
                    <div class="space-y-6">

                        {{-- Edit Cepat --}}
                        <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                            <h4 class="text-base font-semibold text-ink">Edit Cepat</h4>
                            <p class="mt-1 text-sm text-muted">Perbarui approver default tanpa membuka halaman terpisah.</p>
                            <div class="mt-5 space-y-4">
                                <div class="space-y-1.5">
                                    <label class="text-sm font-semibold text-ink" for="lc-stage">Stage</label>
                                    <select id="lc-stage" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        <option>Stage 2 — Kabag / Verifikator</option>
                                        <option>Stage 3 — Pimpinan / PYBMC</option>
                                    </select>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-semibold text-ink" for="lc-approver">Approver</label>
                                    <select id="lc-approver" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        @foreach($approvers as $approver)
                                            <option>{{ $approver['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-semibold text-ink" for="lc-reason">Alasan Perubahan</label>
                                    <textarea
                                        id="lc-reason"
                                        rows="4"
                                        placeholder="Contoh: penyesuaian struktur verifikator cuti"
                                        class="w-full resize-y rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    ></textarea>
                                </div>
                            </div>
                            <button type="button" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                                Simpan Perubahan
                            </button>
                        </div>

                        {{-- Checklist Kesesuaian --}}
                        <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                            <h4 class="text-base font-semibold text-ink">Checklist Kesesuaian</h4>
                            <ul class="mt-4 space-y-3">
                                @foreach($rules as $rule)
                                    <li class="flex items-start gap-3">
                                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success"></span>
                                        <div>
                                            <p class="text-sm font-semibold text-ink">{{ $rule['label'] }}</p>
                                            <p class="text-xs text-muted">{{ $rule['value'] }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- end x-data tab --}}
    </div>{{-- end space-y-8 --}}
</x-layouts.app>
