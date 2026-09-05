# SIMPEG Blade Component System & Design Catalog

> **Panduan Resmi Komponen Antarmuka (UI) SIMPEG — LLDIKTI Wilayah XVI**  
> Mengacu pada [DESIGN.md](../../../DESIGN.md), [design-system.md](../../../design-system.md), dan standar arsitektur Blade Laravel 12 + Tailwind CSS v4.

---

## 📌 1. Prinsip Utama & Filosofi Desain

1. **Physical Scene & Persona**:
   - Pengguna utama: Administrator Kepegawaian, Pimpinan, Kepala Bagian, dan Pegawai LLDIKTI XVI.
   - Karakter antarmuka: Tenang, formal, padat informasi, mudah dipindai (*scannable*), dan responsif pada seluruh ukuran layar.
2. **Light Theme Only**:
   - Seluruh komponen dirancang **eksklusif untuk mode terang (Light Mode)**. Tidak diperkenankan menambahkan styling dark mode ad-hoc.
3. **Reusable & Canonical**:
   - Selalu gunakan komponen yang sudah ada di direktori `resources/views/components/` sebelum membuat elemen UI baru.
   - Dilarang membuat inline styling atau warna Tailwind di luar token `@theme` yang telah didefinisikan di `resources/css/app.css`.

---

## 🎨 2. Design Tokens Reference

Semua token warna terdaftar di `resources/css/app.css` via Tailwind CSS v4 `@theme`:

| Token | Utility Class | Hex Code | Peran / Penggunaan |
|---|---|---|---|
| **Primary** | `bg-primary`, `text-primary`, `border-primary` | `#122E92` | Brand utama, CTA, navigasi aktif, border fokus |
| **Secondary** | `bg-secondary`, `text-secondary`, `border-secondary` | `#D6AC48` | Aksen emas institusional terbatas, highlight |
| **Page** | `bg-page` | `#F8FAFC` | Latar belakang halaman |
| **Surface** | `bg-surface` | `#FFFFFF` | Permukaan card, panel, modal, navbar |
| **Soft** | `bg-soft` | `#F3F4F6` | Header tabel, hover background, input pasif |
| **Ink** | `text-ink` | `#111827` | Teks utama, judul, body text |
| **Muted** | `text-muted` | `#6B7280` | Metadata, label pembantu, placeholder |
| **Border** | `border-border` | `#E5E7EB` | Garis batas, divider, border input |
| **Success** | `bg-success`, `text-success`, `border-success` | `#16A34A` | Status aktif, disetujui, sukses |
| **Warning** | `bg-warning`, `text-warning`, `border-warning` | `#F59E0B` | Status menunggu, perhatian, H-60 |
| **Danger** | `bg-danger`, `text-danger`, `border-danger` | `#DC2626` | Status Tidak Disetujui, nonaktif, bahaya, aksi destruktif |
| **Info** | `bg-info`, `text-info`, `border-info` | `#0284C7` | Informasi umum, H-90, petunjuk |

**Typography**:
- Font Body/Heading: `Poppins`, `ui-sans-serif`, `system-ui`, `sans-serif` (`font-sans`)
- Font Monospace: System Monospace (`font-mono`) — untuk NIP, Token, UUID, atau Nomor SK.

---

## 🧩 3. Katalog Komponen UI (`<x-ui.*>`)

Direktori: `resources/views/components/ui/`

### 🔘 `x-ui.button`
Komponen tombol standar dan anchor link dengan dukungan variasi visual, ukuran, disabled state, dan tooltip.

**Props**:
- `variant`: `'primary'` (default), `'secondary'`, `'muted'`, `'danger'`, `'danger-solid'`, `'success'`, `'warning'`, `'ghost'`, `'link'`.
- `size`: `'xs'`, `'sm'`, `'md'` (default), `'lg'`, `'icon'`.
- `type`: `'button'` (default), `'submit'`, `'reset'`.
- `href`: (string|null) Jika diisi, otomatis dirender sebagai tag `<a>`.
- `as`: (string|null) `'a'` atau `'button'`.
- `disabled`: (bool) Menandai tombol nonaktif.
- `fullWidth`: (bool) Mengatur lebar tombol 100% (`w-full`).
- `title`: (string|null) Teks tooltip saat hover.

