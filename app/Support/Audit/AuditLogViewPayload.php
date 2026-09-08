<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Bentuk data audit untuk permukaan tampilan.
 *
 * Dipisahkan dari controller supaya halaman daftar dan halaman detail memakai kontrak yang
 * sama, sehingga kolom yang ditampilkan tidak bergeser di antara keduanya.
 */
class AuditLogViewPayload
{
    /**
     * Istilah resmi untuk event keputusan cuti.
     *
     * Dua kosakata sengaja dipetakan ke label yang sama karena baris audit lama memakai APPROVE dan
     * POSTPONE, sedangkan baris baru memakai kosakata keputusan. Audit tidak dapat ditulis ulang,
     * jadi keduanya akan selalu berdampingan dan harus terbaca dengan istilah yang sama.
     *
     * @var array<string, string>
     */
    private const DECISION_LABELS = [
        'VERIFY' => 'Diverifikasi',
        'DECIDE' => 'Disetujui',
        'CHANGE_REQUESTED' => 'Perubahan',
        'DEFER' => 'Ditangguhkan',
        'POSTPONE' => 'Ditangguhkan',
        'NOT_APPROVED' => 'Tidak Disetujui',
    ];

    /**
     * Menentukan istilah resmi untuk sebuah baris audit.
     *
     * APPROVE lama tidak dapat dipetakan ke satu label saja karena event itu dahulu dipakai untuk
     * persetujuan tahap menengah maupun keputusan final. Baris lama tidak boleh dibackfill, tetapi
     * payloadnya sudah membawa status hasil, sehingga status itulah yang menentukan labelnya.
     */
    private static function decisionLabel(AuditLog $log): string
    {
        if ($log->event === 'APPROVE') {
            $status = data_get($log->new_values, 'status');

            // Tanpa status hasil, label dipertahankan seperti sebelumnya agar baris lama tidak
            // berpindah makna tanpa dasar.
            return $status !== null && $status !== 'disetujui' ? 'Diverifikasi' : 'Disetujui';
        }

        return self::DECISION_LABELS[$log->event] ?? $log->event;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forView(AuditLog $log, ?array $payload = null): array
    {
        $payload ??= app(AuditLogReadPayload::class)->forLogs(collect([$log]), null)->get($log->id);
        $module = class_basename($log->auditable_type);
        $recordId = $log->auditable_id
            ?? data_get($log->new_values, 'key')
            ?? data_get($log->old_values, 'key')
            ?? '-';

        return [
            'id' => $log->id,
            'timestamp' => $log->created_at?->format('Y-m-d H:i:s') ?? '-',
            'operator' => $log->user_name ?: 'Sistem',
            'event' => $log->event,
            'event_label' => self::decisionLabel($log),
            'kategori' => self::categoryFor($module, $log->event),
            'modul' => $module,
            'record_id' => (string) $recordId,
            'ip_address' => $log->ip_address ?: '-',
            'user_agent' => $log->user_agent ?: '-',
            'old_values' => $payload['old_values'],
            'new_values' => $payload['new_values'],
        ];
    }

    /** Detail satu record tetap memakai redaksi dan scope yang sama dengan daftar/API audit. */
    public static function forReader(AuditLog $log, ?User $actor): array
    {
        return self::forView($log, app(AuditLogReadPayload::class)->forLogs(collect([$log]), $actor)->get($log->id));
    }

    private static function categoryFor(string $module, string $event): string
    {
        // SSO_BINDING (pengikatan pertama subject Keycloak) dan SSO_MAPPING_REJECTED
        // (penolakan mapping identitas) adalah lifecycle autentikasi: tampilkan dalam
        // kategori autentikasi, bukan aktivitas_sistem default.
        if (in_array($event, ['LOGIN', 'LOGOUT', 'SESSION_TIMEOUT', 'SSO_BINDING', 'SSO_MAPPING_REJECTED'], true)) {
            return 'autentikasi';
        }

        return match ($module) {
            'Employee', 'RankHistory', 'PositionHistory', 'SalaryHistory',
            'DisciplineRecord', 'FamilyMember', 'Education' => 'data_pegawai',
            'LeaveRequest', 'LeaveApproval', 'LeaveBalance' => 'transaksi_cuti',
            'EwsConfig', 'Role', 'Permission', 'RefHariLibur', 'Setting' => 'konfigurasi_sistem',
            default => 'aktivitas_sistem',
        };
    }
}
