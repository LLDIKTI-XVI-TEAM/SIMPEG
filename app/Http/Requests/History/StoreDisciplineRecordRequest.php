<?php

namespace App\Http\Requests\History;

use App\Models\Employee;
use App\Support\SkFilePathRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDisciplineRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        // Permission route dan scope record telah memvalidasi otorisasi mutasi.
        return $this->user()?->hasPermission('discipline_records.create') ?? false;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $employeeId = $employee instanceof Employee ? $employee->id : $employee;

        return [
            'jenis_hukuman' => ['required', Rule::in(['Ringan', 'Sedang', 'Berat'])],
            'deskripsi' => ['required', 'string', 'max:2000'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_berakhir' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => SkFilePathRules::nullableUploadOrControlledPath(),
            // Arsip SK hanya boleh dipakai oleh riwayat pegawai pemiliknya agar berkas privat tidak berpindah scope.
            'dokumen_id' => [
                'nullable',
                'uuid',
                Rule::exists('documents', 'id')
                    ->where('employee_id', $employeeId)
                    ->where('jenis_dokumen', 'sk_hukuman_disiplin'),
            ],
        ];
    }

    /**
     * Mengikat path arsip ke dokumen disiplin milik pegawai sebelum mutasi dijalankan.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['file_sk', 'dokumen_id'])) {
                return;
            }

            $employee = $this->route('employee');
            $documentId = $this->input('dokumen_id');
            if ($this->hasFile('file_sk') && is_string($documentId) && $documentId !== '') {
                $validator->errors()->add(
                    'file_sk',
                    'Unggahan baru tidak dapat digabung dengan pilihan dokumen arsip.',
                );

                return;
            }

            $fileSk = $this->input('file_sk');
            if (! $employee instanceof Employee || ! is_string($fileSk) || $fileSk === '') {
                return;
            }

            $document = $employee->documents()
                ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                ->where('file_path', $fileSk)
                ->first();
            if ($document === null) {
                $validator->errors()->add(
                    'file_sk',
                    'Path file harus merujuk SK Hukuman Disiplin milik pegawai yang sedang diproses.',
                );

                return;
            }

            $selectedDocument = is_string($documentId) && $documentId !== ''
                ? $employee->documents()
                    ->whereKey($documentId)
                    ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                    ->first()
                : null;
            if ($selectedDocument !== null && ! hash_equals($selectedDocument->file_path, $fileSk)) {
                $validator->errors()->add(
                    'file_sk',
                    'Path file dan dokumen yang dipilih harus merujuk arsip yang sama.',
                );
            }
        }];
    }
}