**Contoh Penggunaan**:
```blade
{{-- Tombol Submit Form Primer --}}
<x-ui.button type="submit" variant="primary" size="md">
    Simpan Data
</x-ui.button>

{{-- Tombol Link Sekunder Lebar Penuh --}}
<x-ui.button as="a" href="{{ route('pegawai.index') }}" variant="secondary" size="lg" :full-width="true">
    Kembali ke Daftar
</x-ui.button>
```

---

### 📦 `x-ui.card`
Kontainer permukaan putih ber-border untuk mengelompokkan konten administratif dengan header/footer opsional.

**Props**:
- `as`: (string) Tag HTML pembungkus: `'div'` (default), `'section'`, `'article'`, `'form'`, `'a'`.
- `variant`: `'default'` (default), `'soft'`, `'interactive'` (hover translate & shadow).
- `padding`: `'none'`, `'sm'` (p-4), `'md'` (p-5, default), `'lg'` (p-6).
- `shadow`: (bool, default: `true`) Menambahkan bayangan `shadow-md shadow-slate-200/50`.

**Slots**:
- `$header`: (opsional) Area atas dengan pembatas garis bawah (`border-b`).
- `$slot`: Konten utama card.
- `$footer`: (opsional) Area bawah dengan pembatas garis atas (`border-t`).

**Contoh Penggunaan**:
```blade
<x-ui.card padding="lg" variant="default" :shadow="true">
    <x-slot:header>
        <h3 class="text-sm font-bold text-ink">Informasi Pegawai</h3>
    </x-slot:header>

    <p class="text-sm text-muted">Konten detail informasi pegawai...</p>

    <x-slot:footer>
        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" size="sm">Tutup</x-ui.button>
        </div>
    </x-slot:footer>
</x-ui.card>
```

---

### 🏷️ `x-ui.badge`
Badge status untuk menampilkan status pegawai, progres cuti, atau tingkat urgensi EWS.

**Props**:
- `variant`: `'muted'` (default), `'primary'`, `'success'`, `'danger'`, `'warning'`, `'orange'`, `'info'`, `'ink'`, `'none'`.
- `size`: `'md'` (default, status utama: `px-2.5 py-1`), `'sm'` (status pada tabel padat: `px-2 py-0.5`), atau `'xs'` (ruang sangat terbatas: `px-1.5 py-0.5`). Semua varian teks memakai `text-xs font-semibold` agar tetap terbaca.
- `dot`: (bool, default: `false`) Menampilkan titik indikator warna.
- `pill`: (bool, default: `false`) Menjadikan rounded-full (default: rounded-md).
- `uppercase`: (bool, default: `false`) Menjadikan teks kapital ber-tracking.

**Contoh Penggunaan**:
```blade
<x-ui.badge variant="success" :dot="true">Aktif</x-ui.badge>
<x-ui.badge variant="warning" :pill="true">Menunggu Persetujuan</x-ui.badge>
<x-ui.badge variant="danger" size="sm">Non-Aktif</x-ui.badge>
```

---

### ⚠️ `x-ui.alert`
Kotak notifikasi dan peringatan kontekstual dengan icon semantik.

**Props**:
- `variant`: `'info'` (default), `'success'`, `'danger'`, `'warning'`.
- `title`: (string|null) Judul tebal di awal alert.
- `size`: `'sm'`, `'md'` (default), `'lg'`.
- `dismissible`: (bool, default: `false`) Menampilkan tombol tutup.
- `dismissAction`: (string|null) Ekspresi Alpine.js saat tombol tutup diklik.

**Contoh Penggunaan**:
```blade
<x-ui.alert variant="success" title="Berhasil">
    Data riwayat kepangkatan berhasil diperbarui.
</x-ui.alert>

<x-ui.alert variant="danger" title="Kesalahan Validasi">
    Periksa kembali kolom NIP dan nomor SK yang diinput.
</x-ui.alert>
```

---

### 📊 `x-ui.stat-card`
Card statistik metrik untuk dashboard dan rekapitulasi data.

