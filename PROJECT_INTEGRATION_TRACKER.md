# Project Integration Tracker — Super Admin

## 1. Tujuan dan Batas Tracker

Dokumen ini mencatat kondisi integrasi project SIMPEG dari perspektif **Super Admin** berdasarkan:

1. kebutuhan resmi dalam folder `LLDIKTI-DOC/DOCUMENT`;
2. route, controller, middleware, model, migration, seeder, Blade, dan test yang tersedia dalam project Laravel;
3. hasil pemeriksaan aktual pada **24 Juni 2026**.

Super Admin menggunakan **Dashboard Admin yang sama** dengan Admin Kepegawaian. Perbedaannya berada pada permission, menu, dan aksi. Super Admin mewarisi seluruh fitur operasional Admin Kepegawaian serta memiliki akses khusus untuk konfigurasi sistem, user dan role management, reference tables, audit penuh, serta soft delete/restore.

Dokumen ini tidak boleh menganggap target sprint sebagai fitur yang sudah selesai.

## 2. Sumber Resmi

- `LLDIKTI-DOC/DOCUMENT/PRD-SIMPEG-Fase1-Core.md`
- `LLDIKTI-DOC/DOCUMENT/User-Stories-SIMPEG-Fase1.md`
- `LLDIKTI-DOC/DOCUMENT/Issues-SIMPEG-Fase1.md`
- `LLDIKTI-DOC/DOCUMENT/Tracking-Sprint-Vertical-Slice-SIMPEG.md`
- `LLDIKTI-DOC/DOCUMENT/Tim-dan-Pembagian-Tugas-SIMPEG.md`

## 3. Status yang Digunakan

| Status | Arti |
|---|---|
| ✅ Terintegrasi | Frontend dan backend memakai data nyata, otorisasi tersedia, serta test relevan lulus |
| ⚠️ Sebagian | Hanya sebagian layer yang selesai, misalnya backend sudah nyata tetapi UI masih dummy |
| 🧱 Scaffold | Model, migration, route, atau UI awal tersedia tetapi alur bisnis belum berjalan |
| ❌ Belum | Implementasi utama belum tersedia |
| 📅 Target | Dicatat di dokumen sprint, tetapi belum menjadi bukti implementasi |

Status ✅ pada tracker ini hanya berarti **terintegrasi dalam kode dan didukung test yang tersedia**. Status tersebut belum otomatis berarti `Done` menurut gate vertical slice sampai PR direview, merge ke `develop`, dan mendapat QA/retest evidence.

## 4. Konteks Sprint Saat Pemeriksaan

| Item | Kondisi |
|---|---|
| Tanggal pemeriksaan | 25 Juni 2026 |
| Sprint resmi | Sprint 2 — Data Pegawai Core |
| Periode | 21 Juni 2026–30 Juni 2026 |
| Slice aktif menurut urutan dokumen | Slice 2.1 — CRUD Pegawai Core |
| Branch pemeriksaan | `feature/dashboard-backend-integration` |
| Model kerja | Vertical slice mulai Sprint 2 |
| Runtime | Podman Compose: app, nginx, dan PostgreSQL aktif |
| Health check | `GET /up` menghasilkan HTTP 200 |
| Route | 88 route terdaftar |
| Test | 164 test lulus, 592 assertions |

## 5. Cakupan Hak Akses Super Admin

| Kelompok akses | Kebutuhan DOCUMENT | Kondisi tracker/kode saat ini |
|---|---|---|
| Semua fitur Admin Kepegawaian | Data pegawai, riwayat, import, supervisor, cuti, EWS, notifikasi, dokumen, laporan | Dicatat per modul pada bagian 7 |
| Konfigurasi sistem | EWS, approval cuti, hari libur, reference tables, pengaturan | Sebagian tersedia |
| User management | Mapping Keycloak ke pegawai dan assign role internal | Route, controller, view, dan test dasar tersedia |
| RBAC | Kelola role-permission internal | Persistence dan middleware tersedia |
| Soft delete/restore | Nonaktifkan tanpa hapus permanen dan bisa dipulihkan | Model mendukung, flow UI belum terintegrasi |
| Audit penuh | Lihat daftar dan detail audit immutable | Daftar dan detail membaca `audit_logs`; cakupan logging seluruh operasi masih perlu dilengkapi |
| Dashboard Admin | Seluruh KPI dan ringkasan data untuk admin | UI tersedia tetapi data masih hard-coded |

