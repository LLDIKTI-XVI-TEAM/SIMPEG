<?php

namespace Database\Seeders;

use App\Models\NotificationEventChannel;
use App\Models\RefAgama;
use App\Models\RefBup;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisCuti;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisKelamin;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
use App\Models\RefNotificationChannel;
use App\Models\RefStatusPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        // §16.1 ref_golongan (17 records)
        $golongan = [
            ['kode' => 'I/a', 'nama' => 'Juru Muda', 'urutan' => 1],
            ['kode' => 'I/b', 'nama' => 'Juru Muda Tingkat 1', 'urutan' => 2],
            ['kode' => 'I/c', 'nama' => 'Juru', 'urutan' => 3],
            ['kode' => 'I/d', 'nama' => 'Juru Tingkat 1', 'urutan' => 4],
            ['kode' => 'II/a', 'nama' => 'Pengatur Muda', 'urutan' => 5],
            ['kode' => 'II/b', 'nama' => 'Pengatur Muda Tingkat 1', 'urutan' => 6],
            ['kode' => 'II/c', 'nama' => 'Pengatur', 'urutan' => 7],
            ['kode' => 'II/d', 'nama' => 'Pengatur Tingkat 1', 'urutan' => 8],
            ['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 9],
            ['kode' => 'III/b', 'nama' => 'Penata Muda Tingkat 1', 'urutan' => 10],
            ['kode' => 'III/c', 'nama' => 'Penata', 'urutan' => 11],
            ['kode' => 'III/d', 'nama' => 'Penata Tingkat 1', 'urutan' => 12],
            ['kode' => 'IV/a', 'nama' => 'Pembina', 'urutan' => 13],
            ['kode' => 'IV/b', 'nama' => 'Pembina Tingkat 1', 'urutan' => 14],
            ['kode' => 'IV/c', 'nama' => 'Pembina Utama Muda', 'urutan' => 15],
            ['kode' => 'IV/d', 'nama' => 'Pembina Utama Madya', 'urutan' => 16],
            ['kode' => 'IV/e', 'nama' => 'Pembina Utama', 'urutan' => 17],
        ];

        foreach ($golongan as $item) {
            RefGolongan::firstOrCreate(['kode' => $item['kode']], $item);
        }

        // §16.2 ref_jenis_jabatan
        $jenisJabatan = [
            ['nama' => 'Struktural', 'maks_usia_pensiun' => 60, 'catatan' => 'Dapat disesuaikan berdasarkan jabatan detail'],
            ['nama' => 'Fungsional Tertentu', 'maks_usia_pensiun' => 58, 'catatan' => 'Mengikuti jenjang atau jabatan detail; beberapa 60 tahun'],
            ['nama' => 'Fungsional Umum / Pelaksana', 'maks_usia_pensiun' => 58, 'catatan' => 'Default umum'],
            ['nama' => 'Jabatan Akademik / Dosen', 'maks_usia_pensiun' => 65, 'catatan' => 'Disiapkan untuk pengembangan Dosen DPK pada fase berikutnya'],
        ];

        foreach ($jenisJabatan as $item) {
            RefJenisJabatan::firstOrCreate(['nama' => $item['nama']], $item);
        }

        $jenisJabatanByNama = RefJenisJabatan::pluck('id', 'nama');

        // §16.3 ref_eselon
        $eselon = [
            ['kode' => 'I.a', 'nama' => 'Eselon I.a'],
            ['kode' => 'I.b', 'nama' => 'Eselon I.b'],
            ['kode' => 'II.a', 'nama' => 'Eselon II.a'],
            ['kode' => 'II.b', 'nama' => 'Eselon II.b'],
            ['kode' => 'III.a', 'nama' => 'Eselon III.a'],
            ['kode' => 'III.b', 'nama' => 'Eselon III.b'],
            ['kode' => 'IV.a', 'nama' => 'Eselon IV.a'],
            ['kode' => 'IV.b', 'nama' => 'Eselon IV.b'],
        ];

        foreach ($eselon as $item) {
            RefEselon::firstOrCreate(['kode' => $item['kode']], $item);
        }

        // §16.4 ref_jenis_cuti
        $jenisCuti = [
            ['nama' => 'Cuti Tahunan', 'code' => 'tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
            ['nama' => 'Cuti Sakit', 'code' => 'sakit', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
            ['nama' => 'Cuti Melahirkan', 'code' => 'melahirkan', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
            ['nama' => 'Cuti Karena Alasan Penting', 'code' => 'alasan_penting', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => false],
            ['nama' => 'Cuti Besar', 'code' => 'besar', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => true],
            ['nama' => 'Cuti Luar Tanggungan Negara (CLTN)', 'code' => 'cltn', 'mengurangi_saldo_tahunan' => false, 'khusus_pns' => true],
        ];

        foreach ($jenisCuti as $item) {
            // Metadata stabil mencegah rule cuti bergantung pada teks nama yang mudah berubah.
            RefJenisCuti::updateOrCreate(['nama' => $item['nama']], $item);
        }

        // §16.5 ref_agama
        $agama = ['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Konghucu'];

        foreach ($agama as $nama) {
            RefAgama::firstOrCreate(['nama' => $nama]);
        }

        // §16.6 ref_jenis_kelamin
        $jenisKelamin = [
            ['kode' => 'L', 'nama' => 'Laki-laki'],
            ['kode' => 'P', 'nama' => 'Perempuan'],
        ];

        foreach ($jenisKelamin as $item) {
            RefJenisKelamin::firstOrCreate(['kode' => $item['kode']], $item);
        }

        // §16.7 ref_status_perkawinan
        $statusKawin = ['Belum Menikah', 'Menikah', 'Duda / Janda'];

        foreach ($statusKawin as $nama) {
            RefStatusPerkawinan::firstOrCreate(['nama' => $nama]);
        }

        // §16.8 ref_jenjang_pendidikan
        $jenjang = [
            ['nama' => 'SD', 'urutan' => 1],
            ['nama' => 'SMP', 'urutan' => 2],
            ['nama' => 'SMA / SMK / Sederajat', 'urutan' => 3],
            ['nama' => 'D1', 'urutan' => 4],
            ['nama' => 'D2', 'urutan' => 5],
            ['nama' => 'D3', 'urutan' => 6],
            ['nama' => 'D4 / S1', 'urutan' => 7],
            ['nama' => 'S2 / Profesi', 'urutan' => 8],
            ['nama' => 'S3', 'urutan' => 9],
        ];

        foreach ($jenjang as $item) {
            RefJenjangPendidikan::firstOrCreate(['nama' => $item['nama']], $item);
        }

        // §16.9 ref_jenis_pegawai
        foreach (['PNS', 'CPNS', 'PPPK'] as $nama) {
            RefJenisPegawai::firstOrCreate(['nama' => $nama]);
        }

        $statusPegawai = [
            ['kode' => 'AKTIF', 'nama' => 'Aktif', 'kelompok' => 'Aktif', 'keterangan' => 'Pegawai aktif.', 'is_default' => true],
            ['kode' => 'NONAKTIF', 'nama' => 'Nonaktif', 'kelompok' => 'Nonaktif', 'keterangan' => 'Pegawai tidak aktif.', 'is_default' => false],
            ['kode' => 'PENSIUN', 'nama' => 'Pensiun', 'kelompok' => 'Nonaktif', 'keterangan' => 'Pegawai telah pensiun.', 'is_default' => false],
            ['kode' => 'MUTASI', 'nama' => 'Mutasi', 'kelompok' => 'Nonaktif', 'keterangan' => 'Pegawai mutasi keluar.', 'is_default' => false],
            ['kode' => 'CLTN', 'nama' => 'Cuti Luar Tanggungan Negara', 'kelompok' => 'Nonaktif', 'keterangan' => 'Pegawai menjalani cuti luar tanggungan negara.', 'is_default' => false],
            ['kode' => 'PERPANJANGAN_CLTN', 'nama' => 'Perpanjangan CLTN', 'kelompok' => 'Nonaktif', 'keterangan' => 'Cuti luar tanggungan negara diperpanjang.', 'is_default' => false],
            ['kode' => 'TUGAS_BELAJAR', 'nama' => 'Tugas Belajar', 'kelompok' => 'Aktif/khusus', 'keterangan' => 'Pegawai menjalani tugas belajar.', 'is_default' => false],
            ['kode' => 'PEMBERHENTIAN_SEMENTARA', 'nama' => 'Pemberhentian Sementara', 'kelompok' => 'Nonaktif', 'keterangan' => 'Pegawai diberhentikan sementara.', 'is_default' => false],
            ['kode' => 'WAJIB_MILITER', 'nama' => 'Wajib Militer', 'kelompok' => 'Nonaktif/khusus', 'keterangan' => 'Pegawai menjalani wajib militer.', 'is_default' => false],
            ['kode' => 'HILANG', 'nama' => 'PNS Dinyatakan Hilang', 'kelompok' => 'Nonaktif/khusus', 'keterangan' => 'PNS dinyatakan hilang.', 'is_default' => false],
        ];

        foreach ($statusPegawai as $item) {
            RefStatusPegawai::updateOrCreate(['kode' => $item['kode']], $item);
        }

        // ref_hari_libur — subset awal 2026 untuk baseline kalkulasi hari kerja; data mengikuti kalender libur nasional/SKB yang berlaku
        $hariLibur = [
            ['tanggal' => '2026-01-01', 'nama' => 'Tahun Baru Masehi', 'tahun' => 2026, 'is_cuti_bersama' => false],
            ['tanggal' => '2026-03-20', 'nama' => 'Hari Raya Idul Fitri', 'tahun' => 2026, 'is_cuti_bersama' => false],
            ['tanggal' => '2026-03-21', 'nama' => 'Hari Raya Idul Fitri', 'tahun' => 2026, 'is_cuti_bersama' => false],
            ['tanggal' => '2026-05-27', 'nama' => 'Hari Raya Idul Adha', 'tahun' => 2026, 'is_cuti_bersama' => false],
            ['tanggal' => '2026-08-17', 'nama' => 'Hari Kemerdekaan Republik Indonesia', 'tahun' => 2026, 'is_cuti_bersama' => false],
            ['tanggal' => '2026-12-25', 'nama' => 'Hari Raya Natal', 'tahun' => 2026, 'is_cuti_bersama' => false],
        ];

        foreach ($hariLibur as $item) {
            if (! DB::table('ref_hari_libur')->where('tanggal', $item['tanggal'])->exists()) {
                DB::table('ref_hari_libur')->insert([
                    ...$item,
                    'id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $kepalaLembaga = RefUnitKerja::firstOrCreate(
            ['nama' => 'Kepala Lembaga'],
            ['parent_id' => null, 'level' => 0, 'jenis_unit' => 'lembaga', 'is_active' => true, 'keterangan' => 'Root struktur organisasi LLDIKTI.'],
        );

        $kepalaBagianUmum = RefUnitKerja::firstOrCreate(
            ['nama' => 'Kepala Bagian Umum'],
            ['parent_id' => $kepalaLembaga->id, 'level' => 1, 'jenis_unit' => 'bagian', 'is_active' => true, 'keterangan' => 'Bagian umum pada struktur awal.'],
        );

        foreach ([
            ['nama' => 'Ketua Tim Kerja Substansi', 'parent_id' => $kepalaLembaga->id, 'level' => 1, 'jenis_unit' => 'tim_kerja'],
            ['nama' => 'Urusan Organisasi Tata Laksana dan SDM', 'parent_id' => $kepalaBagianUmum->id, 'level' => 2, 'jenis_unit' => 'urusan'],
            ['nama' => 'Urusan Keuangan', 'parent_id' => $kepalaBagianUmum->id, 'level' => 2, 'jenis_unit' => 'urusan'],
            ['nama' => 'Urusan Humas / Layanan Pendukung', 'parent_id' => $kepalaBagianUmum->id, 'level' => 2, 'jenis_unit' => 'urusan'],
        ] as $item) {
            RefUnitKerja::firstOrCreate(
                ['nama' => $item['nama']],
                [...$item, 'is_active' => true],
            );
        }

        $jabatan = [
            ['nama' => 'Analis Kepegawaian', 'jenis' => 'Fungsional Umum / Pelaksana'],
            ['nama' => 'Analis SDM', 'jenis' => 'Fungsional Umum / Pelaksana'],
            ['nama' => 'Pengelola Data', 'jenis' => 'Fungsional Umum / Pelaksana'],
            ['nama' => 'Perencana', 'jenis' => 'Fungsional Tertentu'],
            ['nama' => 'Arsiparis', 'jenis' => 'Fungsional Tertentu'],
            ['nama' => 'Kepala Subbagian Umum', 'jenis' => 'Struktural'],
            ['nama' => 'Kepala Sub Bagian Web', 'jenis' => 'Struktural'],
        ];

        foreach ($jabatan as $item) {
            RefJabatan::firstOrCreate(
                ['nama' => $item['nama']],
                [
                    'jenis_jabatan_id' => $jenisJabatanByNama[$item['jenis']] ?? null,
                    'is_active' => true,
                    'keterangan' => 'Referensi awal jabatan SIMPEG.',
                ],
            );
        }

        // §16.12 ref_bup
        $bup = [
            ['jenis_jabatan' => 'Pelaksana / Fungsional Umum', 'bup_tahun' => 58],
            ['jenis_jabatan' => 'Fungsional Ahli Pertama', 'bup_tahun' => 58],
            ['jenis_jabatan' => 'Fungsional Ahli Muda', 'bup_tahun' => 58],
            ['jenis_jabatan' => 'Fungsional Ahli Madya', 'bup_tahun' => 60],
            ['jenis_jabatan' => 'Pimpinan Tinggi', 'bup_tahun' => 60],
            ['jenis_jabatan' => 'Struktural (Eselon I-II)', 'bup_tahun' => 60],
        ];

        foreach ($bup as $item) {
            RefBup::firstOrCreate(['jenis_jabatan' => $item['jenis_jabatan']], $item);
        }

        foreach ([
            ['code' => 'in_app', 'name' => 'Notifikasi dalam aplikasi', 'is_enabled' => true, 'config' => null],
            ['code' => 'email', 'name' => 'Email', 'is_enabled' => true, 'config' => null],
            ['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => false, 'config' => null],
        ] as $channel) {
            RefNotificationChannel::firstOrCreate(['code' => $channel['code']], $channel);
        }

        $supportedNotificationEvents = [
            'cuti.pengajuan_baru',
            'cuti.menunggu_persetujuan',
            'cuti.disetujui',
            'cuti.ditunda',
            'cuti.perlu_perubahan',
            'cuti.tidak_disetujui',
            'ews.kenaikan_pangkat',
            'ews.kgb',
            'ews.pensiun',
            'ews.kontrak_pppk',
            'ews.satyalancana',
            'ews.tidak_perlu',
            'status_pegawai.diubah',
        ];
        $notificationChannels = RefNotificationChannel::query()
            ->whereIn('code', ['in_app', 'email'])
            ->get();

        // Default pasangan aktif hanya dibuat sekali agar pilihan operator tetap terjaga saat seeder dijalankan ulang.
        foreach ($supportedNotificationEvents as $eventKey) {
            foreach ($notificationChannels as $channel) {
                NotificationEventChannel::firstOrCreate(
                    [
                        'event_key' => $eventKey,
                        'notification_channel_id' => $channel->id,
                    ],
                    ['is_enabled' => true],
                );
            }
        }

        // Kegagalan scheduler adalah alert operasional terpisah; in-app mempertahankan perilaku lama tanpa mengada-adakan email.
        $inAppChannel = $notificationChannels->firstWhere('code', 'in_app');
        if ($inAppChannel !== null) {
            NotificationEventChannel::firstOrCreate(
                [
                    'event_key' => 'ews.scheduler_failed',
                    'notification_channel_id' => $inAppChannel->id,
                ],
                ['is_enabled' => true],
            );
        }
    }
}
