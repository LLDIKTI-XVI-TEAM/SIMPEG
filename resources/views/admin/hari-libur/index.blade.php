<x-layouts.app title="Hari Libur">

    @php
        // Nilai filter aktif dipakai ulang untuk tautan tab tahun, form per-halaman,
        // dan link paginasi supaya konteks filter tidak hilang saat berpindah.
        $filterAktif = array_filter([
            'tahun' => $filters['tahun'],
            'tipe' => $filters['tipe'] !== '' ? $filters['tipe'] : null,
            'search' => $filters['search'] !== '' ? $filters['search'] : null,
            'per_page' => $filters['per_page'],
        ], static fn ($nilai) => $nilai !== null);

        $canCreateHariLibur = auth()->user()?->hasPermission('hari_libur.create') ?? false;
        $canUpdateHariLibur = auth()->user()?->hasPermission('hari_libur.update') ?? false;
        $canDeleteHariLibur = auth()->user()?->hasPermission('hari_libur.delete') ?? false;
        $hasHariLiburMutation = $canUpdateHariLibur || $canDeleteHariLibur;
        $addErrors = $errors->getBag('hariLiburAdd');
        $editErrors = $errors->getBag('hariLiburEdit');
        $hasFormErrors = $addErrors->any() || $editErrors->any();
        $hasPageErrors = $errors->getBag('default')->any() || $hasFormErrors;
        $addInput = $addErrors->any() && old('form_context') === 'add' ? old() : [];
        $showAddForm = $addErrors->any() && old('form_context') === 'add';

        $editInput = $editErrors->any() && old('form_context') === 'edit'
            ? [
                'id' => old('hari_libur_id'),
                'tanggal' => old('tanggal'),
                'nama' => old('nama'),
                'tipe' => old('tipe', 'libur_nasional'),
            ]
            : null;
    @endphp

    <script>
        window.hariLiburEditInput = @json($editInput);
    </script>

    <div
        x-data="{
            showAddForm: @json($showAddForm),
            showEditForm: Boolean(window.hariLiburEditInput),
            editHariLibur: window.hariLiburEditInput ?? { id: '', tanggal: '', nama: '', tipe: 'libur_nasional' },
            lastAddFormFocus: null,
            lastEditFormFocus: null,
            init() {
                if (this.showAddForm) {
                    this.$nextTick(() => this.$refs.addHariLiburModal?.focus());
                }

                if (this.showEditForm) {
                    this.$nextTick(() => this.$refs.editHariLiburModal?.focus());
                }
            },
            openAddHariLibur() {
                this.lastAddFormFocus = document.activeElement;
                this.showAddForm = true;
                this.$nextTick(() => this.$refs.addHariLiburModal?.focus());
            },
            closeAddHariLibur() {
                if (!this.showAddForm) return;

                const previousFocus = this.lastAddFormFocus;
                this.showAddForm = false;
                this.lastAddFormFocus = null;
                this.$nextTick(() => previousFocus?.focus());
            },
            openEditHariLibur(button) {
                this.lastEditFormFocus = document.activeElement;
                this.editHariLibur = {
                    id: button.dataset.id,
                    tanggal: button.dataset.tanggal,
                    nama: button.dataset.nama,
                    tipe: button.dataset.tipe,
                };
                this.showEditForm = true;
                this.$nextTick(() => this.$refs.editHariLiburModal?.focus());
            },
            closeEditHariLibur() {
                if (!this.showEditForm) return;

                const previousFocus = this.lastEditFormFocus;
                this.showEditForm = false;
                this.lastEditFormFocus = null;
                this.$nextTick(() => previousFocus?.focus());
            },
            trapModalFocus(event, modalRef) {
                const modal = this.$refs[modalRef];

                if (!modal) return;

                const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])'));

                if (focusable.length === 0) return;

                const currentIndex = focusable.indexOf(document.activeElement);
                const nextIndex = event.shiftKey
                    ? (currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1)
                    : (currentIndex === focusable.length - 1 ? 0 : currentIndex + 1);

                focusable[nextIndex].focus();
            },
        }"
        class="space-y-6"
    >

        {{-- PAGE HEADER --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Hari Libur</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Hari Libur']
                ]" />
            </div>
            <div class="flex items-center gap-3">

                @if ($canCreateHariLibur)
                    <x-ui.button
                        type="button"
                        @click="openAddHariLibur()"
                        x-bind:aria-expanded="showAddForm ? 'true' : 'false'"
                        aria-controls="modal-tambah-hari-libur"
                        data-hari-libur-action="create"
                        variant="primary"
                    >
                        <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Hari Libur
                    </x-ui.button>
                @endif
            </div>
        </div>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($hasPageErrors)
            <x-ui.alert variant="danger" title="Perubahan hari libur belum tersimpan">
                Periksa kembali kolom yang ditandai pada formulir.
            </x-ui.alert>
        @endif

        {{-- Penjelasan dampak data ini terhadap kalkulasi cuti dan EWS --}}
        <x-ui.alert variant="info" title="Penting untuk integritas sistem">
            Tanggal pada halaman ini dibaca langsung oleh sistem untuk menghitung jumlah
            <strong>hari kerja efektif pengajuan cuti</strong> dan menentukan jadwal peringatan
            <strong>Early Warning System (EWS)</strong>. Perubahan di sini berlaku pada kalkulasi berikutnya.
        </x-ui.alert>

        {{-- Script Setup Alpine Data untuk Kalender --}}
        <script>
            window.kalenderEvents = @json($kalenderHariLibur ?? []);

            window.setupKalenderHariLibur = function(tahunAktif, eventsData) {
                return {
                    tahun: Number(tahunAktif),
                    bulan: new Date().getFullYear() === Number(tahunAktif) ? new Date().getMonth() : 0,
                    namaBulan: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
                    namaHari: ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'],
                    events: eventsData || [],
                    selectedEvent: null,
                    lastCalendarFocus: null,

                    get daysInMonth() {
                        const firstDay = new Date(this.tahun, this.bulan, 1).getDay();
                        const daysCount = new Date(this.tahun, this.bulan + 1, 0).getDate();
                        const days = [];
                        for (let i = 0; i < firstDay; i++) {
                            days.push({ day: null, dateStr: null, isCurrentMonth: false, isWeekend: false, events: [] });
                        }
                        for (let d = 1; d <= daysCount; d++) {
                            const mStr = String(this.bulan + 1).padStart(2, '0');
                            const dStr = String(d).padStart(2, '0');
                            const dateStr = `${this.tahun}-${mStr}-${dStr}`;
                            const eventList = this.events.filter(e => e.tanggal === dateStr);
                            const dayOfWeek = new Date(this.tahun, this.bulan, d).getDay();
                            days.push({
                                day: d,
                                dateStr: dateStr,
                                isCurrentMonth: true,
                                isWeekend: dayOfWeek === 0 || dayOfWeek === 6,
                                events: eventList
                            });
                        }
                        return days;
                    },

                    prevMonth() {
                        if (this.bulan > 0) {
                            this.bulan -= 1;
                        }
                    },
                    nextMonth() {
                        if (this.bulan < 11) {
                            this.bulan += 1;
                        }
                    },
                    selectDay(dayObj) {
                        if (dayObj && dayObj.events && dayObj.events.length > 0) {
                            this.lastCalendarFocus = document.activeElement;
                            this.selectedEvent = dayObj;
                            this.$nextTick(() => this.$refs.calendarDetailModal?.focus());
                        }
                    },
                    closeCalendarDetail() {
                        if (this.selectedEvent === null) return;

                        const previousFocus = this.lastCalendarFocus;
                        this.selectedEvent = null;
                        this.lastCalendarFocus = null;
                        this.$nextTick(() => previousFocus?.focus());
                    },
                    trapCalendarDetailFocus(event) {
                        const focusable = Array.from(this.$refs.calendarDetailModal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])'));

                        if (focusable.length === 0) {
                            return;
                        }

                        const currentIndex = focusable.indexOf(document.activeElement);
                        const nextIndex = event.shiftKey
                            ? (currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1)
                            : (currentIndex === focusable.length - 1 ? 0 : currentIndex + 1);

                        focusable[nextIndex].focus();
                    }
                };
            };
        </script>

        {{-- KOMPONEN KALENDER HARI LIBUR --}}
        <x-ui.card padding="lg" id="kalender-hari-libur-container" class="space-y-4 shadow-sm border border-border"
            x-data="setupKalenderHariLibur({{ (int) $filters['tahun'] }}, window.kalenderEvents)"
        >
            <!-- Header Kalender: Bulan, Navigasi, & Ringkasan Tahun -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-border pb-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-primary/10 text-primary">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 9v7.5" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-ink font-sans leading-tight">
                            Kalender Hari Libur &amp; Cuti Bersama
                        </h3>
                        <p class="text-xs text-muted font-sans mt-0.5">
                            Menampilkan kalender resmi tahun {{ $filters['tahun'] }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        size="icon"
                        @click="prevMonth()"
                        ::disabled="bulan === 0"
                        ::aria-label="bulan === 0 ? 'Sudah bulan Januari' : 'Bulan sebelumnya'"
                        ::title="bulan === 0 ? 'Sudah bulan Januari' : 'Bulan sebelumnya'"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                        </svg>
                    </x-ui.button>

                    <span class="text-sm font-semibold text-ink font-sans min-w-[130px] text-center" x-text="`${namaBulan[bulan]} ${tahun}`"></span>

                    <x-ui.button
                        type="button"
                        variant="secondary"
                        size="icon"
                        @click="nextMonth()"
                        ::disabled="bulan === 11"
                        ::aria-label="bulan === 11 ? 'Sudah bulan Desember' : 'Bulan berikutnya'"
                        ::title="bulan === 11 ? 'Sudah bulan Desember' : 'Bulan berikutnya'"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </x-ui.button>
                </div>
            </div>

            <!-- Legend Indikator -->
            <div class="flex flex-wrap items-center justify-between gap-4 text-xs font-sans px-1">
                <div class="flex flex-wrap items-center gap-4">
                    <span class="flex items-center gap-1.5 font-medium text-ink">
                        <span class="w-3 h-3 rounded-full bg-primary inline-block"></span>
                        Libur Nasional
                    </span>
                    <span class="flex items-center gap-1.5 font-medium text-ink">
                        <span class="w-3 h-3 rounded-full bg-secondary inline-block"></span>
                        Cuti Bersama
                    </span>
                    <span class="flex items-center gap-1.5 font-medium text-muted">
                        <span class="w-3 h-3 rounded-full bg-danger inline-block"></span>
                        Akhir Pekan
                    </span>
                </div>
                <div class="text-muted text-[11px] font-sans">
                    * Klik tanggal yang memiliki indikator untuk melihat detail nama libur
                </div>
            </div>

            <!-- Grid Kalender -->
            <div class="grid grid-cols-7 gap-1 border border-border rounded-lg p-2 bg-soft/30">
                <!-- Header Nama Hari -->
                <template x-for="(hari, idx) in namaHari" :key="idx">
                    <div
                        class="py-2 text-center text-xs font-semibold font-sans rounded"
                        :class="idx === 0 || idx === 6 ? 'text-danger bg-danger/5' : 'text-muted'"
                        x-text="hari"
                    ></div>
                </template>

                <!-- Sel Tanggal -->
                <template x-for="(d, idx) in daysInMonth" :key="idx">
                    <button
                        type="button"
                        class="min-h-[64px] w-full p-1.5 rounded-lg border text-left transition flex flex-col justify-between focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2 disabled:cursor-default disabled:opacity-100"
                        :class="{
                            'bg-transparent border-transparent': !d.isCurrentMonth,
                            'bg-surface border-border hover:border-primary/40 cursor-default': d.isCurrentMonth && (!d.events || d.events.length === 0),
                            'bg-primary/5 border-primary/30 hover:bg-primary/10 cursor-pointer shadow-xs': d.isCurrentMonth && d.events && d.events.some(e => !e.is_cuti_bersama),
                            'bg-secondary/10 border-secondary/40 hover:bg-secondary/20 cursor-pointer shadow-xs': d.isCurrentMonth && d.events && d.events.some(e => e.is_cuti_bersama),
                        }"
                        :disabled="!d.isCurrentMonth || !d.events || d.events.length === 0"
                        :aria-label="d.events && d.events.length ? `${d.dateStr}: ${d.events.map(event => event.nama).join(', ')}. Buka detail hari libur.` : d.dateStr"
                        @click="selectDay(d)"
                    >
                        <span class="flex items-center justify-between">
                            <span
                                class="text-xs font-bold font-sans"
                                :class="{
                                    'text-muted/30': !d.isCurrentMonth,
                                    'text-danger font-bold': d.isCurrentMonth && d.isWeekend && (!d.events || d.events.length === 0),
                                    'text-ink font-semibold': d.isCurrentMonth && !d.isWeekend && (!d.events || d.events.length === 0),
                                    'text-primary font-extrabold': d.isCurrentMonth && d.events && d.events.some(e => !e.is_cuti_bersama),
                                    'text-secondary-hover font-extrabold': d.isCurrentMonth && d.events && d.events.some(e => e.is_cuti_bersama),
                                }"
                                x-text="d.day || ''"
                            ></span>

                            <template x-if="d.events && d.events.length > 0">
                                <span class="flex gap-1">
                                    <template x-for="ev in d.events" :key="ev.id">
                                        <span
                                            class="w-2.5 h-2.5 rounded-full"
                                            :class="ev.is_cuti_bersama ? 'bg-secondary' : 'bg-primary'"
                                            :title="ev.nama"
                                        ></span>
                                    </template>
                                </span>
                            </template>
                        </span>

                        <template x-if="d.events && d.events.length > 0">
                            <span class="mt-1 space-y-0.5">
                                <template x-for="ev in d.events" :key="ev.id">
                                    <span
                                        class="block text-[10px] leading-tight font-semibold rounded px-1 py-0.5 truncate"
                                        :class="ev.is_cuti_bersama ? 'bg-secondary/20 text-ink' : 'bg-primary/15 text-primary'"
                                        x-text="ev.nama"
                                    ></span>
                                </template>
                            </span>
                        </template>
                    </button>
                </template>
            </div>

            <x-ui.modal
                id="modal-detail-hari-libur"
                show="selectedEvent !== null"
                closeAction="closeCalendarDetail()"
                title="Detail Hari Libur"
                maxWidth="sm"
                x-ref="calendarDetailModal"
                tabindex="-1"
                @keydown.tab.prevent="trapCalendarDetailFocus($event)"
            >
                <div class="space-y-3">
                    <p class="text-xs text-muted font-sans">
                        Tanggal: <strong class="text-ink" x-text="selectedEvent ? selectedEvent.dateStr : ''"></strong>
                    </p>
                    <template x-for="ev in (selectedEvent ? selectedEvent.events : [])" :key="ev.id">
                        <div class="p-3 rounded-lg border space-y-1" :class="ev.is_cuti_bersama ? 'bg-secondary/10 border-secondary/30' : 'bg-primary/5 border-primary/20'">
                            <span
                                class="inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider font-sans"
                                :class="ev.is_cuti_bersama ? 'bg-secondary/20 text-ink' : 'bg-primary/15 text-primary'"
                                x-text="ev.label_tipe"
                            ></span>
                            <h5 class="text-sm font-bold text-ink font-sans" x-text="ev.nama"></h5>
                        </div>
                    </template>
                </div>
                <x-slot:footer>
                    <div class="flex justify-end">
                        <x-ui.button type="button" variant="secondary" size="xs" @click="closeCalendarDetail()">
                            Tutup
                        </x-ui.button>
                    </div>
                </x-slot:footer>
            </x-ui.modal>
        </x-ui.card>

        {{-- Form Tambah Hari Libur (Modal) --}}
        @if ($canCreateHariLibur)
            <x-ui.modal
                id="modal-tambah-hari-libur"
                show="showAddForm"
                closeAction="closeAddHariLibur()"
                title="Tambah Hari Libur Baru"
                maxWidth="3xl"
                x-ref="addHariLiburModal"
                tabindex="-1"
                @keydown.tab.prevent="trapModalFocus($event, 'addHariLiburModal')"
            >
                <form id="form-tambah-modal" action="{{ route('hari-libur.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <input type="hidden" name="form_context" value="add">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-form.date
                            name="tanggal"
                            label="Tanggal"
                            :value="$addInput['tanggal'] ?? null"
                            error-bag="hariLiburAdd"
                            :use-old-input="false"
                            required
                            size="lg"
                        />
                        <x-form.input
                            name="nama"
                            label="Nama Hari Libur"
                            type="text"
                            :value="$addInput['nama'] ?? null"
                            error-bag="hariLiburAdd"
                            :use-old-input="false"
                            placeholder="Contoh: Hari Raya Idul Fitri"
                            required
                            size="lg"
                        />
                        <x-form.select
                            name="tipe"
                            label="Jenis Libur"
                            :value="$addInput['tipe'] ?? 'libur_nasional'"
                            error-bag="hariLiburAdd"
                            :use-old-input="false"
                            required
                        >
                            <option value="libur_nasional">Libur Nasional</option>
                            <option value="cuti_bersama">Cuti Bersama</option>
                        </x-form.select>
                    </div>
                    <x-slot:footer>
                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="link" size="sm" @click="closeAddHariLibur()">Batal</x-ui.button>
                            <x-ui.button type="submit" form="form-tambah-modal" variant="primary" size="sm">Simpan</x-ui.button>
                        </div>
                    </x-slot:footer>
                </form>
            </x-ui.modal>
        @endif

        {{-- Form Edit Hari Libur (Modal) --}}
        @if ($canUpdateHariLibur)
            <x-ui.modal
                id="modal-edit-hari-libur"
                show="showEditForm"
                closeAction="closeEditHariLibur()"
                title="Edit Hari Libur"
                maxWidth="3xl"
                x-ref="editHariLiburModal"
                tabindex="-1"
                @keydown.tab.prevent="trapModalFocus($event, 'editHariLiburModal')"
            >
                <form
                    id="form-edit-modal"
                    x-bind:action="'{{ route('hari-libur') }}/' + editHariLibur.id"
                    method="POST"
                    class="space-y-4"
                >
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="form_context" value="edit">
                    <input type="hidden" name="hari_libur_id" x-model="editHariLibur.id">

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-form.date
                            name="tanggal"
                            id="edit_tanggal"
                            label="Tanggal"
                            error-bag="hariLiburEdit"
                            :use-old-input="false"
                            x-model="editHariLibur.tanggal"
                            required
                            size="lg"
                        />
                        <x-form.input
                            name="nama"
                            id="edit_nama"
                            label="Nama Hari Libur"
                            type="text"
                            error-bag="hariLiburEdit"
                            :use-old-input="false"
                            x-model="editHariLibur.nama"
                            required
                            size="lg"
                        />
                        <x-form.select
                            name="tipe"
                            id="edit_tipe"
                            label="Jenis Libur"
                            error-bag="hariLiburEdit"
                            :use-old-input="false"
                            x-model="editHariLibur.tipe"
                            required
                        >
                            <option value="libur_nasional">Libur Nasional</option>
                            <option value="cuti_bersama">Cuti Bersama</option>
                        </x-form.select>
                    </div>

                    <x-slot:footer>
                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="link" size="sm" @click="closeEditHariLibur()">Batal</x-ui.button>
                            <x-ui.button type="submit" form="form-edit-modal" variant="primary" size="sm">Simpan Perubahan</x-ui.button>
                        </div>
                    </x-slot:footer>
                </form>
            </x-ui.modal>
        @endif

        {{-- Filter Bar --}}
        <x-ui.card padding="sm" class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            {{-- Tab tahun dari data yang benar-benar ada di database --}}
            <nav aria-label="Filter tahun" class="flex flex-wrap items-center gap-4 border-b border-border pb-2 md:border-b-0 md:pb-0">
                @forelse ($tahunTersedia as $tahun => $jumlah)
                    <a
                        href="{{ route('hari-libur', array_merge($filterAktif, ['tahun' => $tahun])) }}"
                        @class([
                            'text-sm pb-1 font-sans focus:outline-none focus:ring-2 focus:ring-primary/20 rounded',
                            'text-primary font-semibold border-b-2 border-primary' => (int) $tahun === $filters['tahun'],
                            'text-muted hover:text-ink' => (int) $tahun !== $filters['tahun'],
                        ])
                        @if ((int) $tahun === $filters['tahun']) aria-current="page" @endif
                    >
                        {{ $tahun }} ({{ $jumlah }})
                    </a>
                @empty
                    <span class="text-sm text-muted font-sans">Belum ada tahun yang terdaftar</span>
                @endforelse
            </nav>

            {{-- Pencarian & filter tipe, keduanya diproses di server --}}
            <form method="GET" action="{{ route('hari-libur') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <input type="hidden" name="tahun" value="{{ $filters['tahun'] }}">
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">

                <div class="w-full sm:w-64">
                    <label for="search" class="sr-only">Cari nama hari libur</label>
                    <div class="relative">
                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="{{ $filters['search'] }}"
                            placeholder="Cari nama hari libur..."
                            class="w-full rounded-lg border border-border bg-surface pl-9 pr-4 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans"
                        >
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.602 10.602Z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <x-form.select
                    id="tipe"
                    name="tipe"
                    label="Tipe Libur"
                    labelSrOnly
                    size="sm"
                    :value="$filters['tipe']"
                    onchange="this.form.submit()"
                    wrapperClass="w-full sm:w-44"
                >
                    <option value="">Semua Tipe</option>
                    <option value="libur_nasional">Libur Nasional</option>
                    <option value="cuti_bersama">Cuti Bersama</option>
                </x-form.select>

                <x-ui.button type="submit" variant="primary" size="xs">Terapkan</x-ui.button>
            </form>
        </x-ui.card>

        {{-- Table --}}
        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-ui.table-head>
                        <x-ui.table-row>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Tanggal</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Hari</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Nama Hari Libur</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Jenis Libur</x-ui.table-th>
                            <x-ui.table-th class="px-6 py-3.5 text-[11px]">Tahun</x-ui.table-th>
                            @if ($hasHariLiburMutation)
                                <x-ui.table-th data-hari-libur-column="actions" class="px-6 py-3.5 text-[11px]">Aksi</x-ui.table-th>
                            @endif
                        </x-ui.table-row>
                    </x-ui.table-head>
                    <x-ui.table-body>
                        @forelse ($hariLibur as $item)
                            <x-ui.table-row :interactive="true">
                                <x-ui.table-td padding="comfortable" class="text-sm font-medium">
                                    {{ $item->tanggal?->translatedFormat('d M Y') }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-sm">
                                    {{ $item->tanggal?->translatedFormat('l') }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-sm font-medium">
                                    {{ $item->nama }}
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable">
                                    <span @class([
                                        'text-xs font-semibold font-sans',
                                        'text-secondary' => $item->is_cuti_bersama,
                                        'text-primary' => ! $item->is_cuti_bersama,
                                    ])>
                                        {{ $item->labelTipe() }}
                                    </span>
                                </x-ui.table-td>
                                <x-ui.table-td padding="comfortable" class="text-sm text-muted">
                                    {{ $item->tahun }}
                                </x-ui.table-td>
                                @if ($hasHariLiburMutation)
                                    <x-ui.table-td padding="comfortable">
                                        <div class="flex items-center gap-1.5">
                                            @if ($canUpdateHariLibur)
                                                <x-ui.button
                                                    type="button"
                                                    @click="openEditHariLibur($el)"
                                                    data-id="{{ $item->id }}"
                                                    data-tanggal="{{ $item->tanggal?->format('Y-m-d') }}"
                                                    data-nama="{{ $item->nama }}"
                                                    data-tipe="{{ $item->tipe() }}"
                                                    data-hari-libur-action="update"
                                                    variant="secondary"
                                                    size="icon"
                                                    title="Edit {{ $item->nama }}"
                                                    aria-label="Edit {{ $item->nama }}"
                                                >
                                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                    </svg>
                                                </x-ui.button>
                                            @endif

                                        {{-- Hapus selalu melalui form DELETE ber-CSRF, bukan manipulasi DOM --}}
                                        @if ($canDeleteHariLibur)
                                            <x-ui.confirm-dialog
                                                id="hapus-hari-libur-{{ $item->id }}"
                                                title="Hapus Hari Libur"
                                                message="Hapus {{ $item->nama }} ({{ $item->tanggal?->translatedFormat('d M Y') }})? Kalkulasi hari kerja cuti akan menghitung tanggal ini sebagai hari kerja."
                                                confirm-text="Hapus"
                                                variant="danger"
                                                :show-icon="false"
                                                :show-confirm-icon="false"
                                                :show-cancel-icon="false"
                                                :action="route('hari-libur.destroy', $item)"
                                                method="DELETE"
                                            >
                                                <x-slot:trigger>
                                                    <x-ui.tooltip text="Hapus" position="top-end">
                                                        <button
                                                            type="button"
                                                            data-hari-libur-action="delete"
                                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-surface text-danger shadow-sm transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-danger/20 cursor-pointer"
                                                            aria-label="Hapus {{ $item->nama }}"
                                                        >
                                                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                            </svg>
                                                        </button>
                                                    </x-ui.tooltip>
                                                </x-slot:trigger>
                                            </x-ui.confirm-dialog>
                                        @endif
                                        </div>
                                    </x-ui.table-td>
                                @endif
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-td :colspan="$hasHariLiburMutation ? 6 : 5" align="center" class="px-0 py-0 text-muted bg-surface">
                                    <x-ui.empty-state
                                        icon="document"
                                        title="Belum ada hari libur untuk filter yang dipilih."
                                        message="Tambahkan hari libur agar kalkulasi hari kerja cuti dan jadwal EWS mengikuti kalender resmi."
                                    />
                                </x-ui.table-td>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </div>

            {{-- TABLE FOOTER --}}
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border px-6 py-4 bg-soft/20">
                <div class="flex items-center gap-3 text-sm text-muted">
                    <form method="GET" action="{{ route('hari-libur') }}" class="flex items-center gap-2">
                        <input type="hidden" name="tahun" value="{{ $filters['tahun'] }}">
                        <input type="hidden" name="tipe" value="{{ $filters['tipe'] }}">
                        <input type="hidden" name="search" value="{{ $filters['search'] }}">

                        <span class="whitespace-nowrap">Tampilkan</span>
                        <label for="per_page" class="sr-only">Jumlah baris per halaman</label>
                        <select
                            id="per_page"
                            name="per_page"
                            onchange="this.form.submit()"
                            class="appearance-none bg-none rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-sans cursor-pointer text-center"
                        >
                            @foreach ($perPageOptions as $opsi)
                                <option value="{{ $opsi }}" @selected((int) $filters['per_page'] === $opsi)>{{ $opsi }}</option>
                            @endforeach
                        </select>
                        <span class="hidden sm:inline">data</span>
                    </form>

                    {{-- Meta Info --}}
                    <div class="hidden md:block ml-2 border-l border-border pl-4">
                        Menampilkan <span class="font-medium text-ink">{{ $hariLibur->firstItem() ?? 0 }}</span>
                        - <span class="font-medium text-ink">{{ $hariLibur->lastItem() ?? 0 }}</span>
                        dari <span class="font-medium text-ink">{{ $hariLibur->total() }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-1.5">
                    {{ $hariLibur->appends(request()->query())->links('vendor.pagination.simpeg') }}
                </div>
            </div>
        </x-ui.card>

    </div>

</x-layouts.app>
