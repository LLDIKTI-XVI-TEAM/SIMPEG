<?php

namespace App\Http\Controllers\Admin;

use App\Actions\HariLibur\CreateHariLiburAction;
use App\Actions\HariLibur\DeleteHariLiburAction;
use App\Actions\HariLibur\ShowHariLiburPageAction;
use App\Actions\HariLibur\UpdateHariLiburAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\HariLibur\ListHariLiburPageRequest;
use App\Http\Requests\HariLibur\StoreHariLiburRequest;
use App\Http\Requests\HariLibur\UpdateHariLiburRequest;
use App\Models\RefHariLibur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HariLiburController extends Controller
{
    public function index(ListHariLiburPageRequest $request, ShowHariLiburPageAction $action): View
    {
        return view('admin.hari-libur.index', $action->execute($request->validated()));
    }

    public function store(StoreHariLiburRequest $request, CreateHariLiburAction $action): RedirectResponse
    {
        $hariLibur = $action->execute($request->validated(), $request);

        return $this->kembaliKeDaftar($hariLibur, 'Hari libur "'.$hariLibur->nama.'" berhasil ditambahkan.');
    }

    public function edit(RefHariLibur $hariLibur): View
    {
        return view('admin.hari-libur.edit', ['hariLibur' => $hariLibur]);
    }

    public function update(
        UpdateHariLiburRequest $request,
        RefHariLibur $hariLibur,
        UpdateHariLiburAction $action,
    ): RedirectResponse {
        $hariLibur = $action->execute($hariLibur, $request->validated(), $request);

        return $this->kembaliKeDaftar($hariLibur, 'Hari libur "'.$hariLibur->nama.'" berhasil diperbarui.');
    }

    public function destroy(Request $request, RefHariLibur $hariLibur, DeleteHariLiburAction $action): RedirectResponse
    {
        // Tahun dan nama dibaca sebelum penghapusan karena keduanya dipakai
        // untuk mengarahkan Admin kembali ke tab tahun yang sedang dikelola.
        $tahun = (int) $hariLibur->tahun;
        $nama = $hariLibur->nama;

        $action->execute($hariLibur, $request);

        return redirect()
            ->route('hari-libur', ['tahun' => $tahun])
            ->with('success', 'Hari libur "'.$nama.'" berhasil dihapus.');
    }

    /**
     * Mengembalikan Admin ke tab tahun hasil perubahan, bukan ke tahun berjalan,
     * supaya baris yang baru disimpan langsung terlihat. Nomor halaman sengaja
     * tidak dibawa agar tidak mendarat di halaman yang sudah tidak ada.
     */
    private function kembaliKeDaftar(RefHariLibur $hariLibur, string $message): RedirectResponse
    {
        return redirect()
            ->route('hari-libur', ['tahun' => (int) $hariLibur->tahun])
            ->with('success', $message);
    }
}
