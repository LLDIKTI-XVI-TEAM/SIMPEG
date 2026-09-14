<?php

namespace App\Actions\Employees;

use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefProgramStudi;
use App\Models\RefStatusPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Services\Employees\EmployeeDashboardScopeService;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Employees\EmployeeIdentifierPrivacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrepareEmployeeEditFormDataAction
{
    public function __construct(
        private readonly EmployeeHistoryAttachmentService $attachments,
        private readonly EmployeeDashboardScopeService $employeeScope,
    ) {}

    /**
     * Menyiapkan form sesuai permission, scope, dan privasi, termasuk saat Livewire merender ulang.
     *
     * @param  int|string  $id
     * @return array<string, mixed>
     */
    public function execute($id, bool $rbacSurface = false): array
    {
        $viewer = auth()->user();
        abort_unless($viewer !== null && $viewer->hasPermission('employees.update'), 403);
        $canEditSensitiveIdentifiers = EmployeeIdentifierPrivacy::canManage($viewer);
        $canCheckIdentity = $viewer->hasPermission('employees.create');
        $canReadHistories = $viewer->hasPermission('employee_histories.read');
        $canReadDocuments = $viewer->hasPermission('dokumen_sk.read');

        // Proyeksi eksplisit mencegah identitas terenkripsi ikut didekripsi/diserialisasi ke form delegated.
        $columns = [
            'id', 'nama_lengkap', 'nama_dengan_gelar', 'nip', 'foto', 'tempat_lahir',
            'tanggal_lahir', 'jenis_kelamin', 'agama_id', 'status_kawin_id', 'golongan_darah',
            'jenis_pegawai_id', 'status_pegawai_id', 'status_aktif', 'is_kepala_lembaga',
            'golongan_terakhir', 'pangkat_terakhir', 'jabatan_terakhir', 'kelas_jabatan', 'kelas_jabatan_terakhir',
            'pendidikan_terakhir', 'prodi_pendidikan_terakhir', 'program_studi_id',
            'tanggal_pensiun', 'tanggal_akhir_kontrak', 'alamat', 'no_hp', 'email',
            'email_pribadi', 'no_telepon_rumah',
        ];
        if ($canEditSensitiveIdentifiers) {
            $columns = [...$columns, 'nik', 'no_kk'];
        }
        abort_unless($this->employeeScope->forIdentity($viewer)->whereKey($id)->exists(), 403);
        // Form hanya memakai riwayat terkini; pengangkatan awal tetap terpisah dari kontrak PPPK terbaru.
        $p = $this->employeeScope->forIdentity($viewer)->select($columns)->with([
            'appointment' => fn ($q) => $q->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->limit(1),
            'appointments' => fn ($q) => $q->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))
                ->where('jenis_pengangkatan', 'PPPK')->orderByRaw('tmt_pengangkatan DESC NULLS LAST')->limit(1),
            'jenisPegawai',
            'positionHistories' => fn ($q) => $q->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->where('is_latest', true)->with(['jabatan', 'unitKerja', 'jenisJabatan']),
            'rankHistories' => fn ($q) => $q->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->where('is_latest', true)->with('golongan'),
            'salaryHistories' => fn ($q) => $q->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->where('is_latest', true),
        ])->findOrFail($id);

        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        $unitKerja = RefUnitKerja::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $statusPegawai = RefStatusPegawai::where('is_active', true)->orderByDesc('is_default')->orderBy('nama')->get();
        $golonganRefOptions = RefGolongan::orderBy('kode')->get();
        $eselonOptions = RefEselon::orderBy('nama')->get();
        $programStudiOptions = RefProgramStudi::query()
            ->where('is_active', true)
            ->when(
                $p->program_studi_id,
                fn ($query) => $query->orWhere('id', $p->program_studi_id),
            )
            ->orderBy('nama')
            ->get();
        $latestRank = $p->rankHistories->firstWhere('is_latest', true);
        $latestPosition = $p->positionHistories->firstWhere('is_latest', true);
        $latestSalary = $p->salaryHistories->firstWhere('is_latest', true);

        $histories = collect([
            'rank' => $latestRank,
            'position' => $latestPosition,
            'salary' => $latestSalary,
            'appointment' => $p->appointment,
        ])->filter();
        if ($canReadDocuments) {
            $this->attachments->primeDocumentReferences($histories->pluck('file_sk'));
        }
        $downloadRoute = $rbacSurface ? 'rbac.pegawai.history-attachments.download' : 'pegawai.history-attachments.download';
        $histories->each(function (Model $history, string $type) use ($p, $canReadDocuments, $downloadRoute): void {
            $history->setAttribute(
                'admin_attachment_download_url',
                $canReadDocuments ? $this->attachments->downloadUrl($p, $type, $history, $downloadRoute) : null,
            );
        });

        // Pencarian arsip dimuat terpisah; redirect validasi hanya memulihkan maksimal empat pilihan sah.
        $archiveSelections = [];
        $selectedIds = collect(['pangkat', 'jabatan', 'kgb', 'pengangkatan'])
            ->mapWithKeys(fn (string $type) => ['sk_'.$type => old('existing_document_id_'.$type)])
            ->filter(fn ($id) => is_string($id) && Str::isUuid($id));
        if ($canReadDocuments && $selectedIds->isNotEmpty()) {
            $documents = $p->documents()->whereIn('id', $selectedIds->values())
                ->get(['id', 'jenis_dokumen', 'nomor_dokumen', 'tanggal_dokumen', 'nama_dokumen'])->keyBy('id');
            foreach ($selectedIds as $category => $documentId) {
                $document = $documents->get($documentId);
                if ($document === null || $document->jenis_dokumen !== $category) {
                    continue;
                }
                $archiveSelections[$category] = [
                    'id' => $document->id,
                    'label' => ($document->nomor_dokumen ?? 'Tanpa No.').($document->tanggal_dokumen ? ' — '.$document->tanggal_dokumen->format('d/m/Y') : ''),
                    'nomor_dokumen' => $document->nomor_dokumen,
                    'tanggal_dokumen' => $document->tanggal_dokumen?->format('Y-m-d'),
                    'nama_dokumen' => $document->nama_dokumen,
                ];
            }
        }

        return compact(
            'canEditSensitiveIdentifiers',
            'canCheckIdentity',
            'canReadDocuments',
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
            'programStudiOptions',
            'latestRank',
            'latestPosition',
            'latestSalary',
            'archiveSelections',
        );
    }
}
