# Runbook QA Browser — Switch Role (US-1.6)

**Lampiran PR #218** · Cabang `feat/switch-role`

Checklist uji manual berbasis browser untuk melengkapi bukti penyelesaian US-1.6 (butir **QA browser** pada definisi penyelesaian story). **Status eksekusi:** menunggu pelaksanaan oleh tim QA/demo di environment yang tersedia — langkah di bawah adalah prosedur resmi yang harus dijalankan dan hasilnya dilampirkan sebagai bukti sebelum US-1.6 ditandai selesai.

## Prasyarat
- Akun Super Admin dengan permission `users.switch_role` (seeded/migrated).
- Data pegawai aktif untuk keempat role tujuan (Admin Kepegawaian, Pimpinan, Kepala Bagian, Pegawai).

## Skenario

### S1 — Switch & indikator simulasi
1. Login sebagai Super Admin ber-permission `users.switch_role`.
2. Klik avatar → menu **Simulasi Role** → pilih **Switch ke Pegawai**.
3. Harapan: redirect ke dashboard; indikator **"Simulasi: Pegawai"** tampil di header (desktop & mobile) beserta tombol **Revert**.
4. Submenu switch **tidak** tampil lagi selama simulasi (hanya "Kembalikan Role Asli").

### S2 — Role efektif di permukaan UI
5. Saat simulasi Pegawai: menu/sidebar hanya menampilkan menu Pegawai; tombol cuti "Ajukan Cuti Baru" tampil (permission role tujuan); menu admin tidak tampil.
6. Ulangi untuk target **Pimpinan**, **Kepala Bagian**, **Admin Kepegawaian** — pastikan menu & permission mengikuti role tujuan.

### S3 — Persistensi lintas logout/login
7. Saat simulasi aktif: logout, lalu login kembali sebagai akun yang sama.
8. Harapan: simulasi **tetap aktif** (temporary_role persisten) hingga revert.

### S4 — Revert
9. Klik **Revert** (header) atau **Kembalikan Role Asli** (dropdown).
10. Harapan: indikator hilang; menu kembali ke Super Admin; halaman admin dapat diakses.

### S5 — Audit
11. Buka menu Audit (Super Admin) setelah switch + revert.
12. Harapan: dua baris tercatat — event `SWITCH_ROLE` dan `REVERT_ROLE`, berisi aktor, role asal, role target, waktu, dan konteks simulasi.

### S6 — Fail-closed matriks
13. Coba akses langsung POST `/switch-role` dengan `target_role=super_admin` / role sama / role di luar matriks (tanpa UI).
14. Harapan: ditolak (validasi/hierarki), tidak ada perubahan state, tidak ada baris SWITCH_ROLE.

## Catatan
- Hasil pelaksanaan (tanggal, environment/browser, screenshot bila perlu, dan siapa yang menjalankan) dilampirkan sebagai bukti QA browser US-1.6.
- QA browser tidak dapat dieksekusi dari environment pengembangan otomatis ini (tidak ada browser/UI runner); checklist ini adalah prosedur resmi untuk tim.