**Props**:
- `label`: (string|null) Judul metrik.
- `value`: (string|int|null) Nilai metrik.
- `valueBinding`: (string|null) Ekspresi Alpine.js `x-text` untuk nilai reaktif.
- `unit`: (string|null) Satuan nilai (misal: "Hari", "Pegawai").
- `description`: (string|null) Teks penjelas di bawah nilai.
- `variant`: `'primary'` (default), `'success'`, `'warning'`, `'danger'`, `'orange'`, `'info'`, `'muted'`.
- `size`: `'sm'`, `'md'` (default), `'lg'`.
- `padding`: `'none'`, `'sm'`, `'md'` (default), `'lg'`.
- `surface`: `'default'` (default), `'soft'`.
- `center`: (bool, default: `false`) Rata tengah teks dan icon.
- `accent`: (bool, default: `false`) Garis aksen di bagian bawah card.
- `shadow`: (bool, default: `true`).

**Contoh Penggunaan**:
```blade
<x-ui.stat-card 
    label="Hak Efektif Cuti" 
    value="12" 
    unit="Hari" 
    variant="success" 
    description="Total sisa saldo cuti aktif tahun berjalan."
/>
```

---

### 🗺️ `x-ui.breadcrumb`
Navigasi jejak remah halaman.

**Props**:
- `items`: (array) Daftar item navigasi `[['label' => '...', 'url' => '...'], ...]`.

**Contoh Penggunaan**:
```blade
<x-ui.breadcrumb :items="[
    ['label' => 'Dashboard', 'url' => route('dashboard')],
    ['label' => 'Data Pegawai', 'url' => route('pegawai.index')],
    ['label' => 'Detail Pegawai']
]" />
```

---

### 🛡️ `x-ui.confirm-dialog`
Modal konfirmasi aksi berbahaya (soft delete, reset, mutasi status) dengan pengiriman form HTTP bermetode `POST`/`DELETE` ber-CSRF.

**Props**:
- `id`: (string, required) Identifier modal dialog.
- `title`: (string, default: `'Konfirmasi'`) Judul konfirmasi.
- `message`: (string, default: `'Apakah Anda yakin ingin melanjutkan?'`) Pesan penjelasan dampak aksi.
- `confirmText`: (string, default: `'Ya, Lanjutkan'`) Teks tombol konfirmasi.
- `cancelText`: (string, default: `'Batal'`) Teks tombol batal.
- `variant`: `'primary'` (default), `'danger'`, `'warning'`.
- `action`: (string|null) URL endpoint tujuan submit form.
- `method`: `'POST'` (default), `'DELETE'`, `'PUT'`, `'PATCH'`.

**Slots**:
- `$trigger`: Elemen tombol atau trigger pembuka modal.
- `$slot`: Konten tambahan di dalam dialog.

**Contoh Penggunaan**:
```blade
<x-ui.confirm-dialog
    id="hapus-pegawai-{{ $pegawai->id }}"
    title="Nonaktifkan Pegawai"
    message="Apakah Anda yakin ingin menonaktifkan {{ $pegawai->nama }}? Pegawai ini akan dipindahkan ke daftar nonaktif."
    confirmText="Nonaktifkan"
    variant="danger"
    :action="route('pegawai.destroy', $pegawai->id)"
    method="POST"
>
    <x-slot:trigger>
        <x-ui.button variant="danger" size="xs">Nonaktifkan</x-ui.button>
    </x-slot:trigger>
</x-ui.confirm-dialog>
```

---

### 🪟 `x-ui.modal`
Modal dialog serbaguna berbasis Alpine.js dengan transisi halus dan penanganan aksesibilitas ARIA dialog.

**Props**:
- `show`: (string|null) Nama variabel boolean state Alpine.js (misal: `'openModal'`).
- `title`: (string|null) Judul modal pada header.
- `titleId`: (string|null) Kustom ID elemen judul untuk accessibility `aria-labelledby`.
- `descriptionId`: (string|null) Kustom ID elemen deskripsi untuk `aria-describedby`.
- `maxWidth`: `'sm'`, `'md'` (default), `'lg'`, `'xl'`, `'2xl'`, `'3xl'`, `'4xl'`.
- `closeAction`: (string|null) Ekspresi Alpine.js saat modal ditutup (misal: `'openModal = false'`).
- `bodyClass`: (string, default: `'p-6'`).
- `panelClass`, `headerClass`, `footerClass`, `overlayClass`: Kustom kelas Tailwind tambahan.

**Slots**:
- `$slot`: Konten body modal.
- `$footer`: (opsional) Area footer modal dengan latar `bg-soft` dan border atas.

