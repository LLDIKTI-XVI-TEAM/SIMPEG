# Frontend Backend Integration Tracker

## Legend

* ✅ Complete (Frontend + Backend + Testing + Push)
* 🔃 In Progress
* ⚠️ Partial Integration
* ❌ Not Started

---

## Modul Pegawai

| Feature             | Status | Notes                                    |
| ------------------- | ------ | ---------------------------------------- |
| Daftar Pegawai      | ✅      | Backend terintegrasi, fitur filter/search mengikuti database |
| Detail Pegawai      | ⚠️      | Menggunakan Employee Model + relasi database, namun ada fungsionalitas/data dari backend yang belum lengkap  |
| Tambah Pegawai Baru | ✅      | Terintegrasi dengan form Request Validation dan relasi database backend |
| Edit Pegawai        | ❌      | Masih menggunakan pegawaiList dummy      |
| Hapus Pegawai       | ❌      | Belum menggunakan Soft Delete            |
| Import Pegawai      | ❌      | Belum diaudit                            |
| Export Pegawai      | ❌      | Belum diaudit                            |
| Riwayat Pangkat     | ⚠️      | Sudah tampil dari RankHistory pada halaman Detail Pegawai, CRUD belum terintegrasi     |
| Riwayat Jabatan     | ⚠️      | Sudah tampil dari PositionHistory pada halaman Detail Pegawai, CRUD belum terintegrasi |
| Riwayat KGB         | ⚠️      | Sudah tampil dari SalaryHistory pada halaman Detail Pegawai, CRUD belum terintegrasi |
| Dokumen Pegawai     | ⚠️      | Sudah tampil dari relasi documents pada halaman Detail Pegawai, fitur upload/manajemen belum terintegrasi  |

---

## Modul Cuti

| Feature        | Status | Notes                          |
| -------------- | ------ | ------------------------------ |
| Daftar Cuti    | ❌      | Masih dummy                    |
| Pengajuan Cuti | ❌      | Belum menggunakan LeaveRequest |
| Approval Cuti  | ❌      | Belum menggunakan database     |
| Saldo Cuti     | ❌      | Belum menggunakan LeaveBalance |
| Rekap Cuti     | ❌      | Belum menggunakan database     |

---

## Modul EWS

| Feature              | Status | Notes                                                                 |
| -------------------- | ------ | --------------------------------------------------------------------- |
| EWS Aktif            | ✅      | Terintegrasi dengan `ews_alerts`, filter event, role access, dan test |
| Konfigurasi EWS      | ✅      | Terintegrasi dengan `ews_configs`, validasi, audit DB, dan test       |
| Scheduler Engine EWS | ✅      | Command `app:run-ews`, schedule harian WITA, run log, anti-duplikasi  |
| Notifikasi EWS       | ⚠️     | In-app pegawai sudah jalan; notifikasi admin dan email masih pending  |
| EWS Pribadi          | ❌      | Belum diimplementasikan                                               |

---

## Modul Dokumen

| Feature          | Status | Notes                            |
| ---------------- | ------ | -------------------------------- |
| Daftar Dokumen   | ❌      | Masih dummy                      |
| Upload Dokumen   | ❌      | Belum menggunakan Document Model |
| Download Dokumen | ❌      | Belum diverifikasi               |

---

## Modul Audit Log

| Feature      | Status | Notes                      |
| ------------ | ------ | -------------------------- |
| Daftar Audit | ❌      | Masih array dummy          |
| Detail Audit | ❌      | Belum menggunakan database |

---

## Modul Hari Libur

| Feature           | Status | Notes              |
| ----------------- | ------ | ------------------ |
| Daftar Hari Libur | ❌      | Belum diverifikasi |
| Tambah Hari Libur | ❌      | Belum diverifikasi |
| Edit Hari Libur   | ❌      | Belum diverifikasi |

---

## Modul RBAC

| Feature                  | Status | Notes                                 |
| ------------------------ | ------ | ------------------------------------- |
| Daftar Role              | ⚠️     | Database sudah UUID                   |
| Update Permission Matrix | ⚠️     | Bug intval UUID harus diperbaiki      |
| Middleware Permission    | ✅      | Sudah menggunakan Role dan Permission |

---

## Modul Dashboard

| Feature           | Status | Notes       |
| ----------------- | ------ | ----------- |
| Statistik Pegawai | ❌      | Masih dummy |
| Statistik Cuti    | ❌      | Masih dummy |
| Ringkasan EWS     | ❌      | Masih dummy |

---

Last Updated: 24 Juni 2026
Current Sprint: Frontend Backend Integration
Current Focus: Tambah Pegawai
