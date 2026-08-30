# SIMPEG Design Contract

> Kontrak desain kanonis untuk SIMPEG. Dokumen ini memuat keputusan visual dan
> perilaku UI yang harus dipatuhi. Panduan pemakaian Blade/Tailwind berada di
> [`design-system.md`](design-system.md).

## 0. Status, Authority & Maintenance

### Hierarki keputusan

1. Kebutuhan dan approval konsumen.
2. Kontrak dalam dokumen ini.
3. Definisi token runtime di `resources/css/app.css` dan kontrak komponen di
   `resources/views/components/ui/`.
4. Contoh pemakaian di `design-system.md`.

Jika implementasi komponen yang telah disetujui konsumen berbeda dari dokumen,
jangan mengubah komponen secara otomatis. Pastikan keputusan yang berlaku, lalu
perbarui dokumen ini dan panduan implementasinya dalam perubahan yang sama.

### Tanggung jawab dokumen

- `DESIGN.md`: keputusan desain, semantic tokens, aturan aksesibilitas, dan
  pengecualian yang disetujui.
- `design-system.md`: API komponen, pola Blade/Tailwind, serta checklist
  implementasi yang diturunkan dari dokumen ini.
- `resources/css/app.css`: nilai token yang benar-benar dikompilasi aplikasi.

Tidak ada dokumen lain yang boleh mendefinisikan ulang nilai token, skala
tipografi, atau aturan motion tanpa merujuk ke kontrak ini.

## 1. Atmosphere & Identity

SIMPEG adalah command center kepegawaian LLDIKTI Wilayah XVI: tenang, formal, dan mudah dipindai saat menangani data administratif. Ciri utamanya adalah permukaan terang berlapis ringan dengan biru institusional sebagai penanda aksi dan fokus, sementara status operasional selalu disampaikan oleh teks serta warna.

## 2. Color

### Palette

| Role | Token | Value | Usage |
| --- | --- | --- | --- |
| Primary | `primary` | `#122E92` | CTA, focus, navigasi aktif |
| Primary hover | `primary-hover` | `#1A3EBC` | Hover CTA |
| Secondary | `secondary` | `#D6AC48` | Aksen institusional terbatas |
| Page | `page` | `#F8FAFC` | Latar halaman |
| Surface | `surface` | `#FFFFFF` | Card, input, panel |
| Ink | `ink` | `#111827` | Teks utama |
| Muted | `muted` | `#6B7280` | Metadata dan bantuan |
| Border | `border` | `#E5E7EB` | Batas dan divider |
| Soft | `soft` | `#F3F4F6` | Surface pasif |
| Success | `success` | `#16A34A` | Status berhasil |
| Warning | `warning` | `#F59E0B` | Perhatian dan pending |
| Danger | `danger` | `#DC2626` | Risiko dan keputusan negatif |
| Info | `info` | `#0284C7` | Informasi |

### Rules

- Gunakan token Tailwind yang berasal dari `resources/css/app.css`; jangan menambah warna ad hoc di view, kecuali pada pengecualian yang disetujui di bawah.
- Warna status selalu ditemani teks status atau severity.
- Primary hanya untuk aksi yang benar-benar tersedia dan fokus keyboard.

## 3. Typography

### Scale

| Level | Existing utility | Usage |
| --- | --- | --- |
| Page title | `text-2xl font-semibold` | Judul halaman |
| Section title | `text-lg font-semibold` | Judul card/section |
| Body | `text-sm` | Isi utama dan tabel |
| Metadata | `text-xs text-muted` | Bantuan, label, data sekunder |
| Status | `text-xs font-semibold` | Badge/status |

### Font Stack

- Primary: `Poppins`, `ui-sans-serif`, `system-ui`, `sans-serif`.
- Mono: system monospace melalui utility `font-mono` untuk NIP atau identifier.

### Rules

- Jangan gunakan teks isi lebih kecil dari `text-xs` pada informasi yang harus dibaca. Label navigasi, metadata, dan kontrol tetap harus memenuhi kontras minimum 4,5:1.
- Metadata tidak menggantikan label form.

## 4. Spacing & Layout

