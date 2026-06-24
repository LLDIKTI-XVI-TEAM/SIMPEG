<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;

class SkFilePathRules
{
    /**
     * Membatasi path SK ke file PDF relatif di folder sk/ agar input tidak bisa menyisipkan traversal path.
     *
     * @return array<int, string>
     */
    public static function nullablePdfPath(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:/\Ask\/[A-Za-z0-9][A-Za-z0-9._-]*\.pdf\z/'];
    }

    /**
     * Menerima upload SK baru atau path lama yang sudah terkontrol dari proses sebelumnya.
     *
     * @return array<int, mixed>
     */
    public static function nullableUploadOrControlledPath(): array
    {
        return [
            'nullable',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value instanceof UploadedFile) {
                    $extension = strtolower($value->getClientOriginalExtension());

                    if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                        $fail('The '.$attribute.' field must be a file of type: pdf, jpg, jpeg, png.');

                        return;
                    }

                    $validator = Validator::make(['file' => $value], [
                        'file' => ['file', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('10mb')],
                    ]);

                    if ($validator->fails()) {
                        $fail($validator->errors()->first('file'));
                    }

                    return;
                }

                if (is_string($value) && preg_match('/\Ask\/[A-Za-z0-9][A-Za-z0-9._-]*\.pdf\z/', $value) === 1) {
                    return;
                }

                $fail('The '.$attribute.' field format is invalid.');
            },
        ];
    }
}