## 6. Fitur Khusus Super Admin

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Mapping user Keycloak ke pegawai | US-1.4, Issue #4 | Sprint 1 | ⚠️ Sebagian | `UserMappingController`, route `/user-management`, tabel `users.employee_id`, validasi mapping, dan test tersedia. Audit perubahan perlu dipastikan konsisten memakai database |
| Assign role internal user | US-1.4 | Sprint 1 | ⚠️ Sebagian | Update role tersedia melalui user management. Role aplikasi tetap berasal dari database SIMPEG |
| Daftar role dan permission | PRD 4, Issue #4 | Sprint 1 | ✅ Terintegrasi | Model `Role`, `Permission`, pivot `role_permissions`, `RbacSeeder`, dan test middleware tersedia |
| Update permission matrix | PRD 4, Issue #4 | Sprint 1 | ⚠️ Sebagian | `RbacController::update()` menyinkronkan UUID secara langsung. Catatan lama mengenai bug `intval UUID` sudah tidak berlaku. Audit perubahan masih session-based |
| Middleware role | PRD 4.1–4.2 | Sprint 1 | ✅ Terintegrasi | `EnsureRole` fail-closed dan digunakan pada route terkait |
| Middleware permission | PRD 4.1–4.2 | Sprint 1 | ✅ Terintegrasi | `EnsurePermission` membaca mapping role-permission database dan memiliki feature test |
| Pengaturan sistem | Hak akses Super Admin | Fondasi | ⚠️ Sebagian | Route `/dashboard/pengaturan`, `SettingsController`, dan Blade tersedia; belum semua setting sistem terhubung ke persistence resmi |
| Konfigurasi EWS | US-5.1, konfigurasi sistem | Sprint 5 | ✅ Terintegrasi | `EwsConfigController`, `ews_configs`, validation, database audit, scheduler status, dan test tersedia |
| Konfigurasi approval chain cuti | US-4.10, Issue #28 | Sprint 4 | 🧱 Scaffold | `CutiConfigController`, `ApprovalConfig`, migration, dan seeder tersedia. Route aktif belum terdaftar dan view konfigurasi belum tersedia; konfigurasi belum dipakai oleh approval engine |
| Kelola hari libur/cuti bersama | US-8.4, Issue #10 | Sprint 1 | ⚠️ Sebagian | API `/api/v1/hari-libur` memakai `ref_hari_libur`, permission, audit DB, dan test lengkap. Halaman web `/hari-libur` masih memakai array statis/session |
| Kelola reference tables | US-8.5, Issue #46 | Sprint 6 | 🧱 Scaffold | Migration dan seeder reference tersedia. Route `/data-master` dan Blade tersedia, tetapi CRUD seluruh reference table belum terintegrasi |
| Soft delete pegawai | US-2.9, US-2.10 | Sprint 3 | 🧱 Scaffold | Model `Employee` memakai `SoftDeletes`, tetapi action web `destroy()` masih dummy dan tidak mengubah database |
| Daftar pegawai nonaktif | US-2.9, US-2.10 | Sprint 3 | 🧱 Scaffold | Route `/pegawai/nonaktif-list` dan Blade tersedia, tetapi belum memakai query `onlyTrashed()`/status nonaktif nyata |
| Restore pegawai | US-2.10 | Sprint 3 | ❌ Belum | Permission `employees.restore` tersedia, tetapi action restore database belum tersedia |
| Audit log penuh | US-7.1, US-7.2, US-7.3 | Sprint 1 dan 7 | ⚠️ Sebagian | `audit_logs`, `AuditService`, API, serta Blade daftar/detail sudah memakai database nyata. Cakupan logging belum meliputi seluruh operasi |

