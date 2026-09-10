<?php

namespace App\Actions\Cuti;

use App\Models\User;
use App\Services\Cuti\EmployeeApprovalChainBatchService;
use Illuminate\Support\Facades\Crypt;

class PreviewEmployeeApprovalChainsAction
{
    public function __construct(private readonly EmployeeApprovalChainBatchService $batch) {}

    /** Token mengikat aktor, draft, dan state sepuluh menit; token tidak menggantikan otorisasi apply. */
    public function execute(User $actor, array $input): array
    {
        $prepared = $this->batch->prepare($actor, $input);
        $result = $this->batch->publicResult($prepared);
        $result['data']['can_apply'] = $prepared['can_apply'];
        $result['data']['preview_token'] = Crypt::encryptString(json_encode([
            'version' => 1, 'actor_id' => $actor->id, 'expires_at' => now()->timestamp + 600,
            'draft_hash' => hash('sha256', json_encode($prepared['draft'], JSON_THROW_ON_ERROR)),
            'state_hash' => $this->batch->fingerprint($prepared),
        ], JSON_THROW_ON_ERROR));

        return $result;
    }
}
