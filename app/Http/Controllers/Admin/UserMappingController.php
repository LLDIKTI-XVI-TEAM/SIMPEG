<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\ListUserMappingsAction;
use App\Actions\Auth\UpdateUserMappingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ListUserMappingsRequest;
use App\Http\Requests\Auth\UpdateUserMappingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserMappingController extends Controller
{
    /**
     * Shell halaman — hanya mengembalikan view tanpa data paginator.
     * Data tabel dimuat via AJAX oleh method data() di bawah.
     */
    public function index(): View
    {
        return view('admin.user-management.index', [
            'title' => 'User Management / Kelola Akses User',
        ]);
    }

    /**
     * JSON endpoint untuk x-ui.data-table (Alpine.js AJAX).
     * Mengembalikan rows + meta pagination agar komponen dapat
     * merender tabel secara reaktif tanpa full page reload.
     */
    public function data(ListUserMappingsRequest $request, ListUserMappingsAction $listUserMappings): JsonResponse
    {
        $paginator = $listUserMappings->execute($request->validated());

        $rows = collect($paginator->items())->map(function (array $emp, int $index) use ($paginator): array {
            $roleLabel = match ($emp['role']) {
                null, '' => 'Belum diberi role',
                'super_admin' => 'Super Admin',
                'admin_kepegawaian' => 'Admin Kepegawaian',
                'pimpinan' => 'Pimpinan',
                'kepala_bagian' => 'Kepala Bagian',
                'pegawai' => 'Pegawai',
                default => 'Role tidak dikenal',
            };

            $roleClass = match ($emp['role']) {
                'super_admin' => 'bg-danger/10 text-danger',
                'admin_kepegawaian' => 'bg-primary/10 text-primary',
                'kepala_bagian' => 'bg-warning/10 text-warning',
                'pegawai' => 'bg-success/10 text-success',
                default => 'bg-soft text-muted',
            };

            $statusClass = match ($emp['mapping_status']) {
                'terhubung' => 'bg-success/10 text-success',
                'role_kosong' => 'bg-warning/10 text-warning',
                default => 'bg-danger/10 text-danger',
            };

            return [
                'id' => $emp['id'],
                'no' => ($paginator->firstItem() ?? 0) + $index,
                'nama' => $emp['nama'],
                'nip' => $emp['nip'],
                'mapped_email' => $emp['mapped_email'] ?: '-',
                'keycloak_id' => $emp['keycloak_id'] ?: '-',
                'role' => $emp['role'],
                'role_label' => $roleLabel,
                'role_class' => $roleClass,
                'mapping_status' => $emp['mapping_status'],
                'mapping_status_label' => $emp['mapping_status_label'],
                'mapping_status_class' => $statusClass,
            ];
        })->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function update(UpdateUserMappingRequest $request, UpdateUserMappingAction $updateUserMapping): RedirectResponse
    {
        $updateUserMapping->execute($request->validated(), $request);

        return back()->with('success', 'Akses User berhasil diperbarui!');
    }
}

