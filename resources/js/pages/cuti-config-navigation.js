/** Navigasi lokal mempertahankan editor; hanya perpindahan halaman yang perlu konfirmasi kehilangan draft. */
export const createCutiConfigNavigation = (config = {}) => {
    const tabs = ['pegawai', 'rangkaian', ...(config.hasGlobalIdentityScope ? ['pybmc'] : []), ...(config.canViewAudit ? ['riwayat'] : [])];
    let pending = null;
    let bypassForm = null;
    let unloadListener;
    let outsideLinkListener;
    const browser = () => config.window ?? globalThis.window;

    return {
        tabs, activeTab: tabs.includes(config.initialTab) ? config.initialTab : 'pegawai',
        batchStep: 'susun', batchApplying: false, leaveConfirmOpen: false,
        dirtyEditors: {}, allowUnload: false, navigationNotice: '', batchNotice: '',

        init() {
            unloadListener = (event) => this.beforeUnload(event);
            // Sidebar berada di luar scope Alpine editor; listener dilepas saat halaman dihancurkan.
            outsideLinkListener = (event) => {
                if (!this.$el?.contains(event.target)) this.guardLink(event);
            };
            browser()?.addEventListener('beforeunload', unloadListener);
            browser()?.document.addEventListener('click', outsideLinkListener, true);
            const editor = config.restoredEditor;
            const tab = editor === 'pybmc' ? 'pybmc' : 'pegawai';
            const form = ['pegawai', 'atasan', 'pybmc'].includes(editor) && this.tabs.includes(tab)
                ? this.$el?.querySelector(`[data-config-form="${editor}"]`) : null;
            if (form) {
                // Old input yang ditolak server tetap draft, meskipun belum diketik ulang.
                this.markDirty(editor);
                this.activeTab = tab;
                this.$nextTick?.(() => {
                    const invalid = form.querySelector('[aria-invalid="true"]:not([disabled]):not([type="hidden"])');
                    (invalid ?? form.querySelector('[data-config-error-summary]'))?.focus();
                });
            }
            if (config.batchSuccessCounts) this.$nextTick?.(() => this.notifyBatchSuccess(config.batchSuccessCounts));
        },

        destroy() {
            browser()?.removeEventListener('beforeunload', unloadListener);
            browser()?.document.removeEventListener('click', outsideLinkListener, true);
        },

        updateUrl() {
            const location = config.location ?? browser()?.location;
            const history = config.history ?? browser()?.history;
            if (!location || !history) return;
            const url = new URL(location.href);
            url.hash = '';
            url.searchParams.set('tab', this.activeTab);
            if (this.activeTab === 'rangkaian') url.searchParams.set('step', this.batchStep);
            else url.searchParams.delete('step');
            history.replaceState(null, '', url.href);
        },

        switchTab(tab) {
            if (!this.tabs.includes(tab) || this.batchApplying) return;
            this.activeTab = tab;
            this.updateUrl();
            this.$dispatch?.('cuti-config-tab', { tab });
        },

        handleTabKey(event) {
            const index = this.tabs.indexOf(this.activeTab);
            const positions = { ArrowRight: (index + 1) % this.tabs.length, ArrowLeft: (index + this.tabs.length - 1) % this.tabs.length, Home: 0, End: this.tabs.length - 1 };
            if (!(event.key in positions) || this.batchApplying) return;
            event.preventDefault();
            this.switchTab(this.tabs[positions[event.key]]);
            this.$nextTick?.(() => this.$el.querySelector(`[role="tab"][data-config-tab="${this.activeTab}"]`)?.focus());
        },

        syncBatchState(state) {
            this.batchApplying = Boolean(state.applying);
            this.dirtyEditors.rangkaian = Boolean(state.dirty);
            this.batchNotice = state.notice ?? '';
        },

        syncBatchStep(step) {
            if (!['susun', 'pilih', 'tinjau'].includes(step)) return;
            this.batchStep = step;
            this.updateUrl();
        },

        /** Hasil final server memisahkan mutasi nyata dari rangkaian identik dan seluruh sebab dilewati. */
        notifyBatchSuccess(counts) {
            const skipped = Object.entries(counts).reduce((sum, [key, count]) => sum + (key.startsWith('skip_') ? count : 0), 0);
            const message = `Penerapan rangkaian selesai: ${counts.create ?? 0} dibuat, ${counts.replace ?? 0} diganti, ${counts.unchanged ?? 0} tidak berubah, ${skipped} dilewati.`;
            // Live region halaman tetap mengumumkan keberhasilan setelah editor asal ditinggalkan.
            this.announce?.(message);
            this.$dispatch?.('notify', {
                type: 'success', title: 'Berhasil', message,
            });
        },

        /** Keberhasilan batch bukan persetujuan membuang draft editor lain pada halaman yang sama. */
        finishBatch({ counts, onStay }) {
            const proceed = () => {
                this.allowUnload = true;
                (config.location ?? browser()?.location)?.assign(config.successUrl);
            };
            const stay = () => {
                this.notifyBatchSuccess(counts);
                onStay?.();
            };
            if (this.requestDeparture(proceed, stay, null, 'rangkaian')) proceed();
        },

        markDirty(editor) {
            if (['pegawai', 'atasan', 'pybmc', 'rangkaian'].includes(editor)) this.dirtyEditors[editor] = true;
        },

        trackFormChange(event) {
            const form = event.target.closest?.('[data-config-form]');
            if (form && ['pegawai', 'atasan', 'pybmc'].includes(form.dataset.configForm)) this.markDirty(form.dataset.configForm);
        },

        hasUnsavedExcept(editor) {
            return Object.entries(this.dirtyEditors).some(([key, dirty]) => dirty && key !== editor);
        },

        requestDeparture(proceed, cancel = null, trigger = null, owner = null) {
            if (this.batchApplying) {
                this.navigationNotice = 'Penerapan sedang berlangsung. Tunggu hasilnya sebelum meninggalkan halaman.';
                cancel?.();
                return false;
            }
            if (!this.hasUnsavedExcept(owner)) return true;
            pending = { proceed, cancel, trigger };
            this.leaveConfirmOpen = true;
            return false;
        },

        cancelDeparture() {
            const previous = pending;
            pending = null;
            this.leaveConfirmOpen = false;
            previous?.cancel?.();
            this.$nextTick?.(() => previous?.trigger?.focus());
        },

        confirmDeparture() {
            if (this.batchApplying || !pending) return;
            const previous = pending;
            pending = null;
            this.leaveConfirmOpen = false;
            previous.proceed();
        },

        guardSubmit(event) {
            const form = event.target;
            if (form.matches?.('[data-config-local-form]')) return;
            if (bypassForm === form) {
                bypassForm = null;
                queueMicrotask(() => { this.allowUnload = !event.defaultPrevented; });
                return;
            }
            const restore = () => form.closest('[data-config-employee-picker]')?.dispatchEvent(new CustomEvent('config-selection-restore'));
            const proceed = () => {
                bypassForm = form;
                try {
                    form.requestSubmit(event.submitter ?? undefined);
                } finally {
                    // Validasi HTML dapat berhenti tanpa event submit; izin sekali pakai tidak boleh tertinggal.
                    bypassForm = null;
                }
            };
            if (!this.requestDeparture(proceed, restore, event.submitter, form.dataset.configForm)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            } else {
                // Validasi lokal yang menolak submit tidak boleh mematikan perlindungan refresh berikutnya.
                queueMicrotask(() => { this.allowUnload = !event.defaultPrevented; });
            }
        },

        guardLink(event) {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest?.('a[href]');
            if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
            const location = config.location ?? browser()?.location;
            if (!location) return;
            const url = new URL(link.href, location.href);
            if (!['http:', 'https:'].includes(url.protocol) || (url.hash && url.pathname === location.pathname && url.search === location.search)) return;
            const proceed = () => { this.allowUnload = true; location.assign(url.href); };
            if (!this.requestDeparture(proceed, null, link)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            } else this.allowUnload = true;
        },

        beforeUnload(event) {
            if (this.allowUnload || (!this.batchApplying && !this.hasUnsavedExcept(null))) return;
            event.preventDefault();
            event.returnValue = '';
        },
    };
};

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('cutiConfigNavigation', createCutiConfigNavigation);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
