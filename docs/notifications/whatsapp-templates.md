# Rancangan Template WhatsApp Business SIMPEG — LLDIKTI Wilayah XVI

> **Dokumen Kontrak Pengajuan (*Submission Proposal*)**  
> **Status:** Disiapkan oleh tim pengembang SIMPEG untuk diserahkan kepada LLDIKTI Wilayah XVI guna proses pengajuan template resmi ke Meta / WhatsApp Business Solution Provider resmi yang ditetapkan oleh LLDIKTI Wilayah XVI (dengan Qontak sebagai baseline kandidat acuan).  
> **Acuan Dokumen Kanonis:**  
> - [PRD-SIMPEG-Fase1-Core.md](https://github.com/diyoncrzz18/lldikti-doc-2/blob/1e53db76189b57551cf87de2a5e35834206f1d2e/DOCUMENT/PRD-DLL/PRD-SIMPEG-Fase1-Core.md) (§10 *Early Warning System* dan §11 *Notifikasi*)
> - [User-Stories-SIMPEG-Fase1.md](https://github.com/diyoncrzz18/lldikti-doc-2/blob/1e53db76189b57551cf87de2a5e35834206f1d2e/DOCUMENT/PRD-DLL/User-Stories-SIMPEG-Fase1.md) (US-6.5 AC-1 s.d. AC-5 dan Addendum AC-MTG-4 s.d. AC-MTG-10)
> - [Keputusan-Evaluasi-Meeting-LLDIKTI-15-Agustus-2026.md](https://github.com/diyoncrzz18/lldikti-doc-2/blob/1e53db76189b57551cf87de2a5e35834206f1d2e/DOCUMENT/Keputusan-Evaluasi-Meeting-LLDIKTI-15-Agustus-2026.md) (K-MTG-05, K-MTG-05A, dan K-MTG-07 OQ-MTG-06)

---

## 📌 1. Latar Belakang & Tujuan

Dokumen ini memuat rancangan pesan template resmi WhatsApp Business (*pre-approved message templates*) beserta kamus variabel (*allowlist payload*) untuk sistem **SIMPEG LLDIKTI Wilayah XVI**. Dokumen ini berfungsi sebagai acuan resmi bagi pihak LLDIKTI Wilayah XVI dalam mendaftarkan dan mengajukan template pesan ke pihak Meta / WhatsApp Business Solution Provider.

Sesuai ketetapan arsitektur kanonikal SIMPEG:
1. **Pemisahan Transport & Domain**: Modul bisnis (Cuti & EWS) tidak memanggil provider WhatsApp secara langsung, melainkan melalui layer notifikasi terpusat (`App\Services\NotificationService` dan dispatcher adapter pada Issue #13).
2. **Berbasis Template Terdaftar**: Pengiriman pesan WhatsApp wajib berbasis template resmi yang telah disetujui Meta dengan pengisian parameter/variabel yang telah divalidasi. Sistem **tidak mengirimkan teks bebas (*free text*)**.
3. **Kesiapan Rilis**: Fitur integrasi WhatsApp Business merupakan keluaran wajib yang ditargetkan siap pada **akhir Agustus 2026** (K-MTG-07 OQ-MTG-06).

---

## 🛡️ 2. Prinsip Desain & Batasan Privasi

Dalam penyusunan template ini, prinsip-prinsip berikut ditegakkan secara ketat:

1. **Bahasa Indonesia Formal & Baku**:
   Seluruh rancangan pesan ditulis menggunakan Bahasa Indonesia formal, santun, lugas, dan mudah dipahami oleh aparatur sipil negara dan pimpinan di lingkungan LLDIKTI Wilayah XVI.
2. **Perlindungan Data Pribadi (*Zero Sensitive Data*)**:
   - **DILARANG KERAS** menyertakan Nomor Induk Kependudukan (NIK), Nomor Kartu Keluarga (No. KK), kata sandi (*password*), token sesi/SSO, atau informasi finansial rahasia dalam isi pesan maupun variabel template.
   - Informasi yang dikirimkan dibatasi pada nama pegawai, jenis permohonan/peringatan, tanggal/durasi, status, catatan ringkas, dan tautan resmi aplikasi.
3. **Validasi dan Sanitasi Konten Nilai Teks Bebas (*Free-Text Privacy Guard*)**:
   - Meskipun nama variabel berada dalam *allowlist*, nilai teks bebas yang diinput oleh pengguna/pejabat (seperti variabel `keterangan` pada keputusan cuti dan `ringkasan` pada notifikasi sistem) **wajib melalui proses sanitasi dan pemindaian pola data sensitif** (misalnya pola 16 digit NIK/No. KK atau format token) sebelum diteruskan ke antrean pengiriman WhatsApp.
   - Jika payload terdeteksi memuat data sensitif yang dilarang, pengiriman harus berstatus *fail-closed* / ditolak demi kepatuhan privasi data.
4. **Pengecualian Template OTP**:
   Template One-Time Password (OTP) dari baseline penyedia pesan **tidak diajukan** karena seluruh alur autentikasi dan login SIMPEG terpusat melalui Keycloak Single Sign-On (SSO).
5. **Tautan Akses Aman (*Secure Link & Placeholder Domain*)**:
   Variabel `tautan_detail` selalu mengarah ke domain resmi SIMPEG berprotokol HTTPS yang mewajibkan autentikasi akun pengguna sebelum informasi sensitif dapat diakses. Domain pada contoh dokumen ini bersifat ilustratif/placeholder (`https://<domain-simpeg-resmi>/...`) dan akan menggunakan domain resmi yang ditetapkan oleh LLDIKTI Wilayah XVI pada tahap deployment.
6. **Resolusi Nomor Penerima WhatsApp (*Fail-Closed*)**:
   - Target penerima pada dokumen ini mengidentifikasi pegawai/aktor, bukan nomor telepon yang langsung siap dikirimi pesan. Kontrak LLDIKTI/provider wajib menetapkan sumber nomor WhatsApp kanonis, normalisasi, serta bukti verifikasi kepemilikan/alamat tujuan sebelum adapter diaktifkan.
   - `leave_requests.nomor_telepon` adalah snapshot kontak selama cuti dan **dilarang** dipakai sebagai alamat pengiriman WhatsApp. Nilai `employees.no_hp` juga belum boleh dianggap sebagai alamat WhatsApp terverifikasi hanya karena terisi; ia baru dapat dipakai setelah aturan pemetaan dan verifikasi formal tersedia.
   - Bila penerima tidak memiliki alamat WhatsApp kanonis yang terverifikasi, hasil resolusi ambigu, atau pemeriksaan gagal, adapter wajib *fail-closed* / tidak mengirim.
7. **Antrean dan Outbox Durable**:
   - Setelah seluruh gerbang lolos, SIMPEG mencatat audit delivery dan payload job terenkripsi pada outbox dalam transaksi yang sama. Nomor tujuan, isi pesan, token, serta respons provider tidak disimpan pada audit delivery.
   - Publisher hanya menandai outbox berhasil dipublikasi setelah broker menerima job. Outbox yang kehilangan callback atau mengalami kegagalan broker dipulihkan scheduler secara berbatas dan ber-lease; worker tetap idempoten berdasarkan `idempotency_key`.

---

## 📋 3. Katalog Model Template WhatsApp SIMPEG

Terdapat **3 model wajib** dan **1 model opsional** yang dirancang untuk kebutuhan operasional SIMPEG:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                   KATALOG TEMPLATE WHATSAPP SIMPEG                          │
├───────────────────────────────┬─────────────────────────────────────────────┤
│ 1. simpeg_cuti_perlu_tindakan │ Notifikasi tindakan approval cuti           │
│ 2. simpeg_cuti_status         │ Notifikasi perubahan status cuti pemohon    │
│ 3. simpeg_ews_pengingat       │ Pengingat dini 5 milestone EWS              │
│ 4. simpeg_notifikasi_sistem   │ Notifikasi operasional sistem (opsional)    │
└───────────────────────────────┴─────────────────────────────────────────────┘
```

---

### 1️⃣ Model 1: `simpeg_cuti_perlu_tindakan` *(Wajib)*

* **Kode Template Rancangan**: `simpeg_cuti_perlu_tindakan`
* **Kategori Pesan**: *Utility / Notification*
* **Tujuan**: Memberitahukan aktor persetujuan (*approval step* aktif: Verifikator, Kepala Bagian, atau PYBMC) bahwa terdapat berkas pengajuan cuti pegawai yang memerlukan pemeriksaan atau penetapan keputusan.
* **Target Penerima**: Verifikator, Kepala Bagian, atau Pejabat Yang Berwenang Memberikan Cuti (PYBMC) yang sedang ditugaskan pada langkah persetujuan aktif.
* **Kamus Variabel (*Allowlist Variables*)**:
  | Nama Variabel | Tipe Data | Deskripsi | Contoh Nilai (Ilustratif) |
  |---|---|---|---|
  | `nama_pegawai` | `string` | Nama lengkap pegawai pemohon cuti | `Dr. Jane Doe, M.Pd.` |
  | `jenis_cuti` | `string` | Jenis cuti yang diajukan | `Cuti Tahunan` |
  | `tanggal_mulai` | `string` | Tanggal awal pelaksanaan cuti | `01 September 2026` |
  | `tanggal_selesai`| `string` | Tanggal akhir pelaksanaan cuti | `03 September 2026` |
  | `jumlah_hari` | `string` | Durasi hari kerja yang diajukan | `3 hari kerja` |
  | `tautan_detail` | `string (URL)` | Tautan langsung menuju halaman verifikasi/approval cuti | `https://<domain-simpeg-resmi>/dashboard/cuti/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d` *(placeholder UUID)* |

* **Draft Isi Pesan Template**:
  ```text
  Yth. Bapak/Ibu Verifikator / Pejabat Penyetuju,

  Terdapat permohonan cuti pegawai yang memerlukan verifikasi dan persetujuan Anda pada sistem SIMPEG LLDIKTI Wilayah XVI:

  • Nama Pemohon: {{nama_pegawai}}
  • Jenis Cuti: {{jenis_cuti}}
  • Periode Cuti: {{tanggal_mulai}} s.d. {{tanggal_selesai}} ({{jumlah_hari}})

  Mohon untuk melakukan pemeriksaan dan tindak lanjut permohonan tersebut melalui tautan berikut:
  {{tautan_detail}}

  Terima kasih.
  SIMPEG LLDIKTI Wilayah XVI
  ```

---

### 2️⃣ Model 2: `simpeg_cuti_status` *(Wajib)*

* **Kode Template Rancangan**: `simpeg_cuti_status`
* **Kategori Pesan**: *Utility / Notification*
* **Tujuan**: Memberitahukan pegawai pemohon bahwa permohonan cutinya telah diperbarui statusnya oleh verifikator atau pejabat penyetuju.
* **Target Penerima**: Pegawai pemohon cuti.
* **Nilai Status Kanonikal**: Sesuai aturan kanonikal SIMPEG, nilai status keputusan cuti terdiri dari 4 nilai resmi:
  1. `Disetujui`
  2. `Perubahan`
  3. `Ditangguhkan`
  4. `Tidak Disetujui` *(Penggunaan kata lama "Ditolak" telah dideprecate secara permanen)*.
* **Kamus Variabel (*Allowlist Variables*)**:
  | Nama Variabel | Tipe Data | Deskripsi | Contoh Nilai (Ilustratif) |
  |---|---|---|---|
  | `nama_pegawai` | `string` | Nama lengkap pegawai pemohon | `Ahmad Fauzi, S.Kom.` |
  | `jenis_cuti` | `string` | Jenis cuti yang diajukan | `Cuti Tahunan` |
  | `status` | `string` | Status keputusan resmi | `Disetujui` / `Perubahan` / `Ditangguhkan` / `Tidak Disetujui` |
  | `keterangan` | `string` | Catatan keputusan atau penjelasan status dari sistem / pejabat penyetuju | `Disetujui sesuai usulan.` |
  | `tautan_detail` | `string (URL)` | Tautan untuk melihat riwayat dan lembar persetujuan | `https://<domain-simpeg-resmi>/dashboard/cuti/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d` *(placeholder UUID)* |

* **Draft Isi Pesan Template**:
  ```text
  Yth. {{nama_pegawai}},

  Permohonan {{jenis_cuti}} Anda pada sistem SIMPEG LLDIKTI Wilayah XVI telah diperbarui dengan status keputusan:
  *{{status}}*

  Catatan / Keterangan:
  "{{keterangan}}"

  Rincian keputusan dan riwayat permohonan cuti Anda dapat diakses melalui tautan berikut:
  {{tautan_detail}}

  Terima kasih.
  SIMPEG LLDIKTI Wilayah XVI
  ```

---

### 3️⃣ Model 3: `simpeg_ews_pengingat` *(Wajib)*

* **Kode Template Rancangan**: `simpeg_ews_pengingat`
* **Kategori Pesan**: *Utility / Reminder*
* **Tujuan**: Pengingat dini otomatis (*Early Warning System*) atas jatuh tempo milestone kepegawaian agar pegawai yang bersangkutan dan Admin Kepegawaian dapat menyiapkan berkas tepat waktu.
* **Karakter Pesan (*Recipient-Neutral*)**: Copy dirancang netral terhadap penerima sehingga pesan tetap kontekstual dan akurat baik saat dikirim langsung ke pegawai pemilik milestone maupun saat diteruskan ke Admin Kepegawaian (yang mengelola seluruh notifikasi EWS rutin).
* **Event yang Dicakup**:
  1. Kenaikan Pangkat
  2. Kenaikan Gaji Berkala (KGB)
  3. Pensiun / Batas Usia Pensiun (BUP)
  4. Kontrak PPPK (Batas Akhir Masa Kontrak)
  5. Satyalancana Karya Satya (Milestone masa kerja: 10, 20, atau 30 Tahun)
* **Target Penerima**: Pegawai bersangkutan dan/atau Admin Kepegawaian.
* **Kamus Variabel (*Allowlist Variables*)**:
  | Nama Variabel | Tipe Data | Deskripsi | Contoh Nilai (Ilustratif) |
  |---|---|---|---|
  | `nama_pegawai` | `string` | Nama lengkap pegawai pemilik milestone | `Budi Santoso, M.T.` |
  | `jenis_peringatan`| `string` | Jenis pengingat milestone EWS | `Kenaikan Pangkat` |
  | `tanggal_target` | `string` | Tanggal jatuh tempo / TMT target | `01 Oktober 2026` |
  | `sisa_waktu` | `string` | Keterangan interval atau status selisih waktu pengingat | `H-90 hari` |
  | `tautan_detail` | `string (URL)` | Tautan ke halaman informasi EWS dan kelengkapan dokumen | `https://<domain-simpeg-resmi>/dashboard/ews-saya` *(untuk pegawai)* atau `https://<domain-simpeg-resmi>/ews` *(untuk admin)* |

* **Draft Isi Pesan Template (*Recipient-Neutral*)**:
  ```text
  Pengingat Kepegawaian SIMPEG LLDIKTI Wilayah XVI (Early Warning System):

  • Nama Pegawai: {{nama_pegawai}}
  • Perihal: {{jenis_peringatan}}
  • Tanggal Target / TMT: {{tanggal_target}} ({{sisa_waktu}})

  Silakan periksa kelengkapan persyaratan dan tindak lanjut administrasi kepegawaian melalui tautan berikut:
  {{tautan_detail}}

  Terima kasih atas perhatian dan kerja sama Anda.
  SIMPEG LLDIKTI Wilayah XVI
  ```

---

### 4️⃣ Model 4: `simpeg_notifikasi_sistem` *(Opsional)*

* **Kode Template Rancangan**: `simpeg_notifikasi_sistem`
* **Kategori Pesan**: *Utility / Operational Notification*
* **Tujuan**: Pengumuman penting atau notifikasi operasional sistem di luar modul Cuti dan EWS (hanya diajukan jika terdapat kebutuhan operasional yang disetujui pimpinan).
* **Target Penerima**: Pengguna / penerima sesuai event operasional yang telah disetujui.
* **Kamus Variabel (*Allowlist Variables*)**:
  | Nama Variabel | Tipe Data | Deskripsi | Contoh Nilai (Ilustratif) |
  |---|---|---|---|
  | `judul` | `string` | Judul pengumuman/pemberitahuan | `Pemutakhiran Data Mandiri 2026` |
  | `ringkasan` | `string` | Ringkasan isi pengumuman operasional | `Batas akhir pembaruan berkas profil adalah 31 Agustus 2026.` |
  | `tautan_detail` | `string (URL)` | Tautan menuju pengumuman/dashboard | `https://<domain-simpeg-resmi>/dashboard` *(placeholder)* |

* **Draft Isi Pesan Template**:
  ```text
  Pemberitahuan Sistem SIMPEG LLDIKTI Wilayah XVI:

  *{{judul}}*

  {{ringkasan}}

  Informasi selengkapnya dapat diakses melalui tautan berikut:
  {{tautan_detail}}

  Terima kasih.
  SIMPEG LLDIKTI Wilayah XVI
  ```

---

## 🗂️ 4. Matriks Pemetaan Event Domain ke Template WhatsApp

Tabel berikut menghubungkan katalog event internal sistem (`App\Services\Notifications\NotificationEventCatalog`) yang termasuk dalam cakupan pengiriman WhatsApp dengan kode template yang diajukan:

| Event Internal SIMPEG | Grup Modul | Template WhatsApp Terkait | Target Penerima | Aturan Transformasi Nilai Status / Payload |
|---|---|---|---|---|
| `cuti.pengajuan_baru` | Cuti | `simpeg_cuti_perlu_tindakan` | Aktor / Approver pada langkah persetujuan aktif | Pemetaan langsung dari data permohonan pada langkah aktif |
| `cuti.menunggu_persetujuan` | Cuti | `simpeg_cuti_perlu_tindakan` | Aktor / Approver pada langkah persetujuan aktif (Verifikator / Kepala Bagian / PYBMC) | Pemetaan langsung dari data permohonan pada langkah aktif |
| `cuti.disetujui` | Cuti | `simpeg_cuti_status` | Pegawai Pemohon | `status` = `Disetujui`. Payload runtime wajib membawa `leave_approval_id` dari aksi `APPROVE` yang menghasilkan keputusan final; transformer hanya boleh memuat approval tersebut bila `leave_request_id` dan `action` = `APPROVE` cocok, lalu menyanitasi `komentar` sebagai `keterangan` (fallback bila komentar kosong: `"Permohonan cuti telah disetujui sesuai usulan."`). Tidak boleh memilih approval tahap lain atau approval terbaru berdasarkan waktu. Bila asosiasi immutable tidak tersedia atau tidak cocok, pengiriman *fail-closed* / tidak dikirim. |
| `cuti.ditunda` | Cuti | `simpeg_cuti_status` | Pegawai Pemohon | `status` = `Ditangguhkan`. Payload runtime wajib membawa `leave_approval_id` yang dibuat oleh aksi `POSTPONE`; transformer hanya boleh memuat approval tersebut bila `leave_request_id` dan `action` = `POSTPONE` cocok, lalu menyanitasi `komentar` sebagai `keterangan`. Tidak boleh memilih approval terbaru berdasarkan waktu. Bila asosiasi immutable belum tersedia, tidak cocok, atau gagal privacy guard, pengiriman *fail-closed* / tidak dikirim. |
| `cuti.ditangguhkan_tugas_dinas` | Cuti | `simpeg_cuti_status` | Pegawai Pemohon | **Transformasi Khusus:** `status` = `Ditangguhkan`. Transformer wajib memuat tepat satu `LeaveApproval` untuk `leave_request_id` dengan `action` = `DUTY_POSTPONEMENT`, lalu menyanitasi nilai `komentar` sebagai `alasan_penangguhan`; `keterangan` = `"{alasan_penangguhan} — Hak cuti dilindungi dan reservasi saldo dilepas; silakan ajukan permohonan baru pada tahun berikutnya."`. Bila approval/alasan tidak tersedia, tidak tunggal, kosong, atau gagal privacy guard, pengiriman *fail-closed* / tidak dikirim. |
| `cuti.dikembalikan_karena_rollover` | Cuti | `simpeg_cuti_status` | Pegawai Pemohon | **Transformasi Khusus:** `status` = `Perubahan`; `keterangan` = `"Pengajuan cuti tahun {source_year} ({jumlah_pengajuan} berkas) dikembalikan karena proses rollover saldo; silakan periksa daftar permohonan dan ajukan kembali pada tahun {target_year}."`; `tautan_detail` mengarah ke daftar riwayat permohonan cuti |
| `cuti.perlu_perubahan` | Cuti | `simpeg_cuti_status` | Pegawai Pemohon | `status` = `Perubahan`. Payload runtime wajib membawa `leave_approval_id` yang dibuat oleh aksi `REQUEST_CHANGES`; transformer hanya boleh memuat approval tersebut bila `leave_request_id` dan `action` = `REQUEST_CHANGES` cocok, lalu menyanitasi `komentar` sebagai `keterangan`. Tidak boleh memilih approval terbaru berdasarkan waktu. Bila asosiasi immutable belum tersedia, tidak cocok, atau gagal privacy guard, pengiriman *fail-closed* / tidak dikirim. |
| `cuti.tidak_disetujui` | Cuti | `simpeg_cuti_status` | Pegawai Pemohon | `status` = `Tidak Disetujui`. Payload runtime wajib membawa `leave_approval_id` yang dibuat oleh aksi `NOT_APPROVED`; transformer hanya boleh memuat approval tersebut bila `leave_request_id` dan `action` = `NOT_APPROVED` cocok, lalu menyanitasi `komentar` sebagai `keterangan`. Tidak boleh memilih approval terbaru berdasarkan waktu. Bila asosiasi immutable belum tersedia, tidak cocok, kosong, atau gagal privacy guard, pengiriman *fail-closed* / tidak dikirim. |
| `ews.kenaikan_pangkat` | EWS | `simpeg_ews_pengingat` | Pegawai & Admin Kepegawaian | `jenis_peringatan` = `"Kenaikan Pangkat"` (hanya dipublikasikan untuk pegawai yang memenuhi syarat / eligible); `sisa_waktu` diformat sesuai selisih tanggal target aktual |
| `ews.kgb` | EWS | `simpeg_ews_pengingat` | Pegawai & Admin Kepegawaian | `jenis_peringatan` = `"Kenaikan Gaji Berkala (KGB)"`; `sisa_waktu` diformat sesuai selisih tanggal target aktual |
| `ews.pensiun` | EWS | `simpeg_ews_pengingat` | Pegawai & Admin Kepegawaian | `jenis_peringatan` = `"Batas Usia Pensiun (BUP)"`; `sisa_waktu` diformat sesuai selisih tanggal target aktual |
| `ews.kontrak_pppk` | EWS | `simpeg_ews_pengingat` | Pegawai & Admin Kepegawaian | `jenis_peringatan` = `"Kontrak PPPK"`; `sisa_waktu` diformat sesuai selisih tanggal target aktual |
| `ews.satyalancana` | EWS | `simpeg_ews_pengingat` | Pegawai & Admin Kepegawaian | `jenis_peringatan` = `"Satyalancana Karya Satya " . $alert->satyalancana_years . " Tahun" . (($data['is_eligible'] ?? true) ? '' : ' (Kelayakan Masa Kerja Belum Terpenuhi)')`; **Aturan Validasi:** Wajib memuat `satyalancana_years` bernilai tepat `10`, `20`, atau `30` dari relasi record `ews_alert_id` (jika null atau di luar himpunan tersebut, pengiriman fail-closed / tidak dikirim) |

---

## ⚙️ 5. Ketentuan Teknis Implementasi & Dependensi Provider

1. **Kontrak Runtime Eksternal**:
   - Nama teknis template ID resmi, nama variabel runtime, kode bahasa (misal `id` / `id_ID`), dan konfigurasi tombol tautan URL (*call-to-action button*) akan mengikuti respon resmi dari Meta / WhatsApp Provider yang dikembalikan oleh LLDIKTI Wilayah XVI.
   - Setelah artefak resmi diterima, kontrak tersebut dipasang melalui secret `SIMPEG_WHATSAPP_TEMPLATE_CONFIGURATION` berbentuk JSON dengan dua bagian: `event_templates` (event internal ke template provider) dan `templates` (setiap template memuat `id`, `language`, `variables_map`, `button`, serta `archetype` bila template dipecah per-event). Konfigurasi JSON tidak valid atau kontrak yang tidak lengkap membuat readiness tetap `false`; tidak ada fallback ke nama variabel proposal.
2. **Klausul Pemecahan Template (*Split per-Event*)**:
   - Jika pihak Meta / Provider menolak generalisasi model template (misalnya meminta template terpisah untuk masing-masing jenis cuti atau masing-masing event EWS), tim pengembang akan memecah template tersebut per-event dengan daftar variabel yang telah disetujui, **tanpa mengubah arsitektur domain event internal SIMPEG** (K-MTG-05A.3).
3. **Kesiapan Integrasi (*Fail-Closed Guard & Full Readiness Dependencies*)**:
   - Sesuai ketetapan K-MTG-05.3, US-6.5 AC-4, serta dependensi kesiapan K-MTG-07 (OQ-MTG-06) dan Issue #13, sebelum seluruh dependensi implementasi eksternal—meliputi: penetapan provider final, kontrak API resmi, pemetaan exact variable runtime, kode bahasa terdaftar, konfigurasi tombol URL (*call-to-action button*), kredensial resmi (*API key / secret token*), template ID resmi yang disetujui Meta, nomor uji terdaftar, akses sandbox, sumber nomor penerima kanonis terverifikasi, asosiasi immutable untuk event keputusan yang memuat catatan, serta verifikasi status kesiapan (*readiness flag*)—diterima dan divalidasi secara formal dari LLDIKTI Wilayah XVI, adapter WhatsApp di sisi aplikasi tetap dalam kondisi **nonaktif / fail-closed** dan dispatcher dilarang memanggil layanan eksternal tersebut.
