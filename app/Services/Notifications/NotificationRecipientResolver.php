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

        if ($this->isNonEligiblePromotion($type, $data)) {
            return collect();
        }

        return $this->adminRecipients();
    }

    /**
     * Menahan email pegawai pada EWS kenaikan pangkat yang eksplisit tidak eligible.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function shouldEmailPrimaryRecipient(string $type, ?array $data = null): bool
    {
        if ($this->isNonEligiblePromotion($type, $data)) {
            return false;
        }

        return $this->emailEnabled($type);
    }

    /** Menyerahkan keputusan email sepenuhnya ke kebijakan event-channel dua lapis. */
    public function emailEnabled(string $type): bool
    {
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

    /**
     * Fail-closed sampai keputusan bisnis #34 menetapkan apakah admin perlu email untuk pegawai tidak eligible.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function isNonEligiblePromotion(string $type, ?array $data): bool
    {
        return $type === 'ews.kenaikan_pangkat' && ($data['is_eligible'] ?? true) === false;
    }
}
