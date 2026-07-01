<x-layouts.app title="Import Data Pegawai">
    <div class="mx-auto max-w-5xl space-y-6" x-data="{
        step: 1,
        fileName: '',
        fileSize: '',
        fileError: '',
        fileValid: false,
        dragover: false,
        activeTemplate: 'utama',
        templateFormat: 'xlsx',
        downloadingType: '',
        
        // Batch state dari server
        batchId: null,
        
        // Loading states
        isUploading: false,
        isValidating: false,
        isExecuting: false,
        apiError: '',
        
        // File reference
        selectedFile: null,
        
        // ALL rows data (editable) — [{row: N, data: {field: value, ...}}, ...]
        mainHeaders: [],
        allRows: [],
        
        // Pagination preview
        previewPage: 1,
        previewPerPage: 10,
        get previewTotalPages() { return Math.max(1, Math.ceil(this.allRows.length / this.previewPerPage)); },
        get paginatedRows() {
            const start = (this.previewPage - 1) * this.previewPerPage;
            return this.allRows.slice(start, start + this.previewPerPage);
        },
        
        // Validation results (dari server)
        validations: [],
        totalRows: 0,
        validRows: 0,
        skipRows: 0,
        errorRows: 0,
        
        // Validation pagination
        valPage: 1,
        valPerPage: 15,
        valFilter: 'all',
        get filteredValidations() {
            if (this.valFilter === 'all') return this.validations;
            return this.validations.filter(v => v.status === this.valFilter);
        },
        get valTotalPages() { return Math.max(1, Math.ceil(this.filteredValidations.length / this.valPerPage)); },
        get paginatedValidations() {
            const start = (this.valPage - 1) * this.valPerPage;
            return this.filteredValidations.slice(start, start + this.valPerPage);
        },
        
        // Track edits
        hasEdits: false,
        editedRowIndices: new Set(),
        
        // Execute results
        insertedCount: 0,
        
        // Progress
        progress: 0,
        progressText: 'Memulai proses impor...',
        
        // Unduh template via fetch+blob agar bisa menampilkan loader di kartu yang diklik.
        // window.location.href dihindari karena navigasi browser tidak memberi hook async
        // sehingga user tidak tahu apakah klik-nya sedang diproses.
        async downloadTemplate(type) {
            if (this.downloadingType) return;

            this.downloadingType = type;
            this.apiError = '';

            try {
                const res = await fetch('/pegawai/import/template/' + type + '?format=' + this.templateFormat, {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });

                if (!res.ok) {
                    throw new Error('Gagal mengunduh template. Silakan coba lagi.');
                }

                const blob = await res.blob();
                const filename = 'template_' + type + '.' + this.templateFormat;

                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            } catch (e) {
                this.apiError = e.message;
            } finally {
                this.downloadingType = '';
            }
        },
        
        // Download Laporan Kesalahan
        downloadErrorReport() {
            let headers = ['Baris', 'Nama Pegawai', 'Kolom Bermasalah', 'Jenis Kesalahan'];
            let rows = this.validations
                .filter(v => v.status === 'error')
                .map(v => [v.row, v.name, v.col || '-', v.error || '']);
            
            let csvContent = '\uFEFF' + [headers.join(','), ...rows.map(e => e.map(val => String.fromCharCode(34) + val + String.fromCharCode(34)).join(','))].join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', `laporan_kegagalan_import.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },
        
        // Handle File Upload Select
        handleFileSelect(e) {
            const file = e.target.files ? e.target.files[0] : (e.dataTransfer ? e.dataTransfer.files[0] : null);
            if (file) {
                this.selectedFile = file;
                this.fileName = file.name;
                const sizeInMb = (file.size / (1024 * 1024)).toFixed(2);
                this.fileSize = sizeInMb + ' MB';
                
                if (file.size > 10 * 1024 * 1024) {
                    this.fileError = 'Ukuran berkas melebihi batas 10MB! (Terdeteksi: ' + sizeInMb + 'MB)';
                    this.fileValid = false;
                    this.selectedFile = null;
                    if (e.target && e.target.value) e.target.value = '';
                } else {
                    this.fileError = '';
                    this.fileValid = true;
                }
            }
        },
        
        // Mark cell as edited
        onCellEdit(rowIndex, header) {
            this.hasEdits = true;
            this.editedRowIndices.add(rowIndex);
        },
        
        // Step 1 → 2: Upload file ke server, lalu load preview
        async uploadAndPreview() {
            if (!this.selectedFile) return;
            
            this.isUploading = true;
            this.apiError = '';
            
            try {
                // Upload
                const formData = new FormData();
                formData.append('file', this.selectedFile);
                formData.append('type', this.activeTemplate);
                
                const uploadRes = await fetch('/api/pegawai/import/upload', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        'Accept': 'application/json',
                    },
                    body: formData,
                });
                
                if (!uploadRes.ok) {
                    const err = await uploadRes.json();
                    throw new Error(err.errors?.file?.[0] || err.message || 'Upload gagal.');
                }
                
                const uploadData = await uploadRes.json();
                this.batchId = uploadData.batch_id;
                this.totalRows = uploadData.total_rows;
                this.activeTemplate = uploadData.type || this.activeTemplate;
                
                // Load Preview (all rows)
                const previewRes = await fetch('/api/pegawai/import/' + this.batchId + '/preview', {
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });
                
                if (!previewRes.ok) {
                    const err = await previewRes.json();
                    throw new Error(err.message || 'Gagal memuat preview.');
                }
                
                const previewData = await previewRes.json();
                this.mainHeaders = previewData.headers;
                this.allRows = previewData.rows; // [{row: N, data: {...}}, ...]
                this.totalRows = previewData.total_rows;
                this.previewPage = 1;
                this.hasEdits = false;
                this.editedRowIndices = new Set();
                
                this.step = 2;
                
            } catch (e) {
                this.apiError = e.message;
            } finally {
                this.isUploading = false;
            }
        },
        
        // Step 2 → 3: Jalankan validasi (kirim rows yang diedit)
        async runValidation() {
            if (!this.batchId) return;
            
            this.isValidating = true;
            this.apiError = '';
            
            try {
                // Kirim rows jika ada edit, atau tanpa body jika tidak ada perubahan
                const body = this.hasEdits ? { rows: this.allRows } : {};
                
                const res = await fetch('/api/pegawai/import/' + this.batchId + '/validate', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify(body),
                });
                
                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.message || 'Validasi gagal.');
                }
                
                const data = await res.json();
                this.totalRows = data.total_rows;
                this.validRows = data.valid_count;
                this.errorRows = data.error_count;
                this.skipRows = data.skip_count;
                
                // Transform results untuk tabel validasi
                this.validations = data.results.map((r) => {
                    let errorMessages = [];
                    let errorCols = [];
                    if (r.errors && typeof r.errors === 'object') {
                        for (const [col, msgs] of Object.entries(r.errors)) {
                            errorCols.push(col);
                            if (Array.isArray(msgs)) {
                                errorMessages.push(...msgs);
                            } else {
                                errorMessages.push(String(msgs));
                            }
                        }
                    }
                    return {
                        row: r.row,
                        name: r.nama,
                        status: r.status,
                        col: errorCols.join(', ') || '-',
                        error: errorMessages.join('; ') || '',
                        // Simpan index ke allRows untuk inline edit di step 3
                        dataIndex: this.allRows.findIndex(row => Number(row.row) === Number(r.row)),
                    };
                });
                
                this.hasEdits = false;
                this.editedRowIndices = new Set();
                this.valPage = 1;
                this.valFilter = 'all';
                this.step = 3;
                
            } catch (e) {
                this.apiError = e.message;
            } finally {
                this.isValidating = false;
            }
        },
        
        // Re-validate (dari step 3, setelah edit error rows)
        async reValidate() {
            // Karena allRows sudah diubah, kirim ulang
            this.hasEdits = true;
            await this.runValidation();
        },
        
        // Step 3 → 4 → 5: Execute import
        async executeImport() {
            if (!this.batchId) return;
            
            this.isExecuting = true;
            this.apiError = '';
            this.step = 4;
            this.progress = 0;
            this.progressText = 'Menyiapkan impor...';
            
            try {
                const res = await fetch('/api/pegawai/import/' + this.batchId + '/execute', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });
                
                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.message || 'Gagal memulai impor.');
                }
                
                this.progressText = 'Impor dimasukkan ke antrean...';
                
                const checkStatus = async () => {
                    try {
                        const statusRes = await fetch('/api/pegawai/import/' + this.batchId + '/status', {
                            headers: {
                                'Accept': 'application/json',
                            },
                        });
                        
                        if (!statusRes.ok) {
                            throw new Error('Gagal memeriksa status impor.');
                        }
                        
                        const statusData = await statusRes.json();
                        const status = statusData.status;
                        
                        if (status === 'queued') {
                            this.progress = 5;
                            this.progressText = 'Menunggu antrean diproses...';
                            setTimeout(checkStatus, 1000);
                        } else if (status === 'processing') {
                            this.progress = Math.max(10, statusData.progress);
                            this.progressText = `Memproses impor: ${statusData.processed_count} dari ${statusData.total_rows} pegawai...`;
                            setTimeout(checkStatus, 1000);
                        } else if (status === 'completed') {
                            const result = statusData.result || {};
                            this.insertedCount = result.inserted || 0;
                            this.skipRows = result.skipped || 0;
                            this.errorRows = result.failed || 0;
                            this.validRows = result.inserted || 0;
                            
                            this.progress = 100;
                            this.progressText = 'Impor selesai!';
                            
                            setTimeout(() => {
                                this.step = 5;
                                this.isExecuting = false;
                            }, 500);
                        } else if (status === 'failed') {
                            throw new Error(statusData.error_message || 'Impor gagal di latar belakang.');
                        } else {
                            setTimeout(checkStatus, 1000);
                        }
                    } catch (err) {
                        this.apiError = err.message;
                        this.step = 3;
                        this.isExecuting = false;
                    }
                };
                
                setTimeout(checkStatus, 1000);
                
            } catch (e) {
                this.apiError = e.message;
                this.step = 3;
                this.isExecuting = false;
            }
        },
        
        // Reset semua state
        resetAll() {
            this.step = 1;
            this.fileName = '';
            this.fileSize = '';
            this.fileError = '';
            this.fileValid = false;
            this.selectedFile = null;
            this.batchId = null;
            this.mainHeaders = [];
            this.allRows = [];
            this.validations = [];
            this.totalRows = 0;
            this.validRows = 0;
            this.skipRows = 0;
            this.errorRows = 0;
            this.insertedCount = 0;
            this.apiError = '';
            this.progress = 0;
            this.hasEdits = false;
            this.editedRowIndices = new Set();
            this.previewPage = 1;
            this.valPage = 1;
            this.valFilter = 'all';
        }
    }">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Import Data Pegawai</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Import Excel/CSV</span>
            </nav>
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
        <div class="rounded-lg border border-border bg-surface p-4 shadow-sm select-none">
            <div class="flex items-center justify-between max-w-4xl mx-auto text-xs font-semibold overflow-x-auto pb-1">
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 1 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">1</span>
                    <span :class="step >= 1 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Upload</span>
                </div>
                <div :class="step > 1 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 2 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">2</span>
                    <span :class="step >= 2 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Preview & Edit</span>
                </div>
                <div :class="step > 2 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 3 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">3</span>
                    <span :class="step >= 3 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Validasi</span>
                </div>
                <div :class="step > 3 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 4 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">4</span>
                    <span :class="step >= 4 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Proses</span>
                </div>
                <div :class="step > 4 ? 'bg-primary' : 'bg-border'" class="h-0.5 flex-1 mx-3 min-w-8 max-w-[72px]"></div>
                <div class="flex items-center gap-2 shrink-0">
                    <span :class="step >= 5 ? 'bg-primary text-white' : 'bg-soft text-muted border border-border'" class="h-6 w-6 rounded-full flex items-center justify-center font-mono">5</span>
                    <span :class="step >= 5 ? 'text-primary font-bold' : 'text-muted'" class="font-sans">Hasil</span>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 1: DOWNLOAD TEMPLATE & UPLOAD                                --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 1" class="space-y-6" x-transition>
            
            {{-- Download Template Card --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-ink uppercase tracking-wider font-sans">1. Download Template Import Pegawai</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">Gunakan template agar header kolom sesuai dan data dapat terbaca dengan tepat oleh sistem.</p>
                </div>
                <div class="inline-flex rounded-lg border border-border bg-soft p-1 text-xs font-semibold text-muted">
                    <button type="button" @click="templateFormat = 'xlsx'"
                        :class="templateFormat === 'xlsx' ? 'bg-surface text-primary shadow-sm' : 'hover:text-ink'"
                        class="rounded-md px-3 py-1.5 transition">XLSX</button>
                    <button type="button" @click="templateFormat = 'csv'"
                        :class="templateFormat === 'csv' ? 'bg-surface text-primary shadow-sm' : 'hover:text-ink'"
                        class="rounded-md px-3 py-1.5 transition">CSV UTF-8</button>
                </div>
                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="button" @click="activeTemplate = 'utama'; downloadTemplate('utama')" :disabled="downloadingType !== ''"
                        :class="downloadingType ? 'opacity-50 cursor-not-allowed' : 'hover:bg-soft cursor-pointer'"
                        class="flex items-center gap-3 border border-border bg-surface text-muted p-4 rounded-lg transition shadow-sm w-full">
                        <svg x-show="downloadingType === 'utama'" class="w-8 h-8 text-muted shrink-0 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        <svg x-show="downloadingType !== 'utama'" class="w-8 h-8 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <div class="text-left">
                            <span class="text-sm font-medium block font-sans text-muted">
                                <span x-show="downloadingType === 'utama'">Menyiapkan Template...</span>
                                <span x-show="downloadingType !== 'utama'">Unduh Template Utama Pegawai</span>
                            </span>
                            <span class="text-xs text-muted block mt-0.5 font-sans">Berisi kolom NIP, NIK, No KK, Golongan, Jabatan, Role, dll.</span>
                        </div>
                    </button>
                </div>
            </div>

            {{-- Upload File Area Card --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
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
                        <div class="mt-4 inline-flex items-center gap-2 rounded bg-surface border border-border px-3 py-1.5 text-xs text-ink font-mono shadow-sm">
                            <svg class="w-4 h-4 text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <span x-text="fileName"></span>
                            <span class="text-muted" x-text="'(' + fileSize + ')'"></span>
                        </div>
                    </template>
                    <p x-show="fileError" class="text-xs text-danger font-semibold mt-3 font-sans" x-text="fileError"></p>
                </div>

                <div class="border-t border-border pt-4 flex justify-end">
                    <button type="button" @click="uploadAndPreview()" :disabled="!fileValid || isUploading"
                        :class="(!fileValid || isUploading) ? 'opacity-50 cursor-not-allowed bg-muted' : 'bg-primary hover:opacity-90 cursor-pointer'"
                        class="inline-flex items-center justify-center rounded-lg px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition font-sans gap-2">
                        <svg x-show="isUploading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        <svg x-show="!isUploading" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                        <span x-text="isUploading ? 'Mengupload & Memproses...' : 'Upload & Lanjutkan ke Preview'"></span>
                    </button>
                </div>
            </div>
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

            {{-- Editable Preview Table --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
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
                    <table class="w-full text-xs">
                        <thead class="bg-soft sticky top-0 z-10">
                            <tr>
                                <th class="px-3 py-2 text-left font-bold text-muted border-r border-border w-12">No</th>
                                <template x-for="header in mainHeaders" :key="header">
                                    <th class="px-3 py-2 text-left font-bold text-muted border-r border-border min-w-[200px]" x-text="header"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template x-for="(rowObj, rIndex) in paginatedRows" :key="rowObj.row">
                                <tr class="group hover:bg-primary/[0.02]" :class="editedRowIndices.has((previewPage - 1) * previewPerPage + rIndex) ? 'bg-warning/[0.04]' : ''">
                                    <td class="px-3 py-1.5 border-r border-border font-mono text-muted text-center" x-text="(previewPage - 1) * previewPerPage + rIndex + 1"></td>
                                    <template x-for="header in mainHeaders" :key="header">
                                        <td class="px-0.5 py-0.5 border-r border-border">
                                            <input 
                                                type="text"
                                                :value="rowObj.data[header] ?? ''"
                                                @input="rowObj.data[header] = $event.target.value; onCellEdit((previewPage - 1) * previewPerPage + rIndex, header)"
                                                class="w-full px-2 py-1.5 text-xs font-mono text-ink bg-transparent border border-transparent rounded hover:border-border hover:bg-soft/10 focus:border-primary focus:bg-surface focus:outline-none focus:ring-1 focus:ring-primary/30 transition min-w-[200px]"
                                                :placeholder="header"
                                            >
                                        </td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
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
                <div class="border-t border-border pt-4 flex justify-between items-center gap-3">
                    <button type="button" @click="resetAll()" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans cursor-pointer">
                        Batal & Upload Ulang
                    </button>
                    <button type="button" @click="runValidation()" :disabled="isValidating"
                        :class="isValidating ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:opacity-90'"
                        class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition font-sans gap-2">
                        <svg x-show="isValidating" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        <span x-text="isValidating ? 'Memvalidasi...' : 'Lanjutkan ke Validasi'"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 3: VALIDASI DATA (EDITABLE ERROR ROWS)                       --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 3" class="space-y-6" style="display: none;" x-transition>
            
            {{-- Ringkasan Validasi Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="rounded-lg border border-border bg-surface p-4 shadow-sm text-center">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Total Baris</span>
                    <p class="text-2xl font-bold text-ink font-sans mt-1" x-text="totalRows"></p>
                </div>
                <div class="rounded-lg border border-success/20 bg-success/5 p-4 shadow-sm text-center cursor-pointer" @click="valFilter = valFilter === 'valid' ? 'all' : 'valid'; valPage = 1">
                    <span class="text-[10px] font-bold text-success uppercase tracking-wider font-sans">Valid (Siap Impor)</span>
                    <p class="text-2xl font-bold text-success font-sans mt-1" x-text="validRows"></p>
                </div>
                <div class="rounded-lg border border-primary/20 bg-primary/5 p-4 shadow-sm text-center cursor-pointer" @click="valFilter = valFilter === 'skip' ? 'all' : 'skip'; valPage = 1">
                    <span class="text-[10px] font-bold text-primary uppercase tracking-wider font-sans">Di-skip (Duplikat)</span>
                    <p class="text-2xl font-bold text-primary font-sans mt-1" x-text="skipRows"></p>
                </div>
                <div class="rounded-lg border border-danger/20 bg-danger/5 p-4 shadow-sm text-center cursor-pointer" @click="valFilter = valFilter === 'error' ? 'all' : 'error'; valPage = 1">
                    <span class="text-[10px] font-bold text-danger uppercase tracking-wider font-sans">Error (Bermasalah)</span>
                    <p class="text-2xl font-bold text-danger font-sans mt-1" x-text="errorRows"></p>
                </div>
            </div>

            {{-- Filter indicator --}}
            <div x-show="valFilter !== 'all'" class="rounded-lg bg-soft border border-border p-3 flex items-center justify-between text-xs font-sans">
                <span class="text-muted">Filter aktif: <span class="font-bold text-ink uppercase" x-text="valFilter"></span> (<span x-text="filteredValidations.length"></span> baris)</span>
                <button type="button" @click="valFilter = 'all'; valPage = 1" class="text-primary font-semibold hover:underline cursor-pointer">Tampilkan Semua</button>
            </div>

            {{-- Validation Results Table with Inline Edit --}}
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-4">
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
                    <table class="w-full text-xs">
                        <thead class="bg-soft sticky top-0 z-10">
                            <tr>
                                <th class="px-3 py-2 text-left font-bold text-muted border-r border-border w-16">Baris</th>
                                <th class="px-3 py-2 text-left font-bold text-muted border-r border-border w-16">Status</th>
                                <template x-for="header in mainHeaders" :key="'val-' + header">
                                    <th class="px-3 py-2 text-left font-bold text-muted border-r border-border min-w-[200px]" x-text="header"></th>
                                </template>
                                <th class="px-3 py-2 text-left font-bold text-muted min-w-[200px]">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template x-for="item in paginatedValidations" :key="item.row">
                                <tr :class="{
                                    'bg-danger/[0.03]': item.status === 'error',
                                    'bg-primary/[0.03]': item.status === 'skip',
                                    '': item.status === 'valid'
                                }">
                                    <td class="px-3 py-1.5 border-r border-border font-mono text-muted text-center" x-text="item.row"></td>
                                    <td class="px-3 py-1.5 border-r border-border text-center">
                                        <span :class="{
                                            'text-success bg-success/10': item.status === 'valid',
                                            'text-danger bg-danger/10': item.status === 'error',
                                            'text-primary bg-primary/10': item.status === 'skip'
                                        }" class="inline-block px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider" x-text="item.status"></span>
                                    </td>
                                    <template x-for="header in mainHeaders" :key="'val-cell-' + item.row + '-' + header">
                                        <td class="px-0.5 py-0.5 border-r border-border">
                                            {{-- Error/skip rows: editable inputs --}}
                                            <template x-if="item.status === 'error' || item.status === 'skip'">
                                                <input 
                                                    type="text"
                                                    :value="allRows[item.dataIndex]?.data[header] ?? ''"
                                                @input="if (item.dataIndex >= 0) { allRows[item.dataIndex].data[header] = $event.target.value; onCellEdit(item.dataIndex, header) }"
                                                    :class="item.col && item.col.includes(header) ? 'border-danger/50 bg-danger/[0.03]' : 'border-transparent'"
                                                    class="w-full px-2 py-1.5 text-xs font-mono text-ink bg-transparent border rounded hover:border-border hover:bg-soft/10 focus:border-primary focus:bg-surface focus:outline-none focus:ring-1 focus:ring-primary/30 transition min-w-[200px]"
                                                >
                                            </template>
                                            {{-- Valid rows: read-only --}}
                                            <template x-if="item.status === 'valid'">
                                                <span class="px-2 py-1.5 text-xs font-mono text-ink block min-w-[200px]" x-text="allRows[item.dataIndex]?.data[header] ?? '-'"></span>
                                            </template>
                                        </td>
                                    </template>
                                    <td class="px-3 py-1.5">
                                        <span :class="{
                                            'text-danger font-semibold': item.status === 'error',
                                            'text-primary': item.status === 'skip',
                                            'text-success': item.status === 'valid'
                                        }" class="text-xs font-sans" x-text="item.error || 'Siap impor'"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
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
                    <button type="button" @click="resetAll()" class="inline-flex items-center justify-center rounded-lg border border-danger/15 bg-surface px-5 py-2.5 text-sm font-semibold text-danger transition hover:bg-danger/5 shadow-sm font-sans cursor-pointer">
                        Batalkan Semua
                    </button>
                    <div class="flex items-center gap-3">
                        <button type="button" @click="step = 2; hasEdits = false;" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-soft shadow-sm font-sans cursor-pointer">
                            ← Kembali ke Preview
                        </button>
                        <button type="button" @click="reValidate()" x-show="hasEdits" :disabled="isValidating"
                            :class="isValidating ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:opacity-90'"
                            class="inline-flex items-center justify-center rounded-lg bg-warning px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition font-sans gap-2">
                            <svg x-show="isValidating" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            🔄 Validasi Ulang
                        </button>
                        <button type="button" @click="executeImport()" :disabled="validRows === 0 || isExecuting"
                            :class="(validRows === 0 || isExecuting) ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:opacity-90'"
                            class="inline-flex items-center justify-center rounded-lg bg-success px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition font-sans">
                            Import Valid (<span x-text="validRows"></span> baris)
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 4: PROSES IMPORT                                             --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 4" class="space-y-6" style="display: none;" x-transition>
            <div class="rounded-lg border border-border bg-surface p-12 shadow-sm text-center max-w-xl mx-auto space-y-6">
                <div class="flex items-center justify-center h-16 w-16 rounded-full bg-primary/10 text-primary mx-auto animate-pulse">
                    <svg class="w-8 h-8 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                </div>
                <div class="space-y-2">
                    <h3 class="text-base font-bold text-ink font-sans" x-text="progressText"></h3>
                    <p class="text-xs text-muted font-sans">Proses impor berjalan di latar belakang (antrean). Anda dapat keluar atau menutup halaman ini dengan aman, proses impor akan tetap dilanjutkan oleh server.</p>
                </div>
                <div class="space-y-1">
                    <div class="w-full bg-soft rounded-full h-2.5 overflow-hidden border border-border">
                        <div class="bg-primary h-2.5 rounded-full transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                    </div>
                    <span class="text-[10px] font-bold text-muted font-mono" x-text="progress + '%'"></span>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- STEP 5: LAPORAN HASIL                                             --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        <div x-show="step === 5" class="space-y-6" style="display: none;" x-transition>
            <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
                <div class="flex items-center gap-4 border-b border-border pb-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-success/15 text-success text-2xl">✓</div>
                    <div>
                        <h3 class="text-base font-bold text-ink font-sans">Proses Impor Selesai!</h3>
                        <p class="text-xs text-muted font-sans mt-0.5">Ringkasan hasil impor tercantum di bawah ini.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                    <div class="rounded-lg bg-success/5 border border-success/15 p-4">
                        <span class="text-xs font-bold text-success uppercase tracking-wider font-sans">Berhasil</span>
                        <p class="text-2xl font-bold text-success mt-1" x-text="insertedCount"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(Status Aktif di database)</span>
                    </div>
                    <div class="rounded-lg bg-primary/5 border border-primary/15 p-4">
                        <span class="text-xs font-bold text-primary uppercase tracking-wider font-sans">Di-skip</span>
                        <p class="text-2xl font-bold text-primary mt-1" x-text="skipRows"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(NIP terdaftar)</span>
                    </div>
                    <div class="rounded-lg bg-danger/5 border border-danger/15 p-4">
                        <span class="text-xs font-bold text-danger uppercase tracking-wider font-sans">Gagal</span>
                        <p class="text-2xl font-bold text-danger mt-1" x-text="errorRows"></p>
                        <span class="text-[9px] text-muted font-sans mt-0.5 block">(Data bermasalah)</span>
                    </div>
                </div>

                <div class="bg-soft/40 border border-border rounded-lg p-4 space-y-3.5 text-xs text-ink font-sans">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <span class="font-bold">Laporan Kegagalan</span>
                            <p class="text-[11px] text-muted mt-0.5">Unduh daftar baris bermasalah untuk direvisi.</p>
                        </div>
                        <button @click="downloadErrorReport()" x-show="errorRows > 0"
                            class="inline-flex items-center justify-center rounded-lg border border-danger/15 bg-surface px-4 py-2 font-bold text-danger transition hover:bg-danger/5 shadow-xs cursor-pointer">
                            📥 Unduh Laporan (.csv)
                        </button>
                    </div>
                    <div class="border-t border-border/80 pt-3 space-y-1 text-muted text-[11px]">
                        <span class="font-bold text-ink uppercase tracking-wider block text-[9px] mb-1">📝 Audit Log</span>
                        <p>• Operator: <span class="font-semibold text-ink">{{ session('active_role') ?? 'admin_kepegawaian' }}</span></p>
                        <p>• Timestamp: <span class="font-mono text-ink">{{ date('Y-m-d H:i:s') }} WITA</span></p>
                        <p>• Berkas: <span class="font-mono text-ink" x-text="fileName"></span></p>
                    </div>
                </div>

                <div class="border-t border-border pt-4 flex justify-end">
                    <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans">
                        Kembali ke Daftar Pegawai
                    </a>
                </div>
            </div>
        </div>

    </div>

</x-layouts.app>
