# Agent Handoff Context: SIMPEG LLDIKTI XVI

**Tanggal Handoff:** 29 Juli 2026
**Fokus Terakhir:** Fitur "Status Pegawai" — refactor ke pola Action, sistem replace/timpa (tanpa riwayat), notifikasi ke pegawai, dan fix bug constraint database.

## 1. Apa yang Telah Diselesaikan (Completed Tasks)

### a. Sentralisasi Perubahan Status Pegawai
* Fitur "Status Pegawai" (`/super-admin/status-pegawai`, khusus `super_admin`) sekarang menjadi **satu-satunya jalur** untuk mengubah status kepegawaian.
* Section "Berkas Lainnya" (KTP/KK/SK Mutasi/SK Pensiun) **dihapus total** dari form `create.blade.php` dan `edit.blade.php` pegawai, beserta seluruh logic terkait di `CreateEmployeeAction` dan `UpdateEmployeeAction` (termasuk validasi `berkas_lainnya_*` di `StoreEmployeeRequest`/`UpdateEmployeeRequest`). Kontrak PPPK yang tadinya menumpang di sub-tab itu dipindah ke sub-tab "D. Pengangkatan".
* Dropdown "Status Baru" di halaman Status Pegawai diperbaiki agar memakai `RefStatusPegawai` sungguhan (sebelumnya berisi nama status yang tidak match seed data — bug lama).

### b. Sistem Replace/Timpa (BUKAN Riwayat)
Keputusan produk terbaru (final): **tidak ada riwayat historis** untuk status pegawai. Setiap perubahan **menimpa** data sebelumnya.
* Tabel `employee_status_histories` yang sempat dibuat sebelumnya **sudah di-drop** (migration `2026_07_29_020000_drop_employee_status_histories_table.php`). Model `EmployeeStatusHistory` dan relasi `Employee::statusHistories()` **sudah dihapus**.
* `ChangeEmployeeStatusAction` (`app/Actions/Employees/ChangeEmployeeStatusAction.php`) adalah satu-satunya action yang menjalankan perubahan status:
  1. Update snapshot `status_pegawai_id`, `status_aktif`, `status_alasan`, `status_deskripsi`, `status_tanggal`, `status_berkas_path`, `status_nomor_berkas` di `Employee` — **selalu menimpa**.
  2. Dokumen SK (`Document` dengan `jenis_dokumen = 'sk_status_pegawai'`) dibatasi **hanya satu per pegawai**. Sebelum menyimpan yang baru, dokumen kategori itu untuk employee tersebut selalu di-`delete()` dari database dulu.
  3. Kalau perubahan kali ini **melampirkan berkas baru** → dokumen baru dibuat, file baru disimpan ke storage (`EmployeeFileStorageService::storeBerkasLainnya`).
  4. Kalau **tidak** melampirkan berkas → tidak ada dokumen baru, dan otomatis jadi "kosong".
  5. File fisik lama di storage dihapus **setelah** transaksi database sukses (via `EmployeeFileStorageService::deletePublicFile`), supaya kegagalan transaksi tidak menghapus file yang masih valid.
  6. Audit log (`AuditService::log('UPDATE', 'Employee', ...)`) dan notifikasi in-app/email ke pegawai bersangkutan (event `status_pegawai.diubah`, dikirim lewat `NotificationService::createForEmployee`) tetap jalan — notifikasi bersifat fire-and-forget di luar transaksi.
* Event notifikasi `status_pegawai.diubah` sudah didaftarkan di `ReferenceSeeder` dan migration backfill (`2026_07_28_230100_add_status_pegawai_notification_event.php`) untuk channel `in_app` + `email`.

### c. Halaman Detail Pegawai (`show.blade.php`)
* **Tidak ada tab "Riwayat Status"** — sudah dihapus.
* Info status kepegawaian saat ini (Status Saat Ini, Tanggal Efektif, Alasan, Deskripsi, link Berkas SK) ditambahkan sebagai card baru di **tab "Profil"**, section "Status Kepegawaian".
* Dokumen SK status tetap muncul di tab "Dokumen SK" yang sudah ada (karena tetap tersimpan sebagai `Document` biasa, kategori `sk_status_pegawai`) — hanya sekarang cuma ada maksimal satu record aktif per waktu.

### d. Bug Fix Database Constraint (Penting!)
* Kolom `employees.status_aktif` awalnya dibuat sebagai **Postgres enum** (`Aktif`, `Non-Aktif`, `Pensiun`, `Mutasi`) di migration lama `2026_06_18_100002_create_employees_table.php`.
* Migration `2026_07_28_220337_add_status_details_to_employees_table.php` mengubah kolom jadi `string` via `->change()`, tapi di **PostgreSQL, `Schema::change()` tidak menghapus CHECK constraint lama** yang dibuat oleh `enum()` Laravel. Akibatnya database tetap menolak status baru seperti "Pemberhentian Sementara".
* **Sudah diperbaiki** lewat migration `2026_07_29_010000_drop_stale_status_aktif_check_constraint.php` yang men-drop constraint `employees_status_aktif_check` (Postgres-only, no-op di driver lain). Sudah dijalankan di database dev (`podman exec simpeg_app php artisan migrate --force`).
* Test regresi: `test_can_change_status_to_values_beyond_original_enum` di `ChangeEmployeeStatusTest.php`.

