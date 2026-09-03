<?php

namespace App\Livewire\Admin\Pegawai;

use App\Actions\Documents\PrepareEmployeeDocumentRowsAction;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\RefUnitKerja;
use App\Services\EmployeeDocumentStatusService;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Employees\EmployeeProfilePresentation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Detail Pegawai')]
class Show extends Component
{
    public $pegawaiId;

    public function mount(Request $request, $id)
    {
        $user = $request->user();

        // Detail mentah hanya untuk Super Admin efektif. Semua role lain yang
        // memiliki employees.read memakai surface RBAC dengan scope dan payload
        // permission-driven.
        if ($user?->getEffectiveRole() !== 'super_admin') {
            $this->redirectRoute('rbac.pegawai.show', ['employee' => $id]);

            return;
        }

        $this->pegawaiId = $id;
    }

    /** Route surface detail ter-masked per role efektif, bila tersedia. */
    private function maskedDetailSurface(?User $user): ?string
    {
        return match ($user?->getEffectiveRole()) {
            'pimpinan' => 'pimpinan.pegawai.show',
            default => null,
        };
    }

    public function render(
        EmployeeHistoryAttachmentService $attachments,
        EmployeeDocumentStatusService $documentStatusService,
        PrepareEmployeeDocumentRowsAction $prepareDocuments,
    ) {
        $p = Employee::with([
            'families',
            'rankHistories.golongan',
            'positionHistories.jabatan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories.jenjang',
            'educationHistories.programStudi',
            'documents',
            'appointment',
            'appointments',
            'agama',
            'statusKawin',
            'jenisPegawai',
            'statusPegawai',
            'programStudi',
            'statusHistories' => fn ($query) => $query
                ->orderByDesc('is_latest')
                ->orderByDesc('tanggal_efektif')
                ->orderByDesc('created_at')
                ->orderBy('id'),
            'supervisorAssignments.supervisor.positionHistories' => fn ($query) => $query->where('is_latest', true),
        ])->findOrFail($this->pegawaiId);

        $golonganOptions = RefGolongan::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();
        $jenjangOptions = RefJenjangPendidikan::orderBy('urutan')->get();
        $programStudiOptions = RefProgramStudi::query()
            ->where('is_active', true)
            ->orderBy('nama')
            ->get();
        $educationProgramStudiOptions = RefProgramStudi::query()
            ->where(function ($query) use ($p): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $p->educationHistories->pluck('program_studi_id')->filter());
            })
            ->orderBy('nama')
            ->get();

        $estimasiTanggalPensiun = EmployeeProfilePresentation::retirementDate($p);

        $currentSupervisor = $p->supervisorAssignments
            ->filter(fn ($assignment): bool => $assignment->tanggal_mulai->lte(today())
                && ($assignment->tanggal_berakhir === null || $assignment->tanggal_berakhir->gte(today())))
            ->sortByDesc('tanggal_mulai')
            ->first();
        $currentSupervisorPosition = $currentSupervisor?->supervisor?->positionHistories
            ->where('is_latest', true)
            ->sortByDesc('tmt_jabatan')
            ->first();
        // Gunakan relasi yang sudah dimuat agar Blade tidak menjalankan query berulang saat merender profil.
        $latestRank = $p->rankHistories->firstWhere('is_latest', true);
        $latestPosition = $p->positionHistories->firstWhere('is_latest', true);
        $oldSupervisorId = old('kepala_bagian_id');
        $selectedSupervisor = is_string($oldSupervisorId) && Str::isUuid($oldSupervisorId)
            ? Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($oldSupervisorId)
            : null;
        $selectedSupervisorId = $selectedSupervisor?->id ?? $currentSupervisor?->supervisor?->id;
        $selectedSupervisorName = $selectedSupervisor?->nama_lengkap ?? $currentSupervisor?->supervisor?->nama_lengkap;

        $this->prepareHistoryAttachmentDownloadUrls($p, $attachments);
        $documentRows = $prepareDocuments->execute($p);
        // Matriks aktif harus menjadi sumber tunggal tampilan dan penilaian
        // dokumen wajib pada profil, sama seperti daftar dan endpoint status.
        $documentStatus = $documentStatusService->summarize($p);
        // Arsip SK tetap ditampilkan sebagai record read-only dan tidak pernah
        // menjadi sumber hitungan kelengkapan di luar kalkulator kanonis.
        $archivedSkRows = $documentRows['sk'];
        $otherDocumentRows = $documentRows['others'];

        // Snapshot status adalah sumber utama. Riwayat latest hanya menjadi fallback
        // untuk data lama yang belum memiliki status_tanggal tersinkron.
        $latestStatusHistory = $p->statusHistories->firstWhere('is_latest', true)
            ?? $p->statusHistories->sortByDesc('tanggal_efektif')->first();
        $statusPresentation = EmployeeProfilePresentation::status($p, $latestStatusHistory);

        // Ikat cache browser pada master Program Studi dan riwayat pegawai agar
        // perubahan nama maupun mutasi pendidikan memaksa pemuatan payload terbaru.
        $pendidikanCacheVersion = md5(
            (string) (RefProgramStudi::max('updated_at') ?? '0').'|'.
            (string) ($p->updated_at ?? '0').'|'.
            (string) ($p->educationHistories->max('updated_at') ?? '0').'|'.
            (string) $p->educationHistories->count()
        );

        return view('admin.pegawai.show', compact('p', 'golonganOptions', 'jabatanOptions', 'jenisJabatanOptions', 'unitKerjaOptions', 'eselonOptions', 'jenjangOptions', 'programStudiOptions', 'educationProgramStudiOptions', 'estimasiTanggalPensiun', 'currentSupervisor', 'currentSupervisorPosition', 'latestRank', 'latestPosition', 'selectedSupervisorId', 'selectedSupervisorName', 'statusPresentation', 'latestStatusHistory', 'pendidikanCacheVersion', 'documentStatus', 'archivedSkRows', 'otherDocumentRows'));
    }

    /**
     * Menyiapkan URL unduh admin di server agar Blade tidak memeriksa storage
     * dan tidak merender tautan untuk file privat yang hilang atau salah scope.
     */
    private function prepareHistoryAttachmentDownloadUrls(Employee $employee, EmployeeHistoryAttachmentService $attachments): void
    {
        $groups = [
            'rank' => $employee->rankHistories,
            'position' => $employee->positionHistories,
            'salary' => $employee->salaryHistories,
            'discipline' => $employee->disciplineRecords,
            'education' => $employee->educationHistories,
            'appointment' => collect([$employee->appointment])->filter(),
        ];
        $attachments->primeDocumentReferences(
            collect($groups)->flatten()->map(fn (Model $history): mixed => $history->getAttribute(
                $history instanceof EducationHistory ? 'file_ijazah' : 'file_sk',
            ))->merge($employee->statusHistories->pluck('file_sk'))
                ->merge($employee->documents->where('jenis_dokumen', 'sk_status_pegawai')->pluck('file_path'))
                ->push($employee->status_berkas_path),
        );

        foreach ($groups as $type => $histories) {
            $histories->each(function (Model $history) use ($employee, $type, $attachments): void {
                $history->setAttribute(
                    'admin_attachment_download_url',
                    $attachments->downloadUrl($employee, $type, $history, 'pegawai.history-attachments.download'),
                );
            });
        }

        $employee->statusHistories->each(function (EmployeeStatusHistory $history) use ($employee, $attachments): void {
            $history->setAttribute(
                'admin_attachment_download_url',
                $attachments->statusDownloadUrl($employee, $history, 'pegawai.history-attachments.download'),
            );
        });
        $employee->setAttribute(
            'admin_status_attachment_download_url',
            $attachments->statusSnapshotDownloadUrl($employee, 'pegawai.history-attachments.download'),
        );
    }
}
