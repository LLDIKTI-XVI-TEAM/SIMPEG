<?php

namespace App\Services\Notifications\WhatsApp;

use App\Models\Employee;

class WhatsAppRecipientResolver
{
    /**
     * Sumber alamat kanonis adalah employees.no_hp, dinormalisasi ke prefix negara 62.
     * Snapshot nomor pada leave_requests dilarang dipakai sebagai alamat pengiriman.
     * Bila nomor kosong, bukan format Indonesia yang valid, atau di luar panjang E.164,
     * hasilnya null agar delivery WhatsApp dilewati (fail-closed) tanpa mengganggu
     * kanal notifikasi lain seperti in-app dan email.
     */
    public function resolve(Employee $employee): ?string
    {
        return self::normalize($employee->no_hp);
    }

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        // Pemisah penulisan nomor yang lazim boleh dihapus, tetapi teks, label, atau
        // beberapa nomor tidak boleh berubah diam-diam menjadi alamat penerima yang lain.
        if (! preg_match('/^\+?[0-9\s().-]+$/', $raw)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]+/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        $usesInternationalPrefix = str_starts_with($raw, '+') || str_starts_with($digits, '00');

        // Prefix internasional eksplisit hanya boleh memakai kode negara Indonesia.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($usesInternationalPrefix && ! str_starts_with($digits, '62')) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        // Hanya menerima format Indonesia 62... setelah normalisasi.
        if (! str_starts_with($digits, '62')) {
            return null;
        }

        // Bentuk penulisan lazim "+62 (0)812..." menyisakan nol trunk setelah kode
        // negara; nol tersebut dibuang agar hasil akhir menjadi E.164 yang benar.
        if (str_starts_with($digits, '620')) {
            $digits = '62'.substr($digits, 3);
        }

        // Batas panjang E.164; nomor di luar rentang dianggap tidak valid.
        $length = strlen($digits);
        if ($length < 10 || $length > 15) {
            return null;
        }

        return $digits;
    }
}
