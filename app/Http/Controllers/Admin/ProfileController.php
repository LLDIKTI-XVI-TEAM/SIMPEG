<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Profiles\ShowProfilePageAction;
use App\Actions\Profiles\UpdatePasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profiles\UpdatePasswordRequest;

class ProfileController extends Controller
{
    public function index(ShowProfilePageAction $action)
    {
        return view('admin.profile.index', $action->execute(auth()->user()));
    }

    public function updatePassword(UpdatePasswordRequest $request, UpdatePasswordAction $action)
    {
        $action->execute($request->user(), $request->validated());

        return redirect()->route('profil')
            ->with('success', 'Kata sandi Anda berhasil diperbarui.');
    }
}
