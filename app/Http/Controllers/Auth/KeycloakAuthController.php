<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\HandleDemoLoginAction;
use App\Actions\Auth\HandleKeycloakCallbackAction;
use App\Actions\Auth\LogoutFromKeycloakAction;
use App\Actions\Auth\RedirectToKeycloakAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DemoLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class KeycloakAuthController extends Controller
{
    public function redirectToKeycloak(RedirectToKeycloakAction $action): SymfonyRedirectResponse
    {
        return $action->execute();
    }

    public function defaultDemoLogin(Request $request, HandleDemoLoginAction $action): RedirectResponse
    {
        return $action->executeDefault($request);
    }

    public function demoLogin(DemoLoginRequest $request, HandleDemoLoginAction $action): RedirectResponse
    {
        return $action->execute($request);
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
