# Pimpinan Frontend Hybrid Design

## Goal

Menyelaraskan permukaan Blade untuk role Pimpinan dengan kebutuhan Fase 1 tanpa memalsukan aksi yang belum didukung backend.

## Scope

- Halaman Pimpinan: dashboard, data/detail pegawai, cuti/detail cuti, EWS, dan laporan.
- Struktur visual, salinan UI, affordance formulir, aksesibilitas, serta responsivitas.
- Kontrak hybrid untuk tombol export, cetak, pagination, dan tindakan lain yang belum memiliki backend fungsional.

## Out of Scope

- Mengubah approval engine, workflow cuti, saldo cuti, audit, notifikasi, atau query/data source.
- Mengubah route, middleware, model, Action, Service, migration, dan format export backend.
- Menambah custom PDF export.

## Source Requirements

- Pimpinan memiliki dashboard seluruh pegawai, data pegawai read-only, final approver sesuai chain, serta generate/export laporan: `LLDIKTI-DOC/DOCUMENT/PRD-SIMPEG-Fase1-Core.md:217-225`.
- Dashboard Admin/Pimpinan memuat tujuh widget, server-rendered pada setiap load, dan responsif pada desktop/tablet/mobile: `LLDIKTI-DOC/DOCUMENT/User-Stories-SIMPEG-Fase1.md:1213-1237`.
- EWS aktif memiliki trigger termasuk Kontrak PPPK, kolom/filters yang ditentukan, dan link menuju detail pegawai: `LLDIKTI-DOC/DOCUMENT/User-Stories-SIMPEG-Fase1.md:912-922`, `:942-951`.
- Export custom nominatif hanya Excel, dengan kolom terpilih dan filter yang didukung; kolom sensitif tidak boleh ditawarkan: `LLDIKTI-DOC/DOCUMENT/PRD-SIMPEG-Fase1-Core.md:1249-1257` dan `User-Stories-SIMPEG-Fase1.md:1373-1383`.
- Rekap cuti dan riwayat kepangkatan adalah report fixed PDF/Excel, bukan custom PDF: `LLDIKTI-DOC/DOCUMENT/PRD-SIMPEG-Fase1-Core.md:1259-1277`.
- Label keputusan cuti resmi adalah `Disetujui`, `Perubahan`, `Ditangguhkan`, dan `Tidak Disetujui`; Perubahan/Ditangguhkan membawa keterangan: `LLDIKTI-DOC/DOCUMENT/Panduan-Penulisan-Kode-SIMPEG.md:620-648`.
- Form harus memiliki label yang terhubung, status tidak hanya mengandalkan warna, dan primary flows responsif: `AGENTS.md:69-73`.

## Hybrid UI Contract

1. Kontrol hanya aktif jika route dan backend yang ada sudah benar-benar menyelesaikan aksinya.
2. Kontrol yang belum didukung tidak mengirim request, tidak menggunakan `alert()`, dan tidak menampilkan pesan sukses palsu. Kontrol ini disabled serta memiliki teks penjelasan yang terlihat dan dapat dibaca screen reader.
3. Preview atau dashboard yang masih memakai data contoh harus memiliki penanda non-operasional yang jelas. Penanda tersebut dihapus ketika controller menerima data nyata dari backend.
4. Frontend tidak mencoba menghitung status domain, menentukan approver, menyusun report, atau menggantikan validasi server.

## Page Contracts

### Dashboard

- Pertahankan tujuh slot widget yang ditentukan User Story.
- Tambahkan penanda ringkas bahwa angka/preview adalah data contoh selama controller Pimpinan masih hardcoded.
- Semua link yang sudah memiliki destination nyata tetap aktif. Aksi keputusan ringkas yang belum memakai approval engine diganti menjadi affordance non-mutating yang jujur atau dihilangkan.

### Detail Cuti

- Gunakan istilah resmi `Perubahan`, bukan `Disetujui dengan Perubahan`.
- Saat `Perubahan` atau `Ditangguhkan` dipilih, beri indikator visual dan `aria-describedby` bahwa catatan diwajibkan oleh proses backend.
- Tombol keputusan hanya dapat aktif jika endpoint memakai approval engine nyata. Selama endpoint dummy, tampilkan disabled button dengan penjelasan bahwa keputusan final belum tersedia dari halaman ini.

### Detail Pegawai

- Tab `Info Otomatis` harus merender panel yang dapat dibuka dan dapat diakses keyboard.
- Tombol `Cetak Riwayat` tidak boleh mengirim POST ke endpoint dummy; tampilkan disabled state dan penjelasan sampai export Excel nyata tersedia.
- NIK dan NPWP tetap termasking secara presentasi. Keputusan field-level RBAC backend tetap di luar scope.

### EWS

- Tampilkan placeholder kolom `Tanggal Target` dan `Status Eligibility` sebagai `Belum tersedia dari sumber data` bila controller belum memasok nilai tersebut.
- Tampilkan `Kontrak PPPK` di filter event; apabila backend belum memasok data tersebut, hasil kosong menggunakan empty state yang jujur.
- Hapus tautan sort/pagination yang tidak memiliki perilaku server-side dan tampilkan state disabled/informational.
- Semua indikator warna harus disertai teks severity/status.

### Laporan

- **Nominatif custom:** hanya tawarkan Excel; form menampilkan daftar kolom yang diizinkan dan filter yang diminta PRD sebagai disabled controls bila belum didukung backend. Jangan submit endpoint dummy.
- **Rekap Cuti:** periode bulan/tahun tetap aktif hanya bila route export nyata dapat dipakai. Preview diberi penanda data contoh sampai sumber data nyata tersedia.
- **Riwayat Kepangkatan:** jangan menawarkan format atau tombol export yang hanya menghasilkan `alert()`. Tampilkan status ketersediaan fixed report dan penjelasan format PDF/Excel akan aktif setelah route export tersedia. Jangan menyebut custom PDF.

## Accessibility and Responsive Contract

- Semua `<label>` memiliki `for` yang sesuai dengan `id` input/select/textarea.
- Pesan bantuan/wajib/error memakai ID stabil dan terhubung melalui `aria-describedby`.
- Disabled controls menyediakan alasan lewat teks terdekat dan `aria-describedby`; tooltip bukan satu-satunya penjelasan.
- Tabel mempertahankan wrapper horizontal pada mobile, dengan judul/kolom tetap dapat dipahami.
- QA dilakukan pada lebar 375 px, 768 px, dan 1280 px untuk semua halaman sidebar Pimpinan.

## Acceptance Criteria

- Tidak ada `onclick="alert(...)"`, flash success, atau form submit yang berpura-pura menghasilkan report/keputusan ketika backend belum mendukungnya.
- Tidak ada pilihan custom PDF pada UI Pimpinan.
- Cuti memakai empat label resmi dan memberi affordance catatan wajib untuk Perubahan/Ditangguhkan.
- EWS menunjukkan filter Kontrak PPPK, teks status bersama warna, serta placeholder jujur untuk field yang belum datang dari backend.
- Semua form yang disentuh memenuhi label/ID/`aria-describedby` dan layout tetap dapat digunakan pada 375/768/1280 px.

## Deferred Backend Dependencies

- Approval decision Pimpinan melalui `LeaveApprovalService` dan audit/notification.
- Data dashboard, EWS, pegawai, dan preview report dari PostgreSQL.
- Export `.xlsx` custom nominatif, rekap cuti, dan riwayat kepangkatan; fixed PDF export yang didukung PRD.
