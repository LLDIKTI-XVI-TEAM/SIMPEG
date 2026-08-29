<?php

namespace App\Http\Requests\Cuti;

use App\Models\Employee;
use App\Models\LeavePybmcGlobalConfig;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Memvalidasi konfigurasi chain approval per pegawai.
 * PYBMC final boleh diambil dari konfigurasi global sehingga form hanya perlu mengirim step non-final.
 */
class EmployeeApprovalChainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.configure_chain');
    }

    /**
     * Menghapus baris PYBMC opsional hanya bila seluruh nilainya kosong.
     * Payload parsial tetap dipertahankan agar aturan validasi menolaknya secara fail-closed.
     */
    protected function prepareForValidation(): void
    {
        $steps = $this->input('steps');

        if (! is_array($steps) || ! isset($steps['_pybmc']) || ! is_array($steps['_pybmc'])) {
            return;
        }

        $isFullyEmpty = collect($steps['_pybmc'])
            ->every(fn (mixed $value): bool => $value === null || $value === '');

        if ($isFullyEmpty) {
            unset($steps['_pybmc']);
            $this->merge(['steps' => $steps]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.step_type' => ['required', 'string', 'in:kepala_bagian,verifier,pybmc'],
            'steps.*.role_label' => ['required', 'string', 'max:100'],
            'steps.*.approver_employee_id' => [
                'required',
                // Klasifikasi aktif dari kelompok referensi — satu sumber dengan
                // Employee::whereActiveStatus()/isActive() (termasuk Aktif/khusus).
                Rule::exists('employees', 'id')
                    ->where(fn ($query) => $query->whereIn('id', Employee::query()->whereActiveStatus()->select('id'))),
            ],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'steps.*.approver_employee_id.exists' => 'Approver chain harus merupakan pegawai aktif.',
        ];
    }

    /**
     * Memastikan tahap pertama selalu memakai Kepala Bagian aktif milik pegawai target.
     * Relasi kosong ditolak agar chain tidak dapat mengalihkan approval awal ke pegawai sembarang.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $employee = $this->route('employee');

            if (! $employee instanceof Employee) {
                return;
            }

            $kepalaBagianId = $employee->kepala_bagian_id
                ?? $employee->currentSupervisor()?->kepala_bagian_id;

            if ($kepalaBagianId === null) {
                $validator->errors()->add(
                    'steps.0.approver_employee_id',
                    'Pegawai belum memiliki Kepala Bagian aktif. Tetapkan relasi Kepala Bagian terlebih dahulu.',
                );

                return;
            }

            $firstStep = $this->input('steps.0');

            if (! is_array($firstStep)
                || ($firstStep['step_type'] ?? null) !== 'kepala_bagian'
                || ($firstStep['approver_employee_id'] ?? null) !== $kepalaBagianId) {
                $validator->errors()->add(
                    'steps.0.approver_employee_id',
                    'Approver pertama harus sama dengan Kepala Bagian aktif pegawai.',
                );
            }

            $steps = $this->input('steps', []);
            $pybmcIndexes = collect($steps)
                ->keys()
                ->filter(fn ($index): bool => ($steps[$index]['step_type'] ?? null) === 'pybmc')
                ->values();

            if ($pybmcIndexes->count() > 1) {
                $validator->errors()->add('steps', 'Chain pegawai hanya boleh memiliki satu PYBMC khusus.');

                return;
            }

            if ($pybmcIndexes->isEmpty() && ! LeavePybmcGlobalConfig::query()->exists()) {
                $validator->errors()->add(
                    'steps',
                    'PYBMC khusus wajib dipilih karena PYBMC global belum dikonfigurasi.',
                );

                return;
            }

            if ($pybmcIndexes->isNotEmpty() && $pybmcIndexes->first() !== array_key_last($steps)) {
                $validator->errors()->add('steps', 'PYBMC khusus harus berada pada urutan terakhir.');
            }
        });
    }
}
