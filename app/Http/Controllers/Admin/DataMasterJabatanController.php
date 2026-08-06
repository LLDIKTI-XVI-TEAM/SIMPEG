<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\CreateReferenceItemAction;
use App\Actions\Referensi\DeleteReferenceItemAction;
use App\Actions\Referensi\ToggleReferenceItemActiveAction;
use App\Actions\Referensi\UpdateReferenceItemAction;
use App\Http\Controllers\Admin\Concerns\RedirectsToDataMasterTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Referensi\StoreJabatanRequest;
use App\Http\Requests\Referensi\UpdateJabatanRequest;
use App\Models\RefJabatan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataMasterJabatanController extends Controller
{
    use RedirectsToDataMasterTab;

    public function store(StoreJabatanRequest $request, CreateReferenceItemAction $action): RedirectResponse
    {
        $action->execute(RefJabatan::class, $request->validated(), $request);

        return $this->backToTab($request, 'Jabatan berhasil ditambahkan.');
    }

    public function update(UpdateJabatanRequest $request, RefJabatan $jabatan, UpdateReferenceItemAction $action): RedirectResponse
    {
        $data = $request->validated();

        // Hanya perubahan BUP yang mengubah dasar perhitungan pensiun pegawai yang sudah
        // menjabat, karena kalkulator membaca default_bup langsung dari jabatan ini. Jenis
        // jabatan tidak disertakan: nilai yang dipakai perhitungan adalah jenis yang tersalin
        // pada riwayat jabatan saat penugasan dibuat, sehingga mengubahnya di sini tidak
        // menggeser tanggal pensiun pegawai mana pun.
        //
        // Peringatan hanya menyasar pegawai yang tanggal pensiunnya sudah tersimpan, karena
        // snapshot itulah yang menjadi basi. Pegawai tanpa snapshot dihitung ulang setiap kali
        // dievaluasi sehingga otomatis mengikuti nilai baru.
        $bupBerubah = $this->nilaiBerubah($jabatan->default_bup, $data['default_bup'] ?? null);

        $pemegangDenganSnapshot = $bupBerubah
            ? $jabatan->jumlahPemegangDenganTanggalPensiunTersimpan()
            : 0;

        $action->execute($jabatan, $data, $request);

        $pesan = 'Jabatan berhasil diperbarui.';

        if ($pemegangDenganSnapshot > 0) {
            $pesan .= ' Perhatian: '.$pemegangDenganSnapshot.' pegawai pemegang jabatan ini sudah punya tanggal pensiun tersimpan yang tidak ikut dihitung ulang, sehingga peringatan pensiun mereka masih memakai dasar lama dan perlu disesuaikan lewat data pegawai.';
        }

        return $this->backToTab($request, $pesan);
    }

    public function toggle(Request $request, RefJabatan $jabatan, ToggleReferenceItemActiveAction $action): RedirectResponse
    {
        $action->execute($jabatan, $request);

        return $this->backToTab($request, $jabatan->is_active
            ? 'Jabatan berhasil diaktifkan kembali.'
            : 'Jabatan berhasil dinonaktifkan.');
    }

    public function destroy(Request $request, RefJabatan $jabatan, DeleteReferenceItemAction $action): RedirectResponse
    {
        $action->execute($jabatan, $request);

        return $this->backToTab($request, 'Jabatan berhasil dihapus.');
    }

    /**
     * Membandingkan nilai lama dan baru secara longgar karena masukan formulir selalu berupa
     * teks, sementara nilai tersimpan sudah bertipe integer atau null.
     */
    private function nilaiBerubah(mixed $lama, mixed $baru): bool
    {
        return (string) ($lama ?? '') !== (string) ($baru ?? '');
    }
}