## 7. Fitur Operasional yang Diwarisi dari Admin Kepegawaian

### 7.1 Data Pegawai

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Daftar pegawai | US-2.3, Issue #16 | Sprint 2 | ⚠️ Sebagian | Halaman web membaca `Employee` dan pagination. API mendukung search/filter/sort nyata dan diuji. Filter halaman web hanya memfilter baris pada page aktif |
| Detail pegawai | US-2.4, Issue #17 | Sprint 2 | ⚠️ Sebagian | `Employee` beserta keluarga, riwayat, disiplin, pendidikan, dan dokumen dibaca dari database. Beberapa aksi detail belum tersedia |
| Tambah pegawai | US-2.1, Issue #14 | Sprint 2 | ✅ Terintegrasi | Form request, transaksi `Employee` + `Appointment`, upload, dan integration test tersedia. Flow web sudah 100% menggunakan DB nyata dan menulis audit log. |
| Edit pegawai | US-2.2, Issue #15 | Sprint 2 | ✅ Terintegrasi | API update database dan audit telah diuji. Halaman web menggunakan `Employee` asli (bukan dummy) dan menulis audit log. |
| Nonaktifkan pegawai | US-2.9, US-2.10 | Sprint 3 | ✅ Terintegrasi | Model mendukung soft delete, dan action web `destroy` sudah menghapus record database secara nyata beserta audit log-nya. |
| Export pegawai Excel | US-9.1, Issue #42 | Sprint 6 | ✅ Terintegrasi | Route `/pegawai/export` membaca `Employee`, menghasilkan XLSX, dan memiliki feature test |
| Export pegawai PDF | US-9.2, Issue #42 | Sprint 6 | ❌ Belum | Belum ditemukan output PDF database yang terverifikasi |

### 7.2 Riwayat dan Data Pendukung Pegawai

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Riwayat kepangkatan | US-2.6, Issue #18 | Sprint 2 | ✅ Terintegrasi | API list/create append-only, latest flag, update tanggal EWS, audit, dan test tersedia. Form CRUD pada halaman detail sudah terintegrasi ke API v1 |
| Riwayat jabatan | US-2.6 | Sprint 2 | ✅ Terintegrasi | API list/create append-only, latest flag, audit dan test tersedia. Form input UI sudah terhubung ke API v1 dengan binding referensi |
| Riwayat KGB | US-2.6 | Sprint 2 | ✅ Terintegrasi | API list/create append-only, latest flag, audit dan test tersedia. Form input UI sudah terhubung ke API v1 |
| Hukuman disiplin | US-2.7, Issue #19 | Sprint 2 | ✅ Terintegrasi | Model, migration, dan API append-only teruji secara otomatis. Form UI telah direstrukturisasi dan tersambung penuh dengan endpoint v1. |
| Data keluarga | US-2.8, Issue #24 | Sprint 3 | 🧱 Scaffold | Model, migration, relasi, dan tampilan tersedia. CRUD belum terintegrasi |
| Pendidikan | PRD 7.3 | Pelengkap data | 🧱 Scaffold | Model, migration, dan relasi tersedia; CRUD belum terintegrasi |
| Assign atasan langsung | US-4.11, Issue #27 | Sprint 4 | 🧱 Scaffold | Model/migration `SupervisorAssignment` tersedia; halaman dan flow assign belum terintegrasi |
| Flag kinerja baik | US-5.4, Issue #36 | Sprint 5 | 🧱 Scaffold | Field `is_kinerja_baik` dipakai EWS, tetapi aksi update terotorisasi untuk admin belum tersedia secara lengkap |

### 7.3 Import Pegawai

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Download template | US-3.1, Issue #20 | Sprint 3 | ⚠️ Sebagian | Template CSV/XLSX tersedia untuk beberapa tipe data |
| Upload dan validasi CSV | US-3.2, US-3.3 | Sprint 3 | ✅ Terintegrasi | API import CSV, validation, duplicate check, transaksi database, audit, dan test tersedia |
| Upload XLSX | US-3.2 | Sprint 3 | ❌ Belum | UI menerima `.xlsx`, tetapi backend import hanya menerima CSV/TXT dan test menegaskan XLSX ditolak |
| Preview dan mapping kolom | US-3.2, US-3.3 | Sprint 3 | 🧱 Scaffold | UI preview tersedia, tetapi belum terhubung penuh ke engine import |
| Eksekusi import dan laporan hasil | US-3.4, Issue #22 | Sprint 3 | ⚠️ Sebagian | API mengembalikan inserted/failed/error. Queue job dan laporan hasil downloadable belum lengkap |

