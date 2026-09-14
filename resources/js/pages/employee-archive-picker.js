/** Pilihan arsip terpisah dari halaman hasil agar pencarian tidak mengganti lampiran yang dipilih. */
export const createEmployeeArchivePicker = (config = {}) => {
    let sequence = 0;
    let controller;
    let appliedQuery = '';

    return {
        active: false, query: '', rows: [], selected: config.selected ?? null,
        page: 1, lastPage: 1, total: 0, loading: false, loaded: false, error: '',

        async setActive(active) {
            this.active = active;
            if (!active) {
                sequence++;
                controller?.abort();
                this.loading = false;
            } else if (!this.loaded || this.error) {
                await this.load(1);
            }
        },

        async load(page = 1) {
            if (!this.active) return;
            const current = ++sequence;
            controller?.abort();
            controller = new AbortController();
            this.loading = true;
            this.error = '';
            const search = this.query.trim();
            // Kata kunci baru selalu dimulai dari halaman pertama, termasuk lewat tombol pagination.
            const query = new URLSearchParams({ kategori: config.category, q: search, page: String(search === appliedQuery ? page : 1) });
            try {
                const response = await (config.fetch ?? globalThis.fetch)(`${config.url}?${query}`, {
                    headers: { Accept: 'application/json' }, signal: controller.signal,
                });
                if (current !== sequence) return;
                if (!response.ok) {
                    if ([401, 403, 419].includes(response.status)) {
                        this.clear();
                        this.error = response.status === 403
                            ? 'Akses arsip tidak lagi tersedia. Muat ulang halaman untuk memeriksa izin Anda.'
                            : 'Sesi Anda berakhir. Silakan masuk kembali, lalu buka arsip.';
                    } else {
                        this.error = 'Arsip belum dapat dimuat. Silakan coba lagi.';
                    }
                    this.rows = [];
                    return;
                }
                const payload = await response.json();
                if (current !== sequence) return;
                this.rows = payload.data;
                this.page = payload.meta.current_page;
                this.lastPage = payload.meta.last_page;
                this.total = payload.meta.total;
                appliedQuery = search;
                this.loaded = true;
            } catch (error) {
                if (current !== sequence || error.name === 'AbortError') return;
                this.error = 'Arsip belum dapat dimuat. Periksa koneksi, lalu coba lagi.';
                this.rows = [];
            } finally {
                if (current === sequence) this.loading = false;
            }
        },

        select(id) {
            const doc = this.rows.find(row => row.id === id);
            if (!doc || this.loading) return;
            this.selected = doc;
            this.$dispatch?.('archive-selected', doc);
        },

        clear() {
            this.selected = null;
        },

        destroy() {
            sequence++;
            controller?.abort();
        },
    };
};

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('employeeArchivePicker', createEmployeeArchivePicker);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