- Base unit: 4 px.
- Existing rhythm: `gap-1`/4 px, `gap-2`/8 px, `gap-3`/12 px, `gap-4`/16 px, `gap-6`/24 px.
- Breakpoints: `sm` 640 px, `md` 768 px, `lg` 1024 px, `xl` 1280 px.
- Form grids mulai satu kolom dan berkembang dengan `sm:`/`md:`/`lg:`.
- Tabel memakai wrapper `overflow-x-auto`, bukan page-level overflow.

## 5. Components

- Gunakan komponen `x-ui.*`, `x-form.*`, dan komponen domain yang tersedia sebelum
  membuat markup berkelas Tailwind baru.
- API, varian, dan kelas internal komponen didokumentasikan di `design-system.md`.
  Jangan menyalin rangkaian kelas dari contoh lama jika komponen sudah tersedia.
- Keputusan visual komponen yang disetujui konsumen dicatat sebagai kontrak;
  contoh implementasi harus mengikuti kontrak tersebut, bukan sebaliknya.

### `x-ui.button`

- **Structure:** button atau anchor dengan variant dan size.
- **Variants:** `primary`, `secondary`, `muted`, `danger`, `danger-solid`,
  `success`, `success-solid`, `warning`, `warning-solid`, `ghost`, `link`.
- **States:** default, hover, focus ring, active scale, disabled.
- **Accessibility:** disabled action selalu memiliki teks penjelasan dekat kontrol dan `aria-describedby`; tooltip tidak menjadi satu-satunya penjelasan.

### `x-ui.tabs` and `x-ui.tab`

- **Structure:** `role="tablist"` berisi `role="tab"` buttons dan panel yang terkait.
- **States:** active/inactive, hover, focus, keyboard activation.
- **Accessibility:** setiap tab memakai `aria-selected`, `aria-controls`; setiap panel memakai `role="tabpanel"` dan `aria-labelledby`.

### Form field

- **Structure:** `<label for>`, input/select/textarea dengan `id`, optional help/error text.
- **States:** default, focus, disabled, required guidance.
- **Accessibility:** help/error memakai ID stabil dan direferensikan melalui `aria-describedby`.

### Data table

- **Structure:** card, horizontal-scroll wrapper, semantic table, empty state.
- **States:** populated, empty, unavailable data, mobile overflow.
- **Accessibility:** header jelas; status/severity tidak hanya berdasarkan warna.

## 6. Motion & Interaction

- Gunakan motion bawaan komponen bila tersedia. Untuk markup baru, gunakan
  `transition-colors` sebagai default; `opacity` atau `transform` hanya boleh
  dipakai untuk menyampaikan perubahan state.
- Durasi interaksi baru berada pada rentang 150–200 ms. Hindari menambahkan
  `transition-all` pada markup halaman baru.
- Fokus keyboard selalu terlihat melalui `focus:ring`.
- `prefers-reduced-motion` sudah dihormati oleh `resources/css/app.css`.
- Jangan gunakan `alert()`, link `href="#"`, atau pagination/sort yang tidak menjalankan perilaku nyata.

## 7. Depth & Surface

- Strategy: mixed, dengan card putih, border `border`, soft surface `soft`, dan shadow ringan yang sudah disediakan component.
- Card/panel memakai `surface`, `border`, serta radius yang konsisten dari `x-ui` components.
- Jangan membuat variasi card baru untuk satu halaman jika `x-ui.card` sudah mencukupi.

## 8. Approved Exceptions

### Hero dashboard

Hero banner dashboard yang telah disetujui konsumen boleh memakai gradient,
ilustrasi SVG, efek transparansi/blur, serta warna internal ilustrasi yang tidak
tersedia sebagai token Tailwind. Pengecualian ini hanya berlaku untuk hero
banner; aturan token-only tetap berlaku pada navigasi, card, tabel, form, badge,
dan action di sekitarnya.

Hero bukan pola generik yang boleh disalin ke halaman lain tanpa approval desain
baru. Saat hero diubah, pertahankan keterbacaan teks, `prefers-reduced-motion`,
dan responsivitasnya.

## 9. Governance

- Perubahan yang mengubah token, tipografi, motion, atau kontrak komponen harus
  memperbarui `DESIGN.md` dan bagian terkait di `design-system.md` dalam PR yang
  sama.
- Contoh kode di `design-system.md` bersifat implementatif, bukan aturan visual
  yang dapat menggantikan kontrak ini.
- Audit UI menilai pengecualian yang tercatat di atas sebagai keputusan desain
  yang disengaja, bukan sebagai pelanggaran token.
