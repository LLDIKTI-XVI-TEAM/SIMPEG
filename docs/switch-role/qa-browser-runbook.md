# Runbook QA Browser — Switch Role (US-1.6)

**Lampiran PR #218** · Cabang `feat/switch-role`

Checklist uji manual berbasis browser untuk melengkapi bukti penyelesaian US-1.6 (butir **QA browser** pada definisi penyelesaian story). **Status eksekusi:** selesai pada 20 Agustus 2026 terhadap source `9f32882aa0dc46f8c98fb34348477b7e577cc4e2`.

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

## Bukti Eksekusi 20 Agustus 2026

- Pelaksana: Codex, pada environment pengembangan lokal Podman SIMPEG.
- Browser: Codex In-app Browser berbasis Chromium 151 pada Windows 10.
- Database aplikasi: PostgreSQL 17 pada container lokal; tidak menggunakan data produksi.
- Source: `feat/switch-role` setelah rebase ke `development`, commit implementasi `9f32882aa0dc46f8c98fb34348477b7e577cc4e2`.
- S1 lulus: switch ke Pegawai menampilkan indikator `Simulasi: Pegawai`, tombol `Revert`, dan menyembunyikan submenu switch lanjutan.
- S2 lulus: menu serta dashboard mengikuti empat role tujuan (`admin_kepegawaian`, `pimpinan`, `kepala_bagian`, dan `pegawai`). Identitas yang tampil tetap akun Super Admin asli. Akses langsung `/user-management` saat simulasi Pegawai menghasilkan 403.
- S3 lulus: simulasi Pegawai tetap aktif setelah logout dan login ulang melalui mode demo lokal.
- S4 lulus: revert menghapus indikator simulasi dan memulihkan menu serta akses Super Admin.
- S5 lulus: halaman Audit menampilkan urutan `SWITCH_ROLE`, `ROLE_SIMULATION_USAGE`, dan `REVERT_ROLE` dengan aktor Super Admin asli. Detail switch menyimpan role asal, role tujuan, waktu mulai, dan pelaku switch.
- S6 lulus melalui regression test backend: target role sama, `super_admin`, role di luar allowlist, payload tidak valid, dan switch kedua saat simulasi aktif ditolak tanpa perubahan state yang tidak sah.
- Responsive smoke lulus pada viewport 375 × 812: tombol menu, notifikasi, dan `Revert` tetap dapat digunakan; tidak ada overflow horizontal (`scrollWidth` tetap 375 piksel).
- Console smoke lulus: tidak ditemukan error atau warning selama alur switch, logout/login, akses terlarang, audit, dan revert.
- Performance smoke dashboard: DOM siap sekitar 3,3 detik pada pemuatan dingin dan 2,1 detik pada cache hangat; tidak ditemukan lag interaksi atau polling baru dari fitur switch role.

Verifikasi otomatis pendamping:

- `composer qa`: 2.072 test lulus, 42 skip PostgreSQL-khusus, 11.148 assertion; Pint dan PHPStan lulus.
- `SwitchRoleTest` pada PostgreSQL 17: 43 test lulus, 184 assertion.
- Regression audit durability membuktikan mutasi di-rollback ketika penulisan `ROLE_SIMULATION_USAGE` sengaja digagalkan oleh trigger database.
