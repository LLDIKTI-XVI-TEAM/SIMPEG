<?php

namespace App\Http\Requests\Cuti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Memvalidasi filter halaman konfigurasi chain cuti.
 * Filter dibatasi agar pencarian tetap aman dan tidak berubah menjadi query bebas ke data pegawai.
 */
class CutiConfigPageRequest extends FormRequest
{
    private const TABS = ['pegawai', 'rangkaian', 'pybmc', 'riwayat'];

    private const STEPS = ['susun', 'pilih', 'tinjau'];

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->hasPermission('cuti.configure');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            // Keberadaan dan scope diperiksa sebagai satu query di Action agar ID asing
            // tidak menjadi existence oracle melalui perbedaan pesan validasi.
            'employee_id' => ['nullable', 'uuid'],
            'approver_search' => ['nullable', 'string', 'max:100'],
            'tab' => ['required', 'string', 'in:'.implode(',', self::TABS)],
            'step' => ['required', 'string', 'in:'.implode(',', self::STEPS)],
        ];
    }

    /**
     * Menormalkan state presentasi saja; filter pencarian dan scope pegawai tetap
     * melewati kontrak validasi serta pemeriksaan Action yang sudah ada.
     */
    protected function prepareForValidation(): void
    {
        $tab = $this->input('tab');
        $step = $this->input('step');

        $this->merge([
            'tab' => is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'pegawai',
            'step' => is_string($step) && in_array($step, self::STEPS, true) ? $step : 'susun',
        ]);
    }
}