**Contoh Penggunaan**:
```blade
<div x-data="{ openModal: false }">
    {{-- Tombol pemicu buka modal --}}
    <x-ui.button variant="primary" size="sm" @click="openModal = true">
        Tambah Jenjang
    </x-ui.button>

    {{-- Komponen modal dialog --}}
    <x-ui.modal show="openModal" title="Tambah Jenjang Pendidikan" maxWidth="md" closeAction="openModal = false">
        {{-- Form diberi ID, method POST, endpoint action, dan token @csrf agar tombol submit pada slot footer terhubung dan mengirimkan data mutasi --}}
        <form id="form-tambah-jenjang" method="POST" action="{{ route('data-master.jenjang-pendidikan.store') }}" class="space-y-4">
            @csrf
            <x-form.input name="nama" label="Nama Jenjang" placeholder="Contoh: S1 / Sarjana" required />
            <x-form.input name="urutan" label="Urutan" type="number" placeholder="Contoh: 1" />
        </form>
        
        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" size="sm" @click="openModal = false">Batal</x-ui.button>
                <x-ui.button variant="primary" size="sm" type="submit" form="form-tambah-jenjang">Simpan</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
```

---

### 🎚️ `x-ui.toggle`
Sakelar toggle boolean (switch) berbasis CSS peer-checked.

**Props**:
- `name`: (string|null) Nama field form.
- `id`: (string|null) Identifier elemen (default: otomatis).
- `disabled`: (bool, default: `false`).

**Contoh Penggunaan**:
```blade
<x-ui.toggle name="is_active" :checked="true" />
```

---

### 🔀 `x-ui.segmented-control` & `x-ui.segmented-item`
Kontrol pemilih segmen opsi kompak berbasis tombol tab terpadu.

**Props `x-ui.segmented-control`**:
- `label`: (string|null) Aksesibilitas `aria-label`.

**Props `x-ui.segmented-item`**:
- `active`: (string, required) Ekspresi boolean Alpine.js untuk menandai item aktif (misal: `currentMode === 'ringkas'`).
- `click`: (string|null) Handler Alpine.js saat item diklik (misal: `currentMode = 'ringkas'`).
- `type`: (string, default: `'button'`).

**Contoh Penggunaan**:
```blade
<div x-data="{ currentMode: 'ringkas' }">
    <x-ui.segmented-control label="Mode Tampilan">
        <x-ui.segmented-item active="currentMode === 'ringkas'" @click="currentMode = 'ringkas'">Ringkas</x-ui.segmented-item>
        <x-ui.segmented-item active="currentMode === 'detail'" @click="currentMode = 'detail'">Detail</x-ui.segmented-item>
    </x-ui.segmented-control>
</div>
```

---

### 🔍 `x-ui.filter-bar`
Bar pencarian dan filter data di atas tabel atau daftar.

**Props**:
- `searchModel`: (string|null) Nama properti Alpine.js `x-model` untuk input pencarian.
- `searchId`: (string|null) ID elemen input pencarian.
- `searchName`: (string|null) Nama field input pencarian.
- `searchValue`: (string|null) Nilai awal input pencarian.
- `searchPlaceholder`: (string, default: `'Cari...'`).
- `searchCols`: (string, default: `'col-span-1 sm:col-span-2 lg:col-span-1'`).
- `searchLabel`: (string|null) Label di atas input pencarian.
- `gridClass`: (string, default: `'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4'`).

**Slots**:
- `$header`: (opsional) Header filter bar.
- `$actions`: (opsional) Tombol aksi di sisi kanan header.
- `$slot`: Filter kustom tambahan (select dropdown, date picker, dll).

---

### 📂 `x-ui.empty-state`
Tampilan ramah saat data kosong atau pencarian tidak menemukan hasil.

**Props**:
- `icon`: `'folder'` (default), `'document'`, `'search'`, atau `'none'`.
- `title`: (string, default: `'Tidak ada data'`).
- `message`: (string|null) Pesan penjelas.

**Slots**:
- `$action`: (opsional) Tombol aksi untuk menambahkan data baru atau mereset filter.

**Contoh Penggunaan**:
```blade
<x-ui.empty-state
    icon="search"
    title="Data tidak ditemukan"
    message="Coba periksa kata kunci atau ubah filter pencarian Anda."
>
    <x-slot:action>
        <x-ui.button variant="secondary" size="sm" @click="resetFilter()">Reset Filter</x-ui.button>
    </x-slot:action>
</x-ui.empty-state>
```

