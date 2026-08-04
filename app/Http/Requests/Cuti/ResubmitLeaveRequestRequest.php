<?php

namespace App\Http\Requests\Cuti;

use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveEligibilityService;
use App\Services\WorkdayCalculator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Memvalidasi revisi pengajuan berstatus perlu_perubahan.
 * Jenis cuti tetap terkunci; pemohon hanya boleh memperbaiki tanggal, alasan, dan lampiran.
 */
class ResubmitLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest !== null
            && $this->user()?->employee_id === $leaveRequest->employee_id
            && $leaveRequest->status === 'perlu_perubahan';
    }

    /**
     * Memangkas spasi di sekitar kontak selama cuti sebelum validasi.
     * Sama seperti pengajuan awal, pengiriman ulang wajib merekam ulang snapshot kontak PII;
     * nilai dinormalisasi lebih dulu agar input berisi hanya spasi ditolak sebagai kosong dan batas
     * panjang dihitung tanpa spasi tepi. Hanya nilai string yang dipangkas; nilai non-string dibiarkan
     * agar aturan validasi yang menangkapnya tetap berjalan.
     */
    protected function prepareForValidation(): void
    {
        $ternormalisasi = [];

        foreach (['alamat_selama_cuti', 'nomor_telepon'] as $field) {
            $nilai = $this->input($field);

            if (is_string($nilai)) {
                $ternormalisasi[$field] = trim($nilai);
            }
        }

        if ($ternormalisasi !== []) {
            $this->merge($ternormalisasi);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'max:500'],
            // Snapshot kontak wajib direkam ulang saat pengiriman ulang agar approver punya data kontak terbaru;
            // batas 1000 karakter menjaga alamat tetap ringkas namun cukup lengkap.
            'alamat_selama_cuti' => ['required', 'string', 'max:1000'],
            // Nomor telepon dibatasi 20 karakter dan hanya boleh berisi angka, spasi, serta simbol telepon lazim
            // (kurung, plus, minus, titik) sehingga huruf atau garis miring ditolak.
            'nomor_telepon' => ['required', 'string', 'max:20', 'regex:/^[0-9()+\-.\s]+$/'],
            'lampiran' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tanggal_mulai' => 'tanggal mulai',
            'tanggal_selesai' => 'tanggal selesai',
            'alasan' => 'alasan',
            'alamat_selama_cuti' => 'alamat selama cuti',
            'nomor_telepon' => 'nomor telepon',
            'lampiran' => 'lampiran',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $mulai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_mulai'))->startOfDay();
            $selesai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_selesai'))->startOfDay();

            // PRD §9.4: satu pengajuan cuti apa pun jenisnya tidak boleh melewati tahun kalender,
            // termasuk saat tanggal diperbaiki pada pengiriman ulang.
            if ($mulai->year !== $selesai->year) {
                $validator->errors()->add('tanggal_selesai', 'Pengajuan cuti tidak boleh melewati tahun kalender. Pisahkan menjadi dua pengajuan terpisah untuk tiap tahun.');

                return;
            }

            $leaveRequest = $this->route('leaveRequest');

            $employee = $this->user()?->employee;

            if ($leaveRequest === null || $employee === null) {
                $validator->errors()->add('tanggal_mulai', 'Data pengajuan atau pegawai tidak tersedia untuk pengiriman ulang.');

                return;
            }

            try {
                // Jenis dan rangkaian tidak dapat diganti saat resubmit. Service
                // menghitung ulang cap kalender dengan tanggal versi baru sambil
                // mengecualikan periode lama dari request yang sama.
                app(LeaveEligibilityService::class)->assertResubmissionAllowed(
                    $leaveRequest,
                    $employee,
                    $mulai,
                    $selesai,
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }

                return;
            }

            if (! $leaveRequest?->jenisCuti?->mengurangi_saldo_tahunan) {
                return;
            }

            try {
                // Validasi awal memberi pesan Rule 5 yang spesifik; Action/reservasi tetap
                // mengulang guard ini dalam lock transaksi untuk keamanan submit paralel.
                app(LeaveBalanceService::class)->assertAnnualLeaveAllowed($employee, $mulai->year);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }

                return;
            }

            $hariKerja = app(WorkdayCalculator::class)->calculate($mulai, $selesai);
            // Alokasi request ini sendiri dikecualikan agar resubmit hanya bersaing dengan
            // pengajuan aktif lain. Penyesuaian yang atomik dilakukan Action setelah validasi.
            $sisaSaldo = $employee === null
                ? 0
                : app(LeaveBalanceReservationService::class)->availableForSubmission(
                    $employee,
                    $mulai->year,
                    $mulai,
                    $leaveRequest,
                );

            if ($sisaSaldo < $hariKerja) {
                $validator->errors()->add('tanggal_selesai', "Saldo cuti tahunan tidak mencukupi. Sisa saldo {$sisaSaldo} hari, sedangkan pengajuan membutuhkan {$hariKerja} hari kerja.");
            }
        });
    }
}
