<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RedirectsToDataMasterTab
{
    /**
     * Redirect kembali ke halaman Data Master sambil mengingat tab asal form
     * (dikirim sebagai hidden input), supaya admin tidak terlempar ke tab
     * pertama setiap kali selesai submit.
     */
    protected function backToTab(Request $request, string $message): RedirectResponse
    {
        return back()
            ->with('success', $message)
            ->with('data_master_tab', (string) $request->input('tab', ''));
    }
}
