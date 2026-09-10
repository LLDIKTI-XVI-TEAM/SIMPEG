<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use App\Support\Cuti\ApprovalStepLabel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Menyusun state konfigurasi yang sama bagi pratinjau dan penerapan tanpa menulis atau mengambil lock. */
class EmployeeApprovalChainBatchService
{
    private const DRAFT_KEYS = ['employee_ids', 'mode', 'verifiers', 'pybmc_mode', 'pybmc_employee_id', 'reason'];

    private const OUTCOMES = ['create', 'replace', 'unchanged', 'skip_existing', 'skip_inactive', 'skip_supervisor', 'skip_self_required'];

    public function __construct(
        private readonly EmployeeDashboardScopeService $scope,
        private readonly ApprovalChainInvariantService $invariants,
    ) {}

    /**
     * Menjaga urutan key dan UUID deterministik tanpa mengubah tipe input malformed.
     * Transport token bukan bagian draft; key asing tetap dipertahankan agar validator menolaknya.
     */
    public function normalize(array $input): array
    {
        unset($input['_token'], $input['preview_token']);
        $input += ['pybmc_employee_id' => null, 'reason' => null];
        if (is_array($input['employee_ids'] ?? null)) {
            $input['employee_ids'] = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $input['employee_ids']);
            if (array_is_list($input['employee_ids']) && count(array_filter($input['employee_ids'], 'is_string')) === count($input['employee_ids'])) {
                sort($input['employee_ids'], SORT_STRING);
            }
        }
        if (is_array($input['verifiers'] ?? null)) {
            $input['verifiers'] = array_map(function ($step) {
                if (! is_array($step)) {
                    return $step;
                }
                if (is_string($step['approver_employee_id'] ?? null)) {
                    $step['approver_employee_id'] = strtolower($step['approver_employee_id']);
                }
                if (is_string($step['role_label'] ?? null)) {
                    $step['role_label'] = $this->trimUnicode($step['role_label']);
                }

                // Field berurutan menjadikan hash sama walaupun urutan key JSON input berbeda.
                return array_replace(array_intersect_key(array_fill_keys(['approver_employee_id', 'role_label'], null), $step), $step);
            }, $input['verifiers']);
        }
        if (is_string($input['pybmc_employee_id'])) {
            $input['pybmc_employee_id'] = strtolower($input['pybmc_employee_id']);
        }
        if (is_string($input['reason'])) {
            $input['reason'] = $this->trimUnicode($input['reason']);
            $input['reason'] = $input['reason'] === '' ? null : $input['reason'];
        }

