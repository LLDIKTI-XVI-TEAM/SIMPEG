<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\ListUserMappingsAction;
use App\Actions\Auth\UpdateUserMappingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ListUserMappingsRequest;
use App\Http\Requests\Auth\UpdateUserMappingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserMappingController extends Controller
{
    public function index(ListUserMappingsRequest $request, ListUserMappingsAction $listUserMappings): View
    {
        return view('admin.user-management.index', [
            'pegawai' => $listUserMappings->execute($request->validated()),
            'title' => 'User Management / Kelola Akses User',
        ]);
    }

    public function update(UpdateUserMappingRequest $request, UpdateUserMappingAction $updateUserMapping): RedirectResponse
    {
        $updateUserMapping->execute($request->validated(), $request);

        return back()->with('success', 'Akses User berhasil diperbarui!');
    }
}
