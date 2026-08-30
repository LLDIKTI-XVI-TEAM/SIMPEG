<x-pegawai.detail.panel tab="docs" id-prefix="admin">
    @if($canManageDocuments)
        <x-ui.modal
            show="showUploadBerkas"
            title="Unggah Berkas Lainnya"
            closeAction="if (!isUploadingBerkas) { showUploadBerkas = false; uploadBerkasError = ''; uploadBerkasErrors = {}; }"
            maxWidth="2xl"
            bodyClass="p-5 space-y-4"
        >
            <p class="text-xs text-muted font-sans">
                Form ini hanya untuk dokumen tambahan. Penambahan atau perubahan riwayat SK tetap dilakukan melalui alur riwayat kepegawaian.
            </p>

            <p
                x-show="uploadBerkasError"
                x-text="uploadBerkasError"
                class="rounded-lg border border-danger/20 bg-danger/10 p-3 text-xs font-semibold text-danger font-sans"
                role="alert"
            ></p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="berkas_nama_dokumen" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">
                        Nama Dokumen <span class="text-danger">*</span>
                    </label>
                    <input
                        id="berkas_nama_dokumen"
                        type="text"
                        x-model="newBerkas.nama_dokumen"
                        aria-describedby="berkas_nama_dokumen_error"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                    <p id="berkas_nama_dokumen_error" x-show="uploadBerkasErrors.nama_dokumen" x-text="uploadBerkasErrors.nama_dokumen?.[0]" class="text-xs text-danger"></p>
                </div>

                <div class="space-y-1">
                    <label for="berkas_kategori" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">
                        Kategori <span class="text-danger">*</span>
                    </label>
                    <select
                        id="berkas_kategori"
                        x-model="newBerkas.kategori_dokumen"
                        aria-describedby="berkas_kategori_error"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                        <option value="ktp_kk">KTP &amp; KK</option>
                        <option value="ijazah">Ijazah</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                    <p id="berkas_kategori_error" x-show="uploadBerkasErrors.kategori_dokumen" x-text="uploadBerkasErrors.kategori_dokumen?.[0]" class="text-xs text-danger"></p>
                </div>

                <div class="space-y-1">
                    <label for="berkas_nomor_dokumen" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Nomor Dokumen</label>
                    <input
                        id="berkas_nomor_dokumen"
                        type="text"
                        x-model="newBerkas.nomor_dokumen"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                </div>

                <div class="space-y-1">
                    <label for="berkas_tanggal_terbit" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Tanggal Terbit</label>
                    <input
                        id="berkas_tanggal_terbit"
                        type="date"
                        x-model="newBerkas.tanggal_terbit"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label for="berkas_keterangan" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Keterangan</label>
                    <textarea
                        id="berkas_keterangan"
                        rows="2"
                        x-model="newBerkas.keterangan"
                        class="w-full resize-none rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    ></textarea>
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label for="berkas_upload_input" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">
                        File Berkas <span class="text-danger">*</span>
                    </label>
                    <input
                        id="berkas_upload_input"
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                        @change="newBerkas.file = $event.target.files[0] || null"
                        aria-describedby="berkas_file_help berkas_file_error"
                        class="block w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:font-semibold file:text-primary"
                    >
                    <p id="berkas_file_help" class="text-[10px] text-muted">PDF, DOC, DOCX, JPG, JPEG, atau PNG; maksimum 10 MB.</p>
                    <p id="berkas_file_error" x-show="uploadBerkasErrors.berkas" x-text="uploadBerkasErrors.berkas?.[0]" class="text-xs text-danger"></p>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        @click="showUploadBerkas = false; uploadBerkasError = ''; uploadBerkasErrors = {}"
                        :disabled="isUploadingBerkas"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted transition hover:bg-soft disabled:opacity-60"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitUploadBerkas()"
                        :disabled="isUploadingBerkas"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary/90 disabled:opacity-60"
                    >
                        <span x-text="isUploadingBerkas ? 'Mengunggah...' : 'Unggah Berkas'"></span>
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal
            show="showEditBerkas"
            title="Ubah Berkas Lainnya"
            closeAction="if (!isUpdatingBerkas) { showEditBerkas = false; editBerkasError = ''; editBerkasErrors = {}; editingBerkas = null; }"
            maxWidth="2xl"
            bodyClass="p-5 space-y-4"
        >
            <p class="text-xs text-muted font-sans">
                Ubah metadata atau pilih file baru. Berkas yang menjadi lampiran riwayat kepegawaian harus dikelola melalui alur riwayat terkait.
            </p>

            <p
                x-show="editBerkasError"
                x-text="editBerkasError"
                class="rounded-lg border border-danger/20 bg-danger/10 p-3 text-xs font-semibold text-danger font-sans"
                role="alert"
            ></p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="edit_berkas_nama_dokumen" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">
                        Nama Dokumen <span class="text-danger">*</span>
                    </label>
                    <input
                        id="edit_berkas_nama_dokumen"
                        type="text"
                        x-model="editBerkas.nama_dokumen"
                        aria-describedby="edit_berkas_nama_dokumen_error"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                    <p id="edit_berkas_nama_dokumen_error" x-show="editBerkasErrors.nama_dokumen" x-text="editBerkasErrors.nama_dokumen?.[0]" class="text-xs text-danger"></p>
                </div>

                <div class="space-y-1">
                    <label for="edit_berkas_kategori" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">
                        Kategori <span class="text-danger">*</span>
                    </label>
                    <select
                        id="edit_berkas_kategori"
                        x-model="editBerkas.kategori_dokumen"
                        aria-describedby="edit_berkas_kategori_error"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                        <option value="ktp_kk">KTP &amp; KK</option>
                        <option value="ijazah">Ijazah</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                    <p id="edit_berkas_kategori_error" x-show="editBerkasErrors.kategori_dokumen" x-text="editBerkasErrors.kategori_dokumen?.[0]" class="text-xs text-danger"></p>
                </div>

                <div class="space-y-1">
                    <label for="edit_berkas_nomor_dokumen" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Nomor Dokumen</label>
                    <input
                        id="edit_berkas_nomor_dokumen"
                        type="text"
                        x-model="editBerkas.nomor_dokumen"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                </div>

                <div class="space-y-1">
                    <label for="edit_berkas_tanggal_terbit" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Tanggal Terbit</label>
                    <input
                        id="edit_berkas_tanggal_terbit"
                        type="date"
                        x-model="editBerkas.tanggal_terbit"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label for="edit_berkas_keterangan" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Keterangan</label>
                    <textarea
                        id="edit_berkas_keterangan"
                        rows="2"
                        x-model="editBerkas.keterangan"
                        class="w-full resize-none rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    ></textarea>
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <label for="edit_berkas_upload_input" class="text-xs font-bold uppercase tracking-wider text-ink font-sans">File Pengganti</label>
                    <input
                        id="edit_berkas_upload_input"
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                        @change="editBerkas.file = $event.target.files[0] || null"
                        aria-describedby="edit_berkas_file_help edit_berkas_file_error"
                        class="block w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:font-semibold file:text-primary"
                    >
                    <p id="edit_berkas_file_help" class="text-[10px] text-muted">Kosongkan bila file tidak berubah. Format PDF, DOC, DOCX, JPG, JPEG, atau PNG; maksimum 10 MB.</p>
                    <p id="edit_berkas_file_error" x-show="editBerkasErrors.berkas" x-text="editBerkasErrors.berkas?.[0]" class="text-xs text-danger"></p>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        @click="showEditBerkas = false; editBerkasError = ''; editBerkasErrors = {}; editingBerkas = null"
                        :disabled="isUpdatingBerkas"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted transition hover:bg-soft disabled:opacity-60"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitUpdateBerkas()"
                        :disabled="isUpdatingBerkas"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary/90 disabled:opacity-60"
                    >
                        <span x-text="isUpdatingBerkas ? 'Menyimpan...' : 'Simpan Perubahan'"></span>
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal
            show="showDeleteBerkas"
            title="Hapus Berkas Lainnya"
            closeAction="if (!isDeletingBerkas) { showDeleteBerkas = false; deleteBerkasError = ''; deletingBerkas = null; }"
            maxWidth="lg"
            bodyClass="p-5 space-y-4"
        >
            <p class="text-sm text-ink">
                Hapus <strong x-text="deletingBerkas?.nama_dokumen ?? 'berkas ini'"></strong>? Metadata dan file privat akan dihapus permanen setelah transaksi berhasil.
            </p>
            <p class="text-xs text-muted">
                Berkas yang masih digunakan oleh riwayat kepegawaian akan ditolak oleh sistem.
            </p>
            <p
                x-show="deleteBerkasError"
                x-text="deleteBerkasError"
                class="rounded-lg border border-danger/20 bg-danger/10 p-3 text-xs font-semibold text-danger"
                role="alert"
            ></p>

            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        @click="showDeleteBerkas = false; deleteBerkasError = ''; deletingBerkas = null"
                        :disabled="isDeletingBerkas"
                        class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted transition hover:bg-soft disabled:opacity-60"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitDeleteBerkas()"
                        :disabled="isDeletingBerkas"
                        class="inline-flex items-center justify-center rounded-lg bg-danger px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-danger/90 disabled:opacity-60"
                    >
                        <span x-text="isDeletingBerkas ? 'Menghapus...' : 'Hapus Berkas'"></span>
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @php
        $documentStatusLabels = [
            'tidak_dinilai' => 'Tidak Dinilai',
            'belum_ada' => 'Belum Ada',
            'belum_lengkap' => 'Belum Lengkap',
            'lengkap' => 'Lengkap',
            'perlu_perbaikan' => 'Perlu Perbaikan',
        ];
        $documentStatusBadgeClasses = [
            'tidak_dinilai' => 'bg-soft text-muted',
            'belum_ada' => 'bg-soft text-muted',
            'belum_lengkap' => 'bg-warning/10 text-warning',
            'lengkap' => 'bg-success/10 text-success',
            'perlu_perbaikan' => 'bg-danger/10 text-danger',
        ];
        $requiredSkTabs = [
            'sk_pengangkatan' => 'pengangkatan',
            'sk_pangkat' => 'kepangkatan',
            'sk_jabatan' => 'jabatan',
            'sk_kgb' => 'kgb',
        ];
        $statusKey = $documentStatus['status_kelengkapan'];
    @endphp

    <section
        class="space-y-3"
        aria-labelledby="dokumen-sk-heading"
        data-document-status="{{ $statusKey }}"
        data-document-is-dinilai="{{ $documentStatus['is_dinilai'] ? 'true' : 'false' }}"
        data-document-tersedia="{{ $documentStatus['tersedia_count'] }}"
        data-document-total-wajib="{{ $documentStatus['total_wajib'] }}"
    >
        <x-pegawai.detail.section-header
            title="Dokumen SK"
            description="Daftar dan status berikut mengikuti matriks SK wajib aktif untuk jenis pegawai ini."
            heading-id="dokumen-sk-heading"
        >
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex w-fit shrink-0 items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $documentStatusBadgeClasses[$statusKey] ?? 'bg-soft text-muted' }}">
                        {{ $documentStatusLabels[$statusKey] ?? 'Status Tidak Dikenal' }}
                        @if($documentStatus['is_dinilai'])
                            ({{ $documentStatus['tersedia_count'] }}/{{ $documentStatus['total_wajib'] }})
                        @endif
                    </span>
                    @if($canManageDocuments)
                        <x-ui.button
                            type="button"
                            size="sm"
                            @click="showUploadBerkas = true; uploadBerkasError = ''; uploadBerkasErrors = {}"
                        >Unggah Berkas Lainnya</x-ui.button>
                    @endif
                </div>
            </x-slot:actions>
        </x-pegawai.detail.section-header>

        @if(! $documentStatus['is_dinilai'])
            <div class="rounded-lg border border-border bg-soft/40 p-4" role="status">
                <p class="text-sm font-semibold text-ink">Matriks SK wajib belum dikonfigurasi</p>
                <p class="mt-1 text-xs text-muted">Kelengkapan tidak dinilai sampai jenis pegawai ini memiliki sedikitnya satu kategori SK wajib aktif.</p>
            </div>

            <x-pegawai.detail.table
                name="dokumen-sk"
                :headings="['SK Wajib', 'Status', 'Nomor', 'Tanggal']"
                :show-actions="true"
            >
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-muted">
                        Belum ada kategori SK wajib aktif untuk jenis pegawai ini.
                    </td>
                </tr>
            </x-pegawai.detail.table>
        @else
            <p class="text-xs text-muted">
                {{ $documentStatus['tersedia_count'] }} dari {{ $documentStatus['total_wajib'] }} SK tersedia dan valid.
            </p>

            <x-pegawai.detail.table
                name="dokumen-sk"
                :headings="['SK Wajib', 'Status', 'Nomor', 'Tanggal']"
                :show-actions="true"
            >
                @foreach($documentStatus['required_sks'] as $requiredSk)
                    @php
                        $requiredStatusClass = match ($requiredSk['status']) {
                            'tersedia' => 'bg-success/10 text-success',
                            'perlu_perbaikan' => 'bg-danger/10 text-danger',
                            default => 'bg-soft text-muted',
                        };
                        $managementTab = $requiredSkTabs[$requiredSk['jenis']] ?? 'docs';
                    @endphp
                    <tr
                        class="transition-colors hover:bg-soft/30"
                        data-required-sk="{{ $requiredSk['jenis'] }}"
                        data-required-sk-status="{{ $requiredSk['status'] }}"
                    >
                        <td class="px-4 py-3 font-semibold text-ink">{{ $requiredSk['label'] }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $requiredStatusClass }}">
                                {{ $requiredSk['status_label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-muted">{{ $requiredSk['nomor_sk'] ?: '-' }}</td>
                        <td class="px-4 py-3 text-muted">
                            {{ filled($requiredSk['tanggal_sk']) ? \Illuminate\Support\Carbon::parse($requiredSk['tanggal_sk'])->format('d-m-Y') : '-' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                @if($requiredSk['file_url'])
                                    <a
                                        href="{{ $requiredSk['file_url'] }}"
                                        class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft"
                                        aria-label="Unduh {{ $requiredSk['label'] }}"
                                    >Unduh</a>
                                @endif
                                <a
                                    href="{{ route('pegawai.show', $p).'?tab='.$managementTab }}"
                                    class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft"
                                    aria-label="Kelola {{ $requiredSk['label'] }} dari data sumber"
                                >Kelola</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-pegawai.detail.table>
        @endif
    </section>

    <section class="space-y-3" aria-labelledby="arsip-sk-heading">
        <x-pegawai.detail.section-header
            title="Arsip SK"
            description="Seluruh record arsip SK tetap tersedia untuk dilihat dan diunduh tanpa memengaruhi penilaian matriks aktif."
            heading-id="arsip-sk-heading"
        />

        <x-pegawai.detail.table
            name="arsip-sk"
            :headings="['Nama Dokumen', 'Kategori', 'Nomor', 'Tanggal', 'Ukuran']"
            :show-actions="true"
        >
            @forelse($archivedSkRows as $archive)
                <tr
                    class="transition-colors hover:bg-soft/30"
                    data-archived-sk="{{ $archive['id'] }}"
                >
                    <td class="px-4 py-3 font-semibold text-ink">{{ $archive['nama_dokumen'] }}</td>
                    <td class="px-4 py-3 text-muted">{{ $archive['kategori_label'] }}</td>
                    <td class="px-4 py-3 font-mono text-muted">{{ $archive['nomor_dokumen'] ?: '-' }}</td>
                    <td class="px-4 py-3 text-muted">{{ $archive['tanggal_dokumen'] ?: '-' }}</td>
                    <td class="px-4 py-3 text-muted">{{ $archive['file_size'] }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <a
                                href="{{ $archive['detail_url'] }}"
                                class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft"
                                aria-label="Lihat detail {{ $archive['nama_dokumen'] }}"
                            >Detail</a>
                            @if($archive['file_tersedia'])
                                <a
                                    href="{{ $archive['download_url'] }}"
                                    class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft"
                                    aria-label="Unduh {{ $archive['nama_dokumen'] }}"
                                >Unduh</a>
                            @else
                                <span class="text-xs font-semibold text-danger">File tidak ditemukan</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-muted">Belum ada arsip SK.</td>
                </tr>
            @endforelse
        </x-pegawai.detail.table>
    </section>

    <section class="space-y-3" aria-labelledby="berkas-lainnya-heading">
        <x-pegawai.detail.section-header
            title="Berkas Lainnya"
            description="KTP/KK, ijazah, dan dokumen tambahan lain yang diunggah dari profil pegawai."
            heading-id="berkas-lainnya-heading"
        />

        <x-pegawai.detail.table
            name="berkas-lainnya"
            :headings="['Nama Dokumen', 'Kategori', 'Nomor', 'Tanggal', 'Ukuran']"
            :show-actions="true"
        >
            <template x-if="berkasList.length === 0">
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-muted">Belum ada berkas lainnya.</td>
                </tr>
            </template>
            <template x-for="doc in berkasList" :key="doc.id">
                <tr class="transition-colors hover:bg-soft/30">
                    <td class="px-4 py-3 font-semibold text-ink" x-text="doc.nama_dokumen"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.kategori_label"></td>
                    <td class="px-4 py-3 font-mono text-muted" x-text="doc.nomor_dokumen || '-'"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.tanggal_dokumen || '-'"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.file_size"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            <a :href="doc.detail_url" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft" :aria-label="'Lihat detail ' + doc.nama_dokumen">Detail</a>
                            <a x-show="doc.file_tersedia !== false" :href="doc.download_url" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft" :aria-label="'Unduh ' + doc.nama_dokumen">Unduh</a>
                            <span x-show="doc.file_tersedia === false" class="text-xs font-semibold text-danger">File tidak ditemukan</span>
                            @if($canManageDocuments)
                                <button
                                    x-show="doc.can_mutate"
                                    type="button"
                                    @click="openEditBerkas(doc)"
                                    class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft"
                                    :aria-label="'Ubah ' + doc.nama_dokumen"
                                >Ubah</button>
                                <button
                                    x-show="doc.can_mutate"
                                    type="button"
                                    @click="openDeleteBerkas(doc)"
                                    class="rounded-lg border border-danger/30 bg-surface px-2.5 py-1.5 text-xs font-semibold text-danger hover:bg-danger/10"
                                    :aria-label="'Hapus ' + doc.nama_dokumen"
                                >Hapus</button>
                                <span x-show="!doc.can_mutate" class="text-xs font-semibold text-muted">Dikelola dari riwayat</span>
                            @endif
                        </div>
                    </td>
                </tr>
            </template>
        </x-pegawai.detail.table>
    </section>
</x-pegawai.detail.panel>
