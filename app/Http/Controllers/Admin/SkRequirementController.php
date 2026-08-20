<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ShowSkRequirementMatrixAction;
use App\Actions\Employees\UpdateSkRequirementMatrixAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SkRequirement\UpdateSkRequirementMatrixRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Konfigurasi "SK wajib per jenis pegawai" untuk super admin.
 */
class SkRequirementController extends Controller
{
    public function index(ShowSkRequirementMatrixAction $action): View
    {
        return view('admin.sk-requirements.index', $action->execute());
    }

    public function update(
        UpdateSkRequirementMatrixRequest $request,
        UpdateSkRequirementMatrixAction $action,
    ): RedirectResponse {
        $action->execute($request);

        return redirect()->route('sk-requirements.config')
            ->with('success', 'Matriks SK wajib per jenis pegawai berhasil diperbarui.');
    }
}
