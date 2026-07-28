<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefUnitKerja;
use Illuminate\Foundation\Http\FormRequest;

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
