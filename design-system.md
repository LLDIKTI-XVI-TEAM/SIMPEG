# SIMPEG Design System — Implementation Guide

> Panduan implementasi UI SIMPEG yang diturunkan dari
> [`DESIGN.md`](DESIGN.md). Dokumen tersebut adalah kontrak desain kanonis;
> jika terdapat perbedaan, approval konsumen dan `DESIGN.md` yang berlaku.

> Gunakan panduan ini saat membuat atau memodifikasi markup, tetapi jangan
> menyalin kelas contoh apabila komponen `x-ui.*` yang sesuai sudah tersedia.

---

## 📋 Deskripsi

Design system dan panduan implementasi UI untuk aplikasi **SIMPEG** (Sistem Informasi Manajemen Kepegawaian) berbasis:

| Teknologi | Versi |
|-----------|-------|
| Laravel | 12 |
| Blade Templates | — |
| Tailwind CSS | v4 |
| Vite | latest |
| PostgreSQL | 17 |
| Podman Containers | — |

---

## 🎯 Prinsip Desain

Keputusan desain normatif—termasuk identitas, token semantik, tipografi, motion,
dan pengecualian yang disetujui—berada di [`DESIGN.md`](DESIGN.md). Bagian ini
merangkum konsekuensinya saat mengimplementasikan antarmuka.

**Physical scene:** Staf HR di kantor pemerintahan, bekerja di laptop saat jam kerja di ruangan yang terang. Antarmuka harus *legible*, *scannable*, dan fungsional — bukan dekoratif.

**Color strategy:** Restrained — tinted neutrals dengan satu primary kuat (institutional blue) yang membawa otoritas. Secondary gold digunakan sparingly sebagai aksen.

- ✅ Tema **light only** — tidak ada dark mode
- ✅ Prioritaskan **keterbacaan** dan **kepadatan informasi**
- ✅ Tampilan **profesional dan enterprise** — formal tapi tidak kaku
- ✅ Hindari efek visual yang berlebihan
- ✅ Pertahankan **spacing konsisten** dan perilaku komponen
- ✅ Semua UI harus **mobile responsive**
- ✅ **Reuse komponen** yang sudah ada

---

## 📂 Referensi Kanonis

Gunakan referensi berikut secara berurutan:

1. [`DESIGN.md`](DESIGN.md) untuk keputusan desain dan pengecualian yang
   disetujui konsumen.
2. `resources/css/app.css` untuk nilai token runtime yang dikompilasi.
3. `resources/views/components/ui/` untuk API dan perilaku komponen yang
   benar-benar dipakai aplikasi.

Gunakan design token Tailwind yang sudah ada. Jangan hardcode warna di dalam
komponen Blade jika token tersedia, kecuali pengecualian yang tercatat dalam
`DESIGN.md`.

---

## 🎨 Referensi Token

