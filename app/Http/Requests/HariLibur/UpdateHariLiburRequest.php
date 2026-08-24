<?php

namespace App\Http\Requests\HariLibur;

use App\Models\RefHariLibur;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHariLiburRequest extends FormRequest
{
    /**
     * Error Edit tidak boleh dibaca oleh modal Tambah yang memakai nama field
     * sama pada halaman daftar Hari Libur.
     *
     * @var string
     */
    protected $errorBag = 'hariLiburEdit';

    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'nama' => ['required', 'string', 'max:100'],
            'tipe' => ['required', Rule::in(['libur_nasional', 'cuti_bersama'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $tanggal = $this->input('tanggal');

                if (! is_string($tanggal) || $validator->errors()->has('tanggal')) {
                    return;
                }

                $hariLibur = $this->route('hariLibur');
                $hariLiburId = $hariLibur instanceof RefHariLibur ? $hariLibur->id : null;

                $query = RefHariLibur::whereDate('tanggal', $tanggal);

                if ($hariLiburId !== null) {
                    $query->whereKeyNot($hariLiburId);
                }

                if ($query->exists()) {
                    $validator->errors()->add('tanggal', 'Tanggal hari libur sudah terdaftar.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'tanggal' => 'tanggal hari libur',
            'nama' => 'nama hari libur',
            'tipe' => 'tipe hari libur',
        ];
    }
}
