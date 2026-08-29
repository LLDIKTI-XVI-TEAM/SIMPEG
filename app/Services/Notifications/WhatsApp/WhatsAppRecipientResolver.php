<?php

namespace App\Services\Notifications\WhatsApp;

use App\Models\Employee;

class WhatsAppRecipientResolver
{
    /**
     * Belum ada kontrak LLDIKTI untuk sumber, normalisasi, dan verifikasi alamat WhatsApp.
     * Nomor pegawai maupun snapshot nomor cuti tidak boleh diasumsikan sebagai alamat terverifikasi.
     */
    public function resolve(Employee $employee): ?string
    {
        return null;
    }
}
