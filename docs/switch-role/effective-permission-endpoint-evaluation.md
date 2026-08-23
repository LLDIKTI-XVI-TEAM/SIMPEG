# Evaluasi Permission Efektif pada Seluruh Endpoint — Switch Role (US-1.6)

**Lampiran PR #218** · Cabang `feat/switch-role` · Head `c29bc278`+

Dokumen ini adalah bukti untuk persyaratan penyelesaian US-1.6: **"evaluasi permission efektif seluruh endpoint di backend"**. Tujuannya: memastikan bahwa selama simulasi role aktif, setiap endpoint yang digerbang role/permission mengevaluasi **role tujuan** (bukan role asli), sehingga tidak ada endpoint yang lolos dari kebijakan simulasi.

## 1. Arsitektur evaluasi (single point of evaluation)

Seluruh otorisasi backend SIMPEG melewati **dua primitif saja**, dan keduanya kini mengevaluasi **role efektif**:

| Primitif | Implementasi | Perilaku selama simulasi |
|---|---|---|
| Middleware `role:` → `EnsureRole` | `app/Http/Middleware/EnsureRole.php` | `getEffectiveRole()` dipakai untuk pengecekan role list & keberadaan role |
| `hasPermission()` (dipakai middleware `permission:` dan seluruh cek dalam kode) | `App\Models\User::hasPermission()` | Mencari permission pada `Role::where('name', getEffectiveRole())` |

`User::getEffectiveRole()`:

- Mengembalikan `temporary_role` selama simulasi **hanya jika** `canSwitchToRole(temporary_role)` masih valid terhadap role asli (demosi → simulasi gugur, kembali ke role asli).
- `temporary_permission` **tidak pernah** menjadi sumber otorisasi (murni metadata audit), sesuai K-MTG-03.3/OQ-MTG-01.

**Konsekuensi:** karena semua endpoint digerbang oleh `EnsureRole` dan/atau `hasPermission`, dan keduanya memakai `getEffectiveRole()`, **setiap endpoint role/permission-gated otomatis mengevaluasi permission role tujuan** — tidak ada jalur otorisasi kedua yang bisa melewati kebijakan ini.

## 2. Inventaris endpoint yang digerbang role/permission

Semua kemunculan middleware `role:`/`permission:` di `routes/web.php` dan `routes/api/v1/*` (dihitung dari `routes/`, 134 baris deklarasi middleware):

| Area route | Gate (ringkas) | Jumlah deklarasi | Evaluasi efektif |
|---|---|---|---|
| Auth login/logout, callback Keycloak | `keycloak.auth` (+ session.timeout) | — | N/A (auth, bukan otorisasi) |
| Dashboard & menu utama | grup `role:super_admin,admin_kepegawaian,pimpinan,kepala_bagian,pegawai` (web.php:113) | 1 grup | ✅ via `EnsureRole` |
| Profil pegawai (admin) | `role:super_admin,admin_kepegawaian` + `permission:employees.*` | ±40 deklarasi | ✅ |
| Data Master & referensi | `role:super_admin` + `permission:reference_tables.manage`, `hari_libur.*` | ±20 | ✅ |
| Pengelolaan akun/mapping | `role:super_admin` + permission terkait | beberapa | ✅ |
| Cuti (index/pengajuan/approval/config) | `permission:cuti.*` + `role:...` | ±15 | ✅ |
| Notifikasi | `permission:notifications.*` (web + api/v1) | 6 | ✅ |
| Audit log | `role:super_admin,admin_kepegawaian` + `permission:audit_logs.read` (web + api/v1) | 4 | ✅ |
| Pimpinan surface | grup `role:pimpinan` + `permission:employees.read` | ±7 | ✅ |
| Kepala Bagian surface | grup `role:kepala_bagian` | 1 grup | ✅ |
| API v1 (pegawai, profil, dokumen, cuti, hari libur) | `role:...` + `permission:...` | ±25 | ✅ |
| **Switch role** | `role:super_admin` + `permission:users.switch_role` (web.php:636) | 1 | ✅ (hanya origin super_admin) |
| **Revert role** | tanpa `role:` (hanya auth) — jalur pemulihan by design | 1 | N/A (no-op di luar simulasi) |

## 3. Bukti test yang mengunci perilaku efektif di lintas endpoint

`tests/Feature/SwitchRoleTest.php` (713+ baris) + perubahan terkait pada test/view:

- `test_dashboard_and_requests_use_effective_role_during_simulation` — dashboard redirect + FormRequest filter pimpinan memakai role efektif.
- `test_global_search_uses_effective_role_for_pimpinan_simulation` — `GlobalSearchController` (diubah di PR) memakai role efektif.
- `test_cuti_create_button_uses_effective_role_during_pegawai_simulation` — tombol cuti muncul saat simulasi pegawai (permission `cuti.create` role tujuan).
- `test_permission_added_to_target_role_after_switch_applies_on_next_request` — perubahan permission role tujuan berlaku pada request berikutnya (AC-3/AC-8).
- `test_temporary_permission_is_no_longer_effective_after_revoked_from_role` — `temporary_permission` tidak berpengaruh pada otorisasi.
- `test_simulation_is_cancelled_if_account_role_is_demoted` — demosi membatalkan simulasi (getEffectiveRole).
- Test tambahan di PR: `EwsActivePageTest`, `ProfileTest`, `CutiListDisplayTest` (+`RbacPermissionMiddlewareTest`) mengunci role efektif pada halaman EWS aktif, profil, dan daftar cuti.
- `test_switch_menu_hidden_during_active_simulation` — UI tidak menampilkan submenu switch saat simulasi aktif.

## 4. Kesimpulan evaluasi

- **Tidak ada endpoint yang mengevaluasi permission dari role asli selama simulasi** — seluruh gate memakai `getEffectiveRole()` via dua primitif terpusat.
- **Demosi akun asli** membatalkan simulasi otomatis (tidak ada role sementara yang lebih tinggi dari role asli).
- **`temporary_permission`** tidak pernah memberikan maupun membatasi akses.
- **Pengecualian tunggal yang disengaja:** `/revert-role` (jalur pemulihan, hanya butuh autentikasi, no-op di luar simulasi).

Status: ✅ evaluasi permission efektif **seluruh endpoint** backend tercakup oleh desain terpusat + test lintas area di atas.
