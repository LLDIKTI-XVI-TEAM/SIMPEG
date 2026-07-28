<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefUnitKerja;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUnitKerjaRequest extends StoreUnitKerjaRequest
{
    /** Rantai induk calon parent bersih sampai unit tertinggi. */
    private const CHAIN_CLEAN = 'clean';

    /** Calon parent ternyata berada di bawah unit yang sedang diubah. */
    private const CHAIN_DESCENDANT = 'descendant';

    /** Rantai induk calon parent sudah membentuk lingkaran. */
    private const CHAIN_CORRUPT = 'corrupt';

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $unitId = $this->boundUnit()?->id;

        return [
            'nama' => ['required', 'string', 'max:100', Rule::unique('ref_unit_kerja', 'nama')->ignore($unitId)],
            'parent_id' => ['nullable', 'uuid', 'exists:ref_unit_kerja,id'],
            'jenis_unit' => ['required', 'string', 'in:'.implode(',', self::JENIS_UNIT)],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Induk tidak boleh diri sendiri maupun salah satu keturunannya. Tanpa
     * guard ini pohon dapat membentuk siklus sehingga penelusuran level dan
     * penyusunan daftar unit berjalan tanpa ujung.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Nilai yang sudah gagal aturan uuid/exists tidak boleh diquery:
            // PostgreSQL menolak sintaks uuid yang rusak dengan error 500,
            // bukan pesan validasi.
            if ($validator->errors()->has('parent_id')) {
                return;
            }

            $unit = $this->boundUnit();
            $parentId = $this->input('parent_id');

            if ($unit === null || ! is_string($parentId) || $parentId === '') {
                return;
            }

            if ($parentId === $unit->id) {
                $validator->errors()->add('parent_id', 'Unit induk tidak boleh unit itu sendiri.');

                return;
            }

            match ($this->parentChainVerdict($parentId, $unit->id)) {
                self::CHAIN_DESCENDANT => $validator->errors()->add(
                    'parent_id',
                    'Unit induk tidak boleh diambil dari sub-unit di bawahnya.',
                ),
                self::CHAIN_CORRUPT => $validator->errors()->add(
                    'parent_id',
                    'Rantai induk unit tujuan sudah membentuk lingkaran. Perbaiki struktur unit tersebut lebih dulu.',
                ),
                default => null,
            };
        });
    }

    /**
     * Menelusuri rantai induk calon parent ke atas dan membedakan tiga hasil:
     * rantai bersih, calon parent ternyata keturunan unit ini, atau rantai
     * yang sudah membentuk lingkaran. Lingkaran tidak boleh diperlakukan
     * sebagai rantai bersih karena unit akan ditempelkan ke struktur rusak
     * dan penelusuran level berikutnya tidak akan pernah berhenti.
     */
    private function parentChainVerdict(string $candidateParentId, string $unitId): string
    {
        $visited = [];
        $cursor = $candidateParentId;

        while ($cursor !== '') {
            if (isset($visited[$cursor])) {
                return self::CHAIN_CORRUPT;
            }

            $visited[$cursor] = true;

            $parentId = RefUnitKerja::query()->whereKey($cursor)->value('parent_id');

            if ($parentId === null) {
                return self::CHAIN_CLEAN;
            }

            if ($parentId === $unitId) {
                return self::CHAIN_DESCENDANT;
            }

            $cursor = (string) $parentId;
        }

        return self::CHAIN_CLEAN;
    }

    private function boundUnit(): ?RefUnitKerja
    {
        $unit = $this->route('unitKerja');

        return $unit instanceof RefUnitKerja ? $unit : null;
    }
}
