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
        if (! str_starts_with($type, 'ews.') || $type === 'ews.scheduler_failed') {
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
        return $this->emailEnabled($type, $data);
    }

    /**
     * Menentukan jenis notifikasi yang memakai email.
     * Promosi yang belum eligible menunggu keputusan admin melalui alur follow-up, sehingga tidak mengirim email terlebih dahulu.
     * Fail-closed: delivery hanya aktif bila channel global dan pasangan kebijakan event-channel sama-sama aktif.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function emailEnabled(string $type, ?array $data = null): bool
    {
        if ($type === 'ews.kenaikan_pangkat' && ($data['is_eligible'] ?? null) === false) {
            return false;
        }

        return $this->channels->isEnabledForEvent($type, 'email');
    }

    /** @return Collection<int, Employee> */
    private function adminRecipients(): Collection
    {
        return User::query()
            // EWS rutin hanya perlu ditindaklanjuti Admin Kepegawaian; Super Admin khusus kegagalan scheduler.
            ->where('role', 'admin_kepegawaian')
            ->whereNotNull('employee_id')
            ->with('employee')
            ->get()
            ->pluck('employee')
            ->filter(fn ($employee): bool => $employee instanceof Employee)
            ->unique('id')
            ->values();
    }
}
