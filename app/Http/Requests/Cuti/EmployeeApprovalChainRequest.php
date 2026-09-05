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
    private bool $hasInvalidStepKeys = false;

    private bool $hasMalformedPybmc = false;

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('cuti.configure_chain');
    }

    /**
     * Menormalisasi key numeric dari form dan menempatkan PYBMC khusus sebagai tahap terakhir.
     * Bentuk key atau nilai PYBMC yang malformed ditandai agar validasi gagal tertutup.
     */
    protected function prepareForValidation(): void
    {
        $steps = $this->input('steps');

        if (! is_array($steps)) {
            return;
        }

        $hasPybmc = array_key_exists('_pybmc', $steps);
        $pybmc = $hasPybmc ? $steps['_pybmc'] : null;
        unset($steps['_pybmc']);

        if ($hasPybmc && ! is_array($pybmc)) {
            $this->hasMalformedPybmc = true;
        }

        foreach (array_keys($steps) as $key) {
            $isNonNegativeInteger = is_int($key) && $key >= 0;
            $isCanonicalNumericString = is_string($key)
                && ctype_digit($key)
                && (string) (int) $key === $key;

            if (! $isNonNegativeInteger && ! $isCanonicalNumericString) {
                $this->hasInvalidStepKeys = true;

                return;
            }
        }

        ksort($steps, SORT_NUMERIC);
        $steps = array_values($steps);

        if ($hasPybmc && is_array($pybmc)) {
            $isFullyEmpty = collect($pybmc)
                ->every(fn (mixed $value): bool => $value === null || $value === '');

            if (! $isFullyEmpty) {
                $steps[] = $pybmc;
            }
        }

        $this->merge(['steps' => $steps]);
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
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
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
     * Menegakkan urutan Verifikator, Atasan Langsung efektif, lalu PYBMC final pada boundary HTTP.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasInvalidStepKeys || $this->hasMalformedPybmc) {
                $validator->errors()->add(
                    'steps',
                    'Struktur langkah approval tidak valid. Muat ulang halaman dan susun kembali chain.',
                );
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $employee = $this->route('employee');

            if (! $employee instanceof Employee) {
                return;
            }

            $steps = array_values($this->input('steps', []));
            $kepalaBagianIndexes = collect($steps)
                ->keys()
                ->filter(fn (int $index): bool => ($steps[$index]['step_type'] ?? null) === 'kepala_bagian')
                ->values();
            $verifierIndexes = collect($steps)
                ->keys()
                ->filter(fn (int $index): bool => ($steps[$index]['step_type'] ?? null) === 'verifier')
                ->values();

            if ($kepalaBagianIndexes->count() !== 1) {
                $validator->errors()->add('steps', 'Chain pegawai wajib memiliki tepat satu Atasan Langsung.');

                return;
            }

            $kepalaBagianIndex = (int) $kepalaBagianIndexes->sole();

            if ($verifierIndexes->contains(fn (int $index): bool => $index > $kepalaBagianIndex)) {
                $validator->errors()->add(
                    'steps',
                    'Semua Verifikator harus ditempatkan sebelum Atasan Langsung. Pindahkan Verifikator yang berada setelah Atasan Langsung.',
                );

                return;
            }

            $kepalaBagianId = $employee->currentSupervisor()?->kepala_bagian_id;

            if ($kepalaBagianId === null) {
                $validator->errors()->add(
                    "steps.{$kepalaBagianIndex}.approver_employee_id",
                    'Atasan Langsung belum ditetapkan untuk pegawai. Tetapkan penugasan Atasan Langsung terlebih dahulu.',
                );

                return;
            }

            $kepalaBagianStep = $steps[$kepalaBagianIndex];

            if (($kepalaBagianStep['approver_employee_id'] ?? null) !== $kepalaBagianId) {
                $validator->errors()->add(
                    "steps.{$kepalaBagianIndex}.approver_employee_id",
                    'Approver tahap Atasan Langsung harus sesuai penugasan Atasan Langsung efektif pegawai.',
                );
            }

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
