<?php

namespace App\Actions\HariLibur;

use App\Models\RefHariLibur;
use App\Services\AuditService;
use Illuminate\Http\Request;

class DeleteHariLiburAction
{
    /**
     * Menghapus referensi hari libur dan menyimpan payload lama untuk audit.
     */
    public function execute(RefHariLibur $hariLibur, Request $request): void
    {
        $oldValues = $hariLibur->toApiArray();
        $id = $hariLibur->id;

        $hariLibur->delete();

        AuditService::log('DELETE', 'RefHariLibur', $id, $oldValues, null, $request);
    }
}