### 7.4 Cuti

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Tabel dan model cuti | Issue #26 | Sprint 4 | 🧱 Scaffold | `LeaveRequest`, `LeaveApproval`, `LeaveBalance`, migration, dan relasi tersedia |
| Daftar cuti | US-4.2, US-4.8 | Sprint 4 dan 7 | ❌ Belum | Controller dan Blade masih memakai array dummy |
| Pengajuan cuti | US-4.1, Issue #30 | Sprint 4 | ❌ Belum | Form hanya melakukan validasi dan redirect; belum membuat `LeaveRequest` |
| Kalkulasi hari kerja | US-4.12, Issue #29 | Sprint 4 | ❌ Belum | Belum tersedia service/endpoint kalkulasi yang memakai hari libur database |
| Approval stage 1–3 | US-4.4–US-4.7, Issue #31 | Sprint 4 | ❌ Belum | Route approve/postpone terdaftar, tetapi method dan state machine belum tersedia |
| Skip approver duplikat | US-4.10 | Sprint 4 | 🧱 Scaffold | Konfigurasi dapat dibandingkan di controller config, tetapi belum digunakan dalam approval engine |
| Saldo cuti | US-4.3, US-4.9 | Sprint 4 dan 7 | 🧱 Scaffold | Model/migration tersedia; pengurangan saldo dan koreksi admin belum berjalan |
| Rekap cuti | US-9.3, US-9.4 | Sprint 6 | ❌ Belum | Halaman/export saat ini memakai data dummy |

### 7.5 EWS

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| EWS aktif | US-5.2, Issue #35 | Sprint 5 | ✅ Terintegrasi | Membaca `ews_alerts`, filter event, urgency, eligibility, role/permission, dan memiliki test |
| Scheduler EWS | US-5.1, Issue #34 | Sprint 5 | ✅ Terintegrasi | Command `app:run-ews`, schedule harian WITA, run log, error log, dan test tersedia |
| Anti-duplikasi alert | US-5.1 | Sprint 5 | ✅ Terintegrasi | Query guard dan unique database index tersedia serta diuji |
| Kenaikan pangkat/KGB/pensiun/PPPK | US-5.1, US-5.5 | Sprint 5 | ✅ Terintegrasi | Engine memproses empat jenis trigger dan memiliki test |
| Eligibility pangkat | US-5.4 | Sprint 5 | ✅ Terintegrasi | Kinerja dan hukuman disiplin aktif diperiksa oleh engine |
| Notifikasi EWS pegawai | US-6.1 | Sprint 5 | ✅ Terintegrasi | Notification database dibuat untuk pegawai yang eligible |
| Notifikasi EWS Admin Kepegawaian | PRD Modul EWS | Sprint 5 | ❌ Belum | Engine belum membuat notifikasi normal kepada Admin Kepegawaian |
| Notifikasi kegagalan scheduler ke Super Admin | US-5.1 AC-6 | Sprint 5 | ✅ Terintegrasi | Error run dicatat dan notification Super Admin diuji |
| Email EWS | US-6.3, Issue #37 | Sprint 5 | ❌ Belum | Mail/queue notification belum tersedia |
| EWS pribadi pegawai | US-5.3, Issue #49 | Sprint 5 | ❌ Belum | Belum ada halaman/route pegawai yang terintegrasi |