## 2. Struktur File Terkait

| File | Keterangan |
|---|---|
| `app/Actions/Employees/ChangeEmployeeStatusAction.php` | **Core logic** — replace status, replace dokumen SK, hapus file lama, audit, notifikasi. |
| `app/Http/Controllers/Admin/StatusPegawaiController.php` | Controller tipis, delegasi ke action di atas. |
| `app/Http/Requests/Employee/ChangeEmployeeStatusRequest.php` | Validasi form (hanya `super_admin`). |
| `resources/views/admin/status-pegawai/index.blade.php` | Form ubah status (dropdown `RefStatusPegawai` asli). |
| `resources/views/admin/pegawai/show.blade.php` | Tab Profil menampilkan status kepegawaian saat ini (bukan riwayat). |
| `resources/views/admin/pegawai/create.blade.php`, `edit.blade.php` | Section "Berkas Lainnya" sudah dihapus total. |
| `app/Actions/Employees/CreateEmployeeAction.php`, `UpdateEmployeeAction.php` | Logic "Berkas Lainnya" (termasuk efek samping SK Mutasi/Pensiun mengubah status) sudah dihapus. |
| `app/Actions/Documents/DeleteDocumentAction.php` | Blocking rule untuk `sk_status_pegawai` (tidak bisa dihapus manual selama masih jadi berkas status aktif). |
| `app/Support/Documents/DocumentCategory.php` | Ada label baru `sk_status_pegawai` => "SK Perubahan Status Pegawai". |
| `database/migrations/2026_07_28_220337_...`, `2026_07_29_010000_...` | History kolom `status_aktif` + fix constraint. |
| `database/migrations/2026_07_28_230000_...` (create) & `2026_07_29_020000_...` (drop) | Tabel riwayat dibuat lalu di-drop lagi (keputusan produk berubah). |
| `database/migrations/2026_07_28_230100_...` | Registrasi event notifikasi `status_pegawai.diubah`. |
| `tests/Feature/ChangeEmployeeStatusTest.php` | Test utama fitur ini (replace document/file, notifikasi, enum constraint). |

**Model yang SUDAH TIDAK ADA (jangan dicari lagi):** `App\Models\EmployeeStatusHistory` — sengaja dihapus sesuai keputusan produk terbaru.

## 3. Status Terkini / Catatan Penting

* App berjalan di **Podman** (`simpeg_app`, `simpeg_postgres`, `simpeg_nginx`, `simpeg_scheduler`, `simpeg_queue`, `simpeg_mailpit`). Artisan/test command pakai prefix `podman exec simpeg_app ...`.
* Full test suite terakhir: **1059 passed, 1 skipped** (skip pre-existing, tidak terkait fitur ini). `ReferenceSeederTest.php` dan `NotificationEventChannelMigrationTest.php` sempat perlu penyesuaian angka hardcode (`assertDatabaseCount('notification_event_channels', ...)`) karena penambahan event baru — sudah diperbaiki.
* Semua migration terkait sudah dijalankan di database dev via `podman exec simpeg_app php artisan migrate --force`.
* `DataMasterStatusPegawaiController` (CRUD daftar master `ref_status_pegawai`) **berbeda** dari `StatusPegawaiController` (mengubah status pegawai individu) — jangan tertukar, keduanya sengaja terpisah.
* Ada konflik nama route/tab lama yang perlu diwaspadai bila membuka ulang PR/branch lama: fitur "Riwayat Status" sempat dibuat lalu **dibatalkan** dalam sesi yang sama, jadi kalau ada sisa kode yang merujuk `statusHistories`/`EmployeeStatusHistory`/`employee_status_histories`, itu harus dihapus (bukan dipulihkan).

## 4. Rekomendasi Langkah Selanjutnya

* Belum ada UI konfirmasi/warning di halaman Status Pegawai yang menjelaskan ke admin bahwa berkas SK lama akan **dihapus permanen** saat mereka mengganti status — pertimbangkan tambah alert/warning di form sebelum submit supaya operator tidak kaget kehilangan berkas lama.
* Tanyakan ke user apakah perlu ada log/audit trail read-only terpisah (di luar `audit_logs` generik) untuk memenuhi kebutuhan kepatuhan (compliance) jika suatu saat dibutuhkan bukti histori — saat ini satu-satunya jejak perubahan status ada di `audit_logs` (old_values/new_values JSON), bukan tabel terstruktur.
* Silakan tanyakan ke user fitur/perbaikan apa selanjutnya yang ingin dikerjakan.
