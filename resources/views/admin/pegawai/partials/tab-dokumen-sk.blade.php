{{-- Tab Dokumen & SK — satu-satunya tempat aksi kelola dokumen pegawai (K-MTG-04). --}}
<div class="space-y-8">

    {{-- ==================== Section 1: Dokumen SK ==================== --}}
    <div class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans">Dokumen SK</h3>
                <p class="text-xs text-muted font-sans mt-0.5">Pangkat, jabatan, dan KGB ditambahkan sebagai riwayat; SK Pengangkatan mengganti berkas aktif.</p>
            </div>
            @if($canManageDocuments)
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" @click="showUploadSkForm = !showUploadSkForm; showUploadBerkas = false"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:opacity-90 font-sans">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    <span x-text="showUploadSkForm ? 'Tutup Form SK' : 'Tambah Berkas SK'"></span>
                </button>
            </div>
            @endif
        </div>

        {{-- Form tambah riwayat SK --}}
        @if($canManageDocuments)
        <div x-show="showUploadSkForm" x-transition class="rounded-lg border border-primary/20 bg-primary/5 p-4 space-y-4">
            <div>
                <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tambah Berkas SK</h4>
                <p class="text-[11px] text-muted font-sans mt-0.5" x-text="skUploadHint"></p>
            </div>

            <p x-show="skUploadError" x-text="skUploadError" class="text-xs text-danger font-semibold font-sans"></p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis SK <span class="text-danger">*</span></label>
                    <div class="relative">
                            <select x-model="newSk.kategori_dokumen" @change="resetSkTypeFields()"
                                class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                                {{-- SK append-only (pangkat/KGB/jabatan) membutuhkan izin riwayat;
                                    SK Pengangkatan tetap tersedia bagi pengelola dokumen. --}}
                                @if($canCreateEmployeeHistory)
                                <option value="sk_pangkat">SK Pangkat</option>
                                <option value="sk_kgb">SK KGB</option>
                                <option value="sk_jabatan">SK Jabatan</option>
                                @endif
                                <option value="sk_pengangkatan">SK Pengangkatan</option>
                            </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor SK <span class="text-danger">*</span></label>
                    <input type="text" x-model="newSk.no_sk" placeholder="SK-..."
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    <p x-show="skUploadErrors.no_sk" x-text="skUploadErrors.no_sk?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal SK <span class="text-danger">*</span></label>
                    <input type="date" x-model="newSk.tanggal_sk"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                    <p x-show="skUploadErrors.tanggal_sk" x-text="skUploadErrors.tanggal_sk?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">File SK <span class="text-danger">*</span></label>
                    <div class="flex items-center gap-2">
                        <label for="file_sk_tab"
                            class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-surface px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft font-sans">Pilih File</label>
                        <input type="file" id="file_sk_tab" class="hidden" accept=".pdf,.jpg,.jpeg,.png"
                            @change="newSk.file_sk = $event.target.files[0] || null">
                        <span class="min-w-0 flex-1 truncate text-xs font-sans" :class="newSk.file_sk ? 'text-ink' : 'text-muted'"
                            x-text="newSk.file_sk ? newSk.file_sk.name : 'Belum ada file dipilih'"></span>
                        <button x-show="newSk.file_sk" type="button"
                            @click="newSk.file_sk = null; document.getElementById('file_sk_tab').value = ''"
                            class="shrink-0 text-xs text-danger hover:underline font-sans">Hapus</button>
                    </div>
                    <p class="text-[10px] text-muted italic font-sans">PDF/JPG/JPEG/PNG, maks. 10 MB.</p>
                    <p x-show="skUploadErrors.file_sk" x-text="skUploadErrors.file_sk?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
            </div>

            <div x-show="newSk.kategori_dokumen === 'sk_pangkat'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Golongan <span class="text-danger">*</span></label>
                    <div class="relative">
                        <select x-model="newSk.golongan_id"
                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                            <option value="">-- Pilih Golongan --</option>
                            @foreach($golonganOptions as $gol)
                                <option value="{{ $gol->id }}">{{ $gol->kode }} — {{ $gol->nama }}</option>
                            @endforeach
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                    </div>
                    <p x-show="skUploadErrors.golongan_id" x-text="skUploadErrors.golongan_id?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pangkat <span class="text-danger">*</span></label>
                    <input type="date" x-model="newSk.tmt_pangkat"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                    <p x-show="skUploadErrors.tmt_pangkat" x-text="skUploadErrors.tmt_pangkat?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
            </div>

            <div x-show="newSk.kategori_dokumen === 'sk_jabatan'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jabatan <span class="text-danger">*</span></label>
                    <div class="relative">
                        <select x-model="newSk.jabatan_id"
                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                            <option value="">-- Pilih Jabatan --</option>
                            @foreach($jabatanOptions->where('is_active', true) as $jabatan)
                                <option value="{{ $jabatan->id }}">{{ $jabatan->nama }}{{ $jabatan->jenisJabatan ? ' - '.$jabatan->jenisJabatan->nama : '' }}</option>
                            @endforeach
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                    </div>
                    <p x-show="skUploadErrors.jabatan_id" x-text="skUploadErrors.jabatan_id?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unit Kerja</label>
                    <div class="relative">
                        <select x-model="newSk.unit_kerja_id"
                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                            <option value="">-- Pilih Unit Kerja --</option>
                            @foreach($unitKerjaOptions as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->nama }}</option>
                            @endforeach
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                    </div>
                    <p x-show="skUploadErrors.unit_kerja_id" x-text="skUploadErrors.unit_kerja_id?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Jabatan <span class="text-danger">*</span></label>
                    <input type="date" x-model="newSk.tmt_jabatan"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                    <p x-show="skUploadErrors.tmt_jabatan" x-text="skUploadErrors.tmt_jabatan?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Jabatan <span class="font-normal normal-case text-muted">(opsional)</span></label>
                    <div class="relative">
                        <select x-model="newSk.jenis_jabatan_id"
                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                            <option value="">-- Pilih Jenis Jabatan --</option>
                            @foreach($jenisJabatanOptions as $jj)
                                <option value="{{ $jj->id }}">{{ $jj->nama }}</option>
                            @endforeach
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="newSk.kategori_dokumen === 'sk_kgb'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Gaji Pokok <span class="text-danger">*</span></label>
                    <input type="number" min="0" step="1" x-model="newSk.gaji_pokok" placeholder="4100000"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    <p x-show="skUploadErrors.gaji_pokok" x-text="skUploadErrors.gaji_pokok?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT KGB <span class="text-danger">*</span></label>
                    <input type="date" x-model="newSk.tmt_kgb"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                    <p x-show="skUploadErrors.tmt_kgb" x-text="skUploadErrors.tmt_kgb?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
            </div>

            <div x-show="newSk.kategori_dokumen === 'sk_pengangkatan'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Jenis Pengangkatan <span class="text-danger">*</span></label>
                    <div class="relative">
                        <select x-model="newSk.jenis_pengangkatan"
                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                            <option value="PNS">PNS</option>
                            <option value="PPPK">PPPK</option>
                            <option value="CPNS">CPNS</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                    </div>
                    <p x-show="skUploadErrors.jenis_pengangkatan" x-text="skUploadErrors.jenis_pengangkatan?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">TMT Pengangkatan <span class="text-danger">*</span></label>
                    <input type="date" x-model="newSk.tmt_pengangkatan"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans cursor-pointer">
                    <p x-show="skUploadErrors.tmt_pengangkatan" x-text="skUploadErrors.tmt_pengangkatan?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <button type="button" @click="submitUploadSk()" :disabled="isUploadingSk"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary/90 disabled:opacity-60 font-sans">
                    <span x-text="isUploadingSk ? 'Menyimpan...' : (newSk.kategori_dokumen === 'sk_pengangkatan' ? 'Ganti SK Pengangkatan' : 'Tambah ke Riwayat')"></span>
                </button>
                <button type="button" @click="showUploadSkForm = false; skUploadError = ''; skUploadErrors = {};"
                    class="inline-flex items-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted transition hover:bg-soft font-sans">
                    Batal
                </button>
            </div>
        </div>
        @endif

        {{-- Tabel Dokumen SK --}}
        <x-pegawai.detail.table
            name="sk"
            :headings="['Nama Dokumen', 'Kategori', 'Nomor', 'Tanggal', 'Status', 'Ukuran']"
            :show-actions="true"
        >
            <template x-if="skList.length === 0">
                <tr>
                    <td colspan="7" class="px-4 py-6 text-center font-semibold text-muted font-sans">Belum ada dokumen SK.</td>
                </tr>
            </template>
            <template x-for="doc in skList" :key="doc.id">
                <tr class="transition-colors hover:bg-soft/30">
                    <td class="px-4 py-3 max-w-xs">
                        <div class="flex items-start gap-2.5">
                            <div class="flex h-8 w-6 shrink-0 items-center justify-center rounded border border-border bg-soft p-0.5 shadow-sm">
                                <span class="text-[5px] font-bold text-primary uppercase"
                                    x-text="(doc.file_path || '').split('.').pop()?.substring(0, 4) || 'file'"></span>
                            </div>
                            <div class="min-w-0">
                                <p class="font-bold font-sans truncate" x-text="doc.nama_dokumen"></p>
                                <p x-show="doc.keterangan" class="text-[10px] text-muted truncate" x-text="doc.keterangan"></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-muted font-sans" x-text="doc.kategori_label"></td>
                    <td class="px-4 py-3 text-muted font-mono" x-text="doc.nomor_dokumen || '-'"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.tanggal_dokumen || '-'"></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-1">
                            <span x-show="doc.is_latest"
                                class="inline-flex items-center rounded-md bg-success/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-success">Aktif</span>
                            <span x-show="!doc.is_latest && ['sk_pangkat','sk_jabatan','sk_kgb'].includes(doc.jenis_dokumen)"
                                class="inline-flex items-center rounded-md bg-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-muted">Riwayat</span>
                            <span x-show="!doc.is_latest && !['sk_pangkat','sk_jabatan','sk_kgb'].includes(doc.jenis_dokumen)"
                                class="text-muted">-</span>
                            <span x-show="!doc.file_tersedia"
                                class="inline-flex items-center rounded-md bg-danger/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-danger">File Hilang</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-muted" x-text="doc.file_size"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            <a :href="doc.detail_url"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                title="Lihat detail">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </a>
                            <a :href="doc.download_url"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                title="Unduh">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                            </a>
                            @if($canManageDocuments)
                            <button type="button"
                                x-show="['sk_pengangkatan','sk_pangkat','sk_jabatan','sk_kgb'].includes(doc.jenis_dokumen)"
                                @click="openSkRiwayatForm(doc.jenis_dokumen)"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                title="Tambah / Ganti Berkas SK (riwayat baru, append-only)">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                </svg>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
            </template>
        </x-pegawai.detail.table>
    </div>

    {{-- ==================== Section 2: Berkas Lainnya ==================== --}}
    <div class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="text-sm font-bold text-ink font-sans">Berkas Lainnya</h3>
                <p class="text-xs text-muted font-sans mt-0.5">KTP, KK, ijazah, dan berkas pendukung pegawai ini.@if($canManageDocuments && !$canDeleteBerkas) Hapus berkas hanya bisa dilakukan Super Admin.@endif</p>
            </div>
            @if($canManageDocuments)
            <button type="button" @click="showUploadBerkas = !showUploadBerkas; showUploadSkForm = false"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary transition hover:bg-primary/10 font-sans">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span x-text="showUploadBerkas ? 'Tutup Form Berkas' : 'Unggah Berkas'"></span>
            </button>
            @endif
        </div>

        {{-- Form unggah berkas lainnya --}}
        @if($canManageDocuments)
        <div x-show="showUploadBerkas" x-transition class="rounded-lg border border-primary/20 bg-primary/5 p-4 space-y-4">
            <h4 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Unggah Berkas Baru</h4>

            <p x-show="uploadBerkasError" x-text="uploadBerkasError"
               class="text-xs text-danger font-semibold font-sans"></p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                        Nama Dokumen <span class="text-danger">*</span>
                    </label>
                    <input type="text" x-model="newBerkas.nama_dokumen"
                        placeholder="Misal: KTP An. Budi Santoso"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <p x-show="uploadBerkasErrors.nama_dokumen" x-text="uploadBerkasErrors.nama_dokumen?.[0]"
                       class="text-xs text-danger font-sans"></p>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                        Kategori <span class="text-danger">*</span>
                    </label>
                    <div class="relative">
                        <select x-model="newBerkas.kategori_dokumen"
                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                            <option value="ktp_kk">KTP & KK</option>
                            <option value="ijazah">Ijazah</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                    <p x-show="uploadBerkasErrors.kategori_dokumen" x-text="uploadBerkasErrors.kategori_dokumen?.[0]"
                       class="text-xs text-danger font-sans"></p>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Dokumen</label>
                    <input type="text" x-model="newBerkas.nomor_dokumen"
                        placeholder="Opsional"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Terbit</label>
                    <input type="date" x-model="newBerkas.tanggal_terbit"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Keterangan</label>
                    <input type="text" x-model="newBerkas.keterangan"
                        placeholder="Keterangan opsional"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                        File Berkas <span class="text-danger">*</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <label for="berkas_upload_input"
                            class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-surface px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft font-sans">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                            </svg>
                            Pilih File
                        </label>
                        <input type="file" id="berkas_upload_input" class="hidden"
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            @change="newBerkas.file = $event.target.files[0] || null">
                        <span class="min-w-0 flex-1 truncate text-xs font-sans"
                            :class="newBerkas.file ? 'text-ink' : 'text-muted'"
                            x-text="newBerkas.file ? newBerkas.file.name : 'Belum ada file dipilih'"></span>
                        <button x-show="newBerkas.file" type="button"
                            @click="newBerkas.file = null; document.getElementById('berkas_upload_input').value = ''"
                            class="shrink-0 text-xs text-danger hover:underline font-sans">Hapus</button>
                    </div>
                    <p class="text-[10px] text-muted italic font-sans">Format PDF/JPG/PNG/DOC/DOCX, maks. 10 MB.</p>
                    <p x-show="uploadBerkasErrors.berkas" x-text="uploadBerkasErrors.berkas?.[0]"
                       class="text-xs text-danger font-sans"></p>
                </div>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <button type="button" @click="submitUploadBerkas()"
                    :disabled="isUploadingBerkas"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary/90 disabled:opacity-60 font-sans">
                    <svg x-show="isUploadingBerkas" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="isUploadingBerkas ? 'Mengunggah...' : 'Unggah'"></span>
                </button>
                <button type="button" @click="showUploadBerkas = false; uploadBerkasError = ''; uploadBerkasErrors = {};"
                    class="inline-flex items-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted transition hover:bg-soft font-sans">
                    Batal
                </button>
            </div>
        </div>
        @endif

        {{-- Tabel Berkas Lainnya (kontrak tabel "docs" tetap dipertahankan) --}}
        <x-pegawai.detail.table
            name="docs"
            :headings="['Nama Dokumen', 'Kategori', 'Nomor Dokumen', 'Tanggal Terbit', 'Ukuran']"
            :show-actions="true"
        >
            <template x-if="berkasList.length === 0">
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center font-semibold text-muted font-sans">Belum ada dokumen atau berkas yang diunggah untuk pegawai ini.</td>
                </tr>
            </template>
            <template x-for="doc in berkasList" :key="doc.id">
                <tr class="transition-colors hover:bg-soft/30">
                    <td class="px-4 py-3 max-w-xs">
                        <div class="flex items-start gap-2.5">
                            <div class="flex h-8 w-6 shrink-0 items-center justify-center rounded border border-border bg-soft p-0.5 shadow-sm">
                                <span class="text-[5px] font-bold text-primary uppercase"
                                    x-text="(doc.file_path || '').split('.').pop()?.substring(0, 4) || 'file'"></span>
                            </div>
                            <div class="min-w-0">
                                <p class="font-bold font-sans truncate" x-text="doc.nama_dokumen"></p>
                                <p x-show="doc.keterangan" class="text-[10px] text-muted truncate" x-text="doc.keterangan"></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-muted font-sans" x-text="doc.kategori_label"></td>
                    <td class="px-4 py-3 text-muted font-mono" x-text="doc.nomor_dokumen || '-'"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.tanggal_dokumen || '-'"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.file_size"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            <a :href="doc.detail_url"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                title="Lihat detail">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </a>
                            <a :href="doc.download_url"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                title="Unduh">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                            </a>
                            @if($canManageDocuments)
                            <button type="button" x-show="doc.is_deletable"
                                @click="openEditBerkas(doc)"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-primary transition hover:bg-soft shadow-sm"
                                title="Edit">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                </svg>
                            </button>
                            @endif
                            @if($canDeleteBerkas)
                            <button type="button" x-show="doc.is_deletable"
                                @click="openDeleteBerkas(doc)"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger transition hover:bg-red-50 shadow-sm"
                                title="Hapus">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                </svg>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
            </template>
        </x-pegawai.detail.table>
    </div>

    {{-- ==================== Modal: Edit Berkas Lainnya ==================== --}}
    @if($canManageDocuments)
    <x-ui.modal
        show="showEditBerkasModal"
        title="Edit Dokumen"
        closeAction="showEditBerkasModal = false"
        maxWidth="lg"
        bodyClass="p-5 space-y-3"
    >
        <div class="space-y-3">
            <p x-show="editBerkasError" x-text="editBerkasError" class="rounded-lg bg-danger/10 p-3 text-xs text-danger font-bold font-sans"></p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Kategori <span class="text-danger">*</span></label>
                    <div class="relative">
                        <select x-model="editBerkasForm.kategori_dokumen"
                            class="w-full appearance-none rounded-lg border border-border bg-surface px-4 py-2 pr-10 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                            <option value="ktp_kk">KTP & KK</option>
                            <option value="ijazah">Ijazah</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </div>
                    </div>
                    <p x-show="editBerkasErrors.kategori_dokumen" x-text="editBerkasErrors.kategori_dokumen?.[0]" class="text-xs text-danger font-sans"></p>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nama Dokumen <span class="text-danger">*</span></label>
                    <input type="text" x-model="editBerkasForm.nama_dokumen"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                    <p x-show="editBerkasErrors.nama_dokumen" x-text="editBerkasErrors.nama_dokumen?.[0]" class="text-xs text-danger font-sans"></p>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Nomor Dokumen</label>
                    <input type="text" x-model="editBerkasForm.nomor_dokumen"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Tanggal Terbit</label>
                    <input type="date" x-model="editBerkasForm.tanggal_terbit"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans cursor-pointer">
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Keterangan</label>
                    <input type="text" x-model="editBerkasForm.deskripsi"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans">
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label class="text-xs font-bold text-ink uppercase tracking-wider font-sans">
                        Ganti File <span class="font-normal normal-case text-muted">(opsional)</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <label for="edit_berkas_file_input"
                            class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-primary/20 bg-surface px-3 py-2 text-xs font-semibold text-primary transition hover:bg-soft font-sans">Pilih File</label>
                        <input type="file" id="edit_berkas_file_input" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            @change="editBerkasForm.file = $event.target.files[0] || null">
                        <span class="min-w-0 flex-1 truncate text-xs font-sans" :class="editBerkasForm.file ? 'text-ink' : 'text-muted'"
                            x-text="editBerkasForm.file ? editBerkasForm.file.name : 'Biarkan kosong untuk mempertahankan file lama'"></span>
                        <button x-show="editBerkasForm.file" type="button"
                            @click="editBerkasForm.file = null; document.getElementById('edit_berkas_file_input').value = ''"
                            class="shrink-0 text-xs text-danger hover:underline font-sans">Hapus</button>
                    </div>
                    <p class="text-[10px] text-muted italic font-sans">Format PDF/JPG/PNG/DOC/DOCX, maks. 10 MB.</p>
                    <p x-show="editBerkasErrors.berkas" x-text="editBerkasErrors.berkas?.[0]" class="text-xs text-danger font-sans"></p>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-border">
                <button type="button" @click="showEditBerkasModal = false"
                    class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft cursor-pointer font-sans">
                    Batal
                </button>
                <button type="button" @click="submitEditBerkas()" :disabled="isUpdatingBerkas"
                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                    <span x-text="isUpdatingBerkas ? 'Menyimpan...' : 'Simpan Perubahan'"></span>
                </button>
            </div>
        </div>
    </x-ui.modal>
    @endif

    {{-- ==================== Modal: Hapus Berkas (2-step check-impact) ==================== --}}
    @if($canDeleteBerkas)
    <x-ui.modal
        show="showDeleteBerkasModal"
        title="Hapus Berkas"
        closeAction="showDeleteBerkasModal = false"
        maxWidth="md"
        bodyClass="p-5 space-y-3"
    >
        <div class="space-y-4">
            <template x-if="deleteBerkasStep === 'confirm'">
                <div class="space-y-4">
                    <p class="text-sm text-ink font-sans">
                        Hapus berkas <strong x-text="deleteBerkasName"></strong>?
                        Sistem akan memeriksa dampak penghapusan terhadap data kepegawaian.
                    </p>
                    <div class="flex justify-end gap-3 pt-2 border-t border-border">
                        <button type="button" @click="showDeleteBerkasModal = false"
                            class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft cursor-pointer font-sans">
                            Batal
                        </button>
                        <button type="button" @click="checkDeleteBerkasImpact()" :disabled="deleteBerkasLoading"
                            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                            <span x-text="deleteBerkasLoading ? 'Memeriksa...' : 'Lanjut Periksa'"></span>
                        </button>
                    </div>
                </div>
            </template>

            <template x-if="deleteBerkasStep === 'impact'">
                <div class="space-y-4">
                    <template x-if="!deleteBerkasHasBlocked">
                        <p class="text-sm text-success font-sans">Dokumen tidak digunakan data kepegawaian lain dan aman dihapus.</p>
                    </template>
                    <template x-if="deleteBerkasHasBlocked">
                        <div class="space-y-2">
                            <p class="text-sm text-danger font-sans">Dokumen tidak dapat dihapus karena masih digunakan oleh:</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <template x-for="(impacts, group) in deleteBerkasBlockedImpacts" :key="group">
                                    <li class="text-xs text-ink font-sans">
                                        <span class="font-bold" x-text="group"></span>
                                        <ul class="list-disc pl-4 mt-0.5">
                                            <template x-for="impact in impacts" :key="impact.id">
                                                <li class="text-xs text-muted" x-text="impact.label"></li>
                                            </template>
                                        </ul>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <div class="flex justify-end gap-3 pt-2 border-t border-border">
                        <button type="button" @click="deleteBerkasStep = 'confirm'"
                            class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft cursor-pointer font-sans">
                            Kembali
                        </button>
                        <template x-if="!deleteBerkasHasBlocked">
                            <button type="button" @click="submitDeleteBerkas()" :disabled="isDeletingBerkas"
                                class="inline-flex items-center justify-center rounded-lg bg-danger px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 cursor-pointer font-sans disabled:opacity-50">
                                <span x-text="isDeletingBerkas ? 'Menghapus...' : 'Ya, Hapus Berkas'"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.modal>
    @endif

</div>
