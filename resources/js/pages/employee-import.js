const registerEmployeeImport = () => {
    window.Alpine.data('employeeImport', () => ({
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

        // Mapping merupakan state batch server: satu sumber untuk preview,
        // validasi, dan eksekusi import.
        simpegTargetFields: [
            { key: 'Nama Pegawai', label: 'Nama & Gelar (Nama Pegawai)' },
            { key: 'Person', label: 'Nama Lengkap Tanpa Gelar (Person)' },
            { key: 'NIP', label: 'NIP' },
            { key: 'Email Pegawai', label: 'Email Pegawai' },
            { key: 'Status Kepegawaian', label: 'Status Kepegawaian (PNS/PPPK/CPNS)' },
            { key: 'Nomor Telepon', label: 'Nomor Telepon / No HP' },
            { key: 'Tanggal Lahir', label: 'Tanggal Lahir' },
            { key: 'Jabatan', label: 'Jabatan Terakhir' },
            { key: 'Golongan', label: 'Golongan Terakhir' },
            { key: 'Kelas Jabatan', label: 'Kelas Jabatan' },
            { key: 'Pangkat', label: 'Pangkat Terakhir' },
            { key: 'Pendidikan Terakhir', label: 'Pendidikan Terakhir' },
            { key: 'Prodi Pendidikan Terakhir', label: 'Prodi Pendidikan Terakhir' },
            { key: 'Pensiun', label: 'Tanggal Pensiun' },
            { key: 'NIK', label: 'NIK (Opsional)' },
            { key: 'No KK', label: 'No KK (Opsional)' },
        ],
        columnMapping: {},
        requiredTargetFields: [],
        serverWarnings: { unmatched_columns: [], missing_required: [] },
        get unmappedHeaders() {
            return this.mainHeaders.filter(header => !this.columnMapping[header] || this.columnMapping[header] === 'tidak_dipakai');
        },
        get unmappedHeadersCount() {
            return this.unmappedHeaders.length;
        },
        get skippedSourceHeaders() {
            return this.unmappedHeaders;
        },
        get unknownSourceHeaders() {
            return this.unmappedHeaders.filter(header =>
                !this.isCanonicalSourceHeader(header) && !this.isKnownIgnoredHeader(header)
            );
        },
        get intentionallySkippedSourceHeaders() {
            return this.unmappedHeaders.filter(header => this.isCanonicalSourceHeader(header));
        },
        get knownIgnoredSourceHeaders() {
            return this.unmappedHeaders.filter(header => this.isKnownIgnoredHeader(header));
        },
        get mappedColumnCount() {
            return this.mainHeaders.length - this.unmappedHeadersCount;
        },
        get hasDuplicateMapping() {
            return this.duplicateMappedFields.length > 0;
        },
        get duplicateMappedFields() {
            const counts = {};

            Object.values(this.columnMapping).forEach(target => {
                if (target && target !== 'tidak_dipakai') {
                    counts[target] = (counts[target] || 0) + 1;
                }
            });

            return Object.keys(counts).filter(target => counts[target] > 1);
        },
        get missingRequiredTargets() {
            const selectedTargets = Object.values(this.columnMapping).filter(target => target && target !== 'tidak_dipakai');
            return this.requiredTargetFields.filter(target => !selectedTargets.includes(target));
        },
        get canProceedToValidation() {
            return !this.hasDuplicateMapping && this.missingRequiredTargets.length === 0;
        },
        mappingTargetLabel(target) {
            if (!target || target === 'tidak_dipakai') return 'Tidak dipakai';

            return this.simpegTargetFields.find(field => field.key === target)?.label || target;
        },
        mappingControlId(header, index) {
            const slug = String(header).trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'kolom';
            return `mapping-${index}-${slug}`;
        },
        normalizeSourceHeader(header) {
            // Samakan dengan ImportColumnMapping::normalize(): hanya normalisasi
            // spasi dan huruf besar-kecil. Tanda baca tetap bermakna agar
            // klasifikasi UI sesuai dengan mapping yang diproses server.
            return String(header).trim().replace(/\s+/g, ' ').toLowerCase();
        },
        isCanonicalSourceHeader(header) {
            const normalizedHeader = this.normalizeSourceHeader(header);

            return this.simpegTargetFields.some(field => this.normalizeSourceHeader(field.key) === normalizedHeader);
        },
        isKnownIgnoredHeader(header) {
            return ['no', 'person formula', 'role'].includes(this.normalizeSourceHeader(header));
        },
        isLockedIgnoredHeader(header) {
            return ['no', 'role'].includes(this.normalizeSourceHeader(header));
        },
        sourceHeadersForErrors(errorTargets) {
            // Key error backend mengikuti label atribut validasi, sedangkan mapping
            // memakai header kanonis. Person perlu dinormalisasi agar error tetap
            // menunjuk dan menyorot kolom sumber yang dipetakan secara manual.
            const errorTargetAliases = {
                'Nama Lengkap (Person)': 'Person',
            };
            const targets = new Set(errorTargets.map(target => errorTargetAliases[target] ?? target));
            const sources = Object.entries(this.columnMapping)
                .filter(([, target]) => targets.has(target))
                .map(([source]) => source);

            return sources.length > 0 ? sources : errorTargets;
        },
        onMappingChange() {
            this.apiError = '';
        },
        async persistMapping() {
            if (!this.batchId) return;

            const res = await fetch('/api/pegawai/import/' + this.batchId + '/mapping', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: JSON.stringify({ mapping: this.columnMapping }),
            });

            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'Gagal menyimpan pemetaan kolom.'));
            }

            this.columnMapping = data.mapping || this.columnMapping;
            this.serverWarnings = data.warnings || this.serverWarnings;
        },
        getEditedRows() {
            return this.allRows.filter((rowObj, index) => this.editedRowIndices.has(index));
        },

        // Pagination preview
        previewPage: 1,
        previewPerPage: 10,
        previewRowCount: 0,
        get previewTotalPages() { return Math.max(1, Math.ceil(this.previewRowCount / this.previewPerPage)); },
        get paginatedRows() {
            const start = (this.previewPage - 1) * this.previewPerPage;
            return this.allRows.slice(start, Math.min(start + this.previewPerPage, this.previewRowCount));
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
        validationRowLoads: {},
        get filteredValidations() {
            if (this.valFilter === 'all') return this.validations;
            return this.validations.filter(v => v.status === this.valFilter);
        },
        get valTotalPages() { return Math.max(1, Math.ceil(this.filteredValidations.length / this.valPerPage)); },
        get paginatedValidations() {
            const start = (this.valPage - 1) * this.valPerPage;
            return this.filteredValidations.slice(start, start + this.valPerPage);
        },
        validationStatusLabel(status) {
            return {
                valid: 'Siap diimpor',
                skip: 'Sudah ada — akan dilewati',
                error: 'Error',
            }[status] || status;
        },
        validationFilterLabel(filter) {
            return {
                all: 'Semua',
                valid: 'Valid (siap impor)',
                skip: 'Terlewat (sudah ada)',
                error: 'Error (bermasalah)',
            }[filter] || filter;
        },
        validationStatusDescription(item) {
            if (item.status === 'skip') {
                return 'NIP sudah terdaftar di database. Baris ini tidak akan diimpor.';
            }

            return item.error || 'Siap impor';
        },
        validationCellHook(prefix, row, header) {
            const normalizedHeader = String(header)
                .trim()
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '') || 'kolom';

            return `${prefix}-${row}-${normalizedHeader}`;
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

        rowLoadStatus(row) {
            return this.validationRowLoads[String(row)]?.status || 'idle';
        },

        rowLoadError(row) {
            return this.validationRowLoads[String(row)]?.error || '';
        },

        // Baris di luar preview awal dimuat satu per satu hanya saat perlu diperbaiki.
        async ensureValidationRow(item) {
            if (!this.batchId || item.dataIndex >= 0 || !['error', 'skip'].includes(item.status)) return;

            const key = String(item.row);
            const currentStatus = this.rowLoadStatus(item.row);
            if (currentStatus === 'loading' || currentStatus === 'loaded') return;

            this.validationRowLoads = {
                ...this.validationRowLoads,
                [key]: { status: 'loading', error: '' },
            };

            try {
                const res = await fetch('/api/pegawai/import/' + this.batchId + '/preview?row=' + encodeURIComponent(item.row), {
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });
                const data = await res.json();

                if (!res.ok) {
                    throw new Error(data.message || 'Gagal memuat data baris import.');
                }

                const loadedRow = data.rows?.[0];
                if (!loadedRow || Number(loadedRow.row) !== Number(item.row)) {
                    throw new Error('Data baris yang diterima tidak sesuai dengan hasil validasi.');
                }

                let dataIndex = this.allRows.findIndex(row => Number(row.row) === Number(item.row));
                if (dataIndex < 0) {
                    this.allRows.push(loadedRow);
                    dataIndex = this.allRows.length - 1;
                }

                item.dataIndex = dataIndex;
                this.validationRowLoads = {
                    ...this.validationRowLoads,
                    [key]: { status: 'loaded', error: '' },
                };
            } catch (e) {
                this.validationRowLoads = {
                    ...this.validationRowLoads,
                    [key]: { status: 'error', error: e.message },
                };
            }
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
                this.allRows = previewData.rows; // [{row: N, data: {...}}, ...] — maksimal 10 baris dari server
                this.previewRowCount = previewData.rows.length;
                this.validationRowLoads = {};
                this.totalRows = previewData.total_rows;
                this.previewPage = 1;
                this.hasEdits = false;
                this.editedRowIndices = new Set();
                // Pemetaan awal dan peringatan kolom berasal dari state batch server,
                // bukan tebakan ulang di browser.
                this.columnMapping = previewData.mapping || {};
                this.requiredTargetFields = previewData.required_targets || [];
                this.serverWarnings = previewData.warnings || { unmatched_columns: [], missing_required: [] };

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

            if (!this.canProceedToValidation) {
                this.apiError = 'Selesaikan konflik pemetaan dan petakan seluruh field wajib sebelum melanjutkan.';
                return;
            }

            this.isValidating = true;
            this.apiError = '';

            try {
                // Simpan mapping batch sebelum validasi. Baris yang tidak diedit
                // tetap dibaca server dari batch dengan mapping yang sama.
                await this.persistMapping();
                const editedRows = this.getEditedRows();
                const body = editedRows.length > 0 ? { rows: editedRows } : {};

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
                    // Respons skip membawa alasan dari server, tetapi bukan error
                    // yang perlu disorot atau diperbaiki Admin.
                    const errorSourceHeaders = r.status === 'error'
                        ? this.sourceHeadersForErrors(errorCols)
                        : [];

                    return {
                        row: r.row,
                        name: r.nama,
                        status: r.status,
                        // Simpan nama sumber sebagai array untuk sorotan input. String `col`
                        // hanya dipakai sebagai keterangan; pencarian substring dapat membuat
                        // header seperti "Email" ikut tersorot saat hanya "Email Address" error.
                        errorSourceHeaders,
                        col: errorSourceHeaders.join(', ') || '-',
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
            if (!this.batchId || this.validRows === 0 || this.hasEdits || this.isExecuting) return;

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
            this.columnMapping = {};
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
            this.previewRowCount = 0;
            this.valPage = 1;
            this.valFilter = 'all';
            this.validationRowLoads = {};
            this.columnMapping = {};
            this.requiredTargetFields = [];
            this.serverWarnings = { unmatched_columns: [], missing_required: [] };
        }
    }));
};

if (window.Alpine) {
    registerEmployeeImport();
} else {
    document.addEventListener('alpine:init', registerEmployeeImport, { once: true });
}
