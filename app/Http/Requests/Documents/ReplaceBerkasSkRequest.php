<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentAuthorization;
use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Mengganti berkas fisik SK yang sudah ada tanpa mengubah metadata resminya.
 *
 * Metadata SK tetaplah milik riwayat (read-only); hanya isi berkas yang boleh
 * diganti. Berkas lama dihapus permanen setelah transaksi berhasil.
 */
class ReplaceBerkasSkRequest extends FormRequest
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

    public function rules(): array
    {
        return [
            'berkas' => [
                'required',
                File::types(DocumentCategory::ALLOWED_FILE_TYPES)->max('10mb'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'berkas' => 'berkas SK pengganti',
        ];
    }
}
