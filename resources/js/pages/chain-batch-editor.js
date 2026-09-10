/** State halaman dibatasi pada pilihan eksplisit; filter pencarian tidak pernah menjadi target mutasi. */
export const createChainBatchEditor = (config = {}) => {
    let nextKey = 0;
    const candidate = () => ({
        key: `batch-approver-${++nextKey}`, role_label: 'Verifikator', employeeId: '', query: '',
        results: [], loading: false, state: 'idle', error: '', open: false, activeIndex: -1,
        timer: null, controller: null, sequence: 0,
    });
    const request = (...args) => (config.fetch ?? globalThis.fetch)(...args);
    const scheduleFrame = (callback) => (config.requestAnimationFrame ?? globalThis.requestAnimationFrame)(callback);

    return {
        draft: { mode: 'missing_only', reason: '', pybmc_mode: config.globalPybmc ? 'global' : 'custom', verifiers: [] },
        globalPybmc: config.globalPybmc ?? null,
        pybmc: candidate(), selected: new Map(), pickerRows: [], pickerPage: 1, pickerLastPage: 1,
        pickerTotal: 0, pickerQuery: '', pickerUnit: '', pickerLoading: false, pickerError: '',
        pickerState: 'idle', pickerSequence: 0, pickerController: null, pickerTimer: null,
        preview: null, result: null, previewLoading: false, previewController: null,
        draftSequence: 0, applying: false, confirmOpen: false, error: '', errors: {}, announcement: '', editorRoot: null,
        step: 'susun', stepSequence: 0, visible: config.visible ?? true, dirty: false, notice: '', pendingFocus: null, previewOutdated: false,
        reviewSearch: '', reviewFilter: 'all', reviewPerPage: 10, reviewPage: 1,

        init() {
            this.editorRoot = this.$el ?? null;
            if (config.initialStep && config.initialStep !== 'susun') {
                this.notice = 'Draft belum tersedia setelah halaman dimuat ulang. Susun rangkaian dan pilih pegawai terlebih dahulu.';
            }
            this.publishState();
            this.$dispatch?.('cuti-config-step', { step: this.step });
        },

        destroy() {
            clearTimeout(this.pickerTimer);
            this.pickerSequence++;
            this.pickerController?.abort();
            this.previewController?.abort();
            this.draftSequence++;
            [...this.draft.verifiers, this.pybmc].forEach((step) => this.cancelApprover(step));
        },

        get selectedTargets() { return [...this.selected.values()]; },
        get reviewRows() { return (this.result ?? this.preview)?.rows ?? []; },
        // Tinjauan hanya memotong hasil maksimal 100 pilihan eksplisit, bukan dataset pegawai dari server.
        get filteredReviewRows() {
            const query = this.reviewSearch.trim().toLocaleLowerCase('id');
            return this.reviewRows.filter((row) => {
                const outcomeMatches = this.reviewFilter === 'all' || (this.reviewFilter === 'skipped'
                    ? row.outcome.startsWith('skip_') : row.outcome === this.reviewFilter);
                return outcomeMatches && (!query || `${row.nama_lengkap} ${row.nip}`.toLocaleLowerCase('id').includes(query));
            });
        },
        get reviewLastPage() { return Math.max(1, Math.ceil(this.filteredReviewRows.length / this.reviewPerPage)); },
        get pagedReviewRows() {
            const start = (Math.min(this.reviewPage, this.reviewLastPage) - 1) * this.reviewPerPage;
            return this.filteredReviewRows.slice(start, start + this.reviewPerPage);
        },

        setReviewFilter(field, value) {
            if (field === 'reviewSearch') this.reviewSearch = String(value);
            else if (field === 'reviewPerPage') this.reviewPerPage = Number(value) === 25 ? 25 : 10;
            else if (field === 'reviewFilter') this.reviewFilter = ['all', 'create', 'replace', 'unchanged', 'skipped'].includes(value) ? value : 'all';
            else return;
            this.reviewPage = 1;
        },

        setReviewPage(page) {
            this.reviewPage = Math.max(1, Math.min(this.reviewLastPage, Number(page) || 1));
        },

        resetReview() {
            this.reviewSearch = '';
            this.reviewFilter = 'all';
            this.reviewPage = 1;
        },
        get applyCount() {
            const counts = this.preview?.counts ?? {};
            return (counts.create ?? 0) + (counts.replace ?? 0);
        },

        announce(message) {
            this.announcement = message;
        },

        publishState() {
            this.$dispatch?.('cuti-config-state', {
                dirty: this.dirty, applying: this.applying,
                notice: this.visible ? '' : this.error ? 'Periksa isian' : this.preview ? 'Pratinjau siap' : this.result ? 'Hasil tersedia' : '',
            });
        },

        setVisible(visible) {
            this.visible = visible;
            if (visible && this.pendingFocus) {
                const pending = this.pendingFocus;
                this.pendingFocus = null;
                if (pending === 'error') this.focusFirstError();
                else this.focus(pending);
            }
            this.publishState();
        },

        async goToStep(step) {
            if (this.applying || !['susun', 'pilih', 'tinjau'].includes(step)) return;
            if (step !== 'susun' && ((!this.globalPybmc && this.draft.pybmc_mode === 'global')
                || (this.draft.pybmc_mode === 'custom' && !this.pybmc.employeeId)
                || this.draft.verifiers.some((row) => !row.employeeId || !row.role_label.trim()))) {
                this.notice = 'Lengkapi pilihan approver pada tahap Susun terlebih dahulu.';
                return;
            }
            if (step === 'tinjau' && this.selected.size === 0) {
                this.notice = 'Pilih minimal satu pegawai sebelum meninjau penerapan.';
                return;
            }
            this.notice = '';
            this.stepSequence++;
            this.step = step;
            this.$dispatch?.('cuti-config-step', { step });
            this.focus(`#batch-${step}-heading`);
            if (step === 'pilih' && this.pickerState === 'idle' && config.targetsUrl) await this.loadTargets();
        },

        // x-show membuka panel pada frame berikutnya; nextTick saja belum menjamin target sudah terlihat.
        focus(selector) {
            if (!this.visible) { this.pendingFocus = selector; return; }
            const focusStep = this.step;
            this.$nextTick?.(() => scheduleFrame(() => {
                if (this.step !== focusStep) return;
                if (this.visible) (this.editorRoot ?? this.$el)?.querySelector(selector)?.focus();
                else this.pendingFocus = selector;
            }));
        },

        focusFirstError() {
            const fields = Object.keys(this.errors);
            this.step = fields.some((field) => /^(verifiers|pybmc_|mode)/.test(field)) ? 'susun'
                : fields.some((field) => field.startsWith('employee_ids')) ? 'pilih' : 'tinjau';
            this.$dispatch?.('cuti-config-step', { step: this.step });
            if (!this.visible) { this.pendingFocus = 'error'; return; }
            const focusStep = this.step;
            this.$nextTick?.(() => scheduleFrame(() => {
                if (this.step !== focusStep) return;
                if (!this.visible) { this.pendingFocus = 'error'; return; }
                const root = this.editorRoot ?? this.$el;
                const invalid = [...(root?.querySelectorAll('[aria-invalid="true"]') ?? [])]
                    .find((field) => !field.disabled && field.getClientRects().length);
                (invalid ?? root?.querySelector('[data-batch-error-summary]') ?? this.$refs?.errorSummary)?.focus();
            }));
        },

        fieldError(field) {
            const exact = this.errors[field];
            if (Array.isArray(exact)) return exact[0] ?? '';
            const child = Object.keys(this.errors).find((key) => key.startsWith(`${field}.`));
            return child ? this.errors[child][0] ?? '' : '';
        },

        /** Semua perubahan input membatalkan token, termasuk respons preview yang masih berjalan. */
        invalidatePreview(markDirty = true) {
            if (markDirty) {
                this.dirty = true;
                this.previewOutdated = this.previewOutdated || Boolean(this.preview || this.result);
                this.publishState();
            }
            this.draftSequence++;
            this.previewController?.abort();
            this.preview = null;
            this.result = null;
            this.confirmOpen = false;
            this.error = '';
            this.errors = {};
            this.resetReview();
        },

        changeDraft(field, value) {
            if (this.applying || this.draft[field] === value) return;
            this.draft[field] = value;
            this.invalidatePreview();
            if (field === 'pybmc_mode') this.cancelApprover(this.pybmc);
        },

        toggleTarget(target) {
            if (this.applying) return;
            if (this.selected.has(target.id)) this.selected.delete(target.id);
            else if (this.selected.size < 100) this.selected.set(target.id, { ...target });
            else {
                this.error = 'Maksimal 100 pegawai per penerapan. Lepas pilihan sebelum menambah pegawai.';
                this.errors = { employee_ids: [this.error] };
                this.focusFirstError();
                return;
            }
            this.invalidatePreview();
            this.announce(`${this.selected.size} pegawai dipilih dari maksimal 100.`);
        },

        selectPage() {
            if (this.applying || this.pickerLoading) return;
            const additions = this.pickerRows.filter((row) => !this.selected.has(row.id));
            if (this.selected.size + additions.length > 100) {
                this.error = 'Pilihan halaman ini melebihi maksimal 100 pegawai. Pilih pegawai satu per satu atau lepas pilihan.';
                this.errors = { employee_ids: [this.error] };
                this.focusFirstError();
                return;
            }
            if (!additions.length) return;
            additions.forEach((row) => this.selected.set(row.id, { ...row }));
            this.invalidatePreview();
            this.announce(`${this.selected.size} pegawai dipilih dari maksimal 100.`);
        },

        addVerifier() {
            if (this.applying || this.draft.verifiers.length >= 8) return;
            const step = candidate();
            this.draft.verifiers.push(step);
            this.invalidatePreview();
            this.announce(`Verifikator ${this.draft.verifiers.length} ditambahkan.`);
            this.focus(`#${step.key}-label`);
        },

        removeVerifier(index) {
            if (this.applying) return;
            const step = this.draft.verifiers[index];
            if (!step) return;
            this.cancelApprover(step);
            this.draft.verifiers.splice(index, 1);
            this.invalidatePreview();
            const neighbor = this.draft.verifiers[Math.min(index, this.draft.verifiers.length - 1)];
            this.focus(neighbor ? `#${neighbor.key}-label` : '#batch-add-verifier');
            this.announce(`Verifikator ${index + 1} dihapus.`);
        },

        moveVerifier(index, direction) {
            if (this.applying) return;
            const destination = index + direction;
            if (destination < 0 || destination >= this.draft.verifiers.length) return;
            const [step] = this.draft.verifiers.splice(index, 1);
            this.draft.verifiers.splice(destination, 0, step);
            this.invalidatePreview();
            this.focus(`#${step.key}-label`);
            this.announce(`Verifikator dipindahkan ke urutan ${destination + 1}.`);
        },

        updateVerifier(step, label) {
            if (this.applying || step.role_label === label) return;
            step.role_label = label;
            this.invalidatePreview();
        },

        cancelApprover(step) {
            step.sequence++;
            clearTimeout(step.timer);
            step.controller?.abort();
            step.timer = null;
            step.controller = null;
            step.loading = false;
            step.results = [];
            step.open = false;
            step.activeIndex = -1;
            step.state = 'idle';
            step.error = '';
        },

        scheduleApprover(step, query) {
            if (this.applying) return;
            this.cancelApprover(step);
            step.query = query;
            // UUID harus dikosongkan segera saat teks diubah, supaya identitas lama tidak terkirim.
            step.employeeId = '';
            this.invalidatePreview();
            const value = query.trim();
            if ([...value].length < 2 || [...value].length > 100) return;
            step.state = 'scheduled';
            step.open = true;
            step.timer = setTimeout(() => this.lookupApprover(step, value), 300);
        },

        chooseApprover(step, person) {
            if (this.applying) return;
            this.cancelApprover(step);
            step.employeeId = person.id;
            step.query = `${person.nama_lengkap} (NIP ${person.nip})`;
            this.invalidatePreview();
        },

        moveApprover(step, direction) {
            if (!step.results.length) return;
            step.open = true;
            step.activeIndex = (step.activeIndex + direction + step.results.length) % step.results.length;
            this.$nextTick?.(() => (this.editorRoot ?? this.$el)?.querySelector(
                `#${step.key}-option-${step.activeIndex}`,
            )?.scrollIntoView({ block: 'nearest' }));
        },

        chooseActiveApprover(step, event) {
            if (!step.open || !step.results[step.activeIndex]) return;
            event.preventDefault();
            this.chooseApprover(step, step.results[step.activeIndex]);
        },

        async lookupApprover(step, query = step.query.trim()) {
            if (this.applying || [...query].length < 2 || [...query].length > 100) return;
            this.cancelApprover(step);
            const sequence = step.sequence;
            const controller = new AbortController();
            step.controller = controller;
            step.loading = true;
            step.state = 'loading';
            step.open = true;
            try {
                const payload = await this.readResponse(await request(`${config.approversUrl}?q=${encodeURIComponent(query)}`, {
                    credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'application/json' }, signal: controller.signal,
                }));
                if (sequence !== step.sequence) return;
                if (!Array.isArray(payload.data)) throw this.invalidResponse();
                step.results = payload.data.slice(0, 15);
                step.state = step.results.length ? 'results' : 'empty';
                step.activeIndex = step.results.length ? 0 : -1;
            } catch (error) {
                if (sequence !== step.sequence || error.name === 'AbortError') return;
                step.error = this.errorMessage(error);
                step.state = 'error';
            } finally {
                if (sequence === step.sequence) {
                    step.loading = false;
                    step.controller = null;
                }
            }
        },

        scheduleTargets() {
            clearTimeout(this.pickerTimer);
            this.pickerSequence++;
            this.pickerController?.abort();
            this.pickerLoading = false;
            this.pickerPage = 1;
            this.pickerTimer = setTimeout(() => this.loadTargets(), 300);
        },

        async loadTargets(page = this.pickerPage) {
            clearTimeout(this.pickerTimer);
            this.pickerController?.abort();
            const sequence = ++this.pickerSequence;
            const controller = new AbortController();
            this.pickerController = controller;
            this.pickerLoading = true;
            this.pickerError = '';
            this.pickerState = 'loading';
            const params = new URLSearchParams({ page: String(page) });
            if (this.pickerQuery.trim()) params.set('q', this.pickerQuery.trim());
            if (this.pickerUnit) params.set('unit_kerja_id', this.pickerUnit);
            try {
                const payload = await this.readResponse(await request(`${config.targetsUrl}?${params}`, {
                    credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'application/json' }, signal: controller.signal,
                }));
                if (sequence !== this.pickerSequence) return;
                if (!Array.isArray(payload.data)) throw this.invalidResponse();
                this.pickerRows = payload.data;
                this.pickerPage = payload.current_page;
                this.pickerLastPage = payload.last_page;
                this.pickerTotal = payload.total;
                this.pickerState = payload.data.length ? 'results' : 'empty';
                // Penyegaran label/status hanya untuk pilihan yang sama, tidak menambah atau melepas target.
                payload.data.forEach((row) => { if (this.selected.has(row.id)) this.selected.set(row.id, { ...row }); });
            } catch (error) {
                if (sequence !== this.pickerSequence || error.name === 'AbortError') return;
                this.pickerError = this.errorMessage(error);
                this.pickerState = 'error';
            } finally {
                if (sequence === this.pickerSequence) {
                    this.pickerLoading = false;
                    this.pickerController = null;
                }
            }
        },

        payload() {
            return {
                employee_ids: [...this.selected.keys()], mode: this.draft.mode, reason: this.draft.reason.trim() || null,
                verifiers: this.draft.verifiers.map((step) => ({ approver_employee_id: step.employeeId, role_label: step.role_label })),
                pybmc_mode: this.draft.pybmc_mode,
                pybmc_employee_id: this.draft.pybmc_mode === 'custom' ? this.pybmc.employeeId || null : null,
            };
        },

        post(url, payload, signal) {
            return request(url, {
                method: 'POST', credentials: 'same-origin', redirect: 'manual', signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrfToken ?? '' },
                body: JSON.stringify(payload),
            });
        },

        async loadPreview() {
            if (this.previewLoading || this.applying || this.selected.size === 0) return;
            this.invalidatePreview(false);
            const sequence = this.draftSequence;
            const stepSequence = this.stepSequence;
            const controller = new AbortController();
            this.previewController = controller;
            this.previewLoading = true;
            try {
                const payload = await this.readResponse(await this.post(config.previewUrl, this.payload(), controller.signal));
                if (sequence !== this.draftSequence) return;
                if (!payload.data?.preview_token || !Array.isArray(payload.data.rows) || !payload.data.counts) throw this.invalidResponse();
                this.preview = payload.data;
                this.previewOutdated = false;
                this.announce(`Pratinjau tersedia. ${this.applyCount} pegawai dapat diterapkan.`);
                // Hasil tetap tersedia, tetapi pilihan tahap pengguna selama request tidak boleh ditimpa.
                if (stepSequence === this.stepSequence) {
                    this.step = 'tinjau';
                    this.$dispatch?.('cuti-config-step', { step: this.step });
                    this.focus('#batch-review-heading');
                }
            } catch (error) {
                if (sequence !== this.draftSequence || error.name === 'AbortError') return;
                this.errors = error.fields ?? {};
                this.error = this.errorMessage(error);
                if (stepSequence === this.stepSequence) this.focusFirstError();
            } finally {
                if (this.previewController === controller) {
                    this.previewLoading = false;
                    this.previewController = null;
                }
                this.publishState();
            }
        },

        summary(data = this.result ?? this.preview) {
            const counts = data?.counts ?? {};
            return {
                create: counts.create ?? 0, replace: counts.replace ?? 0, unchanged: counts.unchanged ?? 0,
                skipped: Object.entries(counts).reduce((sum, [key, count]) => sum + (key.startsWith('skip_') ? count : 0), 0),
            };
        },

        outcomeLabel(outcome, final = Boolean(this.result)) {
            if (outcome === 'create') return final ? 'Dibuat' : 'Akan dibuat';
            if (outcome === 'replace') return final ? 'Diganti' : 'Akan diganti';
            return outcome === 'unchanged' ? 'Tidak berubah' : 'Dilewati';
        },

        openConfirmation() {
            if (this.preview?.can_apply && this.applyCount > 0 && !this.applying) this.confirmOpen = true;
        },

        closeConfirmation() {
            if (!this.applying) this.confirmOpen = false;
        },

        async apply() {
            if (!this.confirmOpen || this.applying || !this.preview?.can_apply || this.applyCount === 0) return;
            let appliedCounts = null;
            this.applying = true;
            this.publishState();
            this.error = '';
            this.errors = {};
            try {
                const payload = await this.readResponse(await this.post(config.applyUrl, {
                    ...this.payload(), preview_token: this.preview.preview_token,
                }));
                if (!Array.isArray(payload.data?.rows) || !payload.data?.counts) throw this.invalidResponse();
                this.result = payload.data;
                this.resetReview();
                this.dirty = false;
                this.previewOutdated = false;
                payload.data.rows.forEach((row) => {
                    if (!['create', 'replace', 'unchanged', 'skip_existing'].includes(row.outcome)) return;
                    const selected = this.selected.get(row.employee_id);
                    if (selected) this.selected.set(row.employee_id, { ...selected, has_active_chain: true });
                    this.pickerRows = this.pickerRows.map((target) => target.id === row.employee_id ? { ...target, has_active_chain: true } : target);
                });
                this.announce('Penerapan selesai. Periksa hasil untuk setiap pegawai.');
                appliedCounts = payload.data.counts;
            } catch (error) {
                this.errors = error.fields ?? {};
                // POST yang jawabannya hilang tidak diulang otomatis; server mungkin sudah menyimpan seluruh batch.
                this.error = (!error.status || error.status >= 500)
                    ? 'Hasil penerapan belum dapat dipastikan. Muat pratinjau ulang untuk memeriksa keadaan terbaru sebelum menerapkan lagi.'
                    : this.errorMessage(error);
            } finally {
                this.preview = null;
                this.applying = false;
                this.confirmOpen = false;
                this.publishState();
                if (appliedCounts) {
                    // Editor lain tetap dilindungi navigasi induk; fokus hasil hanya dipulihkan bila pengguna tetap di sini.
                    this.$dispatch?.('cuti-config-applied', {
                        counts: appliedCounts,
                        onStay: () => this.$nextTick?.(() => this.focus('#batch-review-heading')),
                    });
                } else {
                    // Tunggu modal tutup sebelum mengarahkan fokus ke field yang gagal.
                    this.$nextTick?.(() => this.focusFirstError());
                }
            }
        },

        invalidResponse() {
            const error = new Error('Respons server tidak dapat dibaca. Coba muat pratinjau atau pencarian lagi.');
            error.name = 'InvalidResponseError';
            return error;
        },

        async readResponse(response) {
            // Redirect login tidak diikuti ke SSO; respons buram tetap dilaporkan sebagai sesi berakhir.
            if ([401, 419].includes(response.status) || response.redirected || response.type === 'opaqueredirect') {
                const error = new Error('Sesi Anda telah berakhir. Salin isian yang masih ada di halaman ini, lalu masuk kembali dan muat ulang halaman.');
                error.status = 401;
                throw error;
            }
            if ([403, 404, 429].includes(response.status)) {
                const messages = {
                    403: 'Anda tidak lagi memiliki izin untuk tindakan ini. Hubungi pengelola akses.',
                    404: 'Satu atau lebih pegawai tidak tersedia dalam cakupan akses Anda. Periksa pilihan lalu muat pratinjau ulang.',
                    429: 'Terlalu banyak permintaan. Tunggu sebentar, lalu coba lagi secara manual.',
                };
                const error = new Error(messages[response.status]);
                error.status = response.status;
                throw error;
            }
            let payload;
            try { payload = await response.json(); }
            catch { throw this.invalidResponse(); }
            if (!response.ok) {
                const error = new Error(response.status === 422 ? 'Periksa isian yang ditandai, lalu muat pratinjau ulang.' : 'Server belum dapat memproses permintaan. Coba lagi.');
                error.status = response.status;
                error.fields = response.status === 422 ? payload.errors ?? {} : {};
                throw error;
            }
            return payload;
        },

        errorMessage(error) {
            return error.status || error.name === 'InvalidResponseError'
                ? error.message : 'Koneksi bermasalah. Isian tetap tersimpan. Periksa koneksi lalu coba lagi.';
        },
    };
};

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('chainBatchEditor', createChainBatchEditor);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
