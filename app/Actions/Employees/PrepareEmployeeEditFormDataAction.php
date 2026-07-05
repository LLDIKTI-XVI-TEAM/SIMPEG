<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;

class PrepareEmployeeEditFormDataAction
{
    /**
     * Prepare data needed for the employee edit form.
     *
     * @param  int|string  $id
     * @return array<string, mixed>
     */
    public function execute($id): array
    {
        $p = Employee::with([
            'appointment',
            'positionHistories.jabatan',
            'positionHistories.unitKerja',
            'positionHistories.jenisJabatan',
            'rankHistories.golongan',
            'salaryHistories',
            'documents',
        ])->findOrFail($id);

        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        $unitKerja = RefUnitKerja::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $statusPegawai = RefStatusPegawai::orderByDesc('is_default')->orderBy('nama')->get();
        $golonganRefOptions = RefGolongan::orderBy('kode')->get();
        $eselonOptions = RefEselon::orderBy('nama')->get();

        // Dokumen arsip per kategori untuk fitur "Pilih dari Arsip"
        $arsipPangkat = $p->documents()
            ->where('jenis_dokumen', 'sk_pangkat')
            ->orderByDesc('tanggal_dokumen')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'label' => ($d->nomor_dokumen ?? 'Tanpa No.').($d->tanggal_dokumen ? ' — '.date('d/m/Y', strtotime($d->tanggal_dokumen)) : ''),
                'nomor_dokumen' => $d->nomor_dokumen,
                'tanggal_dokumen' => $d->tanggal_dokumen ? date('Y-m-d', strtotime($d->tanggal_dokumen)) : null,
                'file_path' => $d->file_path,
                'nama_dokumen' => $d->nama_dokumen,
            ]);

        $arsipJabatan = $p->documents()
            ->where('jenis_dokumen', 'sk_jabatan')
            ->orderByDesc('tanggal_dokumen')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'label' => ($d->nomor_dokumen ?? 'Tanpa No.').($d->tanggal_dokumen ? ' — '.date('d/m/Y', strtotime($d->tanggal_dokumen)) : ''),
                'nomor_dokumen' => $d->nomor_dokumen,
                'tanggal_dokumen' => $d->tanggal_dokumen ? date('Y-m-d', strtotime($d->tanggal_dokumen)) : null,
                'file_path' => $d->file_path,
                'nama_dokumen' => $d->nama_dokumen,
            ]);

        $arsipKgb = $p->documents()
            ->where('jenis_dokumen', 'sk_kgb')
            ->orderByDesc('tanggal_dokumen')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'label' => ($d->nomor_dokumen ?? 'Tanpa No.').($d->tanggal_dokumen ? ' — '.date('d/m/Y', strtotime($d->tanggal_dokumen)) : ''),
                'nomor_dokumen' => $d->nomor_dokumen,
                'tanggal_dokumen' => $d->tanggal_dokumen ? date('Y-m-d', strtotime($d->tanggal_dokumen)) : null,
                'file_path' => $d->file_path,
                'nama_dokumen' => $d->nama_dokumen,
            ]);

        $arsipPengangkatan = $p->documents()
            ->where('jenis_dokumen', 'sk_pengangkatan')
            ->orderByDesc('tanggal_dokumen')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'label' => ($d->nomor_dokumen ?? 'Tanpa No.').($d->tanggal_dokumen ? ' — '.date('d/m/Y', strtotime($d->tanggal_dokumen)) : ''),
                'nomor_dokumen' => $d->nomor_dokumen,
                'tanggal_dokumen' => $d->tanggal_dokumen ? date('Y-m-d', strtotime($d->tanggal_dokumen)) : null,
                'file_path' => $d->file_path,
                'nama_dokumen' => $d->nama_dokumen,
            ]);

        return compact(
            'p',
            'jenisPegawai',
            'agama',
            'statusKawin',
            'unitKerja',
            'jabatanOptions',
            'jenisJabatanOptions',
            'statusPegawai',
            'golonganRefOptions',
            'eselonOptions',
            'arsipPangkat',
            'arsipJabatan',
            'arsipKgb',
            'arsipPengangkatan',
        );
    }
}
