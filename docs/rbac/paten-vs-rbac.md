# Kontrak PATEN dan RBAC

SIMPEG memakai tiga lapisan yang tidak boleh dicampur:

1. RBAC menentukan capability administratif atau lintas-pegawai.
2. PATEN menentukan ownership, workflow, lifecycle, dan domain invariant.
3. Scope/masking menentukan data yang boleh masuk ke respons atau export setelah capability lolos.

Capability PATEN tidak ditampilkan pada matriks RBAC: profil/riwayat/keluarga sendiri, notifikasi sendiri, baca hari libur, pengajuan dan pembacaan cuti sendiri, saldo sendiri, approval berdasarkan assignment chain aktif, serta penerbitan bukti cuti domain.

Capability lintas-pegawai atau administratif tetap berada pada matriks, termasuk `employees.export`, `cuti.read_all`, konfigurasi dan administrasi cuti, `dokumen_sk.*`, EWS, data referensi, dan import pegawai. Super Admin menerima default bootstrap, tetapi tidak memiliki bypass runtime: perubahan matrix tetap berlaku.

Untuk export pegawai, `employees.read` dan `employees.export` hanya membuka capability. Dataset selalu dimulai dari scope dashboard aktor: global untuk Super Admin/Admin Kepegawaian/Pimpinan, bawahan langsung untuk Kepala Bagian, dan diri sendiri untuk Pegawai. PII dimasking di luar operator administrasi global.
