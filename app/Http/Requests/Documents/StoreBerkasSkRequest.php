<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentAuthorization;
use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreBerkasSkRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (DocumentAuthorization::allowsLocalApiBypass()) {
            return true;
        }

        return DocumentAuthorization::canManage($this->user());
    }

    public function rules(): array
    {
        $category = (string) $this->input('kategori_dokumen');

        $rules = [
            'kategori_dokumen' => ['required', 'string', Rule::in(DocumentCategory::tabSkKeys())],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('10mb')],
        ];

        if ($category === 'sk_pangkat') {
            $rules['golongan_id'] = ['required', 'uuid', 'exists:ref_golongan,id'];
            $rules['tmt_pangkat'] = ['required', 'date'];
        }

        if ($category === 'sk_jabatan') {
            $rules['jabatan_id'] = ['required', 'uuid', Rule::exists('ref_jabatan', 'id')->where('is_active', true)];
            $rules['unit_kerja_id'] = ['required', 'uuid', 'exists:ref_unit_kerja,id'];
            $rules['tmt_jabatan'] = ['required', 'date'];
            $rules['jenis_jabatan_id'] = ['nullable', 'uuid', 'exists:ref_jenis_jabatan,id'];
            $rules['eselon_id'] = ['nullable', 'uuid', 'exists:ref_eselon,id'];
            $rules['kelas_jabatan'] = ['nullable', 'string', 'max:10'];
        }

        if ($category === 'sk_kgb') {
            $rules['gaji_pokok'] = ['required', 'numeric', 'min:0'];
            $rules['tmt_kgb'] = ['required', 'date'];
        }

        if ($category === 'sk_pengangkatan') {
            $rules['jenis_pengangkatan'] = ['required', 'string', Rule::in(['CPNS', 'PNS', 'PPPK'])];
            $rules['tmt_pengangkatan'] = ['required', 'date'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'kategori_dokumen' => 'kategori SK',
            'golongan_id' => 'golongan',
            'tmt_pangkat' => 'TMT pangkat',
            'jabatan_id' => 'jabatan',
            'unit_kerja_id' => 'unit kerja',
            'tmt_jabatan' => 'TMT jabatan',
            'gaji_pokok' => 'gaji pokok',
            'tmt_kgb' => 'TMT KGB',
            'jenis_pengangkatan' => 'jenis pengangkatan',
            'tmt_pengangkatan' => 'TMT pengangkatan',
            'no_sk' => 'nomor SK',
            'tanggal_sk' => 'tanggal SK',
            'file_sk' => 'file SK',
        ];
    }
}
