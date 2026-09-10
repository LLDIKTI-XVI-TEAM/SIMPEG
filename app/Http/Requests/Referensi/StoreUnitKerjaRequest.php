<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefUnitKerja;
use App\Services\Referensi\UnitKerjaHierarchyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUnitKerjaRequest extends FormRequest
{
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
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
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
            $parentId = $this->exists('parent_id')
                ? $this->input('parent_id')
                : $unit?->parent_id;

            if (! is_string($parentId) || $parentId === '') {
                return;
            }

            if ($unit !== null && $parentId === $unit->id) {
                $validator->errors()->add('parent_id', 'Unit induk tidak boleh unit itu sendiri.');

                return;
            }

            $message = app(UnitKerjaHierarchyService::class)->parentValidationMessage(
                $parentId,
                $unit?->id,
                $this->allowsCurrentInactiveParent($unit, $parentId),
            );

            if ($message !== null) {
                $validator->errors()->add('parent_id', $message);
            }
        });
    }

    /**
     * Unit nonaktif boleh mempertahankan relasi lama saat metadata diedit.
     * Parent nonaktif tetap tidak boleh dipilih sebagai relasi baru.
     */
    private function allowsCurrentInactiveParent(?RefUnitKerja $unit, string $parentId): bool
    {
        return $unit !== null
            && ! $unit->is_active
            && $unit->parent_id === $parentId;
    }

    protected function boundUnit(): ?RefUnitKerja
    {
        $unit = $this->route('unitKerja');

        return $unit instanceof RefUnitKerja ? $unit : null;
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
