<?php

namespace App\Actions\Profiles;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UpdatePasswordAction
{
    /**
     * Mengganti kata sandi pengguna setelah kata sandi lama terbukti benar.
     *
     * Perubahan kredensial dicatat ke audit karena memengaruhi akses akun, namun payloadnya hanya
     * berisi penanda bahwa kata sandi berganti. Nilai maupun hash kata sandi tidak pernah disertakan
     * sebab baris audit tidak dapat dihapus dan dapat dibaca operator lain.
     *
     * Penggantian dan jejaknya disatukan dalam satu transaksi agar kredensial tidak pernah berubah
     * tanpa jejak siapa yang mengubahnya dan dari alamat mana.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(User $user, array $payload, ?Request $request = null): void
    {
        if (! Hash::check($payload['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Kata sandi saat ini tidak sesuai.',
            ]);
        }

        DB::transaction(function () use ($user, $payload, $request): void {
            $user->forceFill([
                'password' => Hash::make($payload['new_password']),
            ])->save();

            AuditService::logOrFail(
                'UPDATE',
                'User',
                $user->id,
                null,
                ['password_changed' => true],
                $request,
            );
        });
    }
}
