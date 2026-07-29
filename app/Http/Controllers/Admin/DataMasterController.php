<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPegawai;
use App\Services\Referensi\ReferenceTableCatalog;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Contracts\View\View;

class DataMasterController extends Controller
{
    /**
     * Halaman Data Master: tab yang sudah punya CRUD membaca reference table
     * langsung dari database beserta jumlah pemakainya, sehingga admin tahu
     * item mana yang hanya boleh dinonaktifkan (kebijakan hapus hybrid).
     * Seluruh tabel referensi berukuran kecil dan tidak tumbuh mengikuti
     * data pegawai, jadi pemuatan penuh tanpa paginasi aman di sini.
     */
    public function index(ReferenceUsageService $usage): View
    {
        $statusPegawai = RefStatusPegawai::query()
            ->orderByDesc('is_default')
            ->orderBy('nama')
            ->get();

        return view('admin.data-master.index', [
            'golongan' => RefGolongan::query()->orderBy('urutan')->orderBy('kode')->get(),
            'golonganUsage' => $usage->usageCountMap(RefGolongan::class),
            'jenisJabatan' => RefJenisJabatan::query()->orderBy('nama')->get(),
            'jenisJabatanUsage' => $usage->usageCountMap(RefJenisJabatan::class),
            'eselon' => RefEselon::query()->orderBy('kode')->get(),
            'eselonUsage' => $usage->usageCountMap(RefEselon::class),
            'jenjangPendidikan' => RefJenjangPendidikan::query()->orderBy('urutan')->orderBy('nama')->get(),
            'jenjangPendidikanUsage' => $usage->usageCountMap(RefJenjangPendidikan::class),
            'statusPegawai' => $statusPegawai,
            'statusPegawaiUsage' => $usage->usageCountMap(RefStatusPegawai::class),
            // Alasan proteksi dihitung di sini (bukan di Blade) agar view
            // bebas dari pemanggilan logika domain saat render.
            'statusPegawaiProtection' => $statusPegawai->mapWithKeys(
                fn (RefStatusPegawai $status) => [$status->id => ReferenceTableCatalog::protectionReason($status)]
            )->all(),
        ]);
    }
}
