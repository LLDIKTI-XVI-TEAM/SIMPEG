<?php

namespace App\Services\Cuti;

use App\Http\Requests\Cuti\KepalaBagianLeaveFilterRequest;
use App\Http\Requests\Cuti\ListLeaveCancellationRequest;
use App\Http\Requests\Cuti\PimpinanLeaveFilterRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Cuti\CutiPeriodFilter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Menjaga konteks daftar yang sama pada detail, form keputusan, dan redirect tanpa memberi otoritas. */
final class LeaveDetailNavigation
{
    /**
     * Mutasi pemilik/pengelola memakai konteks aman yang sama; caller tanpa asal tetap menuju detail biasa.
     *
     * @return array<string, mixed>
     */
    public function detailParameters(User $actor, string $id, mixed $from = null, mixed $returnFilters = []): array
    {
        return [
            'id' => $id,
            ...($from === null && $returnFilters === [] ? [] : $this->resolve($actor, $from, $returnFilters)['parameters']),
        ];
    }

    /**
     * Asal pendek hanya memilih route lokal; izin terkini diperiksa kembali sebelum membawa filter.
     *
     * @return array{parameters: array<string, mixed>, backLink: array{url: string, label: string}}
     */
    public function resolve(User $actor, mixed $from = null, mixed $returnFilters = []): array
    {
        $from = is_string($from) ? $from : null;
        $returnFilters = is_array($returnFilters) ? $returnFilters : [];
        $targets = [
            'own' => ['cuti', 'Kembali ke Pengajuan Cuti Saya'],
            'approval' => ['cuti.approval', 'Kembali ke Menunggu Tindakan Saya'],
        ];
        if ($actor->hasPermission('cuti.read_all')) {
            $targets += [
                'pimpinan' => ['pimpinan.cuti.index', 'Kembali ke Monitoring Cuti'],
                'bawahan' => ['kepala-bagian.cuti.index', 'Kembali ke Cuti Bawahan'],
                'monitoring' => ['cuti', 'Kembali ke Monitoring Cuti'],
            ];
        }
        if ($actor->hasPermission('cuti.cancellation.manage')) {
            $targets['cancellations'] = ['cuti.cancellations.index', 'Kembali ke Antrean Pembatalan Cuti'];
        }

        $target = $targets[$from ?? ''] ?? null;
        // Asal palsu atau izin yang dicabut tidak membawa filter halaman lain ke daftar pribadi.
        $filters = $target !== null || $from === null ? $this->returnFilters($from, $returnFilters) : [];
        $origin = $target === null ? 'own' : $from;
        $target ??= $targets['own'];

        return [
            // Asal pribadi harus eksplisit saat POST agar tidak kembali ke default antrean tindakan.
            'parameters' => ['from' => $origin, ...($filters !== [] ? ['return' => $filters] : [])],
            'backLink' => [
                'url' => route($target[0], $origin === 'own' ? ['scope' => 'own', ...$filters] : $filters),
                'label' => $target[1],
            ],
        ];
    }

    /**
     * Pakai kontrak filter halaman asal; input cacat dibuang tanpa menghalangi pembacaan atau keputusan.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function returnFilters(?string $from, array $filters): array
    {
        $paginationRules = [
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
        $rules = match ($from) {
            'approval' => [],
            'cancellations' => (new ListLeaveCancellationRequest)->rules(),
            'pimpinan' => (new PimpinanLeaveFilterRequest)->rules(),
            'bawahan' => (new KepalaBagianLeaveFilterRequest)->rules(),
            default => [
                'status' => ['nullable', 'string', Rule::in([
                    'pending', 'menunggu', 'disetujui', 'ditunda', 'ditangguhkan',
                    LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED, 'ditangguhkan_tugas_dinas',
                    LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, LeaveRequest::STATUS_CANCELLATION_PENDING,
                    LeaveRequest::STATUS_CANCELLED, 'perlu_perubahan', 'tidak_disetujui',
                ])],
                'jenis' => ['nullable', 'string', 'max:255'],
                'periode' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (CutiPeriodFilter::parse($value) === null) {
                        $fail('Periode tidak valid.');
                    }
                }],
                'tahun' => ['nullable', 'digits:4', 'integer', 'min:1'],
                ...($from === 'monitoring' ? [
                    'search' => ['nullable', 'string', 'max:100'],
                    'unit' => ['nullable', 'uuid'],
                ] : []),
            ],
        };
        $rules = [...$rules, ...$paginationRules];
        $filters = array_filter(Arr::only($filters, array_keys($rules)), fn (mixed $value): bool => $value === null || is_string($value) || is_int($value));

        return Validator::make($filters, $rules)->valid();
    }
}
