# Keputusan Program Studi sebagai Data Master

## Status

Disetujui untuk Fase 1 pada 12 Agustus 2026.

## Keputusan

Program Studi dikelola sebagai referensi `ref_program_studi`. Pengelolaannya
(tambah, ubah, aktif/nonaktif, dan hapus bila belum dipakai) hanya tersedia
untuk role Super Admin.

`employees.program_studi_id` dan `education_histories.program_studi_id`
merujuk ke referensi tersebut. Kolom `prodi_pendidikan_terakhir` dan `jurusan`
tetap dipertahankan sebagai snapshot kompatibilitas data lama.

## Kontrak data

- Tambah/edit pegawai dan riwayat pendidikan menggunakan `program_studi_id`.
  Nama snapshot selalu diturunkan dari referensi, bukan dari input teks bebas.
- Program Studi baru dibuat lebih dahulu pada Data Master. Referensi nonaktif
  tidak dapat dipilih untuk data baru; data lama boleh mempertahankan referensi
  nonaktif yang sudah dipakainya.
- Import tetap menerima snapshot teks sesuai template Fase 1. Nilai yang belum
  cocok dengan referensi disimpan sebagai snapshot dan relasinya `null`; nilai
  itu tidak boleh hilang saat field pegawai lain diperbarui.
- Pengosongan Program Studi pegawai memerlukan intent eksplisit
  `clear_program_studi`; pengosongan riwayat pendidikan membersihkan relasi dan
  snapshotnya.
- Saat nama referensi diubah, snapshot pada seluruh pegawai dan riwayat yang
  terhubung disinkronkan agar detail, profil, dan ekspor konsisten.

Referensi Program Studi belum dibatasi per Jenjang Pendidikan karena belum ada
sumber katalog resmi yang memetakan keduanya. Pemetaan tersebut dapat ditambah
sebagai keputusan dan migrasi terpisah ketika sumbernya disetujui.
