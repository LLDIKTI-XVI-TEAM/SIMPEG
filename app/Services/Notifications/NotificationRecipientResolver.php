<?php

namespace App\Services\Notifications;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
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

    /**
     * Menentukan jenis notifikasi yang memakai email; keputusan cuti perlu perubahan dan tidak disetujui dikirim agar pegawai segera menindaklanjuti statusnya.
     */
    public function emailEnabled(string $type): bool
    {
        return in_array($type, [
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
