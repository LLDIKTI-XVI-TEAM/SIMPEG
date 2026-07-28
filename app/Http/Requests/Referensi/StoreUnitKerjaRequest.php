<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefUnitKerja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUnitKerjaRequest extends FormRequest
{
    /** Rantai induk calon parent bersih sampai unit tertinggi. */
    private const CHAIN_CLEAN = 'clean';

    /** Calon parent ternyata berada di bawah unit yang sedang diubah. */
    private const CHAIN_DESCENDANT = 'descendant';

    /** Rantai induk calon parent sudah membentuk lingkaran. */
    private const CHAIN_CORRUPT = 'corrupt';

    /**
     * Kosakata resmi jenis unit mengikuti struktur organisasi yang di-seed.
     * Nilai default kolom database ('unit_kerja') sengaja tidak diikutkan
     * karena tidak pernah dipakai struktur nyata; field ini dibuat wajib
     * supaya default tersebut tidak pernah tersimpan melalui form.
     *
     * @var list<string>
     */
    public const JENIS_UNIT = ['lembaga', 'bagian', 'tim_kerja', 'urusan'];

    public function authorize(): bool
    {
        // Data referensi memengaruhi seluruh dropdown dan riwayat pegawai;
        // hanya super_admin yang boleh mengelolanya (lapisan kedua di atas
        // role middleware pada route).
        return $this->user()?->role === 'super_admin';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Unik se-sistem ditegakkan di validasi karena database belum punya
            // constraint. Seeder dan beberapa laporan mencocokkan unit
            // berdasarkan nama, sehingga duplikat membuat hasilnya tidak pasti.
            'nama' => ['required', 'string', 'max:100', 'unique:ref_unit_kerja,nama'],
            'parent_id' => ['nullable', 'uuid', 'exists:ref_unit_kerja,id'],
            'jenis_unit' => ['required', 'string', 'in:'.implode(',', self::JENIS_UNIT)],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Select kosong mengirim string kosong; disamakan menjadi null agar
        // unit tersimpan sebagai root, bukan gagal aturan uuid.
        if ($this->input('parent_id') === '') {
            $this->merge(['parent_id' => null]);
        }
    }

    /**
     * Calon induk wajib berada pada rantai yang bersih. Pada update, guard
     * yang sama juga mencegah unit memilih diri sendiri atau keturunannya.
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

            if (! is_string($parentId) || $parentId === '') {
                return;
            }

            if ($unit !== null && $parentId === $unit->id) {
                $validator->errors()->add('parent_id', 'Unit induk tidak boleh unit itu sendiri.');

                return;
            }

            match ($this->parentChainVerdict($parentId, $unit?->id)) {
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
     * Menelusuri rantai induk calon parent dan membedakan rantai bersih,
     * keturunan unit saat update, serta lingkaran pada data lama.
     */
    private function parentChainVerdict(string $candidateParentId, ?string $unitId): string
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

            if ($unitId !== null && $parentId === $unitId) {
                return self::CHAIN_DESCENDANT;
            }

            $cursor = (string) $parentId;
        }

        return self::CHAIN_CLEAN;
    }

    protected function boundUnit(): ?RefUnitKerja
    {
        $unit = $this->route('unitKerja');

        return $unit instanceof RefUnitKerja ? $unit : null;
    }

    /**
     * Payload siap simpan. Level tidak pernah berasal dari input pengguna:
     * nilainya diturunkan dari induk supaya kedalaman pohon tidak dapat
     * dipalsukan lewat form. Disediakan sebagai method terpisah agar kontrak
     * validated() bawaan Laravel (akses per key) tetap utuh.
     *
     * @return array<string, mixed>
     */
    public function unitKerjaData(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $parentId = $data['parent_id'] ?? null;

        $data['level'] = $this->levelFromParent(is_string($parentId) ? $parentId : null);

        return $data;
    }

    protected function levelFromParent(?string $parentId): int
    {
        if ($parentId === null) {
            return 0;
        }

        return (int) (RefUnitKerja::query()->whereKey($parentId)->value('level') ?? 0) + 1;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nama' => 'Nama unit kerja',
            'parent_id' => 'Unit induk',
            'jenis_unit' => 'Jenis unit',
            'keterangan' => 'Keterangan',
        ];
    }
}
