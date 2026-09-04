<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\AssignSupervisorAction;
use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Actions\Employees\CreateEmployeeAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\ExportEmployeeAction;
use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Actions\Employees\StoreEmployeeHistoryAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Actions\Employees\UpdateEmployeePerformanceFlagAction;
use App\Actions\Employees\UpdateEmployeeSatyalancanaEligibilityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\AssignSupervisorRequest;
use App\Http\Requests\Employee\ChangeEmployeeStatusRequest;
use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Http\Requests\Employee\RestoreEmployeeRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeePerformanceFlagRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeSatyalancanaEligibilityRequest;
use App\Models\Employee;
use App\Models\EwsConfig;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PegawaiController extends Controller
{
    /**
     * Mengubah status dari modal pada tabel Data Pegawai. Seluruh perubahan
     * tetap melalui action domain agar riwayat, dokumen, audit, dan notifikasi
     * memiliki perilaku yang sama dengan alur sebelumnya.
     */
    public function changeStatus(ChangeEmployeeStatusRequest $request, ChangeEmployeeStatusAction $action)
    {
        $validated = $request->validated();
        $employee = Employee::findOrFail($validated['pegawai_id']);

        try {
            $result = $action->execute($employee, $validated, $request, $request->file('berkas'));
            $message = $result->isScheduled()
                ? 'Perubahan status pegawai '.$employee->nama_lengkap.
                    " berhasil dijadwalkan untuk tanggal {$result->effectiveDate}."
                : 'Status pegawai '.$employee->nama_lengkap.' berhasil diperbarui.';

            return redirect()->route('data-pegawai')
                ->with('success', $message)
                // Daftar dimuat dari sessionStorage; flag ini memaksanya meminta
                // baris terbaru sehingga status pada tabel tidak tertinggal.
                ->with('employee_data_changed', true);
        } catch (\Throwable $e) {
            return back()
                ->withInput($request->except('berkas'))
                ->with('error', 'Gagal memperbarui status pegawai: '.$e->getMessage())
                ->with('open_status_modal', true);
        }
    }

    public function index(Request $request, ListEmployeesAction $listAction)
    {
        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;

        // === Data Referensi — di-cache agar tidak query DB setiap request ===
        $unitKerjaOptions = Cache::remember('ref.unit_kerja', now()->addHours(6), function () {
            return RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']);
        });
        $jenisPegawaiOptions = Cache::remember('ref.jenis_pegawai', now()->addHours(6), function () {
            return RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        });
        $statusOptions = Cache::remember('ref.status_pegawai', now()->addHours(6), function () {
            return RefStatusPegawai::query()->orderByDesc('is_default')->orderBy('nama')->get(['id', 'nama']);
        });
        $golonganOptions = Cache::remember('ref.golongan_distinct', now()->addHours(1), function () {
            $opts = Employee::query()
                ->whereNotNull('golongan_terakhir')
                ->distinct()
                ->orderBy('golongan_terakhir')
                ->pluck('golongan_terakhir')
                ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
                ->filter()
                ->unique()
                ->values();

            return $opts->isEmpty() ? collect(['II', 'III', 'IV']) : $opts;
        });
        $golonganRefOptions = Cache::remember('ref.golongan', now()->addHours(6), function () {
            return RefGolongan::orderBy('kode')->get();
        });
        $jabatanOptions = Cache::remember('ref.jabatan_with_jenis', now()->addHours(6), function () {
            return RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        });
        $jenisJabatanOptions = Cache::remember('ref.jenis_jabatan', now()->addHours(6), function () {
            return RefJenisJabatan::orderBy('nama')->get();
        });
        $eselonOptions = Cache::remember('ref.eselon', now()->addHours(6), function () {
            return RefEselon::orderBy('nama')->get();
        });

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
            'status_pegawai_id' => trim((string) $request->query('status_pegawai_id', 'all')),
            'status_aktif' => trim((string) $request->query('status_aktif', '')),
        ];

        // Backward-compatible query params from the pagination branch.
        if ($filters['unit_kerja_id'] === '' && $request->filled('unit')) {
            $legacyUnit = (string) $request->query('unit');
            $matchedUnit = $unitKerjaOptions->firstWhere('nama', $legacyUnit);
            $filters['unit_kerja_id'] = $matchedUnit?->id ?? '';
        }

        if ($filters['jenis_pegawai_id'] === '' && $request->filled('jenis')) {
            $legacyJenis = (string) $request->query('jenis');
            $matchedJenis = $jenisPegawaiOptions->firstWhere('nama', $legacyJenis);
            $filters['jenis_pegawai_id'] = $matchedJenis?->id ?? '';
        }

        if ($filters['status_aktif'] === '' && $request->filled('status')) {
            $legacyStatus = strtolower((string) $request->query('status'));
            $filters['status_aktif'] = match ($legacyStatus) {
                'aktif' => 'Aktif',
                'nonaktif', 'non-aktif' => 'Non-Aktif',
                'pensiun' => 'Pensiun',
                'mutasi' => 'Mutasi',
                default => '',
            };
        }

        if (($filters['status_pegawai_id'] === '' || $filters['status_pegawai_id'] === 'all') && $filters['status_aktif'] !== '') {
            $filters['status_pegawai_id'] = $statusOptions->firstWhere('nama', $filters['status_aktif'])?->id ?? 'all';
        }

        if ($request->query('filter') === 'pensiun' && $filters['status_aktif'] === '') {
            $filters['status_aktif'] = 'Pensiun';
        }

        if (! $unitKerjaOptions->contains('id', $filters['unit_kerja_id'])) {
            $filters['unit_kerja_id'] = '';
        }
        if (! $jenisPegawaiOptions->contains('id', $filters['jenis_pegawai_id'])) {
            $filters['jenis_pegawai_id'] = '';
        }
        if ($filters['status_pegawai_id'] !== 'all' && ! $statusOptions->contains('id', $filters['status_pegawai_id'])) {
            $filters['status_pegawai_id'] = '';
        }
        if ($filters['status_aktif'] !== '' && ! $statusOptions->contains('nama', $filters['status_aktif'])) {
            $filters['status_aktif'] = '';
        }
        if ($filters['golongan'] !== '' && ! $golonganOptions->contains($filters['golongan'])) {
            $filters['golongan'] = '';
        }

        $allowedSorts = ['nama_lengkap', 'jabatan_terakhir', 'golongan_terakhir', 'created_at'];
        $sort = $request->query('sort', 'nama_lengkap');
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'nama_lengkap';

        $direction = strtolower((string) $request->query('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        // === Initial page data — dikosongkan agar loading halaman instan ===
        // Data akan diambil melalui AJAX oleh AlpineJS atau dari sessionStorage
        $initialRows = [];
        $initialMeta = [
            'total' => 0,
            'current_page' => 1,
            'last_page' => 1,
            'from' => 0,
            'to' => 0,
            'per_page' => $perPage,
        ];

        // Jika request BUKAN dari AJAX/API, DAN BUKAN redirect dari form edit/delete, kita load data awal.
        // Jika session 'employee_data_changed' true, berarti redirect dari action lain yang mana AlpineJS
        // akan me-rehydrate datanya dari sessionStorage, jadi skip query yang mahal.
        if (! $request->ajax() && ! Str::startsWith($request->path(), 'api/') && ! session('employee_data_changed')) {
            $initialPageData = $listAction->execute(array_merge($filters, [
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ]), $request->user());
            $initialRows = $initialPageData->items();
            $initialMeta = [
                'total' => $initialPageData->total(),
                'current_page' => $initialPageData->currentPage(),
                'last_page' => $initialPageData->lastPage(),
                'from' => $initialPageData->firstItem(),
                'to' => $initialPageData->lastItem(),
                'per_page' => $initialPageData->perPage(),
            ];
        }

        return view('admin.pegawai.index', compact(
            'perPage',
            'sort',
            'direction',
            'filters',
            'initialRows',
            'initialMeta',
            'golonganOptions',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'statusOptions',
            'golonganRefOptions',
            'jabatanOptions',
            'jenisJabatanOptions',
            'eselonOptions'
        ));
    }

    public function create()
    {
        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        $unitKerja = RefUnitKerja::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $golonganRefOptions = RefGolongan::orderBy('kode')->get();
        $eselonOptions = RefEselon::orderBy('nama')->get();

        return view('admin.pegawai.create', compact('jenisPegawai', 'agama', 'statusKawin', 'unitKerja', 'jabatanOptions', 'jenisJabatanOptions', 'golonganRefOptions', 'eselonOptions'));
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action)
    {
        try {
            $employee = $action->execute($request->validated(), $request);
            $warnings = $action->warnings;

            $successMsg = 'Data pegawai '.$employee->nama_lengkap.' berhasil ditambahkan.';
            if (! empty($warnings)) {
                $successMsg .= ' Peringatan: '.implode(' ', $warnings);
            }

            $redirect = redirect()->route('data-pegawai')
                ->with('success', $successMsg)
                ->with('employee_data_changed', true);
            if (! empty($warnings)) {
                $redirect = $redirect->with('warnings', $warnings);
            }

            return $redirect;
        } catch (\Throwable $e) {
            return back()
                ->withInput($request->except(array_keys($request->allFiles())))
                ->with('error', 'Gagal menambahkan pegawai: '.$e->getMessage());
        }
    }

    /**
     * Menyiapkan detail pegawai dan Kepala Bagian aktif sekali agar Blade tidak menambah query saat render.
     */
    public function show($id)
    {
        $p = Employee::with([
            'families',
            'rankHistories',
            'positionHistories.jabatan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'statusHistories.document',
            'disciplineRecords',
            'educationHistories',
            'documents',
            'agama',
            'statusKawin',
            'jenisPegawai',
            'statusPegawai',
            'supervisorAssignments.supervisor.positionHistories' => fn ($query) => $query->where('is_latest', true),
        ])->findOrFail($id);

        $golonganOptions = RefGolongan::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();
        $jenjangOptions = RefJenjangPendidikan::orderBy('urutan')->get();

        // Prioritaskan tanggal_pensiun manual, fallback ke kalkulasi BUP
        $estimasiTanggalPensiun = $p->tanggal_pensiun;
        if ($estimasiTanggalPensiun === null) {
            $bupPensiunYears = max(0, (int) EwsConfig::getVal('pensiun_required_age_years', 0));
            $estimasiTanggalPensiun = $bupPensiunYears > 0 && $p->tanggal_lahir
                ? $p->tanggal_lahir->copy()->addYears($bupPensiunYears)
                : null;
        }

        $currentSupervisor = $p->supervisorAssignments
            ->filter(fn ($assignment): bool => $assignment->tanggal_mulai->lte(today())
                && ($assignment->tanggal_berakhir === null || $assignment->tanggal_berakhir->gte(today())))
            ->sortByDesc('tanggal_mulai')
            ->first();
        $currentSupervisorPosition = $currentSupervisor?->supervisor?->positionHistories
            ->where('is_latest', true)
            ->sortByDesc('tmt_jabatan')
            ->first();
        $oldSupervisorId = old('kepala_bagian_id');
        $selectedSupervisor = is_string($oldSupervisorId) && Str::isUuid($oldSupervisorId)
            ? Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($oldSupervisorId)
            : null;
        $selectedSupervisorId = $selectedSupervisor?->id ?? $currentSupervisor?->supervisor?->id;
        $selectedSupervisorName = $selectedSupervisor?->nama_lengkap ?? $currentSupervisor?->supervisor?->nama_lengkap;

        return view('admin.pegawai.show', compact('p', 'golonganOptions', 'jabatanOptions', 'jenisJabatanOptions', 'unitKerjaOptions', 'eselonOptions', 'jenjangOptions', 'estimasiTanggalPensiun', 'currentSupervisor', 'currentSupervisorPosition', 'selectedSupervisorId', 'selectedSupervisorName'));
    }

    public function edit($id, PrepareEmployeeEditFormDataAction $action)
    {
        $data = $action->execute($id);

        return view('admin.pegawai.edit', $data);
    }

    public function update(UpdateEmployeeRequest $request, $id, UpdateEmployeeAction $action)
    {
        $employee = Employee::findOrFail($id);

        try {
            $employee = $action->execute($employee, $request->validated(), $request);
            $warnings = $action->warnings;

            $employee->load([
                'jenisPegawai:id,nama',
                'statusPegawai:id,nama',
                'rankHistories:id,employee_id,no_sk,tanggal_sk,tmt_pangkat,file_sk,is_latest,created_at',
                'positionHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'no_sk', 'tanggal_sk', 'file_sk', 'is_latest', 'tmt_jabatan', 'jabatan_id', 'unit_kerja_id', 'created_at'])
                    ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
                'salaryHistories:id,employee_id,no_sk,tanggal_sk,tmt_kgb,file_sk,is_latest,created_at',
                'appointments' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'no_sk', 'tanggal_sk', 'file_sk', 'tmt_pengangkatan', 'created_at'])
                    ->orderByDesc('tmt_pengangkatan')
                    ->orderByDesc('created_at'),
                'documents:id,employee_id,jenis_dokumen,nama_dokumen,nomor_dokumen,tanggal_dokumen,file_path,keterangan,created_at',
            ]);

            $tableRow = app(ListEmployeesAction::class)->toTableRow($employee);

            // Gabungkan data tabel ringkas dengan data lengkap employee agar semua perubahan
            // (termasuk relasi dan file SK) tercatat di cache sessionStorage
            $editedEmployeeData = array_merge($employee->toArray(), $tableRow);

            $successMsg = 'Data pegawai '.$employee->nama_lengkap.' berhasil diperbarui.';
            if (! empty($warnings)) {
                $successMsg .= ' Peringatan: '.implode(' ', $warnings);
            }

            $redirect = redirect()->route('data-pegawai')
                ->with('success', $successMsg)
                ->with('employee_data_changed', true)
                ->with('edited_employee_id', $employee->id)
                ->with('edited_employee_data', $editedEmployeeData);
            if (! empty($warnings)) {
                $redirect = $redirect->with('warnings', $warnings);
            }

            // Jika ada berkas lainnya yang diunggah, bersihkan juga cache halaman dokumen
            // Hanya jika file benar-benar tersimpan (ada permission), bukan warning
            $hasBerkasUploaded = $request->hasFile('file_berkas_lainnya') && $request->file('file_berkas_lainnya')->isValid() && empty(array_filter($warnings, fn ($w) => str_contains($w, 'Berkas lainnya')));
            if ($hasBerkasUploaded) {
                $redirect = $redirect->with('document_data_changed', true);
            }

            return $redirect;
        } catch (\Throwable $e) {
            return back()
                ->withInput($request->except(array_keys($request->allFiles())))
                ->with('error', 'Gagal memperbarui pegawai: '.$e->getMessage());
        }
    }

    public function destroy(string $id, DeactivateEmployeeRequest $request, DeactivateEmployeeAction $action)
    {
        $employee = Employee::findOrFail($id);
        $nama = $employee->nama_lengkap;

        $result = $action->execute($employee, $request);
        $message = $result->isScheduled()
            ? "Penonaktifan pegawai {$nama} berhasil dijadwalkan untuk tanggal {$result->effectiveDate}."
            : 'Status kepegawaian '.$nama.' berhasil diubah menjadi nonaktif.';

        return redirect()->route('data-pegawai')
            ->with('success', $message)
            ->with('employee_data_changed', true);
    }

    /**
     * Memperbarui flag kinerja manual yang menjadi pengganti SKP sementara untuk eligibility EWS.
     */
    public function updatePerformanceFlag(
        UpdateEmployeePerformanceFlagRequest $request,
        string $id,
        UpdateEmployeePerformanceFlagAction $action,
    ): JsonResponse {
        $employee = Employee::findOrFail($id);

        $updated = $action->execute(
            $employee,
            $request->boolean('is_kinerja_baik'),
            $request,
        );

        return response()->json([
            'message' => 'Status kinerja pegawai berhasil diperbarui.',
            'is_kinerja_baik' => $updated->is_kinerja_baik,
        ]);
    }

    /**
     * Memperbarui kelayakan manual Satyalancana untuk eligibility EWS.
     */
    public function updateSatyalancanaEligibility(
        UpdateEmployeeSatyalancanaEligibilityRequest $request,
        string $id,
        UpdateEmployeeSatyalancanaEligibilityAction $action,
    ): JsonResponse {
        $employee = Employee::findOrFail($id);

        $updated = $action->execute(
            $employee,
            $request->boolean('is_satyalancana_eligible'),
            $request->validated('satyalancana_note'),
            $request,
        );

        return response()->json([
            'message' => 'Kelayakan Satyalancana pegawai berhasil diperbarui.',
            'is_satyalancana_eligible' => $updated->is_satyalancana_eligible,
            'satyalancana_note' => $updated->satyalancana_note,
        ]);
    }

    public function restore(string $id, RestoreEmployeeRequest $request, RestoreEmployeeAction $action)
    {
        $employee = Employee::query()->findOrFail($id);
        $nama = $employee->nama_lengkap;

        $result = $action->execute($employee, $request);
        $message = $result->isScheduled()
            ? "Pengaktifan kembali pegawai {$nama} berhasil dijadwalkan untuk tanggal {$result->effectiveDate}."
            : 'Status kepegawaian '.$nama.' berhasil diaktifkan kembali.';

        return redirect()->route('data-pegawai')
            ->with('success', $message)
            ->with('employee_data_changed', true);
    }

    public function storeRiwayat($id, Request $request, StoreEmployeeHistoryAction $action)
    {
        $employee = Employee::findOrFail($id);

        try {
            $action->execute($employee, $request);

            return response()->json(['success' => true, 'message' => 'Data riwayat berhasil disimpan.']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @return StreamedResponse
     */
    public function export(Request $request, ExportEmployeeAction $action)
    {
        return $action->execute($request);
    }

    /**
     * Menyimpan perubahan penugasan atasan dan mempertahankan pilihan form bila aturan histori menolak perubahan.
     */
    public function assignAtasan(AssignSupervisorRequest $request, $id, AssignSupervisorAction $action)
    {
        $employee = Employee::findOrFail($id);
        $data = $request->validated();
        $isCutiConfig = ($data['redirect_to'] ?? null) === 'cuti-config';

        // Form penetapan juga tersedia inline di halaman Konfigurasi Approval Cuti; nilai redirect_to
        // sudah dibatasi whitelist pada FormRequest sehingga tidak dapat menjadi open redirect.
        [$redirectRoute, $redirectParams] = $isCutiConfig
            ? ['cuti.config', ['employee_id' => $id]]
            : ['pegawai.show', $id];
        // Surface cuti memakai nama peran bisnis, sedangkan detail pegawai mempertahankan label struktural.
        $assignmentLabel = $isCutiConfig ? 'Atasan Langsung' : 'Kepala Bagian';

        try {
            $action->execute(
                $employee,
                $data['kepala_bagian_id'] ?? $data['supervisor_id'] ?? null,
                $data['effective_date'],
                $request,
            );

            return redirect()->route($redirectRoute, $redirectParams)
                ->with('success', $assignmentLabel.' untuk '.$employee->nama_lengkap.' berhasil diperbarui.')
                ->with('employee_data_changed', true);
        } catch (ValidationException $e) {
            return redirect()->route($redirectRoute, $redirectParams)
                ->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            return redirect()->route($redirectRoute, $redirectParams)
                ->withInput()
                ->with('error', 'Gagal memperbarui '.$assignmentLabel.': '.$e->getMessage());
        }
    }
}
