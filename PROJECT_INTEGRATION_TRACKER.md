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
| Daftar Pegawai      | 🔃      | Backend sudah terintegrasi, menunggu testing & verifikasi  |
| Detail Pegawai      | 🔃      | Menunggu testing & verifikasi                            |
| Tambah Pegawai Baru | ❌      | Belum terintegrasi dengan Employee Model |
| Edit Pegawai        | ❌      | Masih menggunakan pegawaiList dummy      |
| Hapus Pegawai       | ❌      | Belum menggunakan Soft Delete            |
| Import Pegawai      | ❌      | Belum diaudit                            |
| Export Pegawai      | ❌      | Belum diaudit                            |
| Riwayat Pangkat     | ❌      | Belum menggunakan relasi RankHistory     |
| Riwayat Jabatan     | ❌      | Belum menggunakan relasi PositionHistory |
| Dokumen Pegawai     | ❌      | Belum menggunakan tabel documents        |

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

| Feature         | Status | Notes                                        |
| --------------- | ------ | -------------------------------------------- |
| Dashboard EWS   | ❌      | Masih mock data                              |
| Konfigurasi EWS | ⚠️     | Tabel tersedia, integrasi belum diverifikasi |
| Notifikasi EWS  | ❌      | Belum menggunakan EwsAlert                   |

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

Last Updated: 23 Juni 2026
Current Sprint: Frontend Backend Integration
Current Focus: Pegawai Create Store
