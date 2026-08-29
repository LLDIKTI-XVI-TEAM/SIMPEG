<?php

namespace App\Services\Notifications;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    /** @var Collection<int, Employee>|null */
    private ?Collection $adminRecipientsCache = null;

    public function __construct(
        private readonly NotificationChannelResolver $channels,
        private readonly NotificationEventCatalog $catalog,
    ) {}

    /**
     * Mengembalikan penerima tambahan untuk EWS lintas role; cuti tetap memakai penerima in-app utama.
     * Event hasil follow-up (ews.followup.*) TIDAK mendapat penerima tambahan karena hanya ditujukan
     * kepada pegawai target, bukan admin yang melakukan tindak lanjut.
     *
     * @param  array<string, mixed>|null  $data
     * @return Collection<int, Employee>
     */
    public function additionalRecipients(Employee $primaryRecipient, string $type, ?array $data = null): Collection
    {
        // Event hasil follow-up (ews.followup.*) hanya untuk pegawai target
        if (str_starts_with($type, 'ews.followup.')) {
            return collect();
        }

        // Event reminder EWS dan scheduler failure
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
        // Katalog domain menjadi batas utama agar kebijakan DB yang stale tidak
        // mengaktifkan adapter email untuk event yang hanya mendukung in-app.
        if (! $this->catalog->supportsChannel($type, 'email')) {
            return false;
        }

        if ($type === 'ews.kenaikan_pangkat' && ($data['is_eligible'] ?? null) === false) {
            return false;
        }

        return $this->channels->isEnabledForEvent($type, 'email');
    }

    /** @return Collection<int, Employee> */
    private function adminRecipients(): Collection
    {
        // Di-cache per instance karena scheduler EWS memanggil resolver ini untuk
        // setiap reminder dalam satu run; tanpa cache, query admin yang sama
        // diulang ribuan kali pada data pegawai besar.
        return $this->adminRecipientsCache ??= User::query()
            // EWS rutin hanya perlu ditindaklanjuti Admin Kepegawaian; Super Admin khusus kegagalan scheduler.
            ->where('role', 'admin_kepegawaian')
            ->whereNotNull('employee_id')
            // Role saja tidak cukup: akun tertaut ke Employee Nonaktif tidak boleh menerima fan-out EWS.
            ->whereIn('employee_id', Employee::query()->whereActiveStatus()->select('id'))
            ->with(['employee.statusPegawai'])
            ->get()
            ->pluck('employee')
            // Hanya admin yang pegawainya masih aktif: deaktivasi harus menghentikan
            // distribusi notifikasi (data pegawai lain) ke akun yang aksesnya dicabut.
            ->filter(fn ($employee): bool => $employee instanceof Employee && $employee->isActive())
            ->unique('id')
            ->values();
    }
}