Semua token didefinisikan sebagai CSS custom properties di
`resources/css/app.css` via Tailwind CSS v4 `@theme {}`. Nilai dan peran
semantiknya ditetapkan oleh [`DESIGN.md#2-color`](DESIGN.md#2-color); tabel di
bawah adalah referensi kelas implementasi, bukan definisi kedua yang mandiri.

### Brand Colors

| Token | CSS Variable | Kelas Tailwind | Peran implementasi |
|-------|-------------|---------------|---------------------|
| Primary | `--color-primary` | `bg-primary` / `text-primary` / `border-primary` | Brand, CTA, active state, sidebar aktif |
| Secondary | `--color-secondary` | `bg-secondary` / `text-secondary` | Aksen emas, badge sekunder, highlight |

### Surface Colors

| Token | CSS Variable | Kelas Tailwind | Peran implementasi |
|-------|-------------|---------------|---------------------|
| Page | `--color-page` | `bg-page` | Background body |
| Surface | `--color-surface` | `bg-surface` | Card, panel, navbar, sidebar |

### Text Colors

| Token | CSS Variable | Kelas Tailwind | Peran implementasi |
|-------|-------------|---------------|---------------------|
| Ink | `--color-ink` | `text-ink` | Body text, heading |
| Muted | `--color-muted` | `text-muted` | Label, metadata, placeholder |

### Status Colors

| Token | CSS Variable | Kelas Tailwind | Penggunaan |
|-------|-------------|---------------|------------|
| Success | `--color-success` | `bg-success` / `text-success` | Aktif, berhasil, status oke |
| Warning | `--color-warning` | `bg-warning` / `text-warning` | Peringatan, H-60, pending |
| Danger | `--color-danger` | `bg-danger` / `text-danger` | Error, H-30, aksi destruktif |
| Info | `--color-info` | `bg-info` / `text-info` | Informasi, H-90 |

### Utility Colors

| Token | CSS Variable | Kelas Tailwind | Peran implementasi |
|-------|-------------|---------------|---------------------|
| Border | `--color-border` | `border-border` | Divider, border input, border card |
| Soft | `--color-soft` | `bg-soft` | Header tabel, hover state, fill subtle |

---

## ✅ Kelas Tailwind yang Diizinkan

### Gunakan (Allowed):

```
bg-primary       text-primary      border-primary
bg-secondary     text-secondary
bg-page          bg-surface
text-ink         text-muted
bg-success       text-success
bg-warning       text-warning
bg-danger        text-danger
bg-info          text-info
border-border
bg-soft
```

### ❌ Jangan Gunakan (Forbidden):

```
bg-blue-600      bg-yellow-500     bg-red-500
text-gray-500    border-gray-300
```

### ❌ Jangan Hardcode Warna:

```html
<!-- SALAH ❌ -->
class="bg-[#122E92]"
class="bg-blue-600"

<!-- BENAR ✅ -->
class="bg-primary"
```

---

## 🔤 Typography

### Font Family

- **Token**: `font-sans`
- **Font**: `Poppins` (Google Fonts, dikonfigurasi di `app.css`)
- **Jangan** import font tambahan lainnya

```css
/* resources/css/app.css */
--font-sans: 'Poppins', ui-sans-serif, system-ui, sans-serif;
```

### Hierarki Heading

| Elemen | Tag | Kelas Tailwind | Penggunaan |
|--------|-----|---------------|-----------|
| Page Title | `<h1>` | `text-2xl font-semibold text-ink` | Satu per halaman |
| Section Title | `<h2>` | `text-lg font-semibold text-ink` | Judul seksi utama |
| Subsection | `<h3>` | `text-sm font-bold text-ink` | Judul card atau subsection |
| Body | `<p>` | `text-sm text-ink` | Konten utama dan tabel |
| Secondary | — | `text-xs text-muted` | Label, metadata, caption |
| Table Header | `<th>` | `text-xs font-semibold uppercase tracking-wide text-muted` | Header kolom tabel |
| Badge / Tag | `<span>` | `text-xs font-semibold` | Dipasangkan dengan warna status |

- **Line length:** Batasi 65–75ch pada blok konten panjang.
- **Heading:** gunakan `text-wrap: balance` pada h1–h3 agar baris rata
- **Prose:** gunakan `text-wrap: pretty` untuk kurangi orphan word
- **Kontras minimum:** body text ≥ 4.5:1 terhadap background — teks muted jangan terlalu terang
- **Jangan** pasang dua font dari keluarga geometris yang mirip

---

## 📐 Layout Standards

### Layout Shell — Diagram

```
┌──────────────────────────────────────────────────────────────┐
│  SIDEBAR (w-64, fixed)     │  NAVBAR (h-16, sticky top)      │
│  ─────────────────────     ├────────────────────────────────  │
│  [Logo] SIMPEG             │  [Page Title]  [🔔] [👤] [Out] │
│  Kepegawaian               │                                  │
│  ─────────────────────     │  Flash Messages (jika ada)       │
│  • Dashboard               │  ─────────────────────────────── │
│  • Data Pegawai            │                                  │
│  • Hari Libur              │  <main>                          │
│  • Cuti                    │    max-w-7xl mx-auto             │
│  • Dokumen                 │    px-4 py-6 lg:px-6             │
│  • Audit Log               │                                  │
│  ─────────────────────     │                                  │
│  [👤 User Info]            │  </main>                         │
└──────────────────────────────────────────────────────────────┘
```

**Mobile (< lg):** Sidebar hidden, toggle via hamburger di navbar. Overlay gelap menutup konten saat sidebar terbuka.

---

## 🏗️ Layout Master

Dua layout tersedia di `resources/views/layouts/`:

| File | Digunakan untuk |
|------|----------------|
| `app.blade.php` | Semua halaman yang memerlukan autentikasi (dashboard, CRUD, dll) |
| `auth.blade.php` | Halaman tanpa sidebar: login, error, unregistered |

### `layouts/app.blade.php` — Layout Utama

**File:** [`resources/views/layouts/app.blade.php`](resources/views/layouts/app.blade.php)

**Dependencies:** Alpine.js (untuk toggle sidebar mobile)

**Cara penggunaan:**

```blade
{{-- Gunakan @extends --}}
@extends('layouts.app')

@section('title', 'Data Pegawai')

@section('content')
    <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
    {{-- ... konten halaman ... --}}
@endsection
```

**Atau dengan anonymous component slot:**

```blade
<x-layouts.app title="Data Pegawai">
    <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
</x-layouts.app>
```

**Props / Section yang tersedia:**

| Nama | Tipe | Default | Keterangan |
|------|------|---------|-----------|
| `$title` | string | `'Dashboard'` | Judul di tab browser dan navbar |
| `$description` | string | `'Sistem Informasi...'` | Meta description untuk SEO |
| `@stack('head')` | stack | — | Inject CSS/meta tambahan di `<head>` |
| `$slot` / `@section('content')` | slot | — | Konten halaman utama |
| `@stack('scripts')` | stack | — | Inject JS tambahan sebelum `</body>` |

**Struktur layout:**

```
html.h-full
└── body.h-full.bg-page.font-sans
    └── div.flex.min-h-screen          ← app shell, x-data="{ sidebarOpen: false }"
        ├── div.overlay                 ← mobile overlay (lg:hidden)
        ├── aside.w-64                  ← SIDEBAR
        │   ├── div.h-16               ← brand (logo + nama)
        │   ├── nav.flex-1             ← menu items (7 item)
        │   └── div.shrink-0           ← user info footer
        └── div.flex.flex-col.flex-1   ← main column
            ├── header.h-16            ← NAVBAR
            │   ├── div                ← kiri: hamburger + page title
            │   └── div                ← kanan: notif + user + logout
            ├── div.flash-messages     ← success/error/warning/info (opsional)
            └── main.flex-1            ← PAGE CONTENT
                └── div.max-w-7xl      ← container
                    └── {{ $slot }}
```

**Fitur bawaan:**

- ✅ Sidebar toggle mobile dengan Alpine.js (`sidebarOpen`)
- ✅ Active state otomatis via `request()->routeIs()`
- ✅ Flash messages (success / error / warning / info) dengan icon
- ✅ Notification bell dengan unread count badge
- ✅ User avatar (initial huruf pertama nama)
- ✅ Form logout dengan CSRF
- ✅ Font Poppins + Vite assets sudah terload
- ✅ `@stack('head')` dan `@stack('scripts')` tersedia

---

### `layouts/auth.blade.php` — Layout Autentikasi

**File:** [`resources/views/layouts/auth.blade.php`](resources/views/layouts/auth.blade.php)

**Cara penggunaan:**

```blade
<x-layouts.auth title="Masuk" heading="Selamat Datang">
    {{-- Form login atau konten auth --}}
    <p class="text-sm text-muted text-center">
        Silakan masuk menggunakan akun instansi Anda.
    </p>
    <a href="{{ route('login') }}" class="mt-4 inline-flex items-center ...">
        Masuk dengan SSO
    </a>
</x-layouts.auth>
```

**Props yang tersedia:**

| Nama | Tipe | Default | Keterangan |
|------|------|---------|-----------|
| `$title` | string | `'Login'` | Judul di tab browser |
| `$heading` | string | `'Sistem Informasi...'` | Heading di atas card |
| `$description` | string | `'Sistem...'` | Meta description |
| `$slot` | slot | — | Konten di dalam card |

**Struktur layout:**

```
body.bg-page
└── div.flex.min-h-screen.items-center.justify-center
    ├── div.mb-8                    ← brand header (icon + nama)
    ├── div.w-full.max-w-md        ← card container
    │   └── div.rounded-lg.border  ← card (p-8 shadow-sm)
    │       └── {{ $slot }}
    └── p.mt-6                     ← footer copyright
```

---

### Contoh Halaman Lengkap Menggunakan Layout

> Contoh di bagian ini menjelaskan komposisi halaman. Pada source aplikasi,
> gunakan komponen `x-ui.*` atau `x-form.*` yang tersedia; rangkaian kelas
> Tailwind pada contoh lama tidak menjadi kontrak visual baru.

#### Dashboard

```blade
<x-layouts.app title="Dashboard">

    {{-- Page Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink" style="text-wrap: balance">Dashboard</h2>
            <p class="mt-1 text-sm text-muted">Selamat datang, {{ auth()->user()->name }}</p>
        </div>
    </div>

    {{-- Stat Cards --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
        {{-- ... kartu statistik ... --}}
    </div>

</x-layouts.app>
```

#### Halaman CRUD (Index)

```blade
<x-layouts.app title="Data Pegawai">

    {{-- Page Header --}}
    <div class="mb-6 flex items-center justify-between">
        <h2 class="text-2xl font-semibold text-ink">Data Pegawai</h2>
        <a href="{{ route('pegawai.create') }}"
           class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
            + Tambah Pegawai
        </a>
    </div>

    {{-- Table Card --}}
    <div class="rounded-lg border border-border bg-surface shadow-sm overflow-hidden">
        <table class="w-full">
            {{-- ... isi tabel ... --}}
        </table>
    </div>

</x-layouts.app>
```

#### Halaman Form

```blade
<x-layouts.app title="Tambah Pegawai">

    {{-- Breadcrumb --}}
    <nav class="mb-4 flex items-center gap-2 text-sm text-muted">
        <a href="{{ route('pegawai.index') }}" class="hover:text-ink">Data Pegawai</a>
        <span>/</span>
        <span class="text-ink font-medium">Tambah</span>
    </nav>

    {{-- Form Card --}}
    <x-ui.card padding="lg">
        <h2 class="mb-6 text-xl font-semibold text-ink">Tambah Pegawai Baru</h2>
        <form method="POST" action="{{ route('pegawai.store') }}">
            @csrf
            {{-- ... field form ... --}}
            <div class="mt-6 flex items-center gap-3">
                <x-ui.button type="submit" variant="primary">Simpan</x-ui.button>
                <x-ui.button href="{{ route('pegawai.index') }}" variant="secondary">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>

</x-layouts.app>
```

### Spacing Scale

Base unit: `0.25rem` (Tailwind default).

| Kelas | Nilai | Penggunaan |
|-------|-------|-----------|
| `p-6` | 1.5rem | Card padding, section padding |
| `gap-4` | 1rem | Grid gap kecil |
| `gap-6` | 1.5rem | Grid gap standar antar section |
| `px-6 py-6` | 1.5rem | Page container padding |
| `px-4 py-3` | — | Table cell compact |
| `px-5 py-3` | — | Button sizing standar |
| `px-4 py-2.5` | — | Form input sizing |

> **Variasikan spacing untuk rhythm.** Jangan gunakan satu nilai spacing untuk semua elemen.

---

## 🧩 Standar Komponen

Selalu periksa source komponen sebelum mengubah kontraknya. Tabel ini mencatat
entry point yang dipakai developer; gaya dan aksesibilitas komponen harus tetap
mematuhi [`DESIGN.md`](DESIGN.md).

| Komponen | Tanggung jawab | Aturan pemakaian |
|----------|----------------|------------------|
| `x-ui.button` | Action, link, dan state disabled | Gunakan varian/ukuran yang tersedia; jangan membuat button berkelas baru tanpa kebutuhan yang disetujui |
| `x-ui.card` | Surface dashboard, form, tabel, panel | Gunakan sebagai wadah; jangan nested tanpa alasan struktural |
| `x-ui.stat-card` | Ringkasan metrik dan link ke tindak lanjut | Gunakan label, nilai, icon, serta meta yang singkat dan bermakna |
| `x-ui.table` | Tabel data responsif | Bungkus dengan `overflow-x-auto`, gunakan header semantik serta empty state |
| `x-ui.badge` | Severity dan status | Selalu tampilkan teks status, bukan warna saja |
| `x-ui.tabs` | Navigasi sub-halaman | Pertahankan relasi tab/panel dan navigasi keyboard |

### Buttons

Gunakan `x-ui.button` untuk action standar. Komponen saat ini menyediakan
varian `primary`, `secondary`, `muted`, `danger`, `danger-solid`, `success`,
`success-solid`, `warning`, `warning-solid`, `ghost`, dan `link`; ukuran `xs`,
`sm`, `md`, `lg`, serta `icon`.
Rujuk source komponen untuk API yang aktual saat menambah varian baru.

```blade
<x-ui.button variant="primary" type="submit">Simpan</x-ui.button>
<x-ui.button variant="secondary" href="{{ route('data-pegawai') }}">Batal</x-ui.button>
<x-ui.button variant="danger-solid" type="submit">Hapus</x-ui.button>
```

Komponen menangani state hover, focus, active, dan disabled. Hindari menyalin
kelas button ke view baru kecuali ada kebutuhan yang sudah disetujui.

### Card Component

Gunakan `x-ui.card` sebagai wadah standar dashboard, form, tabel, dan panel
konten. Appearance internalnya—termasuk radius, shadow, padding, serta variant—
dikontrol oleh komponen dan mencerminkan keputusan yang telah disetujui.

```blade
<x-ui.card padding="lg">
    {{-- konten card --}}
</x-ui.card>
```

**Aturan card:**

- Jangan menduplikasi rangkaian kelas card langsung di halaman baru.
- **Tidak boleh nested cards** tanpa alasan struktural yang disetujui.
- Jika appearance komponen berubah, perbarui kontrak di `DESIGN.md` dan contoh
  ini bersama-sama.

### Badge Component

Pola: `rounded-full bg-{STATUS}/10 text-{STATUS} px-3 py-1 text-xs font-semibold`

```html
{{-- Success --}}
<span class="rounded-full bg-success/10 text-success px-3 py-1 text-xs font-semibold">Aktif</span>

{{-- Warning --}}
<span class="rounded-full bg-warning/10 text-warning px-3 py-1 text-xs font-semibold">Menunggu</span>

{{-- Danger --}}
<span class="rounded-full bg-danger/10 text-danger px-3 py-1 text-xs font-semibold">Nonaktif</span>

{{-- Info --}}
<span class="rounded-full bg-info/10 text-info px-3 py-1 text-xs font-semibold">Informasi</span>

{{-- Secondary --}}
<span class="rounded-full bg-secondary/10 text-secondary px-3 py-1 text-xs font-semibold">Lainnya</span>
```

#### Pola Badge Dinamis — Static Mapping (WAJIB)

```blade
@php
$statusClasses = [
    'aktif'     => 'bg-success/10 text-success',
    'nonaktif'  => 'bg-danger/10 text-danger',
    'menunggu'  => 'bg-warning/10 text-warning',
    'info'      => 'bg-info/10 text-info',
];
$kelas = $statusClasses[$pegawai->status] ?? 'bg-soft text-muted';
@endphp
<span class="rounded-full px-3 py-1 text-xs font-semibold {{ $kelas }}">
    {{ $pegawai->status }}
</span>
```

> ⚠️ **JANGAN PERNAH** gunakan string interpolasi dinamis seperti `"bg-{{ $color }}-500"` — Tailwind tidak dapat mendeteksi kelas yang dibangun secara dinamis.

---

## 📝 Forms

### Label

```html
<label class="text-sm font-semibold text-ink">Nama Pegawai</label>
```

### Input Text

```html
<input
    type="text"
    class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
    placeholder="Masukkan nama..."
>
```

### Select / Dropdown

```html
<select class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
    <option>Pilih opsi...</option>
</select>
```

### Textarea

```html
<textarea
    rows="4"
    class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary resize-y"
></textarea>
```

### Error State

```html
<input class="w-full rounded-lg border border-danger bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-danger/20">
<p class="mt-1 text-xs text-danger">Kolom ini wajib diisi.</p>
```

### Form Group Lengkap

```html
<div class="space-y-1">
    <label class="text-sm font-semibold text-ink" for="nama">
        Nama Pegawai
        <span class="text-danger ml-0.5">*</span>
    </label>
    <input
        id="nama"
        type="text"
        name="nama"
        class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
    >
    @error('nama')
        <p class="text-xs text-danger">{{ $message }}</p>
    @enderror
</div>
```

**Ringkasan focus states:**

| State | Kelas |
|-------|-------|
| Normal | `border-border` |
| Focus | `focus:border-primary focus:ring-2 focus:ring-primary/20` |
| Error | `border-danger focus:ring-2 focus:ring-danger/20` |

---

## 📊 Tables

### Struktur Tabel Standar

```blade
<x-ui.card padding="none" class="overflow-hidden">
    <div class="overflow-x-auto">
        <x-ui.table caption="Daftar pegawai">
            <x-ui.table-head>
                <x-ui.table-row>
                    <x-ui.table-th>NIP</x-ui.table-th>
                    <x-ui.table-th>Nama</x-ui.table-th>
                    <x-ui.table-th>Status</x-ui.table-th>
                    <x-ui.table-th>Aksi</x-ui.table-th>
                </x-ui.table-row>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse($pegawai as $p)
                    <x-ui.table-row>
                        <x-ui.table-td class="font-mono text-muted">{{ $p->nip }}</x-ui.table-td>
                        <x-ui.table-td class="font-medium">{{ $p->nama }}</x-ui.table-td>
                        <x-ui.table-td><x-ui.badge variant="success">{{ $p->status }}</x-ui.badge></x-ui.table-td>
                        <x-ui.table-td><x-ui.button variant="link" href="{{ route('pegawai.show', $p) }}">Detail</x-ui.button></x-ui.table-td>
                    </x-ui.table-row>
                @empty
                    <x-ui.table-row>
                        <x-ui.table-td colspan="4"><x-ui.empty-state icon="none" title="Tidak ada data pegawai." /></x-ui.table-td>
                    </x-ui.table-row>
                @endforelse
            </x-ui.table-body>
        </x-ui.table>
    </div>
</x-ui.card>
```

**Aturan tabel:**

- Gunakan `divide-y divide-border` untuk pemisah baris
- Hindari border yang terlalu tebal
- Gunakan compact spacing (`px-4 py-3`)
- Header selalu uppercase dengan `text-muted` dan `bg-soft`

---

## 🏠 Dashboard Guidelines

### Kartu Statistik (4 Kartu Utama)

Layout: `grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6`

| Kartu | Warna Angka | Warna Icon BG |
|-------|------------|--------------|
| Total Pegawai | `text-primary` | `bg-primary/10` |
| Total Cuti | `text-warning` | `bg-warning/10` |
| Akan Pensiun | `text-danger` | `bg-danger/10` |
| Dokumen Kadaluarsa | `text-danger` | `bg-danger/10` |

> ⚠️ Angka statistik untuk status bahaya **tidak** menggunakan `text-primary`. Warna angka mengikuti status — danger stat pakai `text-danger`.

```blade
<section class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan utama">
    <x-ui.stat-card label="Total Pegawai" value="{{ $totalPegawai }}" variant="primary" size="lg" accent />
    <x-ui.stat-card label="Total Cuti" value="{{ $totalCuti }}" variant="warning" size="lg" accent />
    <x-ui.stat-card label="Akan Pensiun" value="{{ $akanPensiun }}" variant="danger" size="lg" accent />
    <x-ui.stat-card label="Dokumen Kadaluarsa" value="{{ $dokumenKadaluarsa }}" variant="danger" size="lg" accent />
</section>
```

---

## 🔔 Notification Guidelines

### Status Mapping Notifikasi

| Notifikasi | Kondisi | Status | Kelas Badge |
|-----------|---------|--------|-------------|
| H-90 | 90 hari sebelum | Info | `bg-info/10 text-info` |
| H-60 | 60 hari sebelum | Warning | `bg-warning/10 text-warning` |
| H-30 | 30 hari sebelum | Danger | `bg-danger/10 text-danger` |

### Notification Bell di Navbar

```html
<div class="relative">
    <button id="notif-btn" class="relative rounded-lg p-2 text-muted hover:bg-soft transition-colors">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>

        {{-- Unread Count Badge --}}
        @if($unreadCount > 0)
            <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-xs font-bold text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>
</div>
```

---

## 🗂️ Sidebar Guidelines

### Struktur Menu

```
Dashboard
Data Pegawai
Hari Libur
Cuti
Dokumen
Audit Log
```

### Implementasi Sidebar

```html
<aside class="w-64 min-h-screen bg-surface border-r border-border flex flex-col">

    {{-- Brand --}}
    <div class="flex items-center gap-3 px-6 py-5 border-b border-border">
        <div class="h-8 w-8 rounded bg-primary flex items-center justify-center">
            <span class="text-white text-xs font-bold">S</span>
        </div>
        <div>
            <p class="text-sm font-bold text-primary">SIMPEG</p>
            <p class="text-xs text-muted">Kepegawaian</p>
        </div>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 px-4 py-4 space-y-1">
        @php
        $menus = [
            ['label' => 'Dashboard',    'route' => 'dashboard'],
            ['label' => 'Data Pegawai', 'route' => 'pegawai.index'],
            ['label' => 'Hari Libur',   'route' => 'hari-libur.index'],
            ['label' => 'Cuti',         'route' => 'cuti.index'],
            ['label' => 'Dokumen',      'route' => 'dokumen.index'],
            ['label' => 'Audit Log',    'route' => 'audit.index'],
        ];
        @endphp

        @foreach($menus as $menu)
            @php
            $isActive = request()->routeIs($menu['route'] . '*');
            $kelas = $isActive
                ? 'bg-primary text-white'
                : 'text-muted hover:bg-soft hover:text-ink';
            @endphp
            <a href="{{ route($menu['route']) }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors {{ $kelas }}">
                {{ $menu['label'] }}
            </a>
        @endforeach
    </nav>

</aside>
```

**Aturan sidebar:**

| State | Kelas |
|-------|-------|
| Aktif | `bg-primary text-white` |
| Tidak Aktif | `text-muted hover:bg-soft hover:text-ink` |
| Padding item | `px-3 py-2.5` |
| Sidebar mobile | Hidden, toggle via navbar button |

---

## 🔝 Navbar Guidelines

### Spesifikasi

| Properti | Nilai |
|---------|-------|
| Tinggi | `h-16` (64px) |
| Background | `bg-surface` |
| Border bawah | `border-b border-border` |

### Implementasi Navbar

```html
<header class="h-16 bg-surface border-b border-border flex items-center justify-between px-6">

    {{-- Kiri: Judul Halaman --}}
    <h1 class="text-lg font-semibold text-ink">{{ $pageTitle ?? 'Dashboard' }}</h1>

    {{-- Kanan: Notification + Profile + Logout --}}
    <div class="flex items-center gap-3">

        {{-- Notification Bell --}}
        <button class="relative rounded-lg p-2 text-muted hover:bg-soft transition-colors">
            {{-- icon lonceng --}}
            @if($unreadCount > 0)
                <span class="absolute -top-1 -right-1 h-4 w-4 rounded-full bg-danger text-xs font-bold text-white flex items-center justify-center">
                    {{ $unreadCount }}
                </span>
            @endif
        </button>

        {{-- Divider --}}
        <div class="h-6 w-px bg-border"></div>

        {{-- User Profile --}}
        <div class="flex items-center gap-2">
            <div class="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center">
                <span class="text-xs font-bold text-primary">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </span>
            </div>
            <div class="hidden sm:block">
                <p class="text-sm font-semibold text-ink">{{ auth()->user()->name }}</p>
                <p class="text-xs text-muted">{{ auth()->user()->email }}</p>
            </div>
        </div>

        {{-- Logout --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-muted hover:bg-soft hover:text-danger transition-colors">
                Keluar
            </button>
        </form>

    </div>
</header>
```

---

## 🔔 Alert / Flash Message

```html
{{-- Success --}}
@if(session('success'))
    <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 flex items-center gap-3">
        <p class="text-sm font-medium text-success">{{ session('success') }}</p>
    </div>
@endif

{{-- Error --}}
@if(session('error'))
    <div class="rounded-lg border border-danger/20 bg-danger/10 px-4 py-3 flex items-center gap-3">
        <p class="text-sm font-medium text-danger">{{ session('error') }}</p>
    </div>
@endif

{{-- Warning --}}
@if(session('warning'))
    <div class="rounded-lg border border-warning/20 bg-warning/10 px-4 py-3 flex items-center gap-3">
        <p class="text-sm font-medium text-warning">{{ session('warning') }}</p>
    </div>
@endif
```

---

## 🎬 Motion & Animasi

Ini adalah **productivity tool** — motion harus minimal, bertujuan, dan mengikuti
kontrak pada [`DESIGN.md#6-motion--interaction`](DESIGN.md#6-motion--interaction).

| Aturan | Keterangan |
|--------|-----------|
| Transition baru | `transition-colors` sebagai default; `opacity` atau `transform` hanya untuk menyampaikan state |
| Durasi baru | 150–200 ms; gunakan timing bawaan komponen jika komponen sudah tersedia |
| Button hover | `hover:opacity-90` pada primary & danger button |
| Entrance animation | **Tidak ada** — jangan gate content di balik animasi |
| `prefers-reduced-motion` | Semua transisi harus di-disable |

```css
@media (prefers-reduced-motion: reduce) {
    * {
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
    }
}
```

---

## 📱 Responsive Rules

### Breakpoint yang Diwajibkan

| Breakpoint | Min-Width | Prefix |
|-----------|-----------|--------|
| Mobile | < 640px | (default) |
| Small | 640px | `sm:` |
| Medium | 768px | `md:` |
| Large | 1024px | `lg:` |
| Extra Large | 1280px | `xl:` |

### Aturan

- ✅ **Mobile-first** approach
- ✅ Tidak boleh ada horizontal overflow pada halaman; tabel atau grafik lebar
  hanya boleh scroll di wrapper lokal yang jelas
- ✅ Tidak boleh ada teks yang terpotong
- ✅ Tidak boleh ada komponen yang saling overlap
- ✅ Sidebar off-canvas di mobile tidak boleh menerima fokus saat tertutup dan
  fokus harus berpindah ke menu saat dibuka
- ✅ Form label selalu di atas input (bukan inline) di mobile
- ✅ Target action mandiri minimal 24 × 24 px; gunakan ukuran 44 × 44 px bila
  ruang memungkinkan pada layar sentuh

### Pola Grid Responsif

```html
{{-- 1 → 2 → 4 kolom --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">

{{-- 1 → 3 kolom --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

{{-- Sidebar + Konten --}}
<div class="flex flex-col lg:flex-row gap-6">
    <aside class="w-full lg:w-64 shrink-0">...</aside>
    <main class="flex-1 min-w-0">...</main>
</div>

{{-- Responsive tanpa breakpoint --}}
<div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr))">
```

---

## 💻 Aturan Kode

### ❌ Jangan Pernah Generate Kelas Dinamis

```blade
{{-- SALAH ❌ --}}
class="bg-{{ $color }}"
class="text-{{ $variant }}-500"
class="border-{{ $color }}-300"
```

### ✅ Gunakan Static Mapping

```blade
{{-- BENAR ✅ --}}
@php
$variantClasses = [
    'primary'   => 'bg-primary text-white',
    'secondary' => 'bg-secondary text-white',
    'success'   => 'bg-success text-white',
    'warning'   => 'bg-warning text-white',
    'danger'    => 'bg-danger text-white',
    'info'      => 'bg-info text-white',
];
@endphp
<button class="{{ $variantClasses[$variant] ?? 'bg-soft text-ink' }}">
    {{ $slot }}
</button>
```

### Struktur Komponen Blade Reusable

```
resources/views/components/
├── ui/
│   ├── button.blade.php
│   ├── card.blade.php
│   ├── badge.blade.php
│   ├── stat-card.blade.php
│   ├── tabs.blade.php
│   └── table*.blade.php
├── form/
│   ├── input.blade.php
│   ├── select.blade.php
│   └── textarea.blade.php
├── layouts/
│   └── app.blade.php
└── pegawai/detail/
    └── *.blade.php
```

### Contoh Pemakaian Button

```blade
<x-ui.button variant="primary" type="submit">Simpan perubahan</x-ui.button>
<x-ui.button variant="secondary" href="{{ route('dashboard') }}">Kembali</x-ui.button>
<x-ui.button variant="danger-solid" type="submit">Hapus data</x-ui.button>
```

Kelas internal `x-ui.button` tidak diduplikasi di dokumen ini. Ubah komponen
langsung hanya ketika kontrak pada `DESIGN.md` dan approval konsumen memang ikut
berubah.

---

## 🚫 Anti-Patterns (Absolut Dilarang)

Yang berikut ini **wajib dihindari** — ini adalah tanda desain yang buruk:

| Anti-Pattern | Mengapa Dilarang |
|-------------|-----------------|
| `bg-[#122E92]` atau raw hex di Blade | Bypass token system, susah di-maintain |
| `bg-blue-600`, `text-gray-500` | Gunakan alias token, bukan Tailwind default palette |
| `"bg-{{ $color }}-500"` string dinamis | Tailwind purge tidak mendeteksi kelas ini |
| **Nested cards** | Card di dalam card selalu salah secara hierarki visual |
| **Side-stripe border** (`border-left` > 1px sebagai aksen warna) | Rewrite dengan full border, background tint, atau icon |
| **Gradient text** (`background-clip: text`) | Dekoratif tanpa makna; gunakan solid color |
| **Glassmorphism dekoratif** | Hanya boleh jika ada tujuan jelas dan fungsional |
| **Hover transform pada `<img>`** | Tidak memberikan informasi; animasikan card bukan gambar |
| **Eyebrow uppercase di setiap section** | Scaffolding reflex AI; gunakan cadence yang berbeda |
| **Numbered section markers (01/02/03)** | Hanya gunakan jika urutan memang membawa informasi |
| **Identical card grid** (icon+heading+text berulang) | Buat variasi visual berdasarkan konten actual |
| **Teks yang overflow container** | Test setiap heading di semua breakpoint |

Pengecualian yang telah disetujui—termasuk hero banner dashboard—tercatat di
[`DESIGN.md#8-approved-exceptions`](DESIGN.md#8-approved-exceptions). Pengecualian
tersebut tidak boleh diperluas ke surface lain tanpa approval desain baru.

---

## 📁 Lokasi File Penting

```
resources/
├── css/
│   └── app.css                  ← Design tokens (SOURCE OF TRUTH)
└── views/
    ├── components/              ← Reusable Blade components
    ├── layouts/
    │   ├── app.blade.php        ← Layout utama (sidebar + navbar)
    │   └── auth.blade.php       ← Layout autentikasi
    ├── auth/                    ← Halaman login, dll
    ├── dashboard.blade.php      ← Dashboard utama
    ├── pegawai/                 ← Modul Data Pegawai
    ├── cuti/                    ← Modul Cuti
    ├── dokumen/                 ← Modul Dokumen
    ├── hari-libur/              ← Modul Hari Libur
```

**Context files (dibaca oleh impeccable skill):**

| File | Peran |
|------|-------|
| `PRODUCT.md` *(opsional)* | Konteks strategis: register, users, brand personality, bila proyek memilih untuk menambahkannya |
| `DESIGN.md` | Kontrak desain kanonis: keputusan visual, accessibility, dan exception |
| `design-system.md` | Panduan implementasi turunan: API komponen dan pola Blade/Tailwind |

---

## ✅ Review Checklist

Sebelum submit kode UI, pastikan semua item berikut terpenuhi:

- [ ] Menggunakan `font-sans` (Poppins)
- [ ] Mengikuti `DESIGN.md`; pengecualian yang disetujui konsumen tercatat di sana
- [ ] Hanya menggunakan design token (bukan raw hex / kelas Tailwind default) di luar approved exception
- [ ] Tidak ada warna hardcoded (`bg-[#...]` atau `bg-blue-600`) di luar approved exception
- [ ] Mobile responsive di semua breakpoint (`sm`, `md`, `lg`, `xl`)
- [ ] Tidak ada horizontal overflow
- [ ] Spacing konsisten (`gap-4`, `gap-6`, `p-6`)
- [ ] Button menggunakan `x-ui.button` dengan varian yang tersedia
- [ ] Card menggunakan `x-ui.card` dan tidak nested tanpa alasan struktural
- [ ] Focus state pada semua input (`focus:ring-2 focus:ring-primary/20`)
- [ ] Static mapping untuk kelas dinamis (bukan string interpolasi)
- [ ] Teks heading: `text-wrap: balance`; kontras body text ≥ 4.5:1
- [ ] Motion baru mengikuti `DESIGN.md`; `prefers-reduced-motion` didukung
- [ ] Menggunakan Blade reusable components
- [ ] Sesuai visual identity SIMPEG (biru `#122E92` + emas `#D6AC48`)
- [ ] Kompatibel dengan Laravel 12 + Blade + Tailwind CSS v4
- [ ] Tidak ada anti-pattern dari daftar di atas

---

*Versi 2.0 — SIMPEG v2.6 — Juni 2026 — Terintegrasi dengan DESIGN.md & impeccable skill*
