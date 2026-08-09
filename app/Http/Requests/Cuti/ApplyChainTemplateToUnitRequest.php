<?php

namespace App\Http\Requests\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Memvalidasi penyalinan rantai approval cuti dari satu pegawai ke seluruh anggota unit kerja.
 * Operasi ini mengubah konfigurasi persetujuan banyak pegawai sekaligus, jadi alasannya wajib
 * dan permissionnya sama dengan pengaturan chain lain.
 */
class ApplyChainTemplateToUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.configure_chain');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Unit nonaktif tidak boleh menjadi sasaran supaya konfigurasi tidak disalin ke struktur
            // yang sudah tidak dipakai.
            'unit_kerja_id' => ['required', 'uuid', Rule::exists('ref_unit_kerja', 'id')->where('is_active', true)],
            'source_employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'template_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sumber = $this->input('source_employee_id');

            // Pemeriksaan lanjutan hanya dijalankan bila pengenal sudah lolos aturan dasarnya.
            // PostgreSQL menolak perbandingan kolom uuid dengan teks sembarang, jadi kueri di bawah
            // akan menjadi galat basis data alih-alih galat validasi bila nilainya cacat.
            if (! is_string($sumber) || $validator->errors()->has('source_employee_id')) {
                return;
            }

            // Tanpa rantai aktif pada pegawai sumber tidak ada yang dapat disalin, dan kesalahan ini
            // harus terbaca sebagai galat validasi alih-alih kegagalan di tengah penerapan.
            $rantai = LeaveApprovalChain::query()
                ->with('steps')
                ->where('employee_id', $sumber)
                ->where('is_active', true)
                ->first();

            if ($rantai === null) {
                $validator->errors()->add(
                    'source_employee_id',
                    'Pegawai sumber belum memiliki chain approval aktif untuk disalin.',
                );

                return;
            }

            // Resolver menolak pengajuan pada rantai tanpa langkah Kepala Bagian, dan penyalinan tidak
            // dapat menyisipkannya. Rantai sumber seperti itu ditolak agar salinan rusak tidak
            // menonaktifkan chain sah seluruh anggota unit.
            if ($rantai->steps->doesntContain(fn ($step): bool => $step->step_type === 'kepala_bagian')) {
                $validator->errors()->add(
                    'source_employee_id',
                    'Chain pegawai sumber tidak memiliki langkah Kepala Bagian. Perbaiki chain tersebut lebih dahulu.',
                );

                return;
            }

            // Langkah kepala bagian selalu diisi ulang dengan atasan efektif pegawai tujuan, jadi
            // approver lama pada langkah itu tidak pernah disalin dan tidak perlu diperiksa. Membatasi
            // pemeriksaan ke langkah yang benar-benar disalin mencegah penolakan palsu ketika snapshot
            // kepala bagian sumber sudah usang karena rotasi jabatan.
            $langkahDisalin = $rantai->steps->reject(fn ($step): bool => $step->step_type === 'kepala_bagian');

            // Kunci asing approver memakai SET NULL, sehingga penghapusan permanen pegawai
            // meninggalkan langkah tanpa approver yang tidak dapat disalin ke kolom uuid.
            $langkahTanpaApprover = $langkahDisalin
                ->filter(fn ($step): bool => $step->approver_employee_id === null)
                ->pluck('role_label');

            if ($langkahTanpaApprover->isNotEmpty()) {
                $validator->errors()->add(
                    'source_employee_id',
                    sprintf(
                        'Chain pegawai sumber memuat langkah tanpa approver: %s. Perbaiki chain tersebut lebih dahulu.',
                        $langkahTanpaApprover->implode(', '),
                    ),
                );

                return;
            }

            // Approver pada rantai sumber bisa sudah pensiun atau keluar sejak rantai dibuat. Form
            // per pegawai hanya menerima approver aktif, jadi template kedaluwarsa ditolak di sini
            // supaya admin melihat galat yang menerangkan sebabnya, bukan galat server.
            $approverNonaktif = Employee::query()
                ->withTrashed()
                ->whereIn('id', $langkahDisalin->pluck('approver_employee_id')->filter()->unique())
                ->where(fn ($query) => $query->where('status_aktif', '!=', 'Aktif')->orWhereNotNull('deleted_at'))
                ->pluck('nama_lengkap');

            if ($approverNonaktif->isNotEmpty()) {
                $validator->errors()->add(
                    'source_employee_id',
                    sprintf(
                        'Chain pegawai sumber memuat approver nonaktif: %s. Perbarui chain tersebut lebih dahulu.',
                        $approverNonaktif->implode(', '),
                    ),
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'unit_kerja_id.required' => 'Unit kerja tujuan wajib dipilih.',
            'unit_kerja_id.exists' => 'Unit kerja tujuan tidak ditemukan.',
            'source_employee_id.required' => 'Pegawai sumber chain wajib dipilih.',
            'source_employee_id.exists' => 'Pegawai sumber chain tidak ditemukan.',
            'template_reason.required' => 'Alasan penerapan template wajib diisi.',
            'template_reason.min' => 'Alasan penerapan template minimal berisi 5 karakter.',
            'template_reason.max' => 'Alasan penerapan template maksimal 500 karakter.',
        ];
    }
}
