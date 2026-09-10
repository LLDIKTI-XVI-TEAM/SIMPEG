<?php

namespace App\Actions\Cuti;

use App\Models\User;
use App\Services\Cuti\ApprovalChainConfigurationLockService;
use App\Services\Cuti\ApprovalChainInvariantService;
use App\Services\Cuti\EmployeeApprovalChainBatchService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/** Menerapkan rencana terverifikasi dalam satu transaksi agar kewenangan dan audit selalu atomik. */
class ApplyEmployeeApprovalChainsAction
{
    public function __construct(
        private readonly EmployeeApprovalChainBatchService $batch,
        private readonly ApprovalChainConfigurationLockService $configurationLock,
        private readonly ApprovalChainInvariantService $invariants,
        private readonly SaveEmployeeApprovalChainAction $saveChain,
    ) {}

    /** Token hanya bukti pratinjau; capability, scope, lifecycle, dan state diperiksa ulang saat penerapan. */
    public function execute(User $actor, array $input, string $previewToken, ?Request $request = null): array
    {
        abort_unless($actor->hasPermission('cuti.configure'), 403);
        $draft = $this->batch->normalize($input);
        $token = $this->verifiedToken($previewToken, $actor, $draft);

        return DB::transaction(function () use ($actor, $draft, $token, $request): array {
            $this->configurationLock->acquire();
            // Role dan identitas akun dapat berubah selama antre lock; jangan memakai snapshot request.
            $actor->refresh();
            $beforeLock = $this->batch->prepare($actor, $draft);

            try {
                // Semua target, termasuk yang akan dilewati, dikunci bersama approver dalam UUID order.
                // Writer individual hanya mengambil ulang subset lock yang sudah dimiliki transaksi ini.
                $this->invariants->validateApproverIds($beforeLock['approver_ids'], $draft['employee_ids']);
            } catch (QueryException $exception) {
                // Kegagalan SQL tidak boleh disamarkan sebagai hasil skip atau sukses parsial.
                throw $exception;
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'preview_token' => 'Approver berubah atau tidak lagi aktif. Buat pratinjau ulang sebelum menerapkan.',
                ]);
            }

            // Union lock juga dapat menunggu, sehingga otorisasi wajib memakai akun terbaru sebelum menulis.
            $actor->refresh();
            $prepared = $this->batch->prepare($actor, $draft);
            if ($token['expires_at'] <= now()->timestamp || ! hash_equals($token['state_hash'], $this->batch->fingerprint($prepared))) {
                $this->stalePreview();
            }
            if (! $prepared['can_apply']) {
                throw ValidationException::withMessages(['employee_ids' => 'Tidak ada rangkaian yang dapat diterapkan. Tinjau pilihan pegawai dan hasil pratinjau.']);
            }

            $rows = $prepared['rows'];
            usort($rows, fn (array $left, array $right): int => strcmp($left['employee_id'], $right['employee_id']));
            foreach ($rows as $row) {
                if (in_array($row['outcome'], ['create', 'replace'], true)) {
                    $this->saveChain->execute($prepared['targets']->get($row['employee_id']), $row['after_steps'], $actor, $draft['reason'], $request);
                }
            }

            return $this->batch->publicResult($prepared);
        });
    }

    /** Memeriksa bentuk token sebelum nilai dipakai agar ciphertext atau JSON malformed selalu menjadi 422. */
    private function verifiedToken(string $encrypted, User $actor, array $draft): array
    {
        try {
            $token = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            $this->stalePreview();
        }

        if (! is_array($token)
            || ($token['version'] ?? null) !== 1
            || ($token['actor_id'] ?? null) !== $actor->id
            || ! is_int($token['expires_at'] ?? null)
            || $token['expires_at'] <= now()->timestamp
            || ! is_string($token['draft_hash'] ?? null)
            || ! is_string($token['state_hash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $token['state_hash']) !== 1
            || ! hash_equals($token['draft_hash'], hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR)))) {
            $this->stalePreview();
        }

        return $token;
    }

    private function stalePreview(): never
    {
        throw ValidationException::withMessages(['preview_token' => 'Pratinjau tidak berlaku atau konfigurasi sudah berubah. Buat pratinjau ulang sebelum menerapkan.']);
    }
}
