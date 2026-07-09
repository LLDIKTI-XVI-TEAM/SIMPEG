<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateEmployeeAction
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    /**
     * Memperbarui pegawai, termasuk dokumen pengangkatan, riwayat pangkat, jabatan, dan KGB.
     *
     * @param  array<string, mixed>  $validated
     */
    public function execute(Employee $employee, array $validated, Request $request): Employee
    {
        return DB::transaction(function () use ($employee, $validated, $request) {
            $oldValues = $employee->toArray();
            $validated = $this->normalizeEmployeeContract($validated);

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

                $pangkatId = $request->input('pangkat_history_id');
                if ($pangkatId && $pangkatId !== 'new') {
                    $history = $employee->rankHistories()->find($pangkatId);
                    if ($history) {
                        $history->update($pangkatData);
                        if ($history->is_latest) {
                            $golongan = RefGolongan::find($validated['pangkat_golongan_id']);
                            if ($golongan) {
                                $employee->update([
                                    'golongan_terakhir' => $golongan->kode,
                                    'pangkat_terakhir' => $golongan->nama,
                                ]);
                            }
                        }
                    }
                } else {
                    $employee->rankHistories()->update(['is_latest' => false]);
                    $pangkatData['is_latest'] = true;
                    $employee->rankHistories()->create($pangkatData);

                    $golongan = RefGolongan::find($validated['pangkat_golongan_id']);
                    if ($golongan) {
                        $employee->update([
                            'golongan_terakhir' => $golongan->kode,
                            'pangkat_terakhir' => $golongan->nama,
                        ]);
                    }
                }
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

                $jabatanId = $request->input('jabatan_history_id');
                if ($jabatanId && $jabatanId !== 'new') {
                    $history = $employee->positionHistories()->find($jabatanId);
                    if ($history) {
                        $history->update($jabatanData);
                        if ($history->is_latest) {
                            $employee->update([
                                'jabatan_terakhir' => $namaJabatan,
                                'kelas_jabatan_terakhir' => $jabatanData['kelas_jabatan'],
                                'kelas_jabatan' => $jabatanData['kelas_jabatan'],
                            ]);
                        }
                    }
                } else {
                    $employee->positionHistories()->update(['is_latest' => false]);
                    $jabatanData['is_latest'] = true;
                    $employee->positionHistories()->create($jabatanData);

                    $employee->update([
                        'jabatan_terakhir' => $namaJabatan,
                        'kelas_jabatan_terakhir' => $jabatanData['kelas_jabatan'],
                        'kelas_jabatan' => $jabatanData['kelas_jabatan'],
                    ]);
                }
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

                $kgbId = $request->input('kgb_history_id');
                if ($kgbId && $kgbId !== 'new') {
                    $history = $employee->salaryHistories()->find($kgbId);
                    if ($history) {
                        $history->update($kgbData);
                    }
                } else {
                    $employee->salaryHistories()->update(['is_latest' => false]);
                    $kgbData['is_latest'] = true;
                    $employee->salaryHistories()->create($kgbData);
                }
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
                } else {
                    $employee->appointment()->create($appointmentData);
                }

                $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [
                    strtoupper($validated['pengangkatan_jenis_pengangkatan']),
                ])->first();
                if ($jenisPegawai) {
                    $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
                }
            }

            // 5. Berkas Lainnya (KTP, KK, SK Mutasi, SK Pensiun, atau jenis manual)
            if ($request->filled('berkas_lainnya_jenis')
                && $request->hasFile('file_berkas_lainnya')
                && $request->file('file_berkas_lainnya')->isValid()) {
                $jenis = $validated['berkas_lainnya_jenis'];
                $jenisEfektif = $jenis === 'Lainnya'
                    ? trim((string) ($validated['berkas_lainnya_jenis_manual'] ?? ''))
                    : $jenis;

                // KTP & KK dipetakan ke kategori arsip ktp_kk; sisanya masuk kategori lainnya.
                $kategori = in_array($jenis, ['KTP', 'KK'], true) ? 'ktp_kk' : 'lainnya';

                $filePath = $this->files->storeBerkasLainnya($request->file('file_berkas_lainnya'), $employee->id);

                Document::create([
                    'employee_id' => $employee->id,
                    'jenis_dokumen' => $kategori,
                    'nama_dokumen' => $jenisEfektif,
                    'nomor_dokumen' => $validated['berkas_lainnya_nomor'] ?? null,
                    'tanggal_dokumen' => $validated['berkas_lainnya_tanggal'] ?? null,
                    'file_path' => $filePath,
                    'keterangan' => $validated['berkas_lainnya_deskripsi'] ?? null,
                ]);
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

        $kepalaBagianId = $data['kepala_bagian_id'] ?? $data['atasan_langsung_id'] ?? null;
        if ($kepalaBagianId !== null) {
            $data['kepala_bagian_id'] = $kepalaBagianId;
            $data['atasan_langsung_id'] = $kepalaBagianId;
        }

        if (! empty($data['status_pegawai_id']) && empty($data['status_aktif'])) {
            $data['status_aktif'] = RefStatusPegawai::whereKey($data['status_pegawai_id'])->value('nama') ?? 'Aktif';
        } elseif (empty($data['status_pegawai_id']) && ! empty($data['status_aktif'])) {
            $data['status_pegawai_id'] = RefStatusPegawai::where('nama', $data['status_aktif'])->value('id');
        }

        return $data;
    }
}
