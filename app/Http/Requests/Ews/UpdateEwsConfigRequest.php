<?php

namespace App\Http\Requests\Ews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEwsConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('ews.configure');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'ews_scheduler_time' => 'required|date_format:H:i',

            'pangkat_required_years' => 'required|integer|min:1|max:100',
            'pangkat_h90' => 'required|integer|min:1',
            'pangkat_h60' => 'required|integer|min:1',
            'pangkat_h30' => 'required|integer|min:1',

            'kgb_required_years' => 'required|integer|min:1|max:100',
            'kgb_h60' => 'required|integer|min:1',
            'kgb_h30' => 'required|integer|min:1',
            'kgb_h14' => 'required|integer|min:1',

            'pensiun_required_age_years' => 'required|integer|min:0|max:100',
            'pensiun_y1' => 'required|integer|min:1',
            'pensiun_m6' => 'required|integer|min:1',
            'pensiun_m3' => 'required|integer|min:1',

            'pppk_contract_years' => 'required|integer|min:1|max:100',
            'pppk_m6' => 'required|integer|min:1',
            'pppk_m3' => 'required|integer|min:1',
            'pppk_m1' => 'required|integer|min:1',

            'satyalancana_years_1' => 'required|integer|min:1|max:100',
            'satyalancana_years_2' => 'required|integer|min:1|max:100',
            'satyalancana_years_3' => 'required|integer|min:1|max:100',
            'satyalancana_h180' => 'required|integer|min:1',
            'satyalancana_h90' => 'required|integer|min:1',
            'satyalancana_h30' => 'required|integer|min:1',

            'reason' => 'required|string|min:5',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'integer' => ':attribute harus berupa bilangan bulat.',
            'min' => ':attribute minimal :min.',
            'ews_scheduler_time.required' => 'Waktu eksekusi scheduler wajib diisi.',
            'ews_scheduler_time.date_format' => 'Format waktu eksekusi scheduler tidak valid (wajib HH:MM).',
            'reason.required' => 'Alasan perubahan wajib diisi.',
            'reason.min' => 'Alasan perubahan minimal berisi 5 karakter.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'ews_scheduler_time' => 'Waktu eksekusi scheduler',
            'pangkat_required_years' => 'Masa kenaikan pangkat',
            'pangkat_h90' => 'Pangkat Tahap 1',
            'pangkat_h60' => 'Pangkat Tahap 2',
            'pangkat_h30' => 'Pangkat Tahap 3',
            'kgb_required_years' => 'Masa kenaikan KGB',
            'kgb_h60' => 'KGB Tahap 1',
            'kgb_h30' => 'KGB Tahap 2',
            'kgb_h14' => 'KGB Tahap 3',
            'pensiun_required_age_years' => 'Usia BUP global',
            'pensiun_y1' => 'Pensiun Tahap 1',
            'pensiun_m6' => 'Pensiun Tahap 2',
            'pensiun_m3' => 'Pensiun Tahap 3',
            'pppk_contract_years' => 'Masa kontrak PPPK',
            'pppk_m6' => 'PPPK Tahap 1',
            'pppk_m3' => 'PPPK Tahap 2',
            'pppk_m1' => 'PPPK Tahap 3',
            'satyalancana_years_1' => 'Milestone Satyalancana 1',
            'satyalancana_years_2' => 'Milestone Satyalancana 2',
            'satyalancana_years_3' => 'Milestone Satyalancana 3',
            'satyalancana_h180' => 'Satyalancana Tahap 1',
            'satyalancana_h90' => 'Satyalancana Tahap 2',
            'satyalancana_h30' => 'Satyalancana Tahap 3',
            'reason' => 'Alasan perubahan',
        ];
    }

    /**
     * Validasi urutan threshold antar tahap: tahap yang lebih awal harus punya
     * jarak hari lebih besar (H-90 > H-60 > H-30) dan milestone Satyalancana
     * harus menaik. Urutan yang salah membuat EWS mengirim peringatan mundur.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Sama seperti perilaku sebelum refactor: pemeriksaan urutan hanya berjalan
            // setelah seluruh rule dasar lulus, agar field yang kosong/tidak valid tidak
            // ikut memunculkan pesan urutan yang menyesatkan.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $orderedDescending = [
                ['pangkat_h90', 'pangkat_h60', 'Pangkat Tahap 1 harus lebih besar dari Tahap 2.'],
                ['pangkat_h60', 'pangkat_h30', 'Pangkat Tahap 2 harus lebih besar dari Tahap 3.'],
                ['kgb_h60', 'kgb_h30', 'KGB Tahap 1 harus lebih besar dari Tahap 2.'],
                ['kgb_h30', 'kgb_h14', 'KGB Tahap 2 harus lebih besar dari Tahap 3.'],
                ['pensiun_y1', 'pensiun_m6', 'Pensiun Tahap 1 harus lebih besar dari Tahap 2.'],
                ['pensiun_m6', 'pensiun_m3', 'Pensiun Tahap 2 harus lebih besar dari Tahap 3.'],
                ['pppk_m6', 'pppk_m3', 'PPPK Tahap 1 harus lebih besar dari Tahap 2.'],
                ['pppk_m3', 'pppk_m1', 'PPPK Tahap 2 harus lebih besar dari Tahap 3.'],
                ['satyalancana_h180', 'satyalancana_h90', 'Satyalancana Tahap 1 harus lebih besar dari Tahap 2.'],
                ['satyalancana_h90', 'satyalancana_h30', 'Satyalancana Tahap 2 harus lebih besar dari Tahap 3.'],
            ];

            foreach ($orderedDescending as [$higherKey, $lowerKey, $message]) {
                if ((int) $this->input($higherKey) <= (int) $this->input($lowerKey)) {
                    $validator->errors()->add($lowerKey, $message);
                }
            }

            $orderedAscending = [
                ['satyalancana_years_1', 'satyalancana_years_2', 'Milestone Satyalancana 1 harus lebih kecil dari Milestone 2.'],
                ['satyalancana_years_2', 'satyalancana_years_3', 'Milestone Satyalancana 2 harus lebih kecil dari Milestone 3.'],
            ];

            foreach ($orderedAscending as [$lowerKey, $higherKey, $message]) {
                if ((int) $this->input($lowerKey) >= (int) $this->input($higherKey)) {
                    $validator->errors()->add($higherKey, $message);
                }
            }
        });
    }
}
