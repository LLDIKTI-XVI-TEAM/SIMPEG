<?php

namespace App\Services\Notifications;

use App\Services\Notifications\WhatsApp\UnavailableWhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;

final class NotificationEventCatalog
{
    /** @var array<string, array{label: string, group: string, allowed_channels: list<string>}> */
    private const EVENTS = [
        'cuti.pengajuan_baru' => [
            'label' => 'Cuti diajukan',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'cuti.menunggu_persetujuan' => [
            'label' => 'Cuti menunggu persetujuan berikutnya',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'cuti.disetujui' => [
            'label' => 'Cuti disetujui',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'cuti.ditunda' => [
            'label' => 'Cuti ditangguhkan',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'cuti.ditangguhkan_tugas_dinas' => [
            'label' => 'Cuti ditangguhkan karena tugas dinas',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'cuti.dikembalikan_karena_rollover' => [
            'label' => 'Cuti dikembalikan karena rollover',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'cuti.ditangguhkan_administratif' => [
            'label' => 'Cuti ditangguhkan secara administratif',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.pembatalan_diajukan' => [
            'label' => 'Permohonan pembatalan cuti diajukan',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.pembatalan_disetujui' => [
            'label' => 'Permohonan pembatalan cuti disetujui',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.pembatalan_ditolak' => [
            'label' => 'Permohonan pembatalan cuti ditolak',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.tidak_disetujui' => [
            'label' => 'Cuti tidak disetujui',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'ews.kenaikan_pangkat' => [
            'label' => 'EWS kenaikan pangkat',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'ews.kgb' => [
            'label' => 'EWS kenaikan gaji berkala',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'ews.pensiun' => [
            'label' => 'EWS pensiun',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'ews.kontrak_pppk' => [
            'label' => 'EWS kontrak PPPK',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'ews.satyalancana' => [
            'label' => 'EWS Satyalancana',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ],
        'ews.tidak_perlu' => [
            'label' => 'EWS tidak perlu ditindaklanjuti',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'ews.scheduler_failed' => [
            'label' => 'Kegagalan scheduler EWS',
            'group' => 'EWS',
            'allowed_channels' => ['in_app'],
        ],
        // Hasil tindak lanjut EWS untuk pegawai target. Email sengaja belum dibuka;
        // channel email dapat dinyalakan lewat konfigurasi saat keputusan produk
        // mengaktifkannya, tanpa mengubah kode domain.
        'ews.followup.kenaikan_pangkat' => [
            'label' => 'Hasil tindak lanjut EWS kenaikan pangkat',
            'group' => 'EWS',
            'allowed_channels' => ['in_app'],
        ],
        'ews.followup.kgb' => [
            'label' => 'Hasil tindak lanjut EWS KGB',
            'group' => 'EWS',
            'allowed_channels' => ['in_app'],
        ],
        'ews.followup.pensiun' => [
            'label' => 'Hasil tindak lanjut EWS pensiun',
            'group' => 'EWS',
            'allowed_channels' => ['in_app'],
        ],
        'ews.followup.kontrak_pppk' => [
            'label' => 'Hasil tindak lanjut EWS kontrak PPPK',
            'group' => 'EWS',
            'allowed_channels' => ['in_app'],
        ],
        'ews.followup.satyalancana' => [
            'label' => 'Hasil tindak lanjut EWS Satyalancana',
            'group' => 'EWS',
            'allowed_channels' => ['in_app'],
        ],
        'ews.followup.tidak_perlu' => [
            'label' => 'Hasil tindak lanjut EWS tidak perlu',
            'group' => 'EWS',
            'allowed_channels' => ['in_app'],
        ],
        'status_pegawai.diubah' => [
            'label' => 'Status kepegawaian diperbarui',
            'group' => 'Pegawai',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'status_pegawai.dinonaktifkan' => [
            'label' => 'Akun dinonaktifkan',
            'group' => 'Pegawai',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'import_pegawai' => [
            'label' => 'Impor pegawai selesai',
            'group' => 'Impor Pegawai',
            'allowed_channels' => ['in_app'],
        ],
        'import_pegawai_gagal' => [
            'label' => 'Impor pegawai gagal',
            'group' => 'Impor Pegawai',
            'allowed_channels' => ['in_app'],
        ],
    ];

    /** @var list<string> */
    private const RUNTIME_ADAPTERS = ['in_app', 'email'];

    public function __construct(private readonly WhatsAppTemplateAdapter $whatsAppAdapter) {}

    /**
     * Satu katalog domain mencegah identitas event berbeda antara konfigurasi dan delivery runtime.
     *
     * @return array<string, array{label: string, group: string, allowed_channels: list<string>}>
     */
    public function events(): array
    {
        return self::EVENTS;
    }

    public function hasEvent(string $eventKey): bool
    {
        return array_key_exists($eventKey, self::EVENTS);
    }

    public function supportsChannel(string $eventKey, string $channelCode): bool
    {
        return isset(self::EVENTS[$eventKey])
            && in_array($channelCode, self::EVENTS[$eventKey]['allowed_channels'], true);
    }

    public function hasAdapter(string $channelCode): bool
    {
        if (in_array($channelCode, self::RUNTIME_ADAPTERS, true)) {
            return true;
        }

        // Capability WhatsApp tersedia hanya ketika container memakai adapter konkret;
        // readiness dan kebijakan runtime tetap menjadi gerbang pengiriman terpisah.
        return $channelCode === 'whatsapp_business'
            && ! ($this->whatsAppAdapter instanceof UnavailableWhatsAppTemplateAdapter);
    }
}
