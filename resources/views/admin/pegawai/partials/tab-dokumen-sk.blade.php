<x-pegawai.detail.panel tab="docs" id-prefix="admin">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="text-sm font-bold text-ink font-sans">Dokumen &amp; SK</h3>
            <p class="mt-0.5 text-xs text-muted font-sans">
                Dokumen SK ditampilkan terpisah dari KTP/KK, ijazah, dan berkas tambahan lainnya.
            </p>
        </div>

        @if($canManageDocuments)
            <button
                type="button"
                @click="showUploadBerkas = true; uploadBerkasError = ''; uploadBerkasErrors = {}"
                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary/90"
            >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Unggah Berkas Lainnya
            </button>
        @endif
    </div>

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
    @endif

    <section class="space-y-3" aria-labelledby="dokumen-sk-heading">
        <div>
            <h4 id="dokumen-sk-heading" class="text-sm font-semibold text-ink">Dokumen SK</h4>
            <p class="text-xs text-muted">Dokumen yang berasal dari data dan riwayat kepegawaian.</p>
        </div>

        <x-pegawai.detail.table
            name="dokumen-sk"
            :headings="['Nama Dokumen', 'Kategori', 'Nomor', 'Tanggal', 'Ukuran']"
            :show-actions="true"
        >
            <template x-if="skList.length === 0">
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-muted">Belum ada dokumen SK.</td>
                </tr>
            </template>
            <template x-for="doc in skList" :key="doc.id">
                <tr class="transition-colors hover:bg-soft/30">
                    <td class="px-4 py-3 font-semibold text-ink" x-text="doc.nama_dokumen"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.kategori_label"></td>
                    <td class="px-4 py-3 font-mono text-muted" x-text="doc.nomor_dokumen || '-'"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.tanggal_dokumen || '-'"></td>
                    <td class="px-4 py-3 text-muted" x-text="doc.file_size"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            <a :href="doc.detail_url" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft" :aria-label="'Lihat detail ' + doc.nama_dokumen">Detail</a>
                            <a x-show="doc.file_tersedia" :href="doc.download_url" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-soft" :aria-label="'Unduh ' + doc.nama_dokumen">Unduh</a>
                            <span x-show="!doc.file_tersedia" class="text-xs font-semibold text-danger">File tidak ditemukan</span>
                        </div>
                    </td>
                </tr>
            </template>
        </x-pegawai.detail.table>
    </section>

    <section class="space-y-3" aria-labelledby="berkas-lainnya-heading">
        <div>
            <h4 id="berkas-lainnya-heading" class="text-sm font-semibold text-ink">Berkas Lainnya</h4>
            <p class="text-xs text-muted">KTP/KK, ijazah, dan dokumen tambahan lain yang diunggah dari profil pegawai.</p>
        </div>

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
                        </div>
                    </td>
                </tr>
            </template>
        </x-pegawai.detail.table>
    </section>
</x-pegawai.detail.panel>