---

### ⏳ `x-ui.loading`
Indikator spinner pemuatan berbasis animasi SVG.

**Props**:
- `size`: `'xs'`, `'sm'`, `'md'` (default), `'lg'`, `'xl'`.
- `color`: `'current'` (default), `'primary'`, `'white'`, `'muted'`.

**Contoh Penggunaan**:
```blade
<x-ui.loading size="lg" color="primary" />
```

---

### 📑 `x-ui.tabs` & `x-ui.tab`
Navigasi tab konten berbasis state Alpine.js.

**Props `x-ui.tabs`**:
- `variant`: `'underline'` (default), `'sidebar'`, `'sidebar-soft'`, `'pills'`.
- `label`: (string|null) Accessibility `aria-label`.

**Props `x-ui.tab`**:
- `active`: (string, required) Ekspresi boolean Alpine.js untuk menentukan tab aktif (misal: `activeTab === 'profil'`).
- `click`: (string|null) Ekspresi Alpine.js saat tab diklik (misal: `activeTab = 'profil'`).
- `variant`: `'underline'` (default), `'sidebar'`, `'sidebar-soft'`, `'pills'`.
- `type`: `'button'` (default).

**Contoh Penggunaan**:
```blade
<div x-data="{ currentTab: 'umum' }">
    <x-ui.tabs variant="underline">
        <x-ui.tab active="currentTab === 'umum'" @click="currentTab = 'umum'">Informasi Umum</x-ui.tab>
        <x-ui.tab active="currentTab === 'riwayat'" @click="currentTab = 'riwayat'">Riwayat SK</x-ui.tab>
    </x-ui.tabs>
</div>
```

---

### 💬 `x-ui.tooltip`
Tooltip mengambang berbasis Alpine.js saat hover.

**Props**:
- `text`: (string, default: `''`) Teks statis tooltip.
- `dynamicText`: (string|null) Ekspresi Alpine.js untuk teks tooltip dinamis.
- `position`: `'top'` (default), `'bottom'`, `'left'`, `'right'`, `'top-end'`, `'bottom-end'`.
- `nowrap`: (bool, default: `true`).

**Contoh Penggunaan**:
```blade
<x-ui.tooltip text="Cetak Dokumen PDF" position="top">
    <x-ui.button variant="secondary" size="icon">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
    </x-ui.button>
</x-ui.tooltip>
```

---

### ⏳ `x-ui.timeline` & `x-ui.timeline-item`
Visualisasi riwayat alur status berurutan (misal: proses verifikasi usulan cuti).

**Props `x-ui.timeline-item`**:
- `variant`: `'success'` (default), `'danger'`, `'warning'`, `'muted'`.
- `title`: (string, default: `''`) Judul tahapan.
- `description`: (string, default: `''`) Keterangan atau tanggal tahapan.
- `pulse`: (bool, default: `false`) Efek animasi denyut pada titik status aktif.

**Contoh Penggunaan**:
```blade
<x-ui.timeline>
    <x-ui.timeline-item variant="success" title="Pengajuan Dibuat" description="12 Agu 2026 - Oleh Budi Santoso" />
    <x-ui.timeline-item variant="warning" title="Menunggu Persetujuan Atasan" description="Sedang diproses" :pulse="true" />
    <x-ui.timeline-item variant="muted" title="Penerbitan SK Cuti" description="Tahap akhir" />
</x-ui.timeline>
```

---

### 📄 `x-ui.pagination`
Komponen kontrol paginasi nomor halaman berbasis Alpine.js.

**Props**:
- `current`: (string, default: `'currentPage'`) Variabel Alpine.js penampung halaman aktif.
- `total`: (string, default: `'totalPages'`) Variabel Alpine.js penampung total halaman.
- `action`: (string|null) Perintah navigasi saat tombol diklik (misal: `'fetchData(page)'`).

---

### 🗃️ `x-ui.table` & Sub-Komponen Tabel
Struktur tabel semantik data kepegawaian.

