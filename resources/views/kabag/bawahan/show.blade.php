<x-layouts.app title="Detail Bawahan" subtitle="Informasi detail profil dan kepegawaian bawahan.">

    @php
        // DUMMY DATA UNTUK UI (Read-Only)
        $bawahan = [
            'id' => $id ?? 1,
            'nama' => 'Ahmad Fauzi',
            'nip' => '19800101 200001 1 001',
            'jabatan' => 'Analis Kepegawaian Ahli Muda',
            'unit' => 'Subbagian Tata Usaha',
            'golongan' => 'III/c (Penata)',
            'status' => 'Aktif',
            'email' => 'ahmad.fauzi@example.com',
            'no_hp' => '081234567890',
            'tgl_lahir' => '01 Januari 1980',
            'pendidikan' => 'S1 Ilmu Pemerintahan',
        ];

        $riwayatJabatan = [
            ['jabatan' => 'Analis Kepegawaian Ahli Muda', 'unit' => 'Subbagian Tata Usaha', 'tmt' => '01-04-2022'],
            ['jabatan' => 'Analis Kepegawaian Ahli Pertama', 'unit' => 'Subbagian Tata Usaha', 'tmt' => '01-04-2018'],
            ['jabatan' => 'Pengelola Kepegawaian', 'unit' => 'Subbagian Umum', 'tmt' => '01-04-2015'],
        ];

        $ewsList = [
            ['pemicu' => 'Kenaikan Gaji Berkala (KGB)', 'sisa' => '55 Hari Lagi', 'status' => 'Warning'],
        ];
    @endphp

    <div class="mb-6 flex items-center justify-between">
        <x-ui.button href="{{ route('kepala-bagian.bawahan.index') }}" variant="secondary" size="md">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Kembali
        </x-ui.button>
    </div>

    <!-- HEADER PROFIL -->
    <div class="mb-6 bg-surface rounded-xl border border-border shadow-sm overflow-hidden relative">
        <div class="h-24 bg-gradient-to-r from-primary to-[#2143c2]"></div>
        <div class="px-6 pb-6 relative">
            <div class="flex flex-col sm:flex-row sm:items-end gap-6 -mt-12 mb-4">
                <div class="h-24 w-24 rounded-full bg-white p-1 shadow-sm shrink-0">
                    <div class="h-full w-full rounded-full bg-primary/10 flex items-center justify-center border border-border">
                        <span class="text-3xl font-bold text-primary">{{ substr($bawahan['nama'], 0, 1) }}</span>
                    </div>
                </div>
                <div class="pb-2">
                    <h1 class="text-2xl font-extrabold text-ink font-sans leading-tight">{{ $bawahan['nama'] }}</h1>
                    <p class="text-sm font-medium text-muted font-sans mt-1">{{ $bawahan['nip'] }} · {{ $bawahan['jabatan'] }}</p>
                </div>
                <div class="pb-2 sm:ml-auto">
                    <x-ui.badge variant="{{ $bawahan['status'] == 'Aktif' ? 'success' : 'warning' }}" size="lg">
                        {{ $bawahan['status'] }}
                    </x-ui.badge>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- KOLOM KIRI (Informasi Utama) -->
        <div class="lg:col-span-2 space-y-6">
            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Informasi Kepegawaian</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-8">
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Unit Kerja</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $bawahan['unit'] }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Golongan</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $bawahan['golongan'] }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Pendidikan Terakhir</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $bawahan['pendidikan'] }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Status Kepegawaian</p>
                        <p class="text-sm font-medium text-ink font-sans mt-0.5">Pegawai Negeri Sipil (PNS)</p>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Riwayat Jabatan</h3>
                <x-ui.timeline>
                    @foreach($riwayatJabatan as $riwayat)
                    <x-ui.timeline-item title="{{ $riwayat['jabatan'] }}" description="{{ $riwayat['unit'] }}" variant="success">
                        <p class="text-[10px] font-mono font-medium text-muted mt-1 bg-soft inline-block px-1.5 py-0.5 rounded">TMT: {{ $riwayat['tmt'] }}</p>
                    </x-ui.timeline-item>
                    @endforeach
                </x-ui.timeline>
            </x-ui.card>
        </div>

        <!-- KOLOM KANAN (Kontak & EWS) -->
        <div class="space-y-6">
            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Informasi Kontak & Pribadi</h3>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 shrink-0 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase text-muted font-sans tracking-wider">Email</p>
                            <p class="text-xs font-medium text-ink font-sans mt-0.5 break-all">{{ $bawahan['email'] }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 shrink-0 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase text-muted font-sans tracking-wider">No. HP / WhatsApp</p>
                            <p class="text-xs font-medium text-ink font-sans mt-0.5">{{ $bawahan['no_hp'] }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 shrink-0 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8.25v-1.5m0 1.5c-1.355 0-2.697.056-4.024.166C6.845 8.51 6 9.473 6 10.608v2.513m6-4.87c1.355 0 2.697.055 4.024.165C17.155 8.51 18 9.473 18 10.608v2.513m-3-8.093v1.5m0 0c-1.355 0-2.697.056-4.024.166C9.845 6.51 9 7.473 9 8.608v2.513m6-4.87c1.355 0 2.697.055 4.024.165C19.155 6.51 20 7.473 20 8.608v2.513" /></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase text-muted font-sans tracking-wider">Tanggal Lahir</p>
                            <p class="text-xs font-medium text-ink font-sans mt-0.5">{{ $bawahan['tgl_lahir'] }}</p>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">EWS Terkait Bawahan</h3>
                @if(count($ewsList) > 0)
                    <div class="space-y-3">
                        @foreach($ewsList as $ews)
                        <div class="flex items-center justify-between p-3 rounded-lg border {{ $ews['status'] == 'Urgent' ? 'border-danger/30 bg-danger/5' : 'border-warning/30 bg-warning/5' }}">
                            <div>
                                <p class="text-[11px] font-bold text-ink font-sans leading-tight">{{ $ews['pemicu'] }}</p>
                                <p class="text-[9px] font-medium text-muted font-sans mt-1">{{ $ews['sisa'] }}</p>
                            </div>
                            <x-ui.badge variant="{{ $ews['status'] == 'Urgent' ? 'danger' : 'warning' }}" size="sm" dot>
                                {{ $ews['status'] }}
                            </x-ui.badge>
                        </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-4">
                        <p class="text-xs text-muted font-sans">Tidak ada EWS yang perlu ditindaklanjuti.</p>
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>

</x-layouts.app>
