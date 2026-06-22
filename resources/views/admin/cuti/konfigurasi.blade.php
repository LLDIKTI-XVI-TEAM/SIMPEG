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

        $rules = [
            ['label' => 'Akses',             'value' => 'Super Admin only'],
            ['label' => 'Efek perubahan',    'value' => 'Berlaku untuk pengajuan baru'],
            ['label' => 'Audit',             'value' => 'Perubahan wajib tercatat'],
            ['label' => 'Duplikat approver', 'value' => 'Stage sama dilewati otomatis'],
        ];

        $badgeClass = [
            'Read-only'    => 'bg-info/10 text-info',
            'Configurable' => 'bg-primary/10 text-primary',
            'Aktif'        => 'bg-success/10 text-success',
        ];
    @endphp

    <div class="space-y-6">

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
            <span class="inline-flex h-fit shrink-0 items-center rounded-full bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary">
                Super Admin only
            </span>
        </div>

        {{-- ================================================================ --}}
        {{-- STAT CARDS --}}
        {{-- ================================================================ --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach($rules as $rule)
                <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ $rule['label'] }}</p>
                    <p class="mt-2 text-sm font-semibold text-ink">{{ $rule['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ================================================================ --}}
        {{-- MAIN LAYOUT — tabel kiri, panel edit kanan --}}
        {{-- ================================================================ --}}
        <div
            class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px]"
            x-data="{ stage: 'Stage 2 — Kabag / Verifikator', approver: 'Dra. Merlina Rahman', reason: '', saved: false }"
        >
            {{-- ============================================================ --}}
            {{-- LEFT — Tabel konfigurasi + audit trail --}}
            {{-- ============================================================ --}}
            <div class="space-y-6">

                {{-- Approval Config Table --}}
                <div class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-ink">Approval Config</h3>
                            <p class="mt-0.5 text-sm text-muted">
                                Konfigurasi approval chain yang aktif saat ini. Stage 1 bersumber dari data pegawai.
                            </p>
                        </div>
                        {{-- Indikator status aktif --}}
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
                                    <td class="px-4 py-4 text-sm text-muted italic">Dinamis per pegawai</td>
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
                                    <td class="px-4 py-4 text-sm font-semibold text-ink">Dra. Merlina Rahman</td>
                                    <td class="px-4 py-4">
                                        <code class="rounded bg-soft px-2 py-0.5 font-mono text-xs text-muted">approval_config.approver_id</code>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass['Aktif'] }}">Aktif</span>
                                    </td>
                                </tr>
                                <tr class="transition-colors hover:bg-soft/60">
                                    <td class="px-4 py-4 font-mono text-sm font-semibold text-muted">3</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-ink">Pimpinan / PYBMC</td>
                                    <td class="px-4 py-4 text-sm font-semibold text-ink">Dr. Abdul Kadir</td>
                                    <td class="px-4 py-4">
                                        <code class="rounded bg-soft px-2 py-0.5 font-mono text-xs text-muted">approval_config.approver_id</code>
                                    </td>
                                    <td class="px-4 py-4">
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
            {{-- RIGHT — Panel edit cepat + checklist --}}
            {{-- ============================================================ --}}
            <div class="space-y-6">

                {{-- Edit Cepat --}}
                <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-ink">Edit Konfigurasi</h3>
                    <p class="mt-1 text-sm text-muted">Perbarui approver default tanpa membuka halaman terpisah.</p>

                    <div class="mt-5 space-y-4">
                        {{-- Pilih Stage --}}
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-ink" for="cfg-stage">Stage</label>
                            <select
                                id="cfg-stage"
                                x-model="stage"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            >
                                <option>Stage 2 — Kabag / Verifikator</option>
                                <option>Stage 3 — Pimpinan / PYBMC</option>
                            </select>
                        </div>

                        {{-- Pilih Approver --}}
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-ink" for="cfg-approver">Approver</label>
                            <select
                                id="cfg-approver"
                                x-model="approver"
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            >
                                @foreach($approvers as $ap)
                                    <option value="{{ $ap['name'] }}">{{ $ap['name'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Alasan --}}
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-ink" for="cfg-reason">Alasan Perubahan</label>
                            <textarea
                                id="cfg-reason"
                                x-model="reason"
                                rows="4"
                                placeholder="Contoh: penyesuaian struktur verifikator cuti tahun anggaran baru"
                                class="w-full resize-y rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm transition-colors focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            ></textarea>
                        </div>
                    </div>

                    {{-- Peringatan jika alasan kosong --}}
                    <div
                        x-show="saved && reason.trim() === ''"
                        x-transition
                        class="mt-4 rounded-lg border border-warning/20 bg-warning/10 px-4 py-3 text-sm font-medium text-warning"
                    >
                        Alasan perubahan wajib diisi agar tercatat di audit log.
                    </div>

                    {{-- Sukses --}}
                    <div
                        x-show="saved && reason.trim() !== ''"
                        x-transition
                        class="mt-4 rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm font-medium text-success"
                    >
                        Konfigurasi berhasil disimpan dan tercatat di audit log.
                    </div>

                    <button
                        type="button"
                        @click="saved = true"
                        class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 active:opacity-80"
                    >
                        {{-- heroicon: check --}}
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Simpan Perubahan
                    </button>
                </div>

                {{-- Checklist Kesesuaian --}}
                <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-ink">Ketentuan Sistem</h3>
                    <ul class="mt-4 space-y-3">
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Akses terbatas</p>
                                <p class="text-xs text-muted">Hanya Super Admin yang dapat mengubah konfigurasi ini.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Efek perubahan</p>
                                <p class="text-xs text-muted">Berlaku mulai pengajuan cuti berikutnya, tidak retroaktif.</p>
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
                                <p class="text-sm font-semibold text-ink">Skip duplikat</p>
                                <p class="text-xs text-muted">Jika approver stage 2 dan 3 sama, stage final dilewati otomatis.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-info"></span>
                            <div>
                                <p class="text-sm font-semibold text-ink">Monitoring saja</p>
                                <p class="text-xs text-muted">Admin Kepegawaian hanya dapat melihat, bukan mengubah konfigurasi.</p>
                            </div>
                        </li>
                    </ul>
                </div>

            </div>
        </div>
    </div>
</x-layouts.app>