**Sub-komponen**:
- `<x-ui.table>`: Pembungkus tabel utama (`:caption` opsional).
- `<x-ui.table-head>`: Bagian header tabel (`bg-soft`).
- `<x-ui.table-body>`: Badan tabel.
- `<x-ui.table-row>`: Baris data. Mendukung prop `:interactive="true"` untuk menambahkan kelas `cursor-pointer` (efek transisi warna `hover:bg-soft/60` diterapkan secara default).
- `<x-ui.table-th>`: Kolom header dengan typography `text-xs font-semibold uppercase text-muted` (opsi `align`: `'left'`, `'center'`, `'right'`).
- `<x-ui.table-td>`: Sel data tabel.
  - `align`: `'left'` (default), `'center'`, `'right'`.
  - `padding`: `'xs'` (px-3 py-2), `'sm'` (px-4 py-3), `'md'` (px-4 py-3.5, default), `'wide'` (px-5 py-3.5), `'lg'` (px-5 py-4), `'xl'` (px-6 py-5), `'comfortable'` (px-6 py-4).

---

### 🗂️ `x-ui.data-table`
Komponen tabel data lengkap yang menggabungkan bar filter pencarian, pengurutan kolom (*sorting*), indikator pemuatan (*loading state*), *empty state*, dan kontrol paginasi footer dalam satu komponen terpadu.

**Props Utama**:
- `rows`: (string, required) Variabel array data baris di Alpine.js.
- `meta`: (string, required) Objek metadata pagination Laravel (`current_page`, `last_page`, `from`, `to`, `total`).
- `columns`: (array, required) Definisi kolom tabel `[['key' => '...', 'label' => '...', 'sortable' => true, 'align' => 'left'], ...]`.
- `fetchPage`: (string, required) Fungsi callback pemanggil API halaman (misal: `'fetchEmployees(page)'`).
- `isLoading`: (string, default: `'isLoading'`) State boolean loading.
- `perPage`: (string, default: `'perPage'`) State per-page.
- `setPerPage`: (string, default: `'setPerPage($event.target.value)'`).
- `sort`: (string|null), `direction`: (string|null), `setSort`: (string|null).
- `searchModel`: (string|null), `searchPlaceholder`: (string, default: `'Cari...'`).
- `emptyTitle`: (string, default: `'Tidak ada data'`), `emptyIcon`: (string, default: `'search'`).
- `checkAllId`, `checkAllAction`, `checkAllShow`: (string|null) Konfigurasi fitur *select-all checkbox*.

---

## 📝 4. Katalog Komponen Form (`<x-form.*>`)

Komponen form terintegrasi dengan validasi Laravel (`$errors`), penentuan ID otomatis, serta accessibility label (`aria-describedby` & `aria-invalid`). Pemulihan nilai input sebelumnya via `old()` didukung penuh untuk input teks, select, date, dan textarea.

> *Catatan Penanganan Nilai `old()`*:
> - **Input Password**: `<x-form.input type="password">` sengaja tidak mengisi nilai `old()` demi standar keamanan autentikasi.
> - **File Upload**: `<x-form.file-upload>` tidak memulihkan nilai `old()` karena batasan keamanan standar browser.
> - **Checkbox**: Karena browser tidak mengirimkan data saat checkbox tidak dicentang (*unchecked*), `<x-form.checkbox>` mengevaluasi `old($field, $checked)`. Untuk form dengan nilai awal `checked=true`, disarankan menyertakan hidden input bernilai `0` sebelum checkbox jika ingin mempertahankan status unchecked saat validasi gagal.

### 1. `x-form.input`
Input teks, nomor, email, password.
```blade
<x-form.input 
    name="nip" 
    label="NIP Pegawai" 
    placeholder="18 digit NIP" 
    required 
    maxlength="18"
    size="md"
/>
```

### 2. `x-form.select`
Dropdown pilihan referensi atau enum.
```blade
<x-form.select name="jenis_kelamin" label="Jenis Kelamin" required>
    <option value="L">Laki-laki</option>
    <option value="P">Perempuan</option>
</x-form.select>
```

### 3. `x-form.date`
Input tanggal standar ISO (YYYY-MM-DD) dengan kalender native.
```blade
<x-form.date 
    name="tanggal_sk" 
    label="Tanggal Surat Keputusan (SK)" 
    required 
/>
```

### 4. `x-form.textarea`
Input teks multibaris untuk keterangan/alasan.
```blade
<x-form.textarea 
    name="keterangan" 
    label="Keterangan" 
    rows="3" 
    placeholder="Tuliskan catatan tambahan jika ada..."
/>
```

