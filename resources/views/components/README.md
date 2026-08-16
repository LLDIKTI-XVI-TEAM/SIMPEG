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
| **Danger** | `bg-danger`, `text-danger`, `border-danger` | `#DC2626` | Status ditolak, nonaktif, bahaya, aksi destruktif |
| **Info** | `bg-info`, `text-info`, `border-info` | `#0284C7` | Informasi umum, H-90, petunjuk |

**Typography**:
- Font Body/Heading: `Poppins`, `ui-sans-serif`, `system-ui`, `sans-serif` (`font-sans`)
- Font Monospace: System Monospace (`font-mono`) — untuk NIP, Token, UUID, atau Nomor SK.

---

## 🧩 3. Katalog Komponen UI (`<x-ui.*>`)

Direktori: `resources/views/components/ui/`

### 🔘 `x-ui.button`
Komponen tombol standar dan anchor link dengan dukungan variasi visual, ukuran, state loading, dan tooltip.

**Props**:
- `variant`: `primary` (default), `secondary`, `muted`, `danger`, `danger-solid`, `success`, `warning`, `ghost`, `link`.
- `size`: `xs`, `sm`, `md` (default), `lg`, `icon`.
- `type`: `button` (default), `submit`, `reset`.
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

{{-- Tombol Aksi Destruktif --}}
<x-ui.button type="button" variant="danger" size="sm">
    Hapus
</x-ui.button>
```

---

### 📦 `x-ui.card`
Kontainer permukaan putih ber-border untuk mengelompokkan konten administratif.

**Props**:
- `padding`: `none`, `sm`, `md` (default), `lg`.
- `shadow`: `none`, `sm` (default), `md`.

**Contoh Penggunaan**:
```blade
<x-ui.card padding="lg" class="space-y-4">
    <h3 class="text-lg font-semibold text-ink">Informasi Pegawai</h3>
    <p class="text-sm text-muted">Konten informasi detail pegawai...</p>
</x-ui.card>
```

---

### 🏷️ `x-ui.badge`
Badge status untuk menampilkan status pegawai, progres cuti, atau tingkatan EWS.

**Props**:
- `variant`: `primary`, `secondary`, `success`, `warning`, `danger`, `info`, `muted` (default).
- `size`: `sm`, `md` (default).
- `dot`: (bool) Menampilkan titik indikator warna.

**Contoh Penggunaan**:
```blade
<x-ui.badge variant="success" :dot="true">Aktif</x-ui.badge>
<x-ui.badge variant="warning">Menunggu Persetujuan</x-ui.badge>
<x-ui.badge variant="danger">Non-Aktif</x-ui.badge>
```

---

### ⚠️ `x-ui.alert`
Kotak notifikasi dan peringatan kontekstual.

**Props**:
- `variant`: `info` (default), `success`, `warning`, `danger`.
- `title`: (string|null) Judul tebal di awal alert.
- `dismissible`: (bool) Tombol tutup alert (default: false).

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
Card statistik metrik untuk dashboard dan halaman profil ringkas.

**Props**:
- `label`: (string) Judul metrik.
- `value`: (string|int) Angka nilai metrik.
- `unit`: (string|null) Satuan metrik (misal: "Hari", "Pegawai").
- `description`: (string|null) Teks penjelas di bawah nilai.
- `variant`: `primary`, `secondary`, `success`, `warning`, `danger`, `info`.
- `size`: `sm`, `md`, `lg`.

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
- `items`: (array) Daftar asosiatif `[['label' => '...', 'url' => '...'], ...]`.

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
- `id`: (string) Identifier dialog.
- `title`: (string) Judul konfirmasi.
- `message`: (string) Pesan penjelasan dampak aksi.
- `confirm-text`: (string) Teks tombol konfirmasi (misal: "Hapus", "Nonaktifkan").
- `variant`: `danger`, `warning`, `primary`.
- `action`: (string) URL endpoint tujuan submit.
- `method`: `POST`, `DELETE`, `PUT`.

**Contoh Penggunaan**:
```blade
<x-ui.confirm-dialog
    id="hapus-pegawai-{{ $pegawai->id }}"
    title="Nonaktifkan Pegawai"
    message="Apakah Anda yakin ingin menonaktifkan {{ $pegawai->nama }}? Pegawai ini akan dipindahkan ke daftar nonaktif."
    confirm-text="Nonaktifkan"
    variant="danger"
    :action="route('pegawai.deactivate', $pegawai->id)"
    method="POST"
>
    <x-slot:trigger>
        <x-ui.button variant="danger" size="xs">Nonaktifkan</x-ui.button>
    </x-slot:trigger>
</x-ui.confirm-dialog>
```

---

### 🗃️ `x-ui.table` & Sub-Komponen Tabel
Struktur tabel semantik data kepegawaian.

**Sub-komponen**:
- `<x-ui.table>`: Pembungkus tabel utama.
- `<x-ui.table-head>`: Bagian header tabel (`bg-soft`).
- `<x-ui.table-body>`: Badan tabel.
- `<x-ui.table-row>`: Baris data (`:interactive="true"` untuk efek hover).
- `<x-ui.table-th>`: Kolom header dengan typography `text-xs font-semibold uppercase text-muted`.
- `<x-ui.table-td>`: Sel data dengan padding `comfortable` atau `compact`.

---

## 📝 4. Katalog Komponen Form (`<x-form.*>`)

Direktori: `resources/views/components/form/`

Semua komponen form terintegrasi dengan validasi Laravel (`$errors`), old value (`old()`), ID otomatis, serta accessibility label (`aria-describedby`).

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

### 5. `x-form.checkbox` & `x-form.toggle`
Input boolean pilihan atau sakelar status.
```blade
<x-form.checkbox 
    name="is_active" 
    label="Status Aktif" 
    :checked="true" 
/>
```

---

## 🔒 5. Session Management & Keamanan Autentikasi

### Kebijakan Session Timeout:
1. **`SIMPEG_SESSION_IDLE_TIMEOUT=30`**:
   - Sesuai spesifikasi **US-1.3**, batas waktu idle di browser ditetapkan sebesar **30 menit**.
   - Jika tidak ada aktivitas pengguna selama 30 menit, aplikasi menampilkan dialog peringatan idle dan mengarahkan pengguna kembali ke halaman login.
   - Sesi idle yang terputus dicatat secara otomatis ke dalam `audit_logs` dengan event `LOGOUT` atau `TIMEOUT`.
2. **`SESSION_LIFETIME=120`**:
   - Nilai session lifetime server-side diatur lebih besar (120 menit) sebagai batas pengaman (*fail-safe lifetime*) agar sesi backend tidak hangus mendahului timer idle client-side.

### Keamanan Route Logout:
- **Wajib HTTP `POST`**: Seluruh aksi logout dari sistem wajib menggunakan metode `POST` dengan token `@csrf` valid untuk mencegah serangan CSRF via prefetching atau tautan GET.
- **Route `GET /logout` Dilarang**: Route GET untuk logout telah dihapus secara permanen dari `routes/web.php` untuk mematuhi standar OWASP dan release gate SIMPEG.