### 7.6 Notifikasi

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Penyimpanan notifikasi | US-6.1 | Sprint 1 dan 5 | ✅ Terintegrasi | Tabel/model/service notification tersedia |
| Inbox milik user | US-6.1, US-6.2 | Sprint 5 dan 7 | ✅ Terintegrasi | API hanya mengembalikan notifikasi pegawai yang terhubung dan memiliki test ownership |
| Unread count | US-6.1 | Sprint 5 | ✅ Terintegrasi | API dan test tersedia |
| Tandai satu/semua dibaca | US-6.4 | Sprint 5 | ✅ Terintegrasi | API permission-aware dan test tersedia |
| Halaman semua notifikasi | US-6.2, Issue #48 | Sprint 7 | ⚠️ Sebagian | Route dan Blade tersedia; perlu dipastikan binding UI ke API nyata |
| Bell dropdown | US-6.1, Issue #7 | Sprint 1 | ⚠️ Sebagian | Komponen navbar tersedia; integrasi lengkap terhadap inbox perlu diverifikasi |
| Email notification | US-6.3 | Sprint 5 | ❌ Belum | Belum tersedia mail queue/template operasional |

### 7.7 Dokumen Pegawai

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Model dan migration dokumen | PRD 7.3 | Data Pegawai | 🧱 Scaffold | `Document` dan tabel `documents` tersedia |
| Daftar dokumen | Hak Admin Kepegawaian | Data Pegawai | ❌ Belum | Controller halaman memakai array statis |
| Upload dokumen | PRD 7.4 | Data Pegawai | ❌ Belum | Validasi UI ada, tetapi file dan record `Document` tidak disimpan |
| Detail dokumen | PRD 7.3 | Data Pegawai | ❌ Belum | Menggunakan data statis |
| Download dokumen | PRD 7.4 | Data Pegawai | ❌ Belum | Menghasilkan mock PDF, bukan file storage nyata |

### 7.8 Audit Log

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Tabel audit immutable | US-7.1, Issue #5 | Sprint 1 | ⚠️ Sebagian | Tabel/model tidak menyediakan update route, tetapi cakupan logging belum seluruh operasi |
| Audit Employee API | US-7.1 | Sprint 1–2 | ✅ Terintegrasi | Create/update/import API menulis audit database |
| Audit EWS config | US-7.1 | Sprint 5 | ✅ Terintegrasi | Perubahan config ditulis ke `audit_logs` |
| Audit hari libur API | US-7.1 | Sprint 1 | ✅ Terintegrasi | Create/update/delete menulis audit database dan diuji |
| Daftar audit | US-7.2, Issue #47 | Sprint 7 | ✅ Terintegrasi | Blade `/dashboard/audit` membaca `AuditLog`, mempertahankan mapping UI, dan menampilkan perubahan konfigurasi EWS dari database |
| Detail diff audit | US-7.3 | Sprint 7 | ✅ Terintegrasi | Halaman detail membaca audit berdasarkan UUID database dan menampilkan diff `old_values`/`new_values` |
| Audit login/logout | US-1.1, US-1.2 | Sprint 1 | ⚠️ Sebagian | Service tersedia pada flow auth, tetapi cakupan perlu terus diuji |

### 7.9 Dashboard Admin

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Shared Dashboard Admin | US-1.5, US-8.1 | Sprint 6 | ⚠️ Sebagian | Super Admin dan Admin Kepegawaian memakai view dashboard yang sama |
| W1 komposisi pegawai | US-8.1 | Sprint 6 | ❌ Belum | Angka masih hard-coded |
| W2 kenaikan pangkat | US-8.1 | Sprint 6 | ❌ Belum | Angka dan detail masih hard-coded |
| W3 status cuti | US-8.1 | Sprint 6 | ❌ Belum | Data masih hard-coded/dummy |
| W4 EWS aktif | US-8.1 | Sprint 6 | ❌ Belum | Halaman EWS nyata tersedia, tetapi widget dashboard masih hard-coded |
| W5 distribusi golongan/jabatan | US-8.1 | Sprint 6 | ❌ Belum | Chart masih statis |
| W6 audit terbaru | US-8.1 | Sprint 6 | ❌ Belum | Daftar memakai array dummy |
| W7 tren pegawai | US-8.1 | Sprint 6 | ❌ Belum | Grafik masih statis |
| Hari libur mendatang | Konfigurasi sistem | Tambahan dashboard | ❌ Belum | Widget memakai array statis, bukan `ref_hari_libur` |