### 5. `x-form.checkbox`
Input kotak centang pilihan boolean.
```blade
<x-form.checkbox 
    name="is_active" 
    label="Status Aktif" 
    value="1" 
/>
```

### 6. `x-form.file-upload`
Area upload berkas dokumen pendukung dengan mode standar inline atau dropzone seret-lepas.

**Props**:
- `name`: (string|null) Nama field file input.
- `label`: (string|null) Label dokumen.
- `accept`: (string|null) MIME type/ekstensi berkas (misal: `'.pdf,.jpg,.jpeg,.png'`).
- `required`: (bool, default: `false`).
- `disabled`: (bool, default: `false`).
- `mode`: `'inline'` (default) atau `'dropzone'`.
- `size`: `'sm'`, `'md'` (default).
- `title`: (string, default: `'Klik atau seret berkas di sini'`).
- `hint`: (string|null) Teks petunjuk batasan ukuran/format berkas.

**Contoh Penggunaan**:
```blade
<x-form.file-upload 
    name="file_sk" 
    label="Dokumen SK (PDF/Gambar)" 
    accept=".pdf,.jpg,.jpeg,.png" 
    mode="dropzone"
    hint="Ukuran maksimal berkas 10MB (sesuai PRD §7.4)"
    required 
/>
```

---

## 🔒 5. Session Management & Keamanan Autentikasi

### Mekanisme Session Timeout (Server-Side Enforced):
Sistem SIMPEG menerapkan penegakan *idle timeout* berbasis request di sisi server melalui middleware `App\Http\Middleware\SessionTimeoutMessage` yang dipasang seragam pada seluruh surface terautentikasi (seluruh grup rute web terlindungi di `routes/web.php` dan seluruh endpoint API bisnis di `routes/api/v1/*.php`):

1. **`SIMPEG_SESSION_IDLE_TIMEOUT=30`**:
   - Sesuai spesifikasi **US-1.3** dan keputusan kanonis **K-US-03**, batas waktu tidak aktif (*idle*) ditetapkan sebesar **30 menit** berdasarkan selisih waktu `now() - last_activity_at`.
   - Evaluasi timeout terjadi pada **request HTTP berikutnya yang masuk ke rute yang dilindungi middleware**.
   - Ketika idle time melebihi 30 menit:
     1. Middleware mencatat riwayat audit dengan event resmi **`SESSION_TIMEOUT`** lengkap dengan identitas pengguna (`user_id` dan `user_name`).
     2. Memanggil `Auth::logout()` dan `session()->invalidate()`.
     3. Meregenerasi token CSRF (`session()->regenerateToken()`).
     4. Menyimpan pesan flash `simpeg_session_timeout_message` ("*Sesi Anda telah berakhir. Silakan login kembali.*").
     5. Me-redirect request HTML ke `route('login')`, atau mengembalikan respons JSON `401 Unauthorized` untuk request AJAX/API.
   - **Pengecualian Polling Background**: Request polling notifikasi periodik (`api.v1.notifikasi.index` dan `api.v1.notifikasi.jumlah-belum-dibaca`) sengaja tidak memperbarui `last_activity_at` agar browser yang ditinggalkan terbuka tidak membuat sesi hidup selamanya.

2. **`SESSION_LIFETIME=120`**:
   - Waktu kedaluwarsa sesi native Laravel (`config/session.php`) dikonfigurasi sebesar **120 menit**.
   - Pengaturan ini sengaja dibuat lebih besar daripada idle timeout aplikasi (30 menit) sebagai **jaring pengaman (*safety net*)** agar payload sesi dan data user tidak terhapus oleh garbage collection PHP sebelum middleware sempat memeriksa timeout dan mencatat event audit `SESSION_TIMEOUT` dengan identitas pengguna yang valid.

### Keamanan Rute Logout:
- **Wajib HTTP `POST`**: Seluruh aksi logout dari sistem wajib menggunakan metode `POST /logout` dengan token `@csrf` valid untuk mencegah serangan Cross-Site Request Forgery (CSRF).
- **Rute `GET /logout` Dihapus**: Rute GET untuk logout telah dihapus secara permanen dari `routes/web.php` untuk mematuhi standar keamanan OWASP dan release gate SIMPEG.
