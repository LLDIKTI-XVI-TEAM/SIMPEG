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
- `size`: `'xs'` (text-[9px]), `'sm'` (text-[10px], default), `'md'` (text-xs).
- `dot`: (bool, default: `false`) Menampilkan titik indikator warna.
- `pill`: (bool, default: `false`) Menjadikan rounded-full (default: rounded-md).
- `uppercase`: (bool, default: `false`) Menjadikan teks kapital ber-tracking.

**Contoh Penggunaan**:
```blade
<x-ui.badge variant="success" :dot="true">Aktif</x-ui.badge>
<x-ui.badge variant="warning" :pill="true">Menunggu Persetujuan</x-ui.badge>
<x-ui.badge variant="danger">Non-Aktif</x-ui.badge>
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
- `id`: (string) Identifier modal dialog.
- `title`: (string) Judul konfirmasi.
- `message`: (string) Pesan penjelasan dampak aksi.
- `confirm-text`: (string, default: `'Ya, Lanjutkan'`) Teks tombol konfirmasi.
- `cancel-text`: (string, default: `'Batal'`) Teks tombol batal.
- `variant`: `'danger'`, `'warning'`, `'primary'`.
- `action`: (string) URL endpoint tujuan submit.
- `method`: `'POST'`, `'DELETE'`, `'PUT'`, `'PATCH'`.
- `size`: `'md'`, `'sm'`, `'lg'`.

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

### 🪟 `x-ui.modal`
Modal dialog serbaguna berbasis Alpine.js dengan transisi halus dan penanganan aksesibilitas ARIA dialog.

**Props**:
- `show`: (string|null) Nama variabel boolean state Alpine.js (misal: `'showModal'`).
- `title`: (string|null) Judul modal pada header.
- `maxWidth`: `'sm'`, `'md'` (default), `'lg'`, `'xl'`, `'2xl'`, `'3xl'`, `'4xl'`.
- `closeAction`: (string|null) Ekspresi Alpine.js saat modal ditutup (misal: `'showModal = false'`).
- `bodyClass`: (string, default: `'p-6'`).

**Contoh Penggunaan**:
```blade
<x-ui.modal show="openModal" title="Tambah Data Riwayat" maxWidth="lg" closeAction="openModal = false">
    <form class="space-y-4">
        <x-form.input name="nomor_sk" label="Nomor SK" required />
    </form>
    
    <x-slot:footer>
        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" size="sm" @click="openModal = false">Batal</x-ui.button>
            <x-ui.button variant="primary" size="sm" type="submit">Simpan</x-ui.button>
        </div>
    </x-slot:footer>
</x-ui.modal>
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

### 5. `x-form.checkbox`
Input kotak centang pilihan boolean.
```blade
<x-form.checkbox 
    name="is_active" 
    label="Status Aktif" 
    :checked="true" 
/>
```

### 6. `x-form.file-upload`
Area upload berkas/dokumen pendukung.
```blade
<x-form.file-upload 
    name="file_sk" 
    label="Dokumen SK (PDF/Gambar)" 
    accept=".pdf,.jpg,.jpeg,.png" 
    required 
/>
```

---

## 🔒 5. Session Management & Keamanan Autentikasi

### Mekanisme Session Timeout (Server-Side Enforced):
Sistem SIMPEG menerapkan penegakan *idle timeout* berbasis request di sisi server melalui middleware `App\Http\Middleware\SessionTimeoutMessage`:

1. **`SIMPEG_SESSION_IDLE_TIMEOUT=30`**:
   - Sesuai spesifikasi **US-1.3** dan keputusan kanonis **K-US-03**, batas waktu tidak aktif (*idle*) ditetapkan sebesar **30 menit** berdasarkan selisih waktu `now() - last_activity_at`.
   - Evaluasi timeout terjadi pada **request HTTP berikutnya yang masuk ke aplikasi**.
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
