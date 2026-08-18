<?php

namespace App\Http\Requests\Documents;

use App\Models\Employee;
use App\Support\Documents\DocumentAuthorization;
use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (DocumentAuthorization::allowsLocalApiBypass()) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    /**
     * Endpoint store di-scope oleh {employee} dari route, sehingga pegawai_id
     * tidak perlu diwajibkan dari payload klien. Saat tidak dikirim, id pegawai
     * diisi dari route (model binding) agar aturan uuid/exists tetap tervalidasi
     * dan klien tidak perlu memilih pegawai lain yang nilainya akan diabaikan.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('pegawai_id')) {
            return;
        }

        $routeEmployee = $this->route('employee');

        $employeeId = $routeEmployee instanceof Employee
            ? $routeEmployee->id
            : (is_string($routeEmployee) || is_numeric($routeEmployee) ? (string) $routeEmployee : '');

        if ($employeeId !== '') {
            $this->merge(['pegawai_id' => $employeeId]);
        }
    }

    public function rules(): array
    {
        return [
            'nama_dokumen' => ['required', 'string', 'max:255'],
            'nomor_dokumen' => ['nullable', 'string', 'max:100'],
            'tanggal_terbit' => ['nullable', 'date'],
            'kategori_dokumen' => [
                'required',
                'string',
                Rule::in(array_filter(DocumentCategory::editableKeys(), fn ($key) => $key !== 'sk_status_pegawai')),
            ],
            'pegawai_id' => ['required', 'uuid', 'exists:employees,id'],
            'deskripsi' => ['nullable', 'string'],
            'berkas' => [
                'required',
                File::types(DocumentCategory::ALLOWED_FILE_TYPES)->max('10mb'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama_dokumen' => 'nama dokumen',
            'nomor_dokumen' => 'nomor dokumen',
            'tanggal_terbit' => 'tanggal dokumen',
            'kategori_dokumen' => 'kategori dokumen',
            'pegawai_id' => 'pegawai',
            'berkas' => 'berkas dokumen',
        ];
    }
}
