<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ListPimpinanLeavesAction;
use App\Actions\Cuti\ShowPimpinanLeaveDetailAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\PimpinanLeaveFilterRequest;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use Illuminate\Http\Request;

class PimpinanLeaveController extends Controller
{
    public function index(PimpinanLeaveFilterRequest $request, ListPimpinanLeavesAction $leaves)
    {
        $filters = $request->validated();
        $data = $leaves->execute($request->user(), $filters);
        // Jangkar ke awal bulan agar opsi periode tetap berurutan pada tanggal 29-31.
        $bulanBerjalan = now()->startOfMonth();

        return view('pimpinan.cuti.index', array_merge($data, [
            'filters' => $filters,
            'optPeriodes' => collect(range(0, 11))
                ->map(fn (int $offset): string => $bulanBerjalan->copy()->subMonths($offset)->format('Y-m')),
            'jenisCutiOptions' => RefJenisCuti::query()->orderBy('nama')->get(['id', 'nama']),
            'unitKerjaOptions' => RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']),
        ]));
    }

    public function show(Request $request, LeaveRequest $leave, ShowPimpinanLeaveDetailAction $detail)
    {
        return view('pimpinan.cuti.show', $detail->execute($leave, $request->user()));
    }
}
