<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Referensi\PrepareDataMasterIndexAction;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class DataMasterController extends Controller
{
    /**
     * Menampilkan halaman Data Master dari payload yang disiapkan Action.
     */
    public function index(PrepareDataMasterIndexAction $action): View
    {
        return view('admin.data-master.index', $action->execute());
    }
}
