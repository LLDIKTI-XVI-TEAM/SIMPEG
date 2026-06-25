<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\HandleKeycloakCallbackAction;
use App\Actions\Auth\LogoutFromKeycloakAction;
use App\Actions\Auth\RedirectToKeycloakAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KeycloakAuthController extends Controller
{
    public function redirectToKeycloak(RedirectToKeycloakAction $action): RedirectResponse
    {
        return $action->execute();
    }

    public function handleCallback(Request $request, HandleKeycloakCallbackAction $action): RedirectResponse|View
    {
        return $action->execute($request);
    }

    public function logout(Request $request, LogoutFromKeycloakAction $action): RedirectResponse
    {
        return $action->execute($request);
    }
}
