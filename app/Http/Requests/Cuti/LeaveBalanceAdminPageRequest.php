<?php

namespace App\Http\Requests\Cuti;

/**
 * Menjaga administrasi saldo hanya membuka tahun aplikasi yang sedang berjalan.
 */
class LeaveBalanceAdminPageRequest extends ListCutiRekapRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'periode' => ['nullable', 'string', 'regex:/^(?:20\d{2}|2100)$/'],
            'pegawai' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:perlu_tindakan,sudah_terdaftar,semua_pegawai'],
            'search' => ['nullable', 'string', 'max:150'],
            'tab' => ['nullable', 'in:pendaftaran,koreksi'],
            'page_pegawai' => ['nullable', 'integer', 'min:1'],
            'page_ledger' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Parameter lama diterima hanya bila sama dengan tahun aplikasi, lalu dibuang agar URL turunan tetap kanonis.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $periode = $this->input('periode');

        if ($periode !== null && (is_array($periode) || ! is_string($periode) || preg_match('/^(?:20\d{2}|2100)$/', $periode) !== 1)) {
            abort(404);
        }

        if ($periode !== null && (int) $periode !== now(config('app.timezone'))->year) {
            abort(404);
        }

        $this->query->remove('periode');
    }
}
