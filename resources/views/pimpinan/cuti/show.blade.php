<x-layouts.app title="Detail Pengajuan Cuti">


<div class="max-w-7xl mx-auto px-6 py-6 space-y-6">

    {{-- PAGE HEADER --}}
    <div class="mb-6">
        <h2 class="text-2xl font-semibold text-ink">Detail Pengajuan Cuti</h2>
        <x-ui.breadcrumb :items="[
            ['label' => 'Dashboard', 'url' => route('pimpinan.dashboard')],
            ['label' => 'Monitoring Cuti', 'url' => route('pimpinan.cuti.index')],
            ['label' => 'Detail Pengajuan']
        ]" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <div class="lg:col-span-2 space-y-6">
            <x-ui.card>
                <div class="flex items-center justify-between mb-4 border-b border-border pb-4">
                    <div>
                        <h2 class="text-xl font-bold text-ink">Formulir Permintaan Cuti</h2>
                        <p class="text-sm text-muted">Nomor Tiket: #CT-{{ strtoupper(substr($leaveData['id'], 0, 8)) }}</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold bg-warning/10 text-warning">
                        {{ $leaveData['status'] }}
                    </span>
                </div>

                <div class="space-y-6 text-sm">
                    {{-- Data Pegawai --}}
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-muted mb-3">1. Data Pegawai</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-soft/50 p-4 rounded-lg">
                            <div>
                                <span class="block text-xs text-muted mb-1">Nama</span>
                                <span class="font-medium text-ink">{{ $leaveData['nama'] }}</span>
                            </div>
                            <div>
                                <span class="block text-xs text-muted mb-1">NIP</span>
                                <span class="font-mono text-ink">{{ $leaveData['nip'] }}</span>
                            </div>
                        </div>
                    </div>

                    {{-- Data Cuti --}}
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-muted mb-3">2. Detail Cuti</h3>
                        <div class="space-y-4 bg-soft/50 p-4 rounded-lg">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <span class="block text-xs text-muted mb-1">Jenis Cuti</span>
                                    <span class="font-medium text-ink">{{ $leaveData['jenis_cuti'] }}</span>
                                </div>
                                <div>
                                    <span class="block text-xs text-muted mb-1">Lama Cuti</span>
                                    <span class="font-medium text-ink">{{ $leaveData['lama_cuti'] }}</span>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <span class="block text-xs text-muted mb-1">Tanggal Mulai</span>
                                    <span class="font-medium text-ink">{{ \Carbon\Carbon::parse($leaveData['tanggal_mulai'])->format('d M Y') }}</span>
                                </div>
                                <div>
                                    <span class="block text-xs text-muted mb-1">Tanggal Selesai</span>
                                    <span class="font-medium text-ink">{{ \Carbon\Carbon::parse($leaveData['tanggal_selesai'])->format('d M Y') }}</span>
                                </div>
                            </div>
                            <div>
                                <span class="block text-xs text-muted mb-1">Alasan Cuti</span>
                                <span class="text-ink">{{ $leaveData['alasan'] }}</span>
                            </div>
                            <div>
                                <span class="block text-xs text-muted mb-1">Alamat Selama Cuti</span>
                                <span class="text-ink">{{ $leaveData['alamat_cuti'] }} (Telp: {{ $leaveData['telepon'] }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-lg font-semibold text-ink mb-4 border-b border-border pb-2">Catatan Persetujuan Atasan Langsung</h3>
                <div class="flex items-start gap-4">
                    <div class="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                        <span class="text-lg font-bold text-primary">{{ substr($leaveData['approval_atasan']['nama'], 0, 1) }}</span>
                    </div>
                    <div>
                        <p class="font-medium text-ink">{{ $leaveData['approval_atasan']['nama'] }}</p>
                        <p class="text-xs text-muted">{{ $leaveData['approval_atasan']['jabatan'] }}</p>
                        
                        <div class="mt-3 p-3 bg-success/5 border border-success/20 rounded-lg text-sm text-ink">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="rounded bg-success/20 px-2 py-0.5 text-[10px] font-bold text-success uppercase">
                                    {{ $leaveData['approval_atasan']['status'] }}
                                </span>
                                <span class="text-xs text-muted">{{ $leaveData['approval_atasan']['tanggal'] }}</span>
                            </div>
                            <p>"{!! nl2br(e($leaveData['approval_atasan']['catatan'])) !!}"</p>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div class="lg:col-span-1 space-y-6">
            {{-- Sisa Cuti --}}
            <x-ui.card>
                <h3 class="text-sm font-semibold text-ink mb-4">Sisa Cuti Tahunan</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-muted">Tahun {{ date('Y') }} (N)</span>
                        <span class="font-bold text-ink">{{ $leaveData['sisa_cuti']['N'] }} Hari</span>
                    </div>
                    <div class="flex justify-between items-center text-sm border-t border-border pt-2">
                        <span class="text-muted">Tahun {{ date('Y')-1 }} (N-1)</span>
                        <span class="font-bold text-ink">{{ $leaveData['sisa_cuti']['N_1'] }} Hari</span>
                    </div>
                    <div class="flex justify-between items-center text-sm border-t border-border pt-2">
                        <span class="text-muted">Tahun {{ date('Y')-2 }} (N-2)</span>
                        <span class="font-bold text-ink">{{ $leaveData['sisa_cuti']['N_2'] }} Hari</span>
                    </div>
                </div>
            </x-ui.card>

            {{-- Form Keputusan --}}
            <x-ui.card>
                <h3 class="text-lg font-semibold text-ink mb-4">Keputusan Pejabat Berwenang (Pimpinan)</h3>
                
                @if($leaveData['status'] === 'Menunggu Keputusan Pimpinan')
                    <form action="{{ route('pimpinan.cuti.decision', $leaveData['id']) }}" method="POST" class="space-y-4" x-data="{ keputusan: '' }">
                        @csrf
                        <div>
                            <label class="block text-sm font-semibold text-ink mb-2">Ambil Keputusan</label>
                            <select name="keputusan" x-model="keputusan" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" required>
                                <option value="">-- Pilih Keputusan --</option>
                                <option value="DISETUJUI">1. Disetujui</option>
                                <option value="PERUBAHAN">2. Disetujui dengan Perubahan</option>
                                <option value="DITANGGUHKAN">3. Ditangguhkan</option>
                                <option value="TIDAK_DISETUJUI">4. Tidak Disetujui</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-ink mb-2">
                                Catatan Tambahan 
                                <span x-show="keputusan === 'PERUBAHAN' || keputusan === 'DITANGGUHKAN' || keputusan === 'TIDAK_DISETUJUI'" class="text-danger">* (Wajib)</span>
                                <span x-show="keputusan === '' || keputusan === 'DISETUJUI'" class="text-muted font-normal">(Opsional)</span>
                            </label>
                            <textarea name="catatan" :required="keputusan === 'PERUBAHAN' || keputusan === 'DITANGGUHKAN' || keputusan === 'TIDAK_DISETUJUI'" rows="3" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" placeholder="Tulis catatan jika ada perubahan atau penangguhan..."></textarea>
                        </div>

                        <div class="pt-2">
                            <x-ui.button type="submit" variant="primary" class="w-full justify-center">
                                Simpan Keputusan Final
                            </x-ui.button>
                        </div>
                    </form>
                @else
                    <div class="rounded-lg bg-soft p-4 text-center">
                        <p class="text-sm text-muted">Keputusan final telah diberikan.</p>
                        <x-ui.button variant="secondary" class="mt-4 w-full justify-center" disabled>
                            Sudah Diproses
                        </x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        </div>

    </div>

</div>
</x-layouts.app>
