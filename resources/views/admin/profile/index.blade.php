<x-layouts.app title="Profil Saya">


    @if(!$p)
        <div class="space-y-6">
            {{-- Header --}}
            <div class="flex items-center gap-4">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                    <span class="text-2xl font-bold text-primary">{{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}</span>

                </div>
                <div>
                    <h1 class="text-2xl font-semibold text-ink">{{ auth()->user()->name }}</h1>
                    <p class="text-sm text-muted">{{ auth()->user()->email }} &bull; Role: {{ auth()->user()->getEffectiveRole() }}</p>
                </div>
            </div>

            {{-- Card Akun --}}
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-6 py-4 bg-surface">
                    <h3 class="font-semibold text-ink">Informasi Akun</h3>
                    <p class="text-sm text-muted mt-0.5">Informasi dasar akun pengguna Anda.</p>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-1">
                            <label class="block text-sm font-semibold text-ink">Nama Lengkap</label>
                            <input type="text" readonly value="{{ auth()->user()->name }}" class="w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-sm text-ink shadow-sm cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-semibold text-ink">Email</label>
                            <input type="text" readonly value="{{ auth()->user()->email }}" class="w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-sm text-ink shadow-sm cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-semibold text-ink">Role Akun</label>
                            <input type="text" readonly value="{{ auth()->user()->role }}" class="w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-sm text-ink shadow-sm cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-semibold text-ink">Role Aktif Saat Ini</label>
                            <input type="text" readonly value="{{ auth()->user()->getEffectiveRole() }}" class="w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-sm text-ink shadow-sm cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        </div>
                    </div>
                </div>
            </x-ui.card>


            {{-- Card Ganti Password --}}
            <x-ui.card padding="none" class="overflow-hidden">
                <div class="border-b border-border px-6 py-4 bg-surface">
                    <h3 class="font-semibold text-ink">Ubah Kata Sandi</h3>
                    <p class="text-sm text-muted mt-0.5">Perbarui kata sandi akun Anda untuk menjaga keamanan.</p>
                </div>
                <div class="p-6">
                    <form action="{{ route('profile.password.update') }}" method="POST" class="space-y-4 max-w-md">
                        @csrf
                        <div class="space-y-1">
                            <label class="block text-sm font-semibold text-ink">Kata Sandi Saat Ini <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-semibold text-ink">Kata Sandi Baru <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" required minlength="8" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-semibold text-ink">Konfirmasi Kata Sandi Baru <span class="text-danger">*</span></label>
                            <input type="password" name="new_password_confirmation" required minlength="8" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        </div>
                        <div class="pt-2">
                            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                                Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>
            </x-ui.card>
        </div>
    @else
        <div x-data="{
            activeTab: new URLSearchParams(window.location.search).get('tab') || 'profile',
            keluargaList: {{ $p->families->map(fn($f) => ['id' => $f->id, 'nama_anggota' => $f->nama_anggota, 'hubungan' => $f->hubungan, 'nik' => $f->nik, 'tempat_lahir' => $f->tempat_lahir, 'tanggal_lahir' => $f->tanggal_lahir ? \Carbon\Carbon::parse($f->tanggal_lahir)->format('d-m-Y') : '-', 'jenis_kelamin' => $f->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan', 'pekerjaan' => $f->pekerjaan, 'status' => $f->status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung'])->toJson() }}
        }" class="mx-auto max-w-5xl space-y-6">

            {{-- PAGE HEADER --}}
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-ink font-sans">Profil Saya</h2>
                    <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted font-sans">
                        <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                        <span>/</span>
                        <span class="font-medium text-ink">Profil Saya</span>
                    </nav>
                </div>
            </div>

            {{-- EWS WARNING SECTION --}}
            @if($ewsTotal > 0)
            <div class="rounded-lg border border-warning/20 bg-warning/10 px-4 py-3 flex items-start gap-3">
                <svg class="h-5 w-5 text-warning shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h4 class="text-sm font-bold text-warning">Peringatan Penting (EWS)</h4>
                        @if(auth()->user()?->role === 'pegawai')
                            <a href="{{ route('ews.saya') }}" class="text-xs font-semibold text-warning hover:underline">Lihat semua</a>
                        @endif
                    </div>
                    <ul class="mt-1 space-y-1 text-xs text-warning">
                        @foreach($ewsAlerts as $alert)
                            <li>
                                {{ $alert['jenis_event'] }} pada {{ date('d M Y', strtotime($alert['tanggal_target'])) }}
                                ({{ $alert['sisa_hari'] }} hari, {{ $alert['followup_status_label'] }}).
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @endif


            {{-- MAIN DETAIL CARD --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
                {{-- Header info --}}
                <div class="border-b border-border pb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="h-16 w-16 rounded-full border border-border bg-soft flex items-center justify-center overflow-hidden shrink-0">
                            @if($fotoUrl)
                                <img
                                    src="{{ $fotoUrl }}"
                                    alt="Foto {{ $p->nama_lengkap }}"
                                    class="h-full w-full object-cover"
                                >
                            @else
                                <div class="flex h-full w-full items-center justify-center bg-primary/10 text-xl font-bold text-primary font-sans uppercase">
                                    {{ strtoupper(substr($p->nama_lengkap, 0, 1)) }}
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-bold text-ink font-sans leading-tight">{{ $p->nama_lengkap }}</h2>
                                @if($p->is_kinerja_baik)
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold text-success font-sans">
                                        KINERJA BAIK
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-muted">NIP. {{ $p->nip }} &bull; Email: {{ auth()->user()->email }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                <span class="inline-block rounded-full bg-primary/10 text-primary px-2.5 py-0.5 text-xs font-semibold font-sans uppercase">{{ $p->jenisPegawai->nama ?? '-' }}</span>
                                <span class="inline-block rounded-full bg-secondary/10 text-secondary px-2.5 py-0.5 text-xs font-semibold font-sans">Role: {{ auth()->user()->getEffectiveRole() ?? 'Pegawai' }}</span>
                                @if(auth()->user()->keycloak_id)
                                    <span class="inline-block rounded-full bg-success/10 text-success px-2.5 py-0.5 text-xs font-semibold font-sans">SSO Terhubung</span>
                                @else
                                    <span class="inline-block rounded-full bg-warning/10 text-warning px-2.5 py-0.5 text-xs font-semibold font-sans">SSO Belum Terhubung</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        {{-- No action buttons since it's read-only profile view --}}
                        <div class="text-xs text-muted">
                            <p>Data profil dikelola oleh Admin Kepegawaian.</p>
                        </div>
                    </div>
                </div>

                {{-- TAB NAVIGATION --}}
                <x-ui.tabs>
                    <x-ui.tab click="activeTab = 'profile'" active="activeTab === 'profile'">Profil</x-ui.tab>
                    <x-ui.tab click="activeTab = 'cuti'" active="activeTab === 'cuti'">Cuti</x-ui.tab>
                    <x-ui.tab click="activeTab = 'keluarga'" active="activeTab === 'keluarga'">Keluarga</x-ui.tab>
                    <x-ui.tab click="activeTab = 'kepangkatan'" active="activeTab === 'kepangkatan'">Kepangkatan</x-ui.tab>
                    <x-ui.tab click="activeTab = 'jabatan'" active="activeTab === 'jabatan'">Jabatan</x-ui.tab>
                    <x-ui.tab click="activeTab = 'kgb'" active="activeTab === 'kgb'">KGB</x-ui.tab>
                    <x-ui.tab click="activeTab = 'disiplin'" active="activeTab === 'disiplin'">Hukuman Disiplin</x-ui.tab>
                    <x-ui.tab click="activeTab = 'pendidikan'" active="activeTab === 'pendidikan'">Pendidikan</x-ui.tab>
                    <x-ui.tab click="activeTab = 'pengangkatan'" active="activeTab === 'pengangkatan'">Pengangkatan</x-ui.tab>
                    <x-ui.tab click="activeTab = 'docs'" active="activeTab === 'docs'">Dokumen SK</x-ui.tab>
                </x-ui.tabs>

                {{-- TAB 1: PROFIL LENGKAP --}}
                <div x-show="activeTab === 'profile'" class="space-y-6" x-transition>

                    {{-- Atasan Langsung --}}
                    <div class="bg-soft/40 rounded-lg p-4 border border-border">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                </svg>
                            </div>
                            <div>
                                <span class="text-[9px] font-bold text-muted uppercase tracking-wider font-sans block">Atasan Langsung Anda</span>
                                <p class="text-xs font-bold text-ink font-sans">{{ $p->currentSupervisor()?->supervisor->nama_lengkap ?? '-' }}</p>
                                <p class="text-xs text-muted">NIP. {{ $p->currentSupervisor()?->supervisor->nip ?? '-' }} ({{ $p->currentSupervisor()?->supervisor->latestPosition()?->nama_jabatan ?? '-' }})</p>
                            </div>
                        </div>
                    </div>

                    {{-- Auto-Kalkulasi Jadwal --}}
                    <div class="space-y-3">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5 flex items-center gap-1.5">
                            Estimasi Jadwal Kepegawaian
                        </h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="rounded-lg border border-border bg-surface p-3 shadow-sm text-center">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Kenaikan Pangkat Terdekat</span>
                                <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiPangkatNext }}</p>
                                <p class="text-[9px] text-muted font-sans mt-0.5">(Estimasi 4 tahun sejak TMT)</p>
                            </div>
                            <div class="rounded-lg border border-border bg-surface p-3 shadow-sm text-center">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">KGB Terdekat</span>
                                <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiKgbNext }}</p>
                                <p class="text-[9px] text-muted font-sans mt-0.5">(Estimasi 2 tahun sejak TMT)</p>
                            </div>
                            <div class="rounded-lg border border-border bg-surface p-3 shadow-sm text-center">
                                <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Estimasi Tanggal Pensiun</span>
                                <p class="text-sm font-bold text-ink font-sans mt-1">{{ $estimasiPensiun }}</p>
                                <p class="text-[9px] text-danger font-semibold mt-0.5">Sisa: {{ $sisaPensiunStr }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Detail Biodata --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Identitas & Data Pribadi</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">NIK (KTP)</span>
                                    <p class="text-ink font-bold">{{ $p->nik ?? '-' }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">No. Kartu Keluarga (KK)</span>
                                    <p class="text-ink font-bold">{{ $p->no_kk ?? '-' }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Tempat / Tanggal Lahir</span>
                                    <p class="text-ink font-sans">{{ $p->tempat_lahir ?? '-' }}, {{ isset($p->tanggal_lahir) ? \Carbon\Carbon::parse($p->tanggal_lahir)->format('d-m-Y') : '-' }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Jenis Kelamin</span>
                                    <p class="text-ink font-sans">{{ $p->jenis_kelamin === 'L' ? 'Laki-laki' : ($p->jenis_kelamin === 'P' ? 'Perempuan' : '-') }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Agama</span>
                                    <p class="text-ink font-sans">{{ $p->agama->nama ?? '-' }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Status Kawin</span>
                                    <p class="text-ink font-sans">{{ $p->statusKawin->nama ?? '-' }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Golongan Darah</span>
                                    <p class="text-ink font-sans font-bold">{{ $p->golongan_darah ?? '-' }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Kontak & Rumah</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Email</span>
                                    <p class="text-ink font-sans">{{ $p->email_pribadi ?? '-' }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Nomor HP</span>
                                    <p class="text-ink font-sans">{{ $p->no_hp ?? '-' }}</p>
                                </div>
                                <div class="space-y-0.5">
                                    <span class="font-semibold text-muted font-sans">Telepon Rumah</span>
                                    <p class="text-ink font-sans">{{ $p->no_telepon_rumah ?? '-' }}</p>
                                </div>
                                <div class="space-y-0.5 sm:col-span-2">
                                    <span class="font-semibold text-muted font-sans">Alamat</span>
                                    <p class="text-ink font-sans leading-relaxed">{{ $p->alamat ?? '-' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Jabatan Kerja --}}
                    <div class="space-y-4 border-t border-border pt-4">
                        <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Informasi Pekerjaan Utama</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-xs">
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Jabatan Sekarang</span>
                                <p class="text-ink font-sans font-bold">{{ $p->latestPosition()->nama_jabatan ?? $p->jabatan_terakhir ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Unit Kerja</span>
                                <p class="text-ink font-sans">{{ $p->latestPosition()->unitKerja->nama ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Pangkat</span>
                                <p class="text-ink font-sans font-bold">{{ $p->latestRank()->golongan->nama ?? $p->pangkat_terakhir ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Golongan Saat Ini</span>
                                <p class="text-ink font-sans font-bold">{{ $p->latestRank()->golongan->kode ?? $p->golongan_terakhir ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">Kelas Jabatan</span>
                                <p class="text-ink font-sans font-bold">{{ $p->kelas_jabatan ?? '-' }}</p>
                            </div>
                            <div class="space-y-0.5">
                                <span class="font-semibold text-muted font-sans">TMT Golongan</span>
                                <p class="text-ink">{{ $p->latestRank()?->tmt_pangkat ? \Carbon\Carbon::parse($p->latestRank()->tmt_pangkat)->format('d-m-Y') : '-' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- TAB X: INFO CUTI --}}
                <div x-show="activeTab === 'cuti'" style="display: none;" class="space-y-4" x-transition>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Informasi Saldo Cuti ({{ $tahun }})</h3>
                            <p class="text-xs text-muted font-sans mt-0.5">Menampilkan hak efektif cuti tahunan berjalan Anda.</p>
                        </div>
                    </div>

                    <div class="max-w-xs">
                        <x-ui.stat-card label="Hak Efektif Tahun Ini" value="{{ $saldoCuti['saldo_dapat_diajukan'] ?? '-' }}" unit="Hari" variant="primary" size="lg" center role="group" aria-label="{{ $saldoCuti['saldo_dapat_diajukan'] ?? '-' }} Hari">
                        </x-ui.stat-card>
                    </div>
                    @if ($rule5Active)
                        <x-ui.alert variant="warning" size="sm">
                            Saldo tercatat, tidak dapat digunakan pada tahun Cuti Besar. Saldo cuti tahunan tetap tercatat sebagai riwayat. Hak efektif tahun ini adalah 0 karena Cuti Besar telah disetujui.
                        </x-ui.alert>
                    @endif
                </div>

                {{-- TAB 2: DATA KELUARGA --}}
                <div x-show="activeTab === 'keluarga'" class="space-y-4" style="display: none;" x-transition>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-ink font-sans">Susunan Anggota Keluarga</h3>
                            <p class="text-xs text-muted font-sans mt-0.5">Daftar istri/suami dan anak yang tercatat sebagai tanggungan.</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Nama Lengkap & NIK</x-ui.table-th>
                                    <x-ui.table-th>Hubungan</x-ui.table-th>
                                    <x-ui.table-th>TTL</x-ui.table-th>
                                    <x-ui.table-th>Pekerjaan</x-ui.table-th>
                                    <x-ui.table-th>Status</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                <template x-if="keluargaList.length === 0">
                                    <x-ui.table-row>
                                        <x-ui.table-td colspan="5" class="px-0 py-0">
                                            <x-ui.empty-state icon="document" title="Tidak ada data anggota keluarga." />
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                </template>
                                <template x-for="(fam, index) in keluargaList" :key="index">
                                    <x-ui.table-row>
                                        <x-ui.table-td>
                                            <p class="font-bold font-sans" x-text="fam.nama_anggota"></p>
                                            <p class="text-[10px] text-muted" x-text="fam.nik ? 'NIK. ' + fam.nik : 'NIK. -'"></p>
                                        </x-ui.table-td>
                                        <x-ui.table-td>
                                            <p class="font-sans" x-text="fam.hubungan"></p>
                                            <p class="text-[10px] text-muted font-sans" x-text="fam.jenis_kelamin"></p>
                                        </x-ui.table-td>
                                        <x-ui.table-td>
                                            <p class="font-sans" x-text="fam.tempat_lahir || '-'"></p>
                                            <p class="text-[10px] text-muted" x-text="fam.tanggal_lahir"></p>
                                        </x-ui.table-td>
                                        <x-ui.table-td class="font-sans" x-text="fam.pekerjaan || '-'"></x-ui.table-td>
                                        <x-ui.table-td>
                                            <span class="inline-flex items-center gap-1 text-[10px] font-bold"
                                                  :class="fam.status === 'Ditanggung' ? 'text-success' : 'text-muted'"
                                                  x-text="fam.status"></span>
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                </template>
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </div>

                {{-- TAB 3: RIWAYAT KEPANGKATAN --}}
                <div x-show="activeTab === 'kepangkatan'" class="space-y-4" style="display: none;" x-transition>
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kepangkatan & Golongan</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan kenaikan pangkat reguler maupun pilihan selama masa dinas.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Golongan</x-ui.table-th>
                                    <x-ui.table-th>Nomor SK Pangkat</x-ui.table-th>
                                    <x-ui.table-th>Tanggal SK</x-ui.table-th>
                                    <x-ui.table-th>TMT Pangkat</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @forelse($p->rankHistories as $r)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-bold">{{ $r->golongan->nama ?? '-' }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $r->no_sk }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $r->tanggal_sk }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $r->tmt_pangkat }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @empty
                                    <x-ui.table-row>
                                        <x-ui.table-td colspan="4" class="px-0 py-0">
                                            <x-ui.empty-state icon="document" title="Tidak ada data riwayat kepangkatan." />
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforelse
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </div>

                {{-- TAB 4: RIWAYAT JABATAN --}}
                <div x-show="activeTab === 'jabatan'" class="space-y-4" style="display: none;" x-transition>
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Jabatan & Struktural</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan penugasan jabatan fungsional maupun struktural.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Nama Jabatan</x-ui.table-th>
                                    <x-ui.table-th>Unit Kerja</x-ui.table-th>
                                    <x-ui.table-th>Nomor SK Jabatan</x-ui.table-th>
                                    <x-ui.table-th>Tanggal SK</x-ui.table-th>
                                    <x-ui.table-th>TMT Jabatan</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @forelse($p->positionHistories as $j)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-bold">{{ $j->nama_jabatan }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $j->unitKerja->nama ?? '-' }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $j->no_sk }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $j->tanggal_sk }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $j->tmt_jabatan }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @empty
                                    <x-ui.table-row>
                                        <x-ui.table-td colspan="5" class="px-0 py-0">
                                            <x-ui.empty-state icon="document" title="Tidak ada data riwayat jabatan." />
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforelse
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </div>

                {{-- TAB 5: RIWAYAT KGB --}}
                <div x-show="activeTab === 'kgb'" class="space-y-4" style="display: none;" x-transition>
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kenaikan Gaji Berkala (KGB)</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan penyesuaian gaji berkala setiap 2 tahun sekali.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Gaji Pokok Baru</x-ui.table-th>
                                    <x-ui.table-th>Nomor Surat KGB</x-ui.table-th>
                                    <x-ui.table-th>Tanggal Surat</x-ui.table-th>
                                    <x-ui.table-th>TMT KGB</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @forelse($p->salaryHistories as $k)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-bold">Rp {{ number_format($k->gaji_pokok, 0, ',', '.') }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $k->no_sk }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $k->tanggal_sk }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $k->tmt_kgb }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @empty
                                    <x-ui.table-row>
                                        <x-ui.table-td colspan="4" class="px-0 py-0">
                                            <x-ui.empty-state icon="document" title="Tidak ada data riwayat KGB." />
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforelse
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </div>

                {{-- TAB 6: HUKUMAN DISIPLIN --}}
                <div x-show="activeTab === 'disiplin'" class="space-y-4" style="display: none;" x-transition>
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Hukuman Disiplin</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Catatan sanksi disiplin pegawai yang mempengaruhi promosi kepegawaian.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Jenis Hukuman</x-ui.table-th>
                                    <x-ui.table-th>Alasan / Pelanggaran</x-ui.table-th>
                                    <x-ui.table-th>Nomor SK</x-ui.table-th>
                                    <x-ui.table-th>Tanggal SK</x-ui.table-th>
                                    <x-ui.table-th>Masa Berlaku</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @forelse($p->disciplineRecords as $d)
                                    <x-ui.table-row>
                                        <x-ui.table-td>
                                            <span class="font-bold text-danger">{{ $d->jenis_hukuman }}</span>
                                            @if($d->is_active)
                                                <span class="ml-1 inline-flex items-center rounded-full bg-danger/10 px-1.5 py-0.5 text-[8px] font-bold text-danger uppercase">Aktif</span>
                                            @endif
                                        </x-ui.table-td>
                                        <x-ui.table-td>{{ $d->deskripsi }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $d->no_sk }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $d->tanggal_sk ? \Carbon\Carbon::parse($d->tanggal_sk)->format('d-m-Y') : '-' }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $d->tanggal_mulai ? \Carbon\Carbon::parse($d->tanggal_mulai)->format('d-m-Y') : '-' }} s/d {{ $d->tanggal_berakhir ? \Carbon\Carbon::parse($d->tanggal_berakhir)->format('d-m-Y') : 'Sekarang' }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @empty
                                    <x-ui.table-row>
                                        <x-ui.table-td colspan="5" class="px-0 py-0">
                                            <x-ui.empty-state icon="document" title="Anda tidak memiliki riwayat hukuman disiplin. Bersih (Clean Record)." />
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforelse
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </div>

                {{-- TAB 7: RIWAYAT PENDIDIKAN --}}
                <div x-show="activeTab === 'pendidikan'" class="space-y-4" style="display: none;" x-transition>
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Pendidikan Formal</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Riwayat kualifikasi akademis tertinggi.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Tingkat</x-ui.table-th>
                                    <x-ui.table-th>Nama Institusi</x-ui.table-th>
                                    <x-ui.table-th>Program Studi</x-ui.table-th>
                                    <x-ui.table-th>Tahun Lulus</x-ui.table-th>
                                    <x-ui.table-th>Nomor Ijazah</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @forelse($p->educationHistories as $e)
                                    <x-ui.table-row>
                                        <x-ui.table-td class="font-bold">{{ $e->jenjang->nama ?? '-' }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $e->nama_institusi }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $e->programStudi?->nama ?? $e->jurusan ?? '-' }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $e->tahun_lulus }}</x-ui.table-td>
                                        <x-ui.table-td>{{ $e->no_ijazah }}</x-ui.table-td>
                                    </x-ui.table-row>
                                @empty
                                    <x-ui.table-row>
                                        <x-ui.table-td colspan="5" class="px-0 py-0">
                                            <x-ui.empty-state icon="document" title="Tidak ada data riwayat pendidikan." />
                                        </x-ui.table-td>
                                    </x-ui.table-row>
                                @endforelse
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </div>

                {{-- TAB 8: DATA PENGANGKATAN --}}
                <div x-show="activeTab === 'pengangkatan'" class="space-y-4" style="display: none;" x-transition>
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Data & SK Pengangkatan Pertama</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Berkas dasar penerimaan kepegawaian sebagai CPNS/PNS/PPPK.</p>
                    </div>
                    <div class="rounded-lg border border-border bg-soft/30 p-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs font-sans">
                            <div class="space-y-2">
                                <div class="flex justify-between border-b border-border pb-1">
                                    <span class="font-semibold text-muted">Jenis Pengangkatan:</span>
                                    <span class="text-ink font-bold">{{ $p->appointment->jenis_pengangkatan ?? '-' }}</span>
                                </div>
                                <div class="flex justify-between border-b border-border pb-1">
                                    <span class="font-semibold text-muted">Nomor SK Pengangkatan:</span>
                                    <span class="text-ink font-bold">{{ $p->appointment->no_sk ?? '-' }}</span>
                                </div>
                                <div class="flex justify-between border-b border-border pb-1">
                                    <span class="font-semibold text-muted">Tanggal SK Terbit:</span>
                                    <span class="text-ink">{{ $p->appointment?->tanggal_sk ? \Carbon\Carbon::parse($p->appointment->tanggal_sk)->format('d-m-Y') : '-' }}</span>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div class="flex justify-between border-b border-border pb-1">
                                    <span class="font-semibold text-muted">TMT Pengangkatan:</span>
                                    <span class="text-ink font-bold">{{ $p->appointment?->tmt_pengangkatan ? \Carbon\Carbon::parse($p->appointment->tmt_pengangkatan)->format('d-m-Y') : '-' }}</span>
                                </div>
                                <div class="flex justify-between border-b border-border pb-1">
                                    <span class="font-semibold text-muted">Pejabat yang Menetapkan:</span>
                                    <span class="text-ink font-semibold">Kepala LLDIKTI Wilayah XVI</span>
                                </div>
                                <div class="flex justify-between border-b border-border pb-1">
                                    <span class="font-semibold text-muted">Status Dokumen:</span>
                                    <span class="inline-flex items-center gap-1 rounded-md bg-success/10 px-1.5 py-0.2 text-[9px] font-bold text-success uppercase">VERIFIED</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- TAB 9: BERKAS DOKUMEN SK --}}
                <div x-show="activeTab === 'docs'" style="display: none;" class="space-y-4" x-transition>
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Daftar Berkas Fisik Kepegawaian</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Daftar berkas pendukung mutasi pangkat, jabatan, KGB, dan dokumen kepegawaian lain.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-ui.table-head>
                                <x-ui.table-row>
                                    <x-ui.table-th>Nama Dokumen</x-ui.table-th>
                                    <x-ui.table-th>Kategori</x-ui.table-th>
                                    <x-ui.table-th>Nomor Dokumen</x-ui.table-th>
                                    <x-ui.table-th>Tanggal Terbit</x-ui.table-th>
                                    <x-ui.table-th>Ukuran</x-ui.table-th>
                                </x-ui.table-row>
                            </x-ui.table-head>
                            <x-ui.table-body>
                                @forelse($riwayatDokumen as $doc)
                                <x-ui.table-row>
                                    <x-ui.table-td class="max-w-xs">
                                        <div class="flex items-start gap-2.5">
                                            <div class="flex h-8 w-6 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-0.5 shadow-sm relative">
                                                <div class="w-full bg-primary/10 text-primary text-[5px] font-bold text-center py-0.5 uppercase tracking-wide">
                                                    {{ $doc['extension'] }}
                                                </div>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-bold font-sans truncate">{{ $doc['nama'] }}</p>
                                                <p class="text-xs text-muted truncate">{{ $doc['keterangan'] }}</p>
                                            </div>
                                        </div>
                                    </x-ui.table-td>
                                    <x-ui.table-td class="text-muted font-sans">{{ $doc['kategori_label'] }}</x-ui.table-td>
                                    <x-ui.table-td class="text-muted">{{ $doc['nomor'] }}</x-ui.table-td>
                                    <x-ui.table-td class="text-muted">{{ $doc['tanggal'] }}</x-ui.table-td>
                                    <x-ui.table-td class="text-muted">{{ $doc['file_size'] }}</x-ui.table-td>
                                </x-ui.table-row>
                                @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="5" align="center" class="text-muted font-sans">
                                        Belum ada dokumen atau SK kepegawaian yang diunggah.
                                    </x-ui.table-td>
                                </x-ui.table-row>
                                @endforelse
                            </x-ui.table-body>
                        </x-ui.table>
                    </div>
                </div>

            </div>

            {{-- SUPER ADMIN SHORTCUTS CARD (BOTTOM) --}}
            @if(auth()->user()->getEffectiveRole() === 'super_admin')
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm mt-6">
            <div class="mb-6">
                <h3 class="text-sm font-bold text-ink font-sans">
                    Aksi & Administrasi Sistem
                </h3>
                <p class="text-xs text-muted font-sans mt-0.5">Kelola preferensi, pengguna, dan data master sistem SIMPEG secara terpusat.</p>
            </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <a href="{{ route('settings.index') }}" class="flex h-full items-start gap-4 p-5 rounded-xl border border-border bg-surface hover:border-primary hover:shadow-lg transition-all duration-300 group">
                        <div class="h-12 w-12 shrink-0 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <div>
                            <h4 class="font-bold text-ink text-sm">Pengaturan Sistem</h4>
                            <p class="text-[11px] text-muted mt-1 leading-relaxed">Konfigurasi variabel global, nama instansi, logo, dan preferensi aplikasi.</p>
                        </div>
                    </a>

                    <a href="{{ route('data-master') }}" class="flex h-full items-start gap-4 p-5 rounded-xl border border-border bg-surface hover:border-primary hover:shadow-lg transition-all duration-300 group">
                        <div class="h-12 w-12 shrink-0 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                            </svg>
                        </div>
                        <div>
                            <h4 class="font-bold text-ink text-sm">Data Reference</h4>
                            <p class="text-[11px] text-muted mt-1 leading-relaxed">Manajemen tabel master (Unit Kerja, Agama, Eselon, Golongan, dll).</p>
                        </div>
                    </a>

                    <a href="{{ route('user-management') }}" class="flex h-full items-start gap-4 p-5 rounded-xl border border-border bg-surface hover:border-primary hover:shadow-lg transition-all duration-300 group">
                        <div class="h-12 w-12 shrink-0 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12l2.25 2.25L21 10.5" />
                            </svg>
                        </div>
                        <div>
                            <h4 class="font-bold text-ink text-sm">User & Mapping Keycloak</h4>
                            <p class="text-[11px] text-muted mt-1 leading-relaxed">Sinkronisasi ID akun Keycloak dengan data pegawai SIMPEG.</p>
                        </div>
                    </a>

                    <a href="{{ route('audit.index') }}" class="flex h-full items-start gap-4 p-5 rounded-xl border border-border bg-surface hover:border-primary hover:shadow-lg transition-all duration-300 group">
                        <div class="h-12 w-12 shrink-0 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                            </svg>
                        </div>
                        <div>
                            <h4 class="font-bold text-ink text-sm">Audit Log</h4>
                            <p class="text-[11px] text-muted mt-1 leading-relaxed">Pusat pelacakan riwayat segala perubahan data yang terjadi pada sistem.</p>
                        </div>
                    </a>

                </div>
            </div>
            @endif

        </div>
    @endif

</x-layouts.app>
