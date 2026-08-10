<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateEmployeeAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly TmtCalculatorService $tmtCalculator,
    ) {}

    /**
     * Memperbarui pegawai, termasuk dokumen pengangkatan, riwayat pangkat, jabatan, dan KGB.
     *
     * @param  array<string, mixed>  $validated
     */
    public function execute(Employee $employee, array $validated, Request $request): Employee
    {
        return DB::transaction(function () use ($employee, $validated, $request) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $oldValues = $employee->toArray();
            $validated = $this->normalizeEmployeeContract($validated);
            $pppkContractChanged = array_key_exists('tanggal_akhir_kontrak', $validated)
                && ($oldValues['tanggal_akhir_kontrak'] ?? null) !== $validated['tanggal_akhir_kontrak'];

            $oldPensionDate = $employee->tanggal_pensiun?->toDateString();
            $oldBirthDate = $employee->tanggal_lahir?->toDateString();
            $pensionDateChanged = array_key_exists('tanggal_pensiun', $validated)
                && $oldPensionDate !== $validated['tanggal_pensiun'];
            $pensionFieldsChanged = $pensionDateChanged
                || (array_key_exists('tanggal_lahir', $validated) && $oldBirthDate !== $validated['tanggal_lahir']);

            $rankHistoryChanged = false;
            $positionHistoryChanged = false;
            $salaryHistoryChanged = false;
            $appointmentChanged = false;

            if ($request->hasFile('foto') && $request->file('foto')->isValid()) {
                $validated['foto'] = $this->files->storePhoto($request->file('foto'));
            } else {
                unset($validated['foto']);
            }

            // Update Employee Basic Info
            $employee->update($validated);

            // 1. Pangkat (RankHistory)
            if ($request->filled('pangkat_golongan_id') && $request->filled('pangkat_no_sk')
                && $request->filled('pangkat_tanggal_sk') && $request->filled('pangkat_tmt_pangkat')) {
                $pangkatData = [
                    'golongan_id' => $validated['pangkat_golongan_id'],
                    'no_sk' => $validated['pangkat_no_sk'] ?? null,
                    'tanggal_sk' => $validated['pangkat_tanggal_sk'] ?? null,
                    'tmt_pangkat' => $validated['pangkat_tmt_pangkat'] ?? null,
                ];

                if ($request->hasFile('file_sk_pangkat') && $request->file('file_sk_pangkat')->isValid()) {
                    $file = $request->file('file_sk_pangkat');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/ranks/sk'), $filename);
                    $pangkatData['file_sk'] = 'ranks/sk/'.$filename;

                    $golonganLabel = isset($pangkatData['golongan_id'])
                        ? (RefGolongan::find($pangkatData['golongan_id'])?->kode ?? 'Pangkat Baru')
                        : 'Pangkat Baru';
                    Document::create([
                        'employee_id' => $employee->id,
                        'jenis_dokumen' => 'sk_pangkat',
                        'nama_dokumen' => 'SK Kenaikan Pangkat '.$golonganLabel,
                        'nomor_dokumen' => $pangkatData['no_sk'] ?? null,
                        'tanggal_dokumen' => $pangkatData['tanggal_sk'] ?? null,
                        'file_path' => $pangkatData['file_sk'],
                        'keterangan' => 'Diunggah otomatis saat edit pegawai',
                    ]);
                } elseif ($request->filled('existing_document_id_pangkat')) {
                    $existingDoc = Document::where('id', $request->input('existing_document_id_pangkat'))
                        ->where('employee_id', $employee->id)
                        ->first();
                    if ($existingDoc) {
                        $pangkatData['file_sk'] = $existingDoc->file_path;
                        if (empty($pangkatData['no_sk']) && $existingDoc->nomor_dokumen) {
                            $pangkatData['no_sk'] = $existingDoc->nomor_dokumen;
                        }
                        if (empty($pangkatData['tanggal_sk']) && $existingDoc->tanggal_dokumen) {
                            $pangkatData['tanggal_sk'] = $existingDoc->tanggal_dokumen;
                        }
                    }
                }

                // Riwayat (pangkat/jabatan/KGB) bersifat append-only: form edit hanya boleh menambah record baru.
                $pangkatData['is_latest'] = false;
                $employee->rankHistories()->create($pangkatData);
                $rankHistoryChanged = true;
            }

            // 2. Jabatan (PositionHistory)
            $hasJabatanReference = $request->filled('jabatan_jabatan_id') || $request->filled('jabatan_nama_jabatan');
            $hasJenisJabatan = $request->filled('jabatan_jabatan_id') || $request->filled('jabatan_jenis_jabatan_id');
            if ($hasJabatanReference && $hasJenisJabatan && $request->filled('jabatan_unit_kerja_id') && $request->filled('jabatan_no_sk')
                && $request->filled('jabatan_tanggal_sk') && $request->filled('jabatan_tmt_jabatan')) {
                $refJabatan = $request->filled('jabatan_jabatan_id')
                    ? RefJabatan::find($validated['jabatan_jabatan_id'])
                    : null;
                $namaJabatan = $refJabatan?->nama ?? $validated['jabatan_nama_jabatan'] ?? null;
                $jabatanData = [
                    'jabatan_id' => $validated['jabatan_jabatan_id'] ?? null,
                    'nama_jabatan' => $namaJabatan,
                    'jenis_jabatan_id' => $validated['jabatan_jenis_jabatan_id'] ?? $refJabatan?->jenis_jabatan_id,
                    'eselon_id' => $validated['jabatan_eselon_id'] ?? null,
                    'unit_kerja_id' => $validated['jabatan_unit_kerja_id'],
                    'kelas_jabatan' => $validated['jabatan_kelas_jabatan'] ?? $employee->kelas_jabatan_terakhir,
                    'no_sk' => $validated['jabatan_no_sk'],
                    'tanggal_sk' => $validated['jabatan_tanggal_sk'],
                    'tmt_jabatan' => $validated['jabatan_tmt_jabatan'],
                ];

                if ($request->hasFile('file_sk_jabatan') && $request->file('file_sk_jabatan')->isValid()) {
                    $file = $request->file('file_sk_jabatan');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/positions/sk'), $filename);
                    $jabatanData['file_sk'] = 'positions/sk/'.$filename;

                    Document::create([
                        'employee_id' => $employee->id,
                        'jenis_dokumen' => 'sk_jabatan',
                        'nama_dokumen' => 'SK Jabatan '.($jabatanData['nama_jabatan'] ?? 'Baru'),
                        'nomor_dokumen' => $jabatanData['no_sk'] ?? null,
                        'tanggal_dokumen' => $jabatanData['tanggal_sk'] ?? null,
                        'file_path' => $jabatanData['file_sk'],
                        'keterangan' => 'Diunggah otomatis saat edit pegawai',
                    ]);
                } elseif ($request->filled('existing_document_id_jabatan')) {
                    $existingDoc = Document::where('id', $request->input('existing_document_id_jabatan'))
                        ->where('employee_id', $employee->id)
                        ->first();
                    if ($existingDoc) {
                        $jabatanData['file_sk'] = $existingDoc->file_path;
                        if (empty($jabatanData['no_sk']) && $existingDoc->nomor_dokumen) {
                            $jabatanData['no_sk'] = $existingDoc->nomor_dokumen;
                        }
                        if (empty($jabatanData['tanggal_sk']) && $existingDoc->tanggal_dokumen) {
                            $jabatanData['tanggal_sk'] = $existingDoc->tanggal_dokumen;
                        }
                    }
                }

                $jabatanData['is_latest'] = false;
                $employee->positionHistories()->create($jabatanData);
                $positionHistoryChanged = true;
            }

            // 3. KGB (SalaryHistory)
            if ($request->filled('kgb_gaji_pokok') && $request->filled('kgb_no_sk')
                && $request->filled('kgb_tanggal_sk') && $request->filled('kgb_tmt_kgb')) {
                $kgbData = [
                    'gaji_pokok' => $validated['kgb_gaji_pokok'] ?? null,
                    'no_sk' => $validated['kgb_no_sk'] ?? null,
                    'tanggal_sk' => $validated['kgb_tanggal_sk'] ?? null,
                    'tmt_kgb' => $validated['kgb_tmt_kgb'] ?? null,
                ];

                if ($request->hasFile('file_sk_kgb') && $request->file('file_sk_kgb')->isValid()) {
                    $file = $request->file('file_sk_kgb');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/salaries/sk'), $filename);
                    $kgbData['file_sk'] = 'salaries/sk/'.$filename;

                    Document::create([
                        'employee_id' => $employee->id,
                        'jenis_dokumen' => 'sk_kgb',
                        'nama_dokumen' => 'SK KGB',
                        'nomor_dokumen' => $kgbData['no_sk'] ?? null,
                        'tanggal_dokumen' => $kgbData['tanggal_sk'] ?? null,
                        'file_path' => $kgbData['file_sk'],
                        'keterangan' => 'Diunggah otomatis saat edit pegawai',
                    ]);
                } elseif ($request->filled('existing_document_id_kgb')) {
                    $existingDoc = Document::where('id', $request->input('existing_document_id_kgb'))
                        ->where('employee_id', $employee->id)
                        ->first();
                    if ($existingDoc) {
                        $kgbData['file_sk'] = $existingDoc->file_path;
                        if (empty($kgbData['no_sk']) && $existingDoc->nomor_dokumen) {
                            $kgbData['no_sk'] = $existingDoc->nomor_dokumen;
                        }
                        if (empty($kgbData['tanggal_sk']) && $existingDoc->tanggal_dokumen) {
                            $kgbData['tanggal_sk'] = $existingDoc->tanggal_dokumen;
                        }
                    }
                }

                $kgbData['is_latest'] = false;
                $employee->salaryHistories()->create($kgbData);
                $salaryHistoryChanged = true;
            }

            // Bangun ulang flag dari seluruh TMT sah setelah semua penulisan agar backfill/null tidak merusak snapshot terbaru.
            if ($rankHistoryChanged) {
                $this->rebuildLatestRank($employee);
            }
            if ($positionHistoryChanged) {
                $this->rebuildLatestPosition($employee);
            }
            if ($salaryHistoryChanged) {
                $this->rebuildLatestSalary($employee);
            }

            // 4. Pengangkatan (Appointment)
            if ($request->filled('pengangkatan_jenis_pengangkatan')) {
                $appointmentData = [
                    'jenis_pengangkatan' => $validated['pengangkatan_jenis_pengangkatan'],
                    'tmt_pengangkatan' => $validated['pengangkatan_tmt_pengangkatan'] ?? null,
                    'no_sk' => $validated['pengangkatan_no_sk'] ?? null,
                    'tanggal_sk' => $validated['pengangkatan_tanggal_sk'] ?? null,
                ];

                if ($request->hasFile('file_sk_pengangkatan') && $request->file('file_sk_pengangkatan')->isValid()) {
                    $file = $request->file('file_sk_pengangkatan');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/appointments/sk'), $filename);
                    $appointmentData['file_sk'] = 'appointments/sk/'.$filename;

                    Document::create([
                        'employee_id' => $employee->id,
                        'jenis_dokumen' => 'sk_pengangkatan',
                        'nama_dokumen' => 'SK Pengangkatan '.($appointmentData['jenis_pengangkatan'] ?? ''),
                        'nomor_dokumen' => $appointmentData['no_sk'] ?? null,
                        'tanggal_dokumen' => $appointmentData['tanggal_sk'] ?? null,
                        'file_path' => $appointmentData['file_sk'],
                        'keterangan' => 'Diunggah otomatis saat edit pegawai',
                    ]);
                } elseif ($request->filled('existing_document_id_pengangkatan')) {
                    $existingDoc = Document::where('id', $request->input('existing_document_id_pengangkatan'))
                        ->where('employee_id', $employee->id)
                        ->first();
                    if ($existingDoc) {
                        $appointmentData['file_sk'] = $existingDoc->file_path;
                        if (empty($appointmentData['no_sk']) && $existingDoc->nomor_dokumen) {
                            $appointmentData['no_sk'] = $existingDoc->nomor_dokumen;
                        }
                        if (empty($appointmentData['tanggal_sk']) && $existingDoc->tanggal_dokumen) {
                            $appointmentData['tanggal_sk'] = $existingDoc->tanggal_dokumen;
                        }
                    }
                }

                $appointment = $employee->appointment;
                if ($appointment) {
                    $appointment->update($appointmentData);
                    $appointmentChanged = true;
                } else {
                    $employee->appointment()->create($appointmentData);
                    $appointmentChanged = true;
                }

                $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [
                    strtoupper($validated['pengangkatan_jenis_pengangkatan']),
                ])->first();
                if ($jenisPegawai) {
                    $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
                }
            }

            if ($request->filled('pppk_tmt_pengangkatan')) {
                $pppkAppointment = $employee->appointments()
                    ->whereRaw('UPPER(jenis_pengangkatan) = ?', ['PPPK'])
                    ->orderByDesc('tmt_pengangkatan')
                    ->first();

                if ($pppkAppointment && $pppkAppointment->tmt_pengangkatan?->toDateString() !== $validated['pppk_tmt_pengangkatan']) {
                    $pppkAppointment->update(['tmt_pengangkatan' => $validated['pppk_tmt_pengangkatan']]);
                    $pppkContractChanged = true;
                    $appointmentChanged = true;  // Track PPPK TMT changes for Satyalancana milestone
                }
            }

            if ($pppkContractChanged) {
                $activeAlerts = EwsAlert::query()
                    ->where('employee_id', $employee->id)
                    ->where('type', 'KONTRAK_PPPK')
                    ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
                    ->get(['id']);

                if ($activeAlerts->isNotEmpty()) {
                    $alertIds = $activeAlerts->pluck('id');
                    EwsAlert::whereIn('id', $alertIds)->update([
                        'followup_status' => EwsAlert::FOLLOWUP_STATUS_EXPIRED,
                        'is_processed' => true,
                    ]);
                    SimpegNotification::whereIn('ews_alert_id', $alertIds)
                        ->where('is_read', false)
                        ->update(['is_read' => true, 'read_at' => now()]);
                }
            }

            // Satu sinkronisasi setelah seluruh penulisan memastikan semua sumber TMT direkonsiliasi bersama.
            if ($rankHistoryChanged || $positionHistoryChanged || $salaryHistoryChanged || $pensionFieldsChanged || $pppkContractChanged || $appointmentChanged) {
                if ($pensionDateChanged) {
                    // Nilai non-null adalah keputusan resmi Admin; null mengembalikan sumber ke kalkulasi BUP.
                    $this->tmtCalculator->syncForEmployee($employee, $employee->tanggal_pensiun !== null);
                } else {
                    $this->tmtCalculator->syncForEmployee($employee);
                }
            }

            $employee->refresh();
            AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->toArray(), $request);

            return $employee;
        });
    }

    private function normalizeEmployeeContract(array $data): array
    {
        $email = $data['email_pribadi'] ?? $data['email'] ?? null;
        if ($email !== null) {
            $data['email_pribadi'] = $email;
            $data['email'] = $email;
        }

        $kelasJabatan = $data['kelas_jabatan_terakhir'] ?? $data['kelas_jabatan'] ?? null;
        if ($kelasJabatan !== null) {
            $data['kelas_jabatan_terakhir'] = $kelasJabatan;
            $data['kelas_jabatan'] = $kelasJabatan;
        }

        if (! empty($data['jabatan_id']) && empty($data['jabatan_terakhir'])) {
            $data['jabatan_terakhir'] = RefJabatan::find($data['jabatan_id'])?->nama;
        }

        if (! empty($data['status_pegawai_id']) && empty($data['status_aktif'])) {
            $data['status_aktif'] = RefStatusPegawai::whereKey($data['status_pegawai_id'])->value('nama') ?? 'Aktif';
        } elseif (empty($data['status_pegawai_id']) && ! empty($data['status_aktif'])) {
            $data['status_pegawai_id'] = RefStatusPegawai::where('nama', $data['status_aktif'])->value('id');
        }

        return $data;
    }

    private function rebuildLatestRank(Employee $employee): void
    {
        $latest = $employee->rankHistories()
            ->whereNotNull('tmt_pangkat')
            ->orderByDesc('tmt_pangkat')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $employee->rankHistories()->update(['is_latest' => false]);
        if ($latest === null) {
            return;
        }

        $employee->rankHistories()->whereKey($latest->id)->update(['is_latest' => true]);
        $golongan = RefGolongan::find($latest->golongan_id);
        if ($golongan !== null) {
            $employee->update([
                'golongan_terakhir' => $golongan->kode,
                'pangkat_terakhir' => $golongan->nama,
            ]);
        }
    }

    private function rebuildLatestPosition(Employee $employee): void
    {
        $latest = $employee->positionHistories()
            ->whereNotNull('tmt_jabatan')
            ->orderByDesc('tmt_jabatan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $employee->positionHistories()->update(['is_latest' => false]);
        if ($latest === null) {
            return;
        }

        $employee->positionHistories()->whereKey($latest->id)->update(['is_latest' => true]);
        $employee->update([
            'jabatan_terakhir' => $latest->nama_jabatan,
            'kelas_jabatan_terakhir' => $latest->kelas_jabatan ?? $employee->kelas_jabatan_terakhir,
            'kelas_jabatan' => $latest->kelas_jabatan ?? $employee->kelas_jabatan_terakhir,
        ]);
    }

    private function rebuildLatestSalary(Employee $employee): void
    {
        $latest = $employee->salaryHistories()
            ->whereNotNull('tmt_kgb')
            ->orderByDesc('tmt_kgb')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $employee->salaryHistories()->update(['is_latest' => false]);
        if ($latest !== null) {
            $employee->salaryHistories()->whereKey($latest->id)->update(['is_latest' => true]);
        }
    }
}
