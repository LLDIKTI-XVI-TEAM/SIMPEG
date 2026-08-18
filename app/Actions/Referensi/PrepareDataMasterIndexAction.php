<?php

namespace App\Actions\Referensi;

use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Services\Referensi\ReferenceTableCatalog;
use App\Services\Referensi\ReferenceUsageService;
use Illuminate\Support\Collection;

final class PrepareDataMasterIndexAction
{
    /**
     * Label tampilan jenis unit mengikuti kosakata validasi agar dropdown
     * dan kontrak request tidak dapat berbeda.
     *
     * @var array<string, string>
     */
    private const JENIS_UNIT_LABELS = [
        'lembaga' => 'Lembaga',
        'bagian' => 'Bagian',
        'tim_kerja' => 'Tim Kerja',
        'urusan' => 'Urusan',
    ];

    public function __construct(
        private readonly ReferenceUsageService $usage,
    ) {}

    /**
     * Menyiapkan seluruh data referensi dan jumlah pemakai untuk halaman Data Master.
     * Koleksi dimuat penuh karena tabel referensi kecil dan tidak tumbuh mengikuti pegawai.
     *
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $statusPegawai = RefStatusPegawai::query()
            ->orderByDesc('is_default')
            ->orderBy('nama')
            ->get();
        $unitKerja = $this->unitKerjaDepthFirst();

        return [
            'golongan' => RefGolongan::query()->orderBy('urutan')->orderBy('kode')->get(),
            'golonganUsage' => $this->usage->usageCountMap(RefGolongan::class),
            'jenisJabatan' => RefJenisJabatan::query()->orderBy('nama')->get(),
            'jenisJabatanUsage' => $this->usage->usageCountMap(RefJenisJabatan::class),
            // Relasi jenis jabatan dan eselon diselesaikan dari koleksi pilihan yang sudah dimuat.
            'jabatan' => RefJabatan::query()->orderBy('nama')->get(),
            'jabatanUsage' => $this->usage->usageCountMap(RefJabatan::class),
            'eselon' => RefEselon::query()->orderBy('kode')->get(),
            'eselonUsage' => $this->usage->usageCountMap(RefEselon::class),
            'jenjangPendidikan' => RefJenjangPendidikan::query()->orderBy('urutan')->orderBy('nama')->get(),
            'jenjangPendidikanUsage' => $this->usage->usageCountMap(RefJenjangPendidikan::class),
            'programStudi' => RefProgramStudi::query()->orderBy('nama')->get(),
            'programStudiUsage' => $this->usage->usageCountMap(RefProgramStudi::class),
            'unitKerja' => $unitKerja,
            'unitKerjaUsage' => $this->usage->usageCountMap(RefUnitKerja::class),
            'unitKerjaJenisOptions' => self::JENIS_UNIT_LABELS,
            // Hitung sekali agar setiap dropdown tidak menelusuri pohon ulang saat render.
            'unitKerjaCycleGuard' => $this->cycleGuardMap($unitKerja),
            'statusPegawai' => $statusPegawai,
            'statusPegawaiUsage' => $this->usage->usageCountMap(RefStatusPegawai::class),
            // View hanya menerima alasan proteksi siap-render tanpa memanggil logika domain.
            'statusPegawaiProtection' => $statusPegawai->mapWithKeys(
                fn (RefStatusPegawai $status) => [$status->id => ReferenceTableCatalog::protectionReason($status)]
            )->all(),
        ];
    }

    /**
     * Menyusun unit kerja secara depth-first supaya sub-unit tampil tepat di bawah induknya.
     *
     * @return Collection<int, RefUnitKerja>
     */
    private function unitKerjaDepthFirst(): Collection
    {
        $semua = RefUnitKerja::query()->orderBy('nama')->get();
        $anakPerInduk = $semua->groupBy(fn (RefUnitKerja $unit): string => (string) $unit->parent_id);

        $tersusun = collect();
        // Penanda kunjungan mencegah data lama yang melingkar membuat penelusuran tanpa ujung.
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

        // Unit yatim atau bersiklus tetap ditampilkan agar admin dapat memperbaiki datanya.
        $terlewat = $semua->reject(fn (RefUnitKerja $unit): bool => isset($dikunjungi[(string) $unit->getKey()]));

        return $tersusun->concat($terlewat)->values();
    }

    /**
     * Memetakan setiap unit ke id dirinya dan seluruh turunan yang tidak boleh menjadi induk.
     *
     * @param  Collection<int, RefUnitKerja>  $unitKerja
     * @return array<string, list<string>>
     */
    private function cycleGuardMap(Collection $unitKerja): array
    {
        $anakPerInduk = $unitKerja->groupBy(fn (RefUnitKerja $unit): string => (string) $unit->parent_id);

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
