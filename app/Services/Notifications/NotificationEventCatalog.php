<?php

namespace App\Services\Notifications;

final class NotificationEventCatalog
{
    /** @var array<string, array{label: string, group: string, allowed_channels: list<string>}> */
    private const EVENTS = [
        'cuti.pengajuan_baru' => [
            'label' => 'Cuti diajukan',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.menunggu_persetujuan' => [
            'label' => 'Cuti menunggu persetujuan berikutnya',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.disetujui' => [
            'label' => 'Cuti disetujui',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.ditunda' => [
            'label' => 'Cuti ditangguhkan',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.ditangguhkan_tugas_dinas' => [
            'label' => 'Cuti ditangguhkan karena tugas dinas',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.perlu_perubahan' => [
            'label' => 'Cuti perlu perubahan',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'cuti.tidak_disetujui' => [
            'label' => 'Cuti tidak disetujui',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'ews.kenaikan_pangkat' => [
            'label' => 'EWS kenaikan pangkat',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'ews.kgb' => [
            'label' => 'EWS kenaikan gaji berkala',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'ews.pensiun' => [
            'label' => 'EWS pensiun',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'ews.kontrak_pppk' => [
            'label' => 'EWS kontrak PPPK',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email'],
        ],
        'ews.satyalancana' => [
            'label' => 'EWS Satyalancana',
            'group' => 'EWS',
            'allowed_channels' => ['in_app', 'email'],
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
    ];

    /** @var list<string> */
    private const RUNTIME_ADAPTERS = ['in_app', 'email'];

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
        return in_array($channelCode, self::RUNTIME_ADAPTERS, true);
    }
}
