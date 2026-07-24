<?php

namespace App\Services\Notifications;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    public function __construct(private readonly NotificationChannelResolver $channels) {}

    /**
     * Mengembalikan penerima tambahan untuk EWS lintas role; cuti tetap memakai penerima in-app utama.
     *
     * @param  array<string, mixed>|null  $data
     * @return Collection<int, Employee>
     */
    public function additionalRecipients(Employee $primaryRecipient, string $type, ?array $data = null): Collection
    {
        if (! str_starts_with($type, 'ews.')) {
            return collect();
        }

        return $this->adminRecipients();
    }

    /**
     * Menentukan apakah penerima utama (pegawai) perlu mendapat email untuk event ini.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function shouldEmailPrimaryRecipient(string $type, ?array $data = null): bool
    {
        return $this->emailEnabled($type);
    }

    /**
     * Menentukan jenis notifikasi yang memakai email; keputusan cuti perlu perubahan dan tidak disetujui dikirim agar pegawai segera menindaklanjuti statusnya.
     * Email hanya terkirim jika channel aktif dan credential SMTP sudah dikonfigurasi di RefNotificationChannel.
     */
    public function emailEnabled(string $type): bool
    {
        return $this->channels->isEnabled('email') && in_array($type, [
            'cuti.pengajuan_baru',
            'cuti.menunggu_persetujuan',
            'cuti.disetujui',
            'cuti.ditunda',
            'cuti.perlu_perubahan',
            'cuti.tidak_disetujui',
            'ews.kenaikan_pangkat',
            'ews.kgb',
            'ews.pensiun',
            'ews.kontrak_pppk',
            'ews.satyalancana',
        ], true);
    }

    /** @return Collection<int, Employee> */
    private function adminRecipients(): Collection
    {
        return User::query()
            ->whereIn('role', ['super_admin', 'admin_kepegawaian'])
            ->whereNotNull('employee_id')
            ->with('employee')
            ->get()
            ->pluck('employee')
            ->filter(fn ($employee): bool => $employee instanceof Employee)
            ->unique('id')
            ->values();
    }
}
