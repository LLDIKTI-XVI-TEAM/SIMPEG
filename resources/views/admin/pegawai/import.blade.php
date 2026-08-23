@push('head')
    @vite('resources/js/pages/employee-import.js')
@endpush



<x-layouts.app title="Import Data Pegawai">
    <div class="space-y-6" x-data="employeeImport">
        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Import Data Pegawai</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Data Pegawai', 'url' => route('data-pegawai')],
                    ['label' => 'Import Excel/CSV']
                ]" />
            </div>
            <div class="flex shrink-0 items-center gap-3">
                {{-- Additional header actions can be placed here --}}
            </div>
        </div>

        {{-- Global API Error Banner --}}
        <div x-show="apiError" x-cloak class="rounded-lg bg-danger/10 border border-danger/20 p-4 text-xs text-danger font-sans flex items-start gap-2.5" x-transition>
            <span class="text-base leading-none">❌</span>
            <div>
                <span class="font-bold">Terjadi Kesalahan</span>
                <p class="mt-0.5 leading-relaxed" x-text="apiError"></p>
            </div>
            <button type="button" @click="apiError = ''" class="ml-auto text-danger/60 hover:text-danger cursor-pointer">✕</button>
        </div>

        {{-- STEP INDICATORS (Wizard) --}}
        <x-ui.card padding="sm" class="select-none">
            <div class="flex items-center justify-between max-w-4xl mx-auto text-xs font-semibold overflow-x-auto pb-1">
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 1 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center">1</span>
                    <span :class="step >= 1 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Upload</span>
                </div>
                <div :class="step > 1 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 2 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center">2</span>
                    <span :class="step >= 2 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Preview & Edit</span>
                </div>
                <div :class="step > 2 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 3 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center">3</span>
                    <span :class="step >= 3 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Validasi</span>
                </div>
                <div :class="step > 3 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 4 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center">4</span>
                    <span :class="step >= 4 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Proses</span>
                </div>
                <div :class="step > 4 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 5 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center">5</span>
                    <span :class="step >= 5 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Hasil</span>
                </div>
            </div>
        </x-ui.card>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 1: DOWNLOAD TEMPLATE & UPLOAD                                --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 1" class="space-y-6" x-transition>
            
            {{-- Download Template Card --}}
            <x-ui.card padding="lg" class="space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">1. Download Template Import Pegawai</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Gunakan template agar header kolom sesuai dan data dapat terbaca dengan tepat oleh sistem.</p>
                </div>
                <x-ui.segmented-control label="Format template">
                    <x-ui.segmented-item active="templateFormat === 'xlsx'" click="templateFormat = 'xlsx'">XLSX</x-ui.segmented-item>
                    <x-ui.segmented-item active="templateFormat === 'csv'" click="templateFormat = 'csv'">CSV UTF-8</x-ui.segmented-item>
                </x-ui.segmented-control>
                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="button" @click="activeTemplate = 'utama'; downloadTemplate('utama')" :disabled="downloadingType !== ''"
                        :class="downloadingType ? 'opacity-50 cursor-not-allowed' : 'hover:bg-soft cursor-pointer'"
                        class="flex items-center gap-3 border border-border bg-surface text-muted p-4 rounded-lg transition shadow-sm w-full">
                        <x-ui.loading x-show="downloadingType === 'utama'" size="xl" color="muted" class="shrink-0" />
                        <svg x-show="downloadingType !== 'utama'" class="w-8 h-8 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <div class="text-left">
                            <span class="text-sm font-medium block font-sans text-muted">
                                <span x-show="downloadingType === 'utama'">Menyiapkan Template...</span>
                                <span x-show="downloadingType !== 'utama'">Unduh Template Utama Pegawai</span>
                            </span>
                            <span class="text-xs text-muted block mt-0.5 font-sans">Berisi data pegawai seperti NIP, NIK, No. KK, golongan, dan jabatan. Role tidak diimpor; kelola Role melalui menu Kelola Akses User.</span>
                        </div>
                    </button>
                </div>
            </x-ui.card>

            {{-- Upload File Area Card --}}
            <x-ui.card padding="lg" class="space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">2. Unggah Berkas Pegawai (Excel/CSV)</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Unggah berkas data pegawai dalam format XLSX, XLS, atau CSV UTF-8 dengan ukuran maksimal 10MB.</p>
                </div>
                
                <div 
                    @dragover.prevent="dragover = true" 
                    @dragleave.prevent="dragover = false" 
                    @drop.prevent="dragover = false; handleFileSelect($event)"
                    :class="dragover ? 'border-primary bg-primary/5' : 'border-border bg-soft/50'"
                    class="border-2 border-dashed rounded-lg p-10 text-center relative hover:border-primary transition group"
                >
                    <input type="file" id="import_file" accept=".xlsx,.xls,.csv,.txt" @change="handleFileSelect" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                    <svg class="mx-auto h-12 w-12 text-muted group-hover:text-primary transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                    <p class="text-sm text-ink font-semibold mt-3 font-sans">Pilih berkas atau seret berkas Anda di sini</p>
                    <p class="text-xs text-muted mt-1 font-sans">Format: XLSX, XLS, atau CSV UTF-8. Maksimal 10MB.</p>
                    <template x-if="fileName">
                        <div class="mt-4 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink shadow-sm">
                            <svg class="w-4 h-4 text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <span x-text="fileName"></span>
                            <span class="text-muted" x-text="'(' + fileSize + ')'"></span>
                        </div>
                    </template>
                    <p x-show="fileError" class="text-xs text-danger font-semibold mt-3 font-sans" x-text="fileError"></p>
                </div>

                <div class="border-t border-border pt-4 flex items-center justify-between">
                    <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition hover:bg-soft cursor-pointer">
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        Batal
                    </a>

                    <button type="button" @click="uploadAndPreview()" :disabled="!fileValid || isUploading"
                        :class="(!fileValid || isUploading) ? 'opacity-50 cursor-not-allowed bg-muted' : 'bg-primary hover:opacity-90 cursor-pointer'"
                        class="inline-flex items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition font-sans gap-2">
                        <x-ui.loading x-show="isUploading" size="md" />
                        <svg x-show="!isUploading" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                        <span x-text="isUploading ? 'Mengupload & Memproses...' : 'Upload & Lanjutkan ke Preview'"></span>
                    </button>
                </div>
            </x-ui.card>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 2: PREVIEW & EDIT DATA (EDITABLE TABLE)                      --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 2" class="space-y-6" style="display: none;" x-transition>
            
            {{-- File Info Bar --}}
            <div class="rounded-lg border border-primary/20 bg-primary/5 p-4 flex items-center justify-between">
                <div class="flex items-center gap-3 text-xs font-sans">
                    <svg class="w-5 h-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    <div>
                        <span class="font-bold text-ink" x-text="fileName"></span>
                        <span class="text-muted ml-2" x-text="'(' + totalRows + ' baris data)'"></span>
                    </div>
                </div>
                <div x-show="hasEdits" class="flex items-center gap-1.5 text-[10px] font-bold text-warning uppercase tracking-wider">
                    <span>●</span> Ada perubahan belum divalidasi
                </div>
            </div>

            {{-- Column Mapping Card (US-3.2 AC-4, AC-5) --}}
            <x-ui.card padding="lg" class="space-y-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="max-w-3xl">
                        <h3 class="text-lg font-semibold text-ink font-sans">Pemetaan Kolom</h3>
                        <p class="mt-1 text-sm leading-relaxed text-muted font-sans">
                            Periksa pasangan kolom sumber dan field SIMPEG. Anda dapat mengubah mapping sebelum validasi; kolom yang dipilih sebagai <strong class="text-ink">Tidak dipakai</strong> tidak akan disimpan.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2" aria-label="Ringkasan pemetaan kolom">
                        <span class="rounded-full border border-success/20 bg-success/10 px-3 py-1 text-xs font-semibold text-success">
                            <span x-text="mappedColumnCount"></span> dipetakan
                        </span>
                        <span class="rounded-full border border-warning/20 bg-warning/10 px-3 py-1 text-xs font-semibold text-warning-dark">
                            <span x-text="skippedSourceHeaders.length"></span> tidak dipakai
                        </span>
                    </div>
                </div>

                {{-- Unknown columns are non-blocking when required targets remain complete. --}}
                <div x-show="unknownSourceHeaders.length > 0" x-cloak role="status" aria-live="polite"
                    class="flex items-start gap-3 rounded-lg border border-warning/20 bg-warning/10 p-4 text-warning-dark" x-transition>
                    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div class="space-y-1 text-sm">
                        <p class="font-semibold">Kolom tidak dikenal ditemukan</p>
                        <p class="leading-relaxed">
                            Nilai dari
                            <template x-for="(header, index) in unknownSourceHeaders" :key="header">
                                <strong x-text="header + (index < unknownSourceHeaders.length - 1 ? ', ' : '')"></strong>
                            </template>
                            tidak disimpan selama tetap dipetakan ke <strong>Tidak dipakai</strong>. Peringatan ini tidak memblokir import jika seluruh field wajib sudah dipetakan.
                        </p>
                    </div>
                </div>

                {{-- Canonical SIMPEG headers may be skipped by the administrator without becoming unknown. --}}
                <div x-show="intentionallySkippedSourceHeaders.length > 0" x-cloak role="note"
                    class="flex items-start gap-3 rounded-lg border border-border bg-soft/60 p-4 text-ink">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                    <div class="space-y-1 text-sm">
                        <p class="font-semibold">Kolom SIMPEG sengaja tidak dipakai</p>
                        <p class="leading-relaxed text-muted">
                            <template x-for="(header, index) in intentionallySkippedSourceHeaders" :key="header">
                                <strong class="text-ink" x-text="header + (index < intentionallySkippedSourceHeaders.length - 1 ? ', ' : '')"></strong>
                            </template>
                            adalah kolom yang didukung SIMPEG, tetapi nilainya tidak akan disimpan karena dipilih sebagai <strong class="text-ink">Tidak dipakai</strong>. Kondisi ini tidak memblokir import selama field wajib sudah dipetakan.
                        </p>
                    </div>
                </div>

                <div x-show="missingRequiredTargets.length > 0" x-cloak role="alert" aria-live="assertive" dusk="mapping-required-warning"
                    class="flex items-start gap-3 rounded-lg border border-danger/20 bg-danger/10 p-4 text-danger" x-transition>
                    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="text-sm">
                        <p class="font-semibold">Field wajib belum dipetakan</p>
                        <p class="mt-1 leading-relaxed">
                            Pilih kolom sumber untuk
                            <template x-for="(field, index) in missingRequiredTargets" :key="field">
                                <strong x-text="field + (index < missingRequiredTargets.length - 1 ? ', ' : '')"></strong>
                            </template>
                            sebelum melanjutkan.
                        </p>
                    </div>
                </div>

                <fieldset>
                    <legend class="sr-only">Pasangan kolom sumber dan field target SIMPEG</legend>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <template x-for="(header, index) in mainHeaders" :key="'map-' + header">
                            <div class="space-y-2 rounded-lg border bg-surface p-4"
                                :class="duplicateMappedFields.includes(columnMapping[header]) ? 'border-danger/40' : 'border-border'">
                                <div class="flex items-start justify-between gap-3">
                                    <label :for="mappingControlId(header, index)" class="min-w-0 text-sm font-semibold leading-snug text-ink" x-text="header"></label>
                                    <span class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold"
                                        :class="columnMapping[header] === 'tidak_dipakai' ? 'border-warning/20 bg-warning/10 text-warning-dark' : 'border-primary/20 bg-primary/10 text-primary'"
                                        x-text="columnMapping[header] === 'tidak_dipakai' ? 'Tidak dipakai' : 'Dipetakan'">
                                    </span>
                                </div>
                                <select :id="mappingControlId(header, index)" x-model="columnMapping[header]"
                                    :disabled="isLockedIgnoredHeader(header)" @change="onMappingChange()"
                                    :aria-describedby="mappingControlId(header, index) + '-help'"
                                    class="min-h-11 w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:bg-soft disabled:text-muted">
                                    <option value="tidak_dipakai">Tidak dipakai / Skip (nilai diabaikan)</option>
                                    <template x-for="target in simpegTargetFields" :key="target.key">
                                        <option :value="target.key" :selected="columnMapping[header] === target.key" x-text="target.label"></option>
                                    </template>
                                </select>
                                <p :id="mappingControlId(header, index) + '-help'" class="text-xs leading-relaxed text-muted"
                                    x-text="isLockedIgnoredHeader(header) ? 'Kolom ini dikunci sebagai Tidak dipakai sesuai PRD.' : (columnMapping[header] === 'tidak_dipakai' ? 'Nilai kolom ini tidak akan disimpan.' : 'Target: ' + mappingTargetLabel(columnMapping[header]))">
                                </p>
                            </div>
                        </template>
                    </div>
                </fieldset>
            </x-ui.card>

            {{-- Editable Preview Table --}}
            <x-ui.card padding="lg" class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">Preview & Edit Data</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Klik langsung pada sel untuk mengedit data. Perubahan akan disimpan secara otomatis sebelum validasi.</p>
                    </div>
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans bg-soft px-2 py-1 rounded">
                        Hal. <span x-text="previewPage"></span> / <span x-text="previewTotalPages"></span>
                    </span>
                </div>
                
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table class="text-xs">
                        <x-ui.table-head class="sticky top-0 z-10">
                            <x-ui.table-row>
                                <x-ui.table-th padding="xs" class="border-r w-12">No</x-ui.table-th>
                                <template x-for="header in mainHeaders" :key="header">
                                    <x-ui.table-th padding="xs" class="border-r min-w-[200px]">
                                        <span class="block" x-text="header"></span>
                                        <span class="mt-0.5 block text-[10px] font-medium normal-case tracking-normal text-muted"
                                            x-text="columnMapping[header] === 'tidak_dipakai' ? 'Tidak dipakai' : '→ ' + mappingTargetLabel(columnMapping[header])"></span>
                                    </x-ui.table-th>
                                </template>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            <template x-for="(rowObj, rIndex) in paginatedRows" :key="rowObj.row">
                                <x-ui.table-row class="group hover:bg-primary/[0.02]">
                                    <x-ui.table-td x-text="(previewPage - 1) * previewPerPage + rIndex + 1" align="center" class="px-3 py-1.5 border-r border-border text-muted"></x-ui.table-td>
                                    <template x-for="header in mainHeaders" :key="header">
                                        <x-ui.table-td class="px-0.5 py-0.5 border-r border-border">
                                            <input 
                                                type="text"
                                                :value="rowObj.data[header] ?? ''"
                                                 @input="rowObj.data[header] = $event.target.value; onCellEdit((previewPage - 1) * previewPerPage + rIndex, header)"
                                                 :aria-label="'Baris ' + rowObj.row + ', ' + header"
                                                 class="w-full px-2 py-1.5 text-xs text-ink bg-transparent border border-transparent rounded hover:border-border hover:bg-soft/10 focus:border-primary focus:bg-surface focus:outline-none focus:ring-1 focus:ring-primary/30 transition min-w-[200px]"
                                                :placeholder="header"
                                            >
                                        </x-ui.table-td>
                                    </template>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>

                {{-- Pagination --}}
                <div class="flex items-center justify-between text-xs font-sans" x-show="previewTotalPages > 1">
                    <span class="text-muted">
                        Menampilkan <span class="font-bold text-ink" x-text="((previewPage - 1) * previewPerPage) + 1"></span>–<span class="font-bold text-ink" x-text="Math.min(previewPage * previewPerPage, allRows.length)"></span>
                        dari <span class="font-bold text-ink" x-text="allRows.length"></span> baris
                    </span>
                    <div class="flex items-center gap-1">
                        <x-ui.pagination current="previewPage" total="previewTotalPages" />
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <x-ui.button type="button" variant="muted" @click="resetAll()">
                        Batal & Upload Ulang
                    </x-ui.button>
                    <div class="flex flex-col items-stretch gap-2 sm:items-end">
                        <p id="mapping-readiness-message" aria-live="polite" class="text-xs"
                            :class="canProceedToValidation ? 'text-success' : 'text-danger'"
                            x-text="canProceedToValidation ? 'Pemetaan siap digunakan untuk validasi.' : 'Selesaikan konflik dan field wajib pada panel pemetaan.'"></p>
                        <button type="button" @click="runValidation()" :disabled="isValidating || !canProceedToValidation" dusk="mapping-continue"
                            aria-describedby="mapping-readiness-message"
                            :class="(isValidating || !canProceedToValidation) ? 'cursor-not-allowed bg-muted opacity-60' : 'cursor-pointer bg-primary hover:bg-primary-hover'"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition font-sans focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2">
                            <x-ui.loading x-show="isValidating" size="md" />
                            <span x-text="isValidating ? 'Memvalidasi...' : 'Lanjutkan ke Validasi'"></span>
                        </button>
                    </div>
                </div>
            </x-ui.card>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 3: VALIDASI DATA (EDITABLE ERROR ROWS)                       --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 3" class="space-y-6" style="display: none;" x-transition>
            
            {{-- Ringkasan Validasi Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <x-ui.card padding="sm" class="text-center">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Total Baris</span>
                    <p class="text-2xl font-bold text-ink font-sans mt-1" x-text="totalRows"></p>
                </x-ui.card>
                <x-ui.button type="button" variant="ghost"
                    @click="valFilter = valFilter === 'valid' ? 'all' : 'valid'; valPage = 1"
                    x-bind:aria-pressed="valFilter === 'valid'"
                    dusk="validation-filter-valid"
                    class="h-full w-full flex-col rounded-lg border-success/20 bg-success/5 p-4 text-center hover:bg-success/10 focus:ring-success/30">
                    <x-ui.badge variant="success" size="sm" uppercase>Valid (Siap Impor)</x-ui.badge>
                    <p class="text-2xl font-bold text-success font-sans mt-1" x-text="validRows"></p>
                </x-ui.button>
                <x-ui.button type="button" variant="ghost"
                    @click="valFilter = valFilter === 'skip' ? 'all' : 'skip'; valPage = 1"
                    x-bind:aria-pressed="valFilter === 'skip'"
                    dusk="validation-filter-skip"
                    class="h-full w-full flex-col rounded-lg border-primary/20 bg-primary/5 p-4 text-center hover:bg-primary/10 focus:ring-primary/30">
                    <x-ui.badge variant="primary" size="sm">Terlewat (sudah ada)</x-ui.badge>
                    <p dusk="validation-skip-count" class="text-2xl font-bold text-primary font-sans mt-1" x-text="skipRows"></p>
                </x-ui.button>
                <x-ui.button type="button" variant="ghost"
                    @click="valFilter = valFilter === 'error' ? 'all' : 'error'; valPage = 1"
                    x-bind:aria-pressed="valFilter === 'error'"
                    dusk="validation-filter-error"
                    class="h-full w-full flex-col rounded-lg border-danger/20 bg-danger/5 p-4 text-center hover:bg-danger/10 focus:ring-danger/30">
                    <x-ui.badge variant="danger" size="sm" uppercase>Error (Bermasalah)</x-ui.badge>
                    <p class="text-2xl font-bold text-danger font-sans mt-1" x-text="errorRows"></p>
                </x-ui.button>
            </div>

            {{-- Filter indicator --}}
            <div x-show="valFilter !== 'all'" class="rounded-lg bg-soft border border-border p-3 flex items-center justify-between text-xs font-sans">
                <span class="text-muted">Filter aktif: <span class="font-bold text-ink" x-text="validationFilterLabel(valFilter)"></span> (<span x-text="filteredValidations.length"></span> baris)</span>
                <button type="button" @click="valFilter = 'all'; valPage = 1" class="text-primary font-semibold hover:underline cursor-pointer">Tampilkan Semua</button>
            </div>

            {{-- Validation Results Table with Inline Edit --}}
            <x-ui.card padding="lg" class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">Hasil Validasi</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Baris error bisa diedit langsung. Klik <strong>"Validasi Ulang"</strong> setelah memperbaiki data.</p>
                    </div>
                    <div x-show="hasEdits" class="flex items-center gap-1.5 text-[10px] font-bold text-warning uppercase tracking-wider">
                        <span>●</span> Ada perubahan
                    </div>
                </div>
                
                <div class="overflow-x-auto rounded-lg border border-border">
                    <x-ui.table class="text-xs">
                        <x-ui.table-head class="sticky top-0 z-10">
                            <x-ui.table-row>
                                <x-ui.table-th padding="xs" class="border-r w-16">Baris</x-ui.table-th>
                                <x-ui.table-th padding="xs" class="border-r w-16">Status</x-ui.table-th>
                                <template x-for="header in mainHeaders" :key="'val-' + header">
                                    <x-ui.table-th padding="xs" class="border-r min-w-[200px]">
                                        <span class="block" x-text="header"></span>
                                        <span class="mt-0.5 block text-[10px] font-medium normal-case tracking-normal text-muted"
                                            x-text="columnMapping[header] === 'tidak_dipakai' ? 'Tidak dipakai' : '→ ' + mappingTargetLabel(columnMapping[header])"></span>
                                    </x-ui.table-th>
                                </template>
                                <x-ui.table-th padding="xs" class="min-w-[200px]">Keterangan</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <x-ui.table-body>
                            <template x-for="item in paginatedValidations" :key="item.row">
                                <x-ui.table-row x-init="ensureValidationRow(item)" x-bind:dusk="'validation-row-' + item.row" x-bind:class="{
                                    'bg-danger/[0.03]': item.status === 'error',
                                    'bg-primary/[0.03]': item.status === 'skip',
                                    '': item.status === 'valid'
                                }">
                                    <x-ui.table-td x-text="item.row" align="center" class="px-3 py-1.5 border-r border-border text-muted"></x-ui.table-td>
                                    <x-ui.table-td align="center" class="px-3 py-1.5 border-r border-border">
                                        <x-ui.badge
                                            variant="none"
                                            size="xs"
                                            :pill="false"
                                            uppercase
                                            x-bind:class="{
                                                'bg-success/10 text-success': item.status === 'valid',
                                                'bg-danger/10 text-danger': item.status === 'error',
                                                'bg-primary/10 text-primary': item.status === 'skip'
                                            }"
                                            x-bind:dusk="'validation-status-' + item.row"
                                            x-text="validationStatusLabel(item.status)"
                                        ></x-ui.badge>
                                    </x-ui.table-td>
                                    <template x-for="header in mainHeaders" :key="'val-cell-' + item.row + '-' + header">
                                        <x-ui.table-td class="px-0.5 py-0.5 border-r border-border">
                                            {{-- Hanya error yang dapat diperbaiki Admin. --}}
                                            <template x-if="item.status === 'error'">
                                                 <input
                                                     type="text"
                                                     :value="allRows[item.dataIndex]?.data[header] ?? ''"
                                                     :disabled="item.dataIndex < 0"
                                                     :aria-busy="rowLoadStatus(item.row) === 'loading'"
                                                     :aria-label="'Baris validasi ' + item.row + ', ' + header"
                                                     x-bind:dusk="validationCellHook('validation-input', item.row, header)"
                                                     @input="if (item.dataIndex >= 0) { allRows[item.dataIndex].data[header] = $event.target.value; onCellEdit(item.dataIndex, header) }"
                                                     :class="item.errorSourceHeaders?.includes(header) ? 'border-danger/50 bg-danger/[0.03]' : 'border-transparent'"
                                                     class="w-full px-2 py-1.5 text-xs text-ink bg-transparent border rounded hover:border-border hover:bg-soft/10 focus:border-primary focus:bg-surface focus:outline-none focus:ring-1 focus:ring-primary/30 transition min-w-[200px] disabled:cursor-wait disabled:bg-soft disabled:text-muted"
                                                 >
                                            </template>
                                            {{-- Valid dan terlewat: hanya untuk dibaca. --}}
                                            <template x-if="item.status !== 'error'">
                                                <span x-bind:dusk="validationCellHook('validation-value', item.row, header)"
                                                    :class="item.status === 'skip' ? 'text-primary' : 'text-ink'"
                                                    class="px-2 py-1.5 text-xs block min-w-[200px]"
                                                    x-text="allRows[item.dataIndex]?.data[header] ?? '-'"></span>
                                            </template>
                                        </x-ui.table-td>
                                     </template>
                                     <x-ui.table-td class="px-3 py-1.5">
                                         <span x-show="item.dataIndex < 0 && rowLoadStatus(item.row) === 'loading'" class="mb-1 block text-xs text-muted" role="status">
                                             Memuat data baris untuk diperbaiki...
                                         </span>
                                         <div x-show="item.dataIndex < 0 && rowLoadStatus(item.row) === 'error'" class="mb-1 space-y-1">
                                             <p class="text-xs font-semibold text-danger" x-text="rowLoadError(item.row)"></p>
                                             <button type="button" @click="ensureValidationRow(item)" class="text-xs font-semibold text-primary hover:underline focus:outline-none focus:ring-1 focus:ring-primary">
                                                 Muat ulang data baris
                                             </button>
                                         </div>
                                         <span :class="{
                                            'text-danger font-semibold': item.status === 'error',
                                            'text-primary': item.status === 'skip',
                                            'text-success': item.status === 'valid'
                                        }" x-bind:dusk="'validation-description-' + item.row" class="text-xs font-sans" x-text="validationStatusDescription(item)"></span>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            </template>
                        </x-ui.table-body>
                    </x-ui.table>
                </div>

                {{-- Validation Pagination --}}
                <div class="flex items-center justify-between text-xs font-sans" x-show="valTotalPages > 1">
                    <span class="text-muted">
                        Hal. <span class="font-bold text-ink" x-text="valPage"></span> / <span class="font-bold text-ink" x-text="valTotalPages"></span>
                    </span>
                    <div class="flex items-center gap-1">
                        <x-ui.pagination current="valPage" total="valTotalPages" />
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-4 flex justify-between items-center gap-3">
                    <x-ui.button type="button" variant="danger" @click="resetAll()">
                        Batalkan Semua
                    </x-ui.button>
                    <div class="flex items-center gap-3">
                        <x-ui.button type="button" variant="muted" @click="step = 2">
                            ← Kembali ke Preview
                        </x-ui.button>
                        <x-ui.button type="button" variant="warning" @click="reValidate()" x-show="hasEdits"
                            x-bind:disabled="isValidating" dusk="validation-revalidate">
                            <x-ui.loading x-show="isValidating" size="md" />
                            Validasi Ulang
                        </x-ui.button>
                        <x-ui.button type="button" variant="primary" @click="executeImport()"
                            x-bind:disabled="validRows === 0 || hasEdits || isExecuting"
                            aria-describedby="import-readiness-message" dusk="validation-import">
                            Import Valid (<span x-text="validRows"></span> baris)
                        </x-ui.button>
                    </div>
                </div>
                <p id="import-readiness-message" aria-live="polite" class="text-xs text-muted"
                    x-text="hasEdits ? 'Validasi ulang perubahan sebelum mengimpor.' : (validRows === 0 ? 'Tidak ada baris valid untuk diimpor.' : '')"></p>
            </x-ui.card>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 4: PROSES IMPORT                                             --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 4" class="space-y-6" style="display: none;" x-transition>
            <x-ui.card padding="none" class="p-12 text-center max-w-xl mx-auto space-y-6">
                <div class="flex items-center justify-center h-16 w-16 rounded-full bg-primary/10 text-primary mx-auto animate-pulse">
                    <x-ui.loading size="xl" />
                </div>
                <div class="space-y-2">
                    <h3 class="text-base font-bold text-ink font-sans" x-text="progressText"></h3>
                    <p class="text-xs text-muted font-sans">Proses impor berjalan di latar belakang. Halaman ini akan otomatis lanjut ke hasil ketika selesai.</p>
                </div>
                <div class="space-y-1">
                    <div class="w-full bg-soft rounded-full h-2.5 overflow-hidden border border-border">
                        <div class="bg-primary h-2.5 rounded-full transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                    </div>
                    <span class="text-[10px] font-bold text-muted" x-text="progress + '%'"></span>
                </div>
                <div class="border-t border-border pt-4 flex justify-center">
                    <a href="{{ route('data-pegawai') }}"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted hover:text-ink transition font-sans underline-offset-2 hover:underline">
                        Lanjutkan di Background →
                    </a>
                </div>
                <p class="text-[10px] text-muted/70 font-sans -mt-2">Anda akan mendapat notifikasi sistem saat import selesai.</p>
            </x-ui.card>
        </div>


        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 5: LAPORAN HASIL                                             --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 5" class="space-y-6" style="display: none;" x-transition>
            <x-ui.card padding="lg" class="space-y-6">
                <div class="flex items-center gap-4 border-b border-border pb-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-success/15 text-success text-2xl">✓</div>
                    <div>
                        <h3 class="text-base font-bold text-ink font-sans">Proses Impor Selesai!</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Ringkasan hasil impor tercantum di bawah ini.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                    <div class="rounded-lg bg-success/5 border border-success/15 p-4">
                        <x-ui.badge variant="success" size="md" uppercase>Berhasil</x-ui.badge>
                        <p class="text-2xl font-bold text-success mt-1" x-text="insertedCount"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(Status Aktif di database)</span>
                    </div>
                    <div class="rounded-lg bg-primary/5 border border-primary/15 p-4">
                        <x-ui.badge variant="primary" size="md">Terlewat (sudah ada)</x-ui.badge>
                        <p class="text-2xl font-bold text-primary mt-1" x-text="skipRows"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(NIP terdaftar)</span>
                    </div>
                    <div class="rounded-lg bg-danger/5 border border-danger/15 p-4">
                        <x-ui.badge variant="danger" size="md" uppercase>Gagal</x-ui.badge>
                        <p class="text-2xl font-bold text-danger mt-1" x-text="errorRows"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(Data bermasalah)</span>
                    </div>
                </div>

                <div class="bg-soft/40 border border-border rounded-lg p-4 space-y-3.5 text-xs text-ink font-sans">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <span class="font-bold">Laporan Hasil Import</span>
                            <p class="text-[11px] text-muted mt-0.5">Laporan tersimpan permanen di server: ringkasan hasil beserta baris gagal/dilewati dan alasannya.</p>
                        </div>
                        <a :href="'/pegawai/import/' + batchId + '/laporan'" x-show="batchId"
                            class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 font-bold text-primary transition hover:bg-primary/5 shadow-xs cursor-pointer">
                            📥 Unduh Laporan (.csv)
                        </a>
                    </div>
                    <div class="border-t border-border/80 pt-3 space-y-1 text-muted text-[11px]">
                        <span class="font-bold text-ink uppercase tracking-wider block text-[9px] mb-1">📝 Audit Log</span>
                        {{-- Label operator memakai role efektif agar konsisten dengan otorisasi saat simulasi role aktif. --}}
                        <p>• Operator: <span class="font-semibold text-ink">{{ auth()->user()?->getEffectiveRole() ?? 'admin_kepegawaian' }}</span></p>
                        <p>• Timestamp: <span class="text-ink">{{ date('Y-m-d H:i:s') }} WITA</span></p>
                        <p>• Berkas: <span class="text-ink" x-text="fileName"></span></p>
                    </div>
                </div>

                <div class="border-t border-border pt-4 flex justify-end">
                    <x-ui.button href="{{ route('data-pegawai') }}" variant="primary">
                        Kembali ke Daftar Pegawai
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>

    </div>

</x-layouts.app>
