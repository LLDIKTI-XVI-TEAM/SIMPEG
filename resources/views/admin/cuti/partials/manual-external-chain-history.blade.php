@if ($usageRow->source_type === 'manual_external')
    <div class="mt-3 border-t border-border pt-3">
        <p class="font-semibold text-ink">Snapshot persetujuan</p>
        @if ($usageRow->approval_document_number)
            <p class="mt-1 break-words text-muted">Nomor dokumen: {{ $usageRow->approval_document_number }}</p>
        @endif

        @if ($usageRow->externalApprovalSteps->isEmpty())
            <p class="mt-1 text-muted">Rangkaian persetujuan belum tersedia pada data sebelum revisi.</p>
        @else
            <ol class="mt-2 list-decimal space-y-1 pl-4">
                @foreach ($usageRow->externalApprovalSteps as $approvalStep)
                    <li class="break-words">
                        <span class="font-semibold text-ink">{{ \App\Support\Cuti\ApprovalStepLabel::display(
                            $approvalStep->step_type,
                            match ($approvalStep->step_type) {
                                'verifier' => 'Verifikator',
                                'pybmc' => 'PYBMC',
                                default => 'Tahap persetujuan',
                            },
                        ) }}:</span>
                        {{ $approvalStep->approver_name_snapshot }}@if ($approvalStep->approver_nip_snapshot) (NIP {{ $approvalStep->approver_nip_snapshot }})@endif;
                        {{ $approvalStep->acted_on->format('d/m/Y') }},
                        {{ match ($approvalStep->result_code) {
                            'verified' => 'Diverifikasi',
                            'acknowledged' => 'Diketahui',
                            'approved' => 'Disetujui',
                            default => 'Keputusan tercatat',
                        } }}@if ($approvalStep->decision_note) - {{ $approvalStep->decision_note }}@endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
@endif
