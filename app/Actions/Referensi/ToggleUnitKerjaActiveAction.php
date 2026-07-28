<?php

namespace App\Actions\Referensi;

use App\Models\RefUnitKerja;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ToggleUnitKerjaActiveAction
{
    public function __construct(
        private readonly ToggleReferenceItemActiveAction $toggle,
    ) {}

    /**
     * Menonaktifkan/mengaktifkan unit kerja dengan satu aturan tambahan:
     * induk yang masih memiliki sub-unit aktif tidak boleh dinonaktifkan.
     * Menonaktifkan induk secara berantai akan mematikan seluruh cabang tanpa
     * jejak yang terlihat admin, sedangkan membiarkannya membuat sub-unit
     * aktif menggantung di bawah induk nonaktif. Pengaktifan kembali tidak
     * pernah dibatasi karena tidak menghilangkan apa pun.
     *
     * @throws ValidationException bila unit masih memiliki sub-unit aktif
     */
    public function execute(RefUnitKerja $unit, Request $request): void
    {
        if ($unit->is_active) {
            $anakAktif = RefUnitKerja::query()
                ->where('parent_id', $unit->getKey())
                ->where('is_active', true)
                ->count();

            if ($anakAktif > 0) {
                throw ValidationException::withMessages([
                    'referensi' => sprintf(
                        'Unit tidak dapat dinonaktifkan karena masih memiliki %d sub-unit aktif. Nonaktifkan sub-unit tersebut lebih dulu.',
                        $anakAktif,
                    ),
                ]);
            }
        }

        $this->toggle->execute($unit, $request);
    }
}
