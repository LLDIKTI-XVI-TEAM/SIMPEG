<?php

namespace App\Http\Requests\Referensi;

use App\Models\RefJabatan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJabatanRequest extends FormRequest
{
    /** Pengelolaan referensi jabatan mengikuti permission Data Master. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('reference_tables.manage');
    }

    /**
     * Aturan sama dengan penambahan, kecuali keunikan nama yang mengabaikan baris
     * yang sedang disunting agar admin dapat mengubah kolom lain tanpa mengganti nama.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $jabatanRoute = $this->route('jabatan');
        $jabatan = $jabatanRoute instanceof RefJabatan ? $jabatanRoute : null;
        $jabatanId = $jabatan?->id;

        return [
            'nama' => ['required', 'string', 'max:255', Rule::unique('ref_jabatan', 'nama')->ignore($jabatanId)],
            // Referensi nonaktif hanya diizinkan bila memang nilai yang sudah tersimpan pada
            // baris ini, supaya admin dapat menyunting kolom lain tanpa dipaksa mengganti
            // jenis jabatan atau eselon yang kebetulan sudah dinonaktifkan.
            'jenis_jabatan_id' => ['nullable', 'uuid', Rule::exists('ref_jenis_jabatan', 'id')
                ->where(fn ($query) => $this->izinkanRelasiTersimpan($query, $jabatan?->jenis_jabatan_id))],
            'eselon_id' => ['nullable', 'uuid', Rule::exists('ref_eselon', 'id')
                ->where(fn ($query) => $this->izinkanRelasiTersimpan($query, $jabatan?->eselon_id))],
            'default_bup' => ['nullable', 'integer', 'min:50', 'max:70'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Membatasi pilihan pada baris aktif, ditambah satu baris nonaktif yang sudah terpasang.
     */
    private function izinkanRelasiTersimpan(mixed $query, ?string $idTersimpan): void
    {
        $query->where('is_active', true);

        if ($idTersimpan !== null) {
            $query->orWhere('id', $idTersimpan);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama' => 'Nama jabatan',
            'jenis_jabatan_id' => 'Jenis jabatan',
            'eselon_id' => 'Eselon',
            'default_bup' => 'BUP default',
            'keterangan' => 'Keterangan',
        ];
    }
}
