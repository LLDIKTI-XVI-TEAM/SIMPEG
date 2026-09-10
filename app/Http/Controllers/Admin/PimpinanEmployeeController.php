<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\PrepareDocumentDownloadAction;
use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Employees\PrepareEmployeeHistoryAttachmentDownloadAction;
use App\Actions\Employees\PreparePimpinanEmployeeDetailAction;
use App\Actions\Employees\ShowSkRequirementMatrixAction;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\Documents\SkRequirementMatrixVersionService;
use App\Support\Documents\DocumentCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PimpinanEmployeeController extends Controller
{
    /** @var list<string> */
    private const HISTORY_ATTACHMENT_TYPES = ['rank', 'position', 'salary', 'appointment', 'education'];

    public function index(
        Request $request,
        ListEmployeesAction $listEmployees,
        SkRequirementMatrixVersionService $matrixVersion,
    ) {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
            'status_pegawai_id' => trim((string) $request->query('status_pegawai_id', '')),
        ];
        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $sort = in_array($request->query('sort'), ['nama_lengkap', 'jabatan_terakhir', 'golongan_terakhir'], true)
            ? $request->query('sort')
            : 'nama_lengkap';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $employees = $listEmployees->execute(array_merge($filters, [
            'per_page' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
        ]), $request->user());
        $initialRows = $employees->items();
        $initialMeta = [
            'total' => $employees->total(),
            'current_page' => $employees->currentPage(),
            'last_page' => $employees->lastPage(),
            'from' => $employees->firstItem() ?? 0,
            'to' => $employees->lastItem() ?? 0,
            'per_page' => $employees->perPage(),
        ];
        $employeeShowUrlPrefix = route('pimpinan.pegawai.index');
        $serverRenderedDetailLinks = collect($initialRows)
            ->map(fn (array $employee): array => [
                'name' => $employee['nama_lengkap'],
                'url' => route('rbac.pegawai.show', $employee['id']),
            ])
            ->all();
        // Permission-driven (kontrak RBAC): capability halaman mengikuti permission
        // pada role efektif — bukan role asli. Halaman read-only default; blok aksi
        // (SK wajib, tambah/import pegawai) tampil sesuai permission yang diberikan.
        $user = $request->user();
        $canManageSkRequirements = $user?->hasPermission('sk_requirements.manage') ?? false;
        $skRequirementMatrix = $canManageSkRequirements
            ? app(ShowSkRequirementMatrixAction::class)->execute()
            : ['skPool' => [], 'current' => [], 'namesByType' => [], 'lockedTypes' => []];
        $canCreateEmployee = $user?->hasPermission('employees.create') ?? false;
        $canImportEmployees = $user?->hasPermission('employees.import') ?? false;
        $hasEmployeeMutationCapability = $canManageSkRequirements
            || $canCreateEmployee
            || $canImportEmployees
            || ($user?->hasPermission('employees.update') ?? false)
            || ($user?->hasPermission('employees.deactivate') ?? false)
            || ($user?->hasPermission('employees.restore') ?? false);
        $isReadOnly = ! $hasEmployeeMutationCapability;
        $skRequirementVersion = $matrixVersion->current();

        $golonganOptions = Employee::query()
            ->whereNotNull('golongan_terakhir')
            ->distinct()
            ->orderBy('golongan_terakhir')
            ->pluck('golongan_terakhir')
            ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
            ->filter()
            ->unique()
            ->values();

        if ($golonganOptions->isEmpty()) {
            $golonganOptions = collect(['II', 'III', 'IV']);
        }

        $unitKerjaOptions = RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        $statusOptions = RefStatusPegawai::query()->orderByDesc('is_default')->orderBy('nama')->get(['id', 'nama']);

        return view('pimpinan.pegawai.index', compact(
            'initialRows', 'initialMeta',
            'golonganOptions', 'unitKerjaOptions', 'jenisPegawaiOptions', 'statusOptions',
            'employees',
            'filters',
            'perPage',
            'sort',
            'direction',
            'employeeShowUrlPrefix',
            'serverRenderedDetailLinks',
            'isReadOnly',
            'skRequirementVersion',
            'canManageSkRequirements',
            'skRequirementMatrix',
            'canCreateEmployee',
            'canImportEmployees',
        ));
    }

    public function show(Employee $employee, PreparePimpinanEmployeeDetailAction $action)
    {
        // Surface khusus Pimpinan: payload dimasking di level query (tanpa NIK)
        // sehingga tidak bergantung pada endpoint API mentah lintas pegawai.
        /** @var User $viewer */
        $viewer = request()->user();

        return view('pimpinan.pegawai.show', $action->execute($employee->id, $viewer));
    }

    public function downloadDocument(
        Employee $employee,
        string $document,
        PrepareDocumentDownloadAction $action,
    ) {
        $download = $action->execute(
            $document,
            $employee->id,
            DocumentCategory::visibleToPimpinanKeys(),
            rejectAmbiguousMetadata: true,
        );

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function downloadDisciplineAttachment(
        Employee $employee,
        string $history,
        PrepareEmployeeHistoryAttachmentDownloadAction $action,
    ) {
        // Surface Pimpinan hanya membuka berkas hukuman disiplin; tipe riwayat lain tetap tidak dirutekan.
        $download = $action->execute($employee, 'discipline', $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function downloadStatusAttachment(
        Employee $employee,
        string $history,
        PrepareEmployeeHistoryAttachmentDownloadAction $action,
    ) {
        // UUID pegawai sendiri menandai snapshot legacy; UUID lain wajib record status milik pegawai target.
        $type = hash_equals($employee->id, $history) ? 'status-snapshot' : 'status';
        $download = $action->execute($employee, $type, $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Mengunduh SK riwayat legacy yang memang dibutuhkan Pimpinan secara read-only.
     *
     * Allowlist ini mencegah route Pimpinan menjadi pintu akses ke tipe riwayat yang
     * belum disetujui untuk permukaan kepemimpinan.
     */
    public function downloadHistoryAttachment(
        Employee $employee,
        string $type,
        string $history,
        PrepareEmployeeHistoryAttachmentDownloadAction $action,
    ) {
        abort_unless(in_array($type, self::HISTORY_ATTACHMENT_TYPES, true), 404);
        $download = $action->execute($employee, $type, $history);

        return Storage::disk(Document::STORAGE_DISK)->download($download['path'], $download['filename'], [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
