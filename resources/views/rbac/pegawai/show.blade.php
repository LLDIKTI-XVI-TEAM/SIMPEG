<x-layouts.app title="Detail Pegawai">
    @php
        $tabs = [
            'profile' => 'Profil',
            ...($canReadFamilies ? ['keluarga' => 'Keluarga'] : []),
            ...($canReadHistories ? [
                'kepangkatan' => 'Kepangkatan',
                'jabatan' => 'Jabatan',
                'kgb' => 'KGB',
                'pendidikan' => 'Pendidikan',
                'pengangkatan' => 'Pengangkatan',
                'status' => 'Status',
            ] : []),
            ...($canReadDiscipline ? ['disiplin' => 'Hukuman Disiplin'] : []),
            ...($canReadDocuments ? ['docs' => 'Dokumen SK'] : []),
        ];
    @endphp

    <div
        x-data="{
            activeTab: 'profile',
            tabs: {{ \Illuminate\Support\Js::from(array_keys($tabs)) }},
            selectTab(tab) {
                this.activeTab = tab;
                this.$nextTick(() => document.getElementById(`rbac-tab-${tab}`)?.focus());
            },
            moveTab(offset) {
                const current = this.tabs.indexOf(this.activeTab);
                this.selectTab(this.tabs[(current + offset + this.tabs.length) % this.tabs.length]);
            },
        }"
        class="mx-auto max-w-5xl space-y-6"
    >
        <x-pegawai.detail.page-header
            :dashboard-url="route('dashboard')"
            :employees-url="route('data-pegawai')"
        />

        <x-pegawai.detail.shell>
            <x-pegawai.detail.identity-header
                :employee="$p"
                :photo-url="$p->foto_url"
                :primary-badge-label="$p->jenisPegawai?->nama ?? '-'"
            >
                <x-slot:badges>
                    @if($p->is_kinerja_baik)
                        <x-ui.badge variant="success" size="md" class="!font-bold">Kinerja Baik</x-ui.badge>
                    @endif
                    @if($p->is_kepala_lembaga)
                        <x-ui.badge variant="primary" size="md" class="!font-bold">Kepala Lembaga</x-ui.badge>
                    @endif
                </x-slot:badges>
            </x-pegawai.detail.identity-header>

            @php
                $canCreateRbac = auth()->user()?->hasPermission('employees.create');
                $canUpdateRbac = auth()->user()?->hasPermission('employees.update');
                $canDeactivateRbac = auth()->user()?->hasPermission('employees.deactivate');
                $canRestoreRbac = auth()->user()?->hasPermission('employees.restore');
            @endphp
            @if($canCreateRbac || $canUpdateRbac || $canDeactivateRbac || $canRestoreRbac)
                <div class="flex flex-wrap items-center gap-2 border-b border-border bg-soft/20 px-4 py-3">
                    @if($canCreateRbac)
                        <x-ui.button as="a" href="{{ route('rbac.pegawai.create') }}" variant="secondary" size="sm">Tambah Pegawai</x-ui.button>
                    @endif
                    @if($canUpdateRbac)
                        <x-ui.button as="a" href="{{ route('rbac.pegawai.edit', $p->id) }}" variant="secondary" size="sm">Edit</x-ui.button>
                    @endif
                    @if($canDeactivateRbac && $p->isActive())
                        <form method="POST" action="{{ route('rbac.pegawai.destroy', $p->id) }}" onsubmit="return confirm('Nonaktifkan pegawai ini?')">
                            @csrf
                            <x-ui.button type="submit" variant="danger" size="sm">Hapus / Nonaktifkan</x-ui.button>
                        </form>
                    @endif
                    @if($canRestoreRbac && ! $p->isActive())
                        <form method="POST" action="{{ route('rbac.pegawai.restore', $p->id) }}">
                            @csrf
                            <x-ui.button type="submit" variant="success" size="sm">Pulihkan</x-ui.button>
                        </form>
                    @endif
                </div>
            @endif

            <x-pegawai.detail.tabs :tabs="$tabs" id-prefix="rbac" />

            <div class="min-w-0 flex-1">
                <x-pegawai.detail.panel tab="profile" id-prefix="rbac">
                    <x-pegawai.detail.profile
                        :employee="$p"
                        :status-presentation="$statusPresentation"
                        :active-position="$activePosition"
                        :latest-rank="$latestRank"
                        :latest-status-history="$latestStatusHistory"
                        :active-supervisor-assignments="$activeSupervisorAssignments"
                        :retirement-date="$retirementDate"
                        :can-read-histories="$canReadHistories"
                    />
                </x-pegawai.detail.panel>

                @if($canReadFamilies)
                <x-pegawai.detail.panel tab="keluarga" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Data Keluarga</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Daftar istri/suami dan anak yang tercatat sebagai tanggungan.</p>
                    </div>
                    <x-pegawai.detail.table
                        name="keluarga"
                        :headings="['Nama Lengkap & NIK', 'Hubungan', 'TTL', 'Pekerjaan', 'Status']"
                        :empty="$p->families->isEmpty()"
                        empty-label="Pegawai ini belum memiliki data anggota keluarga."
                    >
                        @foreach($p->families as $family)
                            <tr class="transition-colors hover:bg-soft/30" data-family-readonly-row>
                                @include('pegawai.partials.detail.family-readonly-cells', ['family' => $family])
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                </x-pegawai.detail.panel>
                @endif

                @if($canReadHistories)
                <x-pegawai.detail.panel tab="kepangkatan" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kepangkatan &amp; Golongan</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Catatan kenaikan pangkat reguler maupun pilihan selama masa dinas.</p>
                    </div>
                    <x-pegawai.detail.table name="kepangkatan" :headings="['Golongan', 'Nomor SK Pangkat', 'Tanggal SK', 'TMT Pangkat', 'Berkas']" :empty="$p->rankHistories->isEmpty()" empty-label="Pegawai ini belum memiliki riwayat kepangkatan.">
                        @foreach($p->rankHistories as $rank)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 font-bold">{{ $rank->golongan?->nama ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $rank->no_sk ?: '-' }}</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $rank->tanggal_sk])</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $rank->tmt_pangkat])</td>
                                <td class="px-4 py-3">
                                    @if($canReadDocuments && $rank->rbac_attachment_download_url)
                                        <a href="{{ $rank->rbac_attachment_download_url }}" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                </x-pegawai.detail.panel>

                <x-pegawai.detail.panel tab="jabatan" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Jabatan &amp; Struktural</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Catatan penugasan jabatan fungsional maupun struktural.</p>
                    </div>
                    <x-pegawai.detail.table name="jabatan" :headings="['Nama Jabatan', 'Unit Kerja', 'Nomor SK Jabatan', 'Tanggal SK', 'TMT Jabatan', 'Berkas']" :empty="$p->positionHistories->isEmpty()" empty-label="Pegawai ini belum memiliki riwayat jabatan.">
                        @foreach($p->positionHistories as $position)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 font-bold">{{ $position->jabatan?->nama ?? $position->nama_jabatan ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $position->unitKerja?->nama ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $position->no_sk ?: '-' }}</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $position->tanggal_sk])</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $position->tmt_jabatan])</td>
                                <td class="px-4 py-3">
                                    @if($canReadDocuments && $position->rbac_attachment_download_url)
                                        <a href="{{ $position->rbac_attachment_download_url }}" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                </x-pegawai.detail.panel>

                <x-pegawai.detail.panel tab="kgb" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Kenaikan Gaji Berkala (KGB)</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Catatan penyesuaian gaji berkala setiap 2 tahun sekali.</p>
                    </div>
                    <x-pegawai.detail.table name="kgb" :headings="['Gaji Pokok Baru', 'Nomor Surat KGB', 'Tanggal Surat', 'TMT KGB', 'Berkas']" :empty="$p->salaryHistories->isEmpty()" empty-label="Pegawai ini belum memiliki riwayat KGB.">
                        @foreach($p->salaryHistories as $salary)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 font-bold">Rp {{ number_format((float) $salary->gaji_pokok, 0, ',', '.') }}</td>
                                <td class="px-4 py-3">{{ $salary->no_sk ?: '-' }}</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $salary->tanggal_sk])</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $salary->tmt_kgb])</td>
                                <td class="px-4 py-3">
                                    @if($canReadDocuments && $salary->rbac_attachment_download_url)
                                        <a href="{{ $salary->rbac_attachment_download_url }}" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                </x-pegawai.detail.panel>

                <x-pegawai.detail.panel tab="pendidikan" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Pendidikan Formal</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Riwayat kualifikasi akademis tertinggi staf.</p>
                    </div>
                    <x-pegawai.detail.table name="pendidikan" :headings="['Jenjang', 'Nama Institusi', 'Program Studi', 'Tahun Lulus', 'Nomor Ijazah', 'Berkas']" :empty="$p->educationHistories->isEmpty()" empty-label="Pegawai ini belum memiliki riwayat pendidikan formal.">
                        @foreach($p->educationHistories as $education)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 font-bold">{{ $education->jenjang?->nama ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $education->nama_institusi ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $education->programStudi?->nama ?? $education->jurusan ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $education->tahun_lulus ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $education->no_ijazah ?: '-' }}</td>
                                <td class="px-4 py-3">
                                    @if($canReadDocuments && $education->rbac_attachment_download_url)
                                        <a href="{{ $education->rbac_attachment_download_url }}" class="font-semibold text-primary hover:underline">Unduh Ijazah</a>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                </x-pegawai.detail.panel>

                <x-pegawai.detail.panel tab="pengangkatan" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Data &amp; SK Pengangkatan Pertama</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Berkas dasar penerimaan kepegawaian sebagai CPNS/PNS/PPPK.</p>
                    </div>
                    @include('pegawai.partials.detail.appointment-readonly', [
                        'appointment' => $p->appointment,
                        'attachmentDownloadUrl' => $canReadDocuments ? ($p->appointment?->rbac_attachment_download_url ?? null) : null,
                    ])
                </x-pegawai.detail.panel>

                <x-pegawai.detail.panel tab="status" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Status Kepegawaian</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Perubahan status aktif, cuti, mutasi, hingga pensiun.</p>
                    </div>
                    <x-pegawai.detail.table name="status" :headings="['Status', 'Keterangan', 'Tanggal Efektif', 'Nomor Berkas', 'Berkas']" :empty="$p->statusHistories->isEmpty()" empty-label="Belum ada riwayat status.">
                        @foreach($p->statusHistories as $history)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 font-bold">{{ $history->status_nama }}</td>
                                <td class="px-4 py-3">{{ $history->keterangan ?: '-' }}</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $history->tanggal_efektif])</td>
                                <td class="px-4 py-3 font-mono text-muted">{{ $history->nomor_berkas ?: '-' }}</td>
                                <td class="px-4 py-3">
                                    @if($canReadDocuments && $history->rbac_attachment_download_url)
                                        <a href="{{ $history->rbac_attachment_download_url }}" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                    @if($p->rbac_status_attachment_download_url && $canReadDocuments)
                        <div class="mt-4 rounded-lg border border-border bg-soft/30 p-4">
                            <p class="text-xs font-semibold text-ink">Snapshot Status Saat Ini</p>
                            <a href="{{ $p->rbac_status_attachment_download_url }}" class="mt-1 inline-flex text-xs font-semibold text-primary hover:underline">Unduh Berkas Status Snapshot</a>
                        </div>
                    @endif
                </x-pegawai.detail.panel>
                @endif

                @if($canReadDiscipline)
                <x-pegawai.detail.panel tab="disiplin" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Riwayat Hukuman Disiplin</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Catatan sanksi disiplin pegawai yang mempengaruhi promosi kepegawaian.</p>
                    </div>
                    <x-pegawai.detail.table name="disiplin" :headings="['Jenis Hukuman', 'Alasan / Pelanggaran', 'Nomor SK', 'Tanggal SK', 'Masa Berlaku', 'Berkas']" :empty="$p->disciplineRecords->isEmpty()" empty-label="Pegawai ini tidak memiliki riwayat hukuman disiplin.">
                        @foreach($p->disciplineRecords as $discipline)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3">
                                    <span class="font-bold text-danger">{{ $discipline->jenis_hukuman ?: '-' }}</span>
                                    @if($discipline->is_active)
                                        <span class="ml-1 inline-flex items-center rounded-full bg-danger/10 px-1.5 py-0.5 text-[8px] font-bold uppercase text-danger">Aktif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $discipline->deskripsi ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $discipline->no_sk ?: '-' }}</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $discipline->tanggal_sk])</td>
                                <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $discipline->tanggal_mulai]) s/d @include('pegawai.partials.detail.date', ['value' => $discipline->tanggal_berakhir, 'fallback' => 'Sekarang'])</td>
                                <td class="px-4 py-3">
                                    @if($canReadDocuments && $discipline->rbac_attachment_download_url)
                                        <a href="{{ $discipline->rbac_attachment_download_url }}" class="font-semibold text-primary hover:underline">Unduh SK</a>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                </x-pegawai.detail.panel>
                @endif

                @if($canReadDocuments)
                <x-pegawai.detail.panel tab="docs" id-prefix="rbac">
                    <div>
                        <h3 class="text-sm font-bold text-ink font-sans">Daftar Dokumen &amp; Berkas Pegawai</h3>
                        <p class="mt-0.5 text-xs text-muted font-sans">Seluruh dokumen pegawai; akses dikontrol oleh permission dokumen_sk.read.</p>
                    </div>
                    <x-pegawai.detail.table name="docs" :headings="['Nama Dokumen', 'Kategori', 'Nomor Dokumen', 'Tanggal Terbit', 'Ukuran']" :empty="$p->documents->isEmpty()" empty-label="Belum ada dokumen atau berkas yang diunggah untuk pegawai ini.">
                        @foreach($p->documents as $document)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="max-w-xs px-4 py-3">
                                    @if($document->rbac_download_url)
                                        <a href="{{ $document->rbac_download_url }}" class="font-bold text-primary hover:underline">{{ $document->nama_dokumen }}</a>
                                    @else
                                        <span class="font-bold text-ink">{{ $document->nama_dokumen }}</span>
                                    @endif
                                    @if($document->keterangan)
                                        <p class="truncate text-[10px] text-muted">{{ $document->keterangan }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-muted">{{ \App\Support\Documents\DocumentCategory::label($document->jenis_dokumen) }}</td>
                                <td class="px-4 py-3 font-mono text-muted">{{ $document->nomor_dokumen ?: '-' }}</td>
                                <td class="px-4 py-3 text-muted">@include('pegawai.partials.detail.date', ['value' => $document->tanggal_dokumen])</td>
                                <td class="px-4 py-3 text-muted">{{ $document->rbac_file_size_label }}</td>
                            </tr>
                        @endforeach
                    </x-pegawai.detail.table>
                </x-pegawai.detail.panel>
                @endif
            </div>
        </x-pegawai.detail.shell>
    </div>
</x-layouts.app>
