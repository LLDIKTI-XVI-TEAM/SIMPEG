<?php

namespace App\Actions\Referensi;

use App\Models\RefUnitKerja;
use Illuminate\Validation\ValidationException;

class SyncUnitKerjaLevelAction
{
    /**
     * Menyelaraskan level unit beserta seluruh keturunannya setelah induk
     * berubah. Level tidak boleh dibiarkan basi karena dipakai untuk
     * menyusun urutan dan indentasi daftar unit; tanpa penyelarasan ini
     * sub-unit tampil pada kedalaman yang salah setelah pemindahan.
     *
     * @throws ValidationException bila struktur unit sudah membentuk lingkaran
     */
    public function execute(RefUnitKerja $unit): void
    {
        $parentLevel = $unit->parent_id === null
            ? -1
            : (int) (RefUnitKerja::query()->whereKey($unit->parent_id)->value('level') ?? -1);

        $dikunjungi = [];
        $this->applyLevel($unit, $parentLevel + 1, $dikunjungi);
    }

    /**
     * Turun secara rekursif melalui anak langsung. Kedalaman struktur
     * organisasi hanya beberapa tingkat sehingga penelusuran ini tetap murah,
     * dan penulisan dilakukan hanya bila nilainya benar-benar berubah.
     *
     * Penanda kunjungan wajib ada: data lama yang rantai induknya sudah
     * melingkar membuat rekursi tanpa ujung dan menghabiskan proses.
     * Lingkaran dilaporkan sebagai kegagalan validasi agar admin memperbaiki
     * strukturnya, bukan dibiarkan tersimpan setengah jadi.
     *
     * @param  array<string, true>  $dikunjungi
     */
    private function applyLevel(RefUnitKerja $unit, int $level, array &$dikunjungi): void
    {
        $unitId = (string) $unit->getKey();

        if (isset($dikunjungi[$unitId])) {
            throw ValidationException::withMessages([
                'referensi' => 'Struktur unit kerja membentuk lingkaran sehingga kedalaman unit tidak dapat dihitung. Perbaiki relasi induk unit tersebut lebih dulu.',
            ]);
        }

        $dikunjungi[$unitId] = true;

        if ($unit->level !== $level) {
            $unit->forceFill(['level' => $level])->save();
        }

        RefUnitKerja::query()
            ->where('parent_id', $unit->getKey())
            ->get()
            ->each(fn (RefUnitKerja $child) => $this->applyLevel($child, $level + 1, $dikunjungi));
    }
}
