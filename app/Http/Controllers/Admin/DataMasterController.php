<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Services\Referensi\ReferenceTableCatalog;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class DataMasterController extends Controller
{
    /**
     * Halaman Data Master: tab yang sudah punya CRUD membaca reference table
     * langsung dari database beserta jumlah pemakainya, sehingga admin tahu
     * item mana yang hanya boleh dinonaktifkan (kebijakan hapus hybrid).
     * Seluruh tabel referensi berukuran kecil dan tidak tumbuh mengikuti
     * data pegawai, jadi pemuatan penuh tanpa paginasi aman di sini.
     */
    /**
     * Label tampilan jenis unit; kuncinya mengikuti kosakata resmi pada
     * StoreUnitKerjaRequest agar dropdown dan validasi tidak bisa berbeda.
     *
     * @var array<string, string>
     */
    private const JENIS_UNIT_LABELS = [
        'lembaga' => 'Lembaga',
        'bagian' => 'Bagian',
        'tim_kerja' => 'Tim Kerja',
        'urusan' => 'Urusan',
    ];

    public function index(ReferenceUsageService $usage): View
    {
        $statusPegawai = RefStatusPegawai::query()
            ->orderByDesc('is_default')
            ->orderBy('nama')
            ->get();
        $unitKerja = $this->unitKerjaDepthFirst();

        return view('admin.data-master.index', [
            'golongan' => RefGolongan::query()->orderBy('urutan')->orderBy('kode')->get(),
            'golonganUsage' => $usage->usageCountMap(RefGolongan::class),
            'jenisJabatan' => RefJenisJabatan::query()->orderBy('nama')->get(),
            'jenisJabatanUsage' => $usage->usageCountMap(RefJenisJabatan::class),
            'eselon' => RefEselon::query()->orderBy('kode')->get(),
            'eselonUsage' => $usage->usageCountMap(RefEselon::class),
            'jenjangPendidikan' => RefJenjangPendidikan::query()->orderBy('urutan')->orderBy('nama')->get(),
            'jenjangPendidikanUsage' => $usage->usageCountMap(RefJenjangPendidikan::class),
            'unitKerja' => $unitKerja,
            'unitKerjaUsage' => $usage->usageCountMap(RefUnitKerja::class),
            'unitKerjaJenisOptions' => self::JENIS_UNIT_LABELS,
            // Daftar id yang tidak boleh menjadi induk (diri sendiri beserta
            // sub-unitnya) dihitung sekali di sini agar dropdown pada setiap
            // baris tidak menelusuri pohon ulang saat render.
            'unitKerjaCycleGuard' => $this->cycleGuardMap($unitKerja),
            'statusPegawai' => $statusPegawai,
            'statusPegawaiUsage' => $usage->usageCountMap(RefStatusPegawai::class),
            // Alasan proteksi dihitung di sini (bukan di Blade) agar view
            // bebas dari pemanggilan logika domain saat render.
            'statusPegawaiProtection' => $statusPegawai->mapWithKeys(
                fn (RefStatusPegawai $status) => [$status->id => ReferenceTableCatalog::protectionReason($status)]
            )->all(),
        ]);
    }

    /**
     * Menyusun unit kerja secara depth-first supaya sub-unit selalu tampil
     * tepat di bawah induknya. Penyusunan dilakukan di sini, bukan di Blade,
     * agar view tidak memicu query tambahan saat merender pohon.
     *
     * @return Collection<int, RefUnitKerja>
     */
    private function unitKerjaDepthFirst(): Collection
    {
        $semua = RefUnitKerja::query()->orderBy('nama')->get();
        $anakPerInduk = $semua->groupBy(fn (RefUnitKerja $unit): string => (string) $unit->parent_id);

        $tersusun = collect();
        // Penanda kunjungan melindungi dua hal sekaligus: data lama yang
        // rantai induknya melingkar tidak membuat penelusuran tanpa ujung,
        // dan pencarian unit yang terlewat tidak perlu memindai ulang koleksi.
        $dikunjungi = [];
        $susun = function (string $indukId) use (&$susun, $anakPerInduk, $tersusun, &$dikunjungi): void {
            foreach ($anakPerInduk->get($indukId, collect()) as $unit) {
                $unitId = (string) $unit->getKey();

                if (isset($dikunjungi[$unitId])) {
                    continue;
                }

                $dikunjungi[$unitId] = true;
                $tersusun->push($unit);
                $susun($unitId);
            }
        };

        // Root memiliki parent_id null yang menjadi string kosong saat dikelompokkan.
        $susun('');

        // Unit yang induknya tidak ikut termuat, termasuk yang terjebak
        // lingkaran, tetap ditampilkan agar tidak hilang diam-diam dari
        // halaman dan admin dapat memperbaikinya.
        $terlewat = $semua->reject(fn (RefUnitKerja $unit): bool => isset($dikunjungi[(string) $unit->getKey()]));

        return $tersusun->concat($terlewat)->values();
    }

    /**
     * Memetakan setiap unit ke daftar id yang tidak boleh menjadi induknya,
     * yaitu dirinya sendiri beserta seluruh sub-unit di bawahnya. Peta ini
     * menjaga dropdown induk agar tidak menawarkan pilihan yang membentuk
     * siklus; validasi server tetap menjadi penjaga terakhir.
     *
     * @param  Collection<int, RefUnitKerja>  $unitKerja
     * @return array<string, list<string>>
     */
    private function cycleGuardMap(Collection $unitKerja): array
    {
        $anakPerInduk = $unitKerja->groupBy(fn (RefUnitKerja $unit): string => (string) $unit->parent_id);

        // Penanda kunjungan menjaga penelusuran tetap berhenti pada data lama
        // yang rantai induknya sudah melingkar; tanpa ini membuka halaman
        // Data Master saja sudah cukup untuk menggantung proses.
        $kumpulkan = function (string $unitId, array $dikunjungi) use (&$kumpulkan, $anakPerInduk): array {
            if (isset($dikunjungi[$unitId])) {
                return [];
            }

            $dikunjungi[$unitId] = true;
            $terlarang = [$unitId];

            foreach ($anakPerInduk->get($unitId, collect()) as $anak) {
                $terlarang = array_merge($terlarang, $kumpulkan((string) $anak->getKey(), $dikunjungi));
            }

            return $terlarang;
        };

        return $unitKerja
            ->mapWithKeys(fn (RefUnitKerja $unit): array => [
                (string) $unit->getKey() => $kumpulkan((string) $unit->getKey(), []),
            ])
            ->all();
    }
}