        return array_replace(array_intersect_key(array_fill_keys(self::DRAFT_KEYS, null), $input), $input);
    }

    /** Aturan struktur dipakai boundary HTTP dan pemanggil Action langsung sebelum query UUID. */
    public static function draftRules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'employee_ids.*' => ['bail', 'required', 'uuid', 'distinct:strict'],
            'mode' => ['required', 'string', 'in:missing_only,replace'],
            'verifiers' => ['present', 'array', 'list', 'max:8'],
            'verifiers.*' => ['required', 'array:approver_employee_id,role_label'],
            'verifiers.*.approver_employee_id' => ['bail', 'required', 'uuid', 'distinct:strict'],
            'verifiers.*.role_label' => ['required', 'string', 'min:1', 'max:100'],
            'pybmc_mode' => ['required', 'string', 'in:global,custom'],
            'pybmc_employee_id' => ['bail', 'nullable', 'required_if:pybmc_mode,custom', 'prohibited_if:pybmc_mode,global', 'uuid'],
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * Seluruh target diiriskan dengan scope sebelum identitas kandidat atau detail domain dibaca.
     * Setiap panggilan membaca DB segar agar apply dapat mengulangnya sesudah union lock diperoleh.
     *
     * @return array<string, mixed>
     */
    public function prepare(User $actor, array $draft): array
    {
        abort_unless($actor->hasPermission('cuti.configure'), 403);
        $draft = $this->normalize($draft);
        if (array_diff(array_keys($draft), self::DRAFT_KEYS) !== []) {
            throw ValidationException::withMessages(['draft' => 'Field draft tidak dikenal.']);
        }
        Validator::make($draft, self::draftRules())->validate();
        $targets = $this->scope->forIdentity($actor)
            ->with('statusPegawai:id,kelompok')->whereIn('employees.id', $draft['employee_ids'])
            ->orderBy('employees.id')->get(['employees.id', 'nama_lengkap', 'nip', 'status_pegawai_id', 'status_aktif'])->keyBy('id');
        abort_unless($targets->count() === count($draft['employee_ids']), 404);

        $global = $draft['pybmc_mode'] === 'global'
            ? LeavePybmcGlobalConfig::query()->latestRevision()
                ->first(['id', 'approver_employee_id', 'effective_from'])
            : null;
        $pybmcId = $draft['pybmc_mode'] === 'global' ? $global?->approver_employee_id : $draft['pybmc_employee_id'];
        $commonIds = array_values(array_unique([...array_column($draft['verifiers'], 'approver_employee_id'), ...($pybmcId === null ? [] : [$pybmcId])]));
        $assignments = $targets->map(fn (Employee $employee) => $employee->currentSupervisor());
        $supervisorIds = $assignments->pluck('kepala_bagian_id')->filter()->unique()->values()->all();
        $candidates = Employee::query()->with('statusPegawai:id,kelompok')
            ->whereIn('id', array_values(array_unique([...$commonIds, ...$supervisorIds])))
            ->get(['id', 'nama_lengkap', 'nip', 'status_pegawai_id', 'status_aktif'])->keyBy('id');
        if ($pybmcId === null || ! $candidates->get($pybmcId)?->isActive()) {
            throw ValidationException::withMessages(['pybmc_employee_id' => 'Pilih PYBMC aktif; PYBMC global mungkin belum ditetapkan.']);
        }
        foreach ($draft['verifiers'] as $verifier) {
            if (! $candidates->get($verifier['approver_employee_id'])?->isActive()) {
                throw ValidationException::withMessages(['verifiers' => 'Seluruh Verifikator harus merupakan pegawai aktif yang tersedia.']);
            }
        }

        $chains = LeaveApprovalChain::query()->whereIn('employee_id', $draft['employee_ids'])->where('is_active', true)
            ->with(['steps' => fn ($query) => $query->orderBy('step_order')
                ->select(['id', 'leave_approval_chain_id', 'step_order', 'step_type', 'role_label', 'approver_employee_id', 'approver_role_key', 'is_final']), 'steps.approver:id,nama_lengkap,nip'])
            ->get(['id', 'employee_id', 'is_active', 'effective_from', 'effective_until'])->keyBy('employee_id');
        $references = $candidates->map(fn (Employee $e) => $this->identity($e))->all();
        $rows = [];
        $approverIds = $commonIds;
        foreach ($targets as $id => $employee) {
            $assignment = $assignments->get($id);
            $supervisor = $candidates->get($assignment?->kepala_bagian_id);
            $chain = $chains->get($id);
            $before = [];
            foreach ($chain?->steps ?? [] as $step) {
                $before[] = $this->step($step->step_type, $step->role_label, $step->approver_employee_id, $step->is_final, $step->approver_role_key);
                if ($step->approver !== null) {
                    $references[$step->approver->id] = $this->identity($step->approver);
                }
            }
            if ($supervisor?->isActive()) {
                $approverIds[] = $supervisor->id;
            }
            [$outcome, $message, $after] = $this->candidate($employee, $supervisor, $chain !== null, $before, $draft, $pybmcId);
            $rows[] = [
                'employee_id' => $id, 'nama_lengkap' => $employee->nama_lengkap, 'nip' => $employee->nip,
                'supervisor' => $supervisor === null ? null : $this->identity($supervisor),
                'before_steps' => $before, 'after_steps' => $after, 'outcome' => $outcome, 'message' => $message,
                // State berisi metadata utuh untuk stale protection, tanpa nama/NIP/alasan privat.
                'state' => [
                    'employee_id' => $id, 'lifecycle' => $this->lifecycle($employee),
                    'assignment' => $assignment === null ? null : [
                        'id' => $assignment->id, 'kepala_bagian_id' => $assignment->kepala_bagian_id,
                        'tanggal_mulai' => $assignment->tanggal_mulai->toDateString(),
                        'tanggal_berakhir' => $assignment->tanggal_berakhir?->toDateString(),
                    ],
                    'chain' => $chain === null ? null : [
                        'id' => $chain->id, 'is_active' => $chain->is_active,
                        'effective_from' => $chain->effective_from->toDateString(),
                        'effective_until' => $chain->effective_until?->toDateString(),
                        'steps' => $chain->steps->map(fn ($step) => [
                            'id' => $step->id, 'step_order' => $step->step_order, 'step_type' => $step->step_type,
                            'role_label' => $step->role_label, 'approver_employee_id' => $step->approver_employee_id,
                            'approver_role_key' => $step->approver_role_key, 'is_final' => $step->is_final,
                        ])->all(),
                    ],
                    'outcome' => $outcome, 'after_steps' => $after,
                ],
            ];
        }
        $outcomes = array_column($rows, 'outcome');
        if ($draft['mode'] === 'replace' && count($draft['employee_ids']) > 1 && in_array('replace', $outcomes, true) && $draft['reason'] === null) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi saat mengganti rangkaian beberapa pegawai.']);
        }
        $approverIds = array_values(array_unique($approverIds));
        sort($approverIds, SORT_STRING);

        return [
            'draft' => $draft, 'targets' => $targets, 'rows' => $rows, 'approver_ids' => $approverIds,
            'references' => $references,
            'approver_state' => $candidates->sortKeys()->map(fn (Employee $e) => $this->lifecycle($e))->all(),
            'global_reference' => $global === null ? null : ['id' => $global->id, 'approver_employee_id' => $global->approver_employee_id, 'effective_from' => $global->effective_from->toDateString()],
            'can_apply' => in_array('create', $outcomes, true) || in_array('replace', $outcomes, true),
        ];
    }

    /** Payload browser memakai allowlist; model dan fingerprint internal tidak pernah ikut dikirim. */
    public function publicResult(array $prepared): array
    {
        $counts = array_fill_keys(self::OUTCOMES, 0);
        $rows = [];
        foreach ($prepared['rows'] as $row) {
            $counts[$row['outcome']]++;
            $publicRow = [
                'employee_id' => $row['employee_id'], 'nama_lengkap' => $row['nama_lengkap'], 'nip' => $row['nip'],
                'supervisor' => $row['supervisor'], 'outcome' => $row['outcome'], 'message' => $row['message'],
            ];
            foreach (['before_steps', 'after_steps'] as $key) {
                $publicRow[$key] = array_map(fn (array $step, int $index) => [
                    'step_order' => $index + 1,
                    ...$this->step($step['step_type'], $step['role_label'], $step['approver_employee_id'], $step['is_final'], $step['approver_role_key']),
                    'approver' => $prepared['references'][$step['approver_employee_id']] ?? null,
                ], $row[$key], array_keys($row[$key]));
            }
            $rows[] = $publicRow;
        }

        return ['data' => ['rows' => $rows, 'counts' => $counts]];
    }

    /** Menangkap perubahan chain in-place, lifecycle, assignment efektif, dan referensi global. */
    public function fingerprint(array $prepared): string
    {
        return hash('sha256', json_encode([
            'rows' => array_column($prepared['rows'], 'state'),
            'approvers' => $prepared['approver_state'],
            'global_reference' => $prepared['global_reference'],
        ], JSON_THROW_ON_ERROR));
    }

    /** Urutan keputusan mempertahankan missing-only sebelum klasifikasi target yang sudah memiliki chain. */
    private function candidate(Employee $employee, ?Employee $supervisor, bool $hasChain, array $before, array $draft, string $pybmcId): array
    {
        if ($draft['mode'] === 'missing_only' && $hasChain) {
            return ['skip_existing', 'Sudah memiliki rangkaian aktif.', $before];
        }
        if (! $employee->isActive()) {
            return ['skip_inactive', 'Pegawai target tidak aktif.', []];
        }
        if (! $supervisor?->isActive()) {
            return ['skip_supervisor', 'Atasan Langsung efektif belum tersedia atau tidak aktif.', []];
        }
        if ($supervisor->id === $employee->id || $pybmcId === $employee->id) {
            return ['skip_self_required', 'Pegawai tidak dapat menjadi Atasan Langsung atau PYBMC bagi dirinya sendiri.', []];
        }
        $after = array_map(fn ($v) => $this->step('verifier', $v['role_label'], $v['approver_employee_id'], false), $draft['verifiers']);
        $after[] = $this->step('kepala_bagian', 'Atasan Langsung', $supervisor->id, false);
        $after[] = $this->step('pybmc', 'PYBMC', $pybmcId, true);
        $this->invariants->assertCanonicalShape($after);
        $outcome = ! $hasChain ? 'create' : ($before === $after ? 'unchanged' : 'replace');
        $message = match ($outcome) {
            'create' => 'Membuat rangkaian baru.',
            'replace' => 'Mengganti rangkaian aktif; riwayat tetap tersimpan.',
            default => 'Rangkaian sudah sama dan tidak berubah.',
        };
        if (in_array($employee->id, array_column($draft['verifiers'], 'approver_employee_id'), true)) {
            $message .= ' Tahap Verifikator diri sendiri tidak menjadi approval saat pengajuan.';
        }

        return [$outcome, $message, $after];
    }

    /** Metadata sama dengan writer agar perubahan alasan saja tidak membentuk successor fiktif. */
    private function step(string $type, ?string $label, ?string $id, bool $final, ?string $roleKey = null): array
    {
        return ['step_type' => $type, 'role_label' => ApprovalStepLabel::display($type, $label), 'approver_employee_id' => $id, 'approver_role_key' => $roleKey, 'is_final' => $final];
    }

    private function identity(Employee $employee): array
    {
        return ['id' => $employee->id, 'nama_lengkap' => $employee->nama_lengkap, 'nip' => $employee->nip];
    }

    /** Kelompok referensi, bukan snapshot nama status, merupakan sumber keaktifan. */
    private function lifecycle(Employee $employee): array
    {
        return ['status_pegawai_id' => $employee->status_pegawai_id, 'kelompok' => $employee->statusPegawai?->kelompok, 'active' => $employee->isActive()];
    }

    private function trimUnicode(string $value): string
    {
        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value) ?? $value;
    }
}
