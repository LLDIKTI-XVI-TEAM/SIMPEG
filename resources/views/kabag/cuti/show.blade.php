<x-layouts.app title="Detail Pengajuan Cuti" subtitle="Tinjau dan berikan keputusan atas permohonan cuti bawahan.">

    @php
        // DUMMY DATA UNTUK UI
        $cuti = [
            'id' => $id ?? 1,
            'nama' => 'Ahmad Fauzi',
            'nip' => '19800101 200001 1 001',
            'jabatan' => 'Analis Kepegawaian Ahli Muda',
            'unit' => 'Subbagian Tata Usaha',
            'jenis_cuti' => 'Cuti Tahunan',
            'tgl_mulai' => '20 Jun 2026',
            'tgl_selesai' => '24 Jun 2026',
            'jml_hari' => '5',
            'alasan' => 'Acara keluarga di kampung halaman (pernikahan adik kandung).',
            'saldo_tahunan' => 12,
            'sisa_saldo' => 7,
            'status' => 'Menunggu Tindakan Saya',
            'tgl_ajukan' => '15 Jun 2026, 09:30',
        ];

        $timeline = [
            [
                'step' => 'Pengajuan',
                'aktor' => 'Ahmad Fauzi',
                'keputusan' => 'Diajukan',
                'waktu' => '15 Jun 2026, 09:30',
                'keterangan' => 'Mengajukan Cuti Tahunan',
                'active' => false,
            ],
            [
                'step' => 'Persetujuan Atasan Langsung',
                'aktor' => 'Demo Klabat (Anda)',
                'keputusan' => 'Menunggu Keputusan',
                'waktu' => '-',
                'keterangan' => '-',
                'active' => true,
            ],
            [
                'step' => 'Verifikasi Kepegawaian',
                'aktor' => 'Admin Kepegawaian',
                'keputusan' => 'Menunggu',
                'waktu' => '-',
                'keterangan' => '-',
                'active' => false,
            ]
        ];
    @endphp

    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Detail Pengajuan Cuti</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Cuti Bawahan', 'url' => route('kepala-bagian.cuti.index')],
                ['label' => 'Detail Cuti']
            ]" />
        </div>
    </div>

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <x-ui.button href="{{ route('kepala-bagian.cuti.index') }}" variant="secondary" size="md">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Kembali
        </x-ui.button>
        
        @if($cuti['status'] === 'Menunggu Tindakan Saya')
            <div class="flex items-center gap-2 bg-warning/10 px-4 py-2 rounded-lg border border-warning/20">
                <div class="w-2 h-2 rounded-full bg-warning animate-pulse"></div>
                <span class="text-sm font-semibold text-warning-dark">Menunggu Keputusan Anda</span>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- KOLOM KIRI (Informasi Utama) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Data Pemohon -->
            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                    Data Pemohon
                </h3>
                <div class="flex items-start gap-4">
                    <div class="h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 flex">
                        <span class="text-lg font-bold text-primary">{{ substr($cuti['nama'], 0, 1) }}</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-8 flex-1">
                        <div>
                            <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Nama & NIP</p>
                            <p class="text-sm font-bold text-ink font-sans mt-0.5">{{ $cuti['nama'] }}</p>
                            <p class="text-xs text-muted font-sans mt-0.5">{{ $cuti['nip'] }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Jabatan & Unit</p>
                            <p class="text-sm font-medium text-ink font-sans mt-0.5">{{ $cuti['jabatan'] }}</p>
                            <p class="text-xs text-muted font-sans mt-0.5">{{ $cuti['unit'] }}</p>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <!-- Data Cuti & Lampiran -->
            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    Informasi Pengajuan
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-6 gap-x-8">
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Jenis Cuti</p>
                        <p class="text-sm font-medium text-ink font-sans mt-1">
                            <x-ui.badge variant="info" size="md">{{ $cuti['jenis_cuti'] }}</x-ui.badge>
                        </p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Lama Cuti</p>
                        <p class="text-sm font-bold text-ink font-sans mt-1">{{ $cuti['jml_hari'] }} Hari Kerja</p>
                        <p class="text-xs text-muted font-sans mt-0.5">{{ $cuti['tgl_mulai'] }} s.d. {{ $cuti['tgl_selesai'] }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Alasan Cuti</p>
                        <div class="mt-1 p-3 bg-surface rounded-lg border border-border">
                            <p class="text-sm font-medium text-ink font-sans">{{ $cuti['alasan'] }}</p>
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-[10px] uppercase font-bold text-muted font-sans tracking-wider">Lampiran Pendukung</p>
                        <div class="mt-1 flex items-center gap-3 p-3 rounded-lg border border-border bg-white cursor-pointer hover:bg-soft transition-colors w-max">
                            <div class="text-primary">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-ink">Surat_Undangan_Keluarga.pdf</p>
                                <p class="text-xs text-muted">245 KB · PDF Document</p>
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <!-- Aksi Kepala Bagian -->
            @if($cuti['status'] === 'Menunggu Tindakan Saya')
            <x-ui.card padding="lg" class="border-primary/20 bg-primary/5">
                <h3 class="text-sm font-bold text-primary-dark font-sans border-b border-primary/20 pb-3 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" /></svg>
                    Tindak Lanjut Anda
                </h3>
                
                <form action="#" method="POST" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-ink font-sans mb-1.5">Keputusan Resmi</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                            <label class="cursor-pointer">
                                <input type="radio" name="keputusan" value="disetujui" class="peer sr-only" checked />
                                <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-success peer-checked:bg-success/10 peer-checked:text-success-dark">
                                    <span class="text-sm font-bold font-sans">Disetujui</span>
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="keputusan" value="perubahan" class="peer sr-only" />
                                <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-warning peer-checked:bg-warning/10 peer-checked:text-warning-dark">
                                    <span class="text-sm font-bold font-sans">Perubahan</span>
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="keputusan" value="ditangguhkan" class="peer sr-only" />
                                <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-info peer-checked:bg-info/10 peer-checked:text-info-dark">
                                    <span class="text-sm font-bold font-sans">Ditangguhkan</span>
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="keputusan" value="tidak_disetujui" class="peer sr-only" />
                                <div class="rounded-lg border border-border bg-white px-3 py-2.5 text-center transition-all peer-checked:border-danger peer-checked:bg-danger/10 peer-checked:text-danger-dark">
                                    <span class="text-sm font-bold font-sans">Tidak Disetujui</span>
                                </div>
                            </label>
                        </div>
                        <p class="text-xs text-muted font-sans mt-2">Pilih salah satu tindakan. Keputusan Anda akan dicatat ke dalam timeline.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-ink font-sans mb-1.5">Keterangan / Catatan Tambahan</label>
                        <textarea rows="3" placeholder="Wajib diisi jika memilih Perubahan, Ditangguhkan, atau Tidak Disetujui..." class="w-full rounded-lg border border-border bg-white p-3 text-sm font-sans placeholder:text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-sm"></textarea>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <x-ui.button type="button" variant="primary" size="md">Simpan Keputusan</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
            @endif
        </div>

        <!-- KOLOM KANAN (Saldo & Timeline) -->
        <div class="space-y-6">
            @if(in_array($cuti['jenis_cuti'], ['Cuti Tahunan', 'Cuti Besar']))
            <x-ui.card padding="lg" class="bg-primary text-white overflow-hidden relative border-none">
                <div class="absolute right-0 top-0 opacity-10">
                    <svg class="w-32 h-32 -mt-4 -mr-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6v6.242l4.28 4.28-1.06 1.06-4.97-4.97V6h1.75z" /></svg>
                </div>
                <div class="relative z-10">
                    <p class="text-xs font-bold uppercase tracking-wider text-white/80 font-sans mb-1">Informasi Saldo {{ $cuti['jenis_cuti'] }}</p>
                    <div class="flex items-end gap-2">
                        <span class="text-4xl font-extrabold">{{ $cuti['saldo_tahunan'] }}</span>
                        <span class="text-sm font-medium text-white/80 mb-1">Hari Tersisa</span>
                    </div>
                    <div class="mt-4 text-sm bg-white/10 rounded-lg px-3 py-2 flex justify-between items-center border border-white/20">
                        <span>Jika Disetujui (-{{ $cuti['jml_hari'] }}):</span>
                        <span class="font-bold">{{ $cuti['sisa_saldo'] }} Hari</span>
                    </div>
                </div>
            </x-ui.card>
            @endif

            <x-ui.card padding="lg">
                <h3 class="text-sm font-bold text-ink font-sans border-b border-border pb-3 mb-4">Timeline Persetujuan</h3>
                <x-ui.timeline>
                    @foreach($timeline as $item)
                        <x-ui.timeline-item 
                            variant="{{ $item['active'] ? 'warning' : 'muted' }}"
                            title="{{ $item['step'] }}"
                            description="{{ $item['aktor'] }}"
                            pulse="{{ $item['active'] }}">
                            
                            <div class="mt-1.5 bg-surface rounded p-2 border border-border">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-ink font-sans">{{ $item['keputusan'] }}</p>
                                @if($item['keterangan'] !== '-')
                                    <p class="text-xs text-muted font-sans mt-0.5">{{ $item['keterangan'] }}</p>
                                @endif
                            </div>
                            
                            @if($item['waktu'] !== '-')
                                <p class="text-[9px] font-mono font-medium text-muted mt-1">{{ $item['waktu'] }}</p>
                            @endif
                        </x-ui.timeline-item>
                    @endforeach
                </x-ui.timeline>
            </x-ui.card>
        </div>
    </div>

</x-layouts.app>