### 7.10 Laporan dan Export

| Fitur | Referensi | Target sprint | Status aktual | Kondisi implementasi dan bukti |
|---|---|---:|---|---|
| Export daftar pegawai Excel | US-9.1 | Sprint 6 | ✅ Terintegrasi | Export `/pegawai/export` membaca database dan diuji |
| Export daftar pegawai PDF | US-9.2 | Sprint 6 | ❌ Belum | Belum terverifikasi |
| Export rekap cuti Excel | US-9.3 | Sprint 6 | ❌ Belum | Route yang ada memakai array cuti dummy |
| Export rekap cuti PDF | US-9.4 | Sprint 6 | ❌ Belum | Belum tersedia |
| Riwayat kepangkatan | PRD L3 | Sprint 6 | ❌ Belum | Belum tersedia export laporan resmi |

## 8. Ringkasan Integrasi Super Admin

### Sudah terintegrasi dalam kode dan didukung test

- RBAC database dan permission middleware.
- EWS aktif, konfigurasi EWS, scheduler, run log, anti-duplikasi, dan notifikasi pegawai.
- Notifikasi database: inbox, unread count, mark read, dan ownership.
- API hari libur/cuti bersama.
- API daftar/update pegawai dan API riwayat pegawai.
- Import CSV pegawai.
- Export pegawai Excel berbasis database.

### Sudah dibuat tetapi belum end-to-end

- User mapping dan assign role.
- Pengaturan sistem.
- Data master/reference tables.
- Konfigurasi approval cuti.
- Daftar/detail/tambah pegawai melalui halaman web.
- Riwayat, disiplin, keluarga, pendidikan, supervisor, dan flag kinerja.
- Halaman semua notifikasi.
- Soft delete/restore.

### Masih dummy atau belum tersedia

- Cuti end-to-end.
- Dokumen pegawai end-to-end.
- Dashboard Admin real data.
- Reference table CRUD lengkap.
- Restore pegawai.
- Email notification.
- EWS pribadi.
- PDF dan laporan cuti berbasis database.

## 9. Prioritas Integrasi Berdasarkan Sprint Resmi

1. **Sprint 2 — 21–30 Juni 2026:** selesaikan web CRUD Pegawai Core, riwayat, disiplin, audit, review, dan QA.
2. **Sprint 3 — 1–10 Juli 2026:** import lengkap, profil/keluarga, soft delete, daftar nonaktif, dan restore.
3. **Sprint 4 — 11–20 Juli 2026:** konfigurasi approval, supervisor, saldo, kalkulasi hari kerja, pengajuan, dan approval cuti.
4. **Sprint 5 — 21–30 Juli 2026:** tutup gap EWS pribadi, notifikasi Admin Kepegawaian, email, dan session timeout.
5. **Sprint 6 — 31 Juli–9 Agustus 2026:** dashboard real data, CRUD reference tables, laporan, Excel, dan PDF.
6. **Sprint 7 — 10–20 Agustus 2026:** audit view database, role redirect, regression, UAT, dan release candidate.

## 10. Kesimpulan Kondisi Saat Ini

Cakupan menu dan tanggung jawab Super Admin **sudah terpetakan lengkap dalam tracker ini**, tetapi implementasinya **belum seluruhnya terintegrasi**.

Kondisi terkuat saat ini berada pada fondasi RBAC, API data pegawai, riwayat pegawai, EWS, audit Blade berbasis database, notifikasi database, hari libur API, import CSV, dan export pegawai Excel. Gap terbesar berada pada cuti end-to-end, soft delete/restore, reference tables, dokumen pegawai, dashboard real data, dan laporan lengkap.

---

**Last verified:** 25 Juni 2026

**Current sprint:** Sprint 2 — Data Pegawai Core

**Current vertical slice:** Slice 2.1 — CRUD Pegawai Core

**Primary role scope:** Super Admin

**Implementation status:** Sebagian terintegrasi
