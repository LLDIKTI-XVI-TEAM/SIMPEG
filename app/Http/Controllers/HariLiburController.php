<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHariLiburRequest;
use App\Http\Requests\UpdateHariLiburRequest;
use App\Models\RefHariLibur;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HariLiburController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RefHariLibur::query()->orderBy('tanggal');

        if ($request->filled('tahun')) {
            $query->where('tahun', (int) $request->input('tahun'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (RefHariLibur $hariLibur) => $hariLibur->toApiArray())->values(),
        ]);
    }

    public function store(StoreHariLiburRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tanggalCarbon = Carbon::parse($data['tanggal']);
        $tanggal = $tanggalCarbon->format('Y-m-d');

        $hariLibur = RefHariLibur::create([
            'tanggal' => $tanggal,
            'nama' => $data['nama'],
            'tahun' => (int) $tanggalCarbon->format('Y'),
            'is_cuti_bersama' => $data['tipe'] === 'cuti_bersama',
        ]);

        AuditService::log('CREATE', 'RefHariLibur', $hariLibur->id, null, $hariLibur->toApiArray(), $request);

        return response()->json([
            'message' => 'Hari libur berhasil ditambahkan.',
            'data' => $hariLibur->toApiArray(),
        ], 201);
    }

    public function update(UpdateHariLiburRequest $request, RefHariLibur $hariLibur): JsonResponse
    {
        $oldValues = $hariLibur->toApiArray();
        $data = $request->validated();
        $tanggalCarbon = Carbon::parse($data['tanggal']);
        $tanggal = $tanggalCarbon->format('Y-m-d');

        $hariLibur->update([
            'tanggal' => $tanggal,
            'nama' => $data['nama'],
            'tahun' => (int) $tanggalCarbon->format('Y'),
            'is_cuti_bersama' => $data['tipe'] === 'cuti_bersama',
        ]);

        $hariLibur->refresh();

        AuditService::log('UPDATE', 'RefHariLibur', $hariLibur->id, $oldValues, $hariLibur->toApiArray(), $request);

        return response()->json([
            'message' => 'Hari libur berhasil diperbarui.',
            'data' => $hariLibur->toApiArray(),
        ]);
    }

    public function destroy(Request $request, RefHariLibur $hariLibur): JsonResponse
    {
        $oldValues = $hariLibur->toApiArray();
        $id = $hariLibur->id;

        $hariLibur->delete();

        AuditService::log('DELETE', 'RefHariLibur', $id, $oldValues, null, $request);

        return response()->json([
            'message' => 'Hari libur berhasil dihapus.',
        ]);
    }
}
