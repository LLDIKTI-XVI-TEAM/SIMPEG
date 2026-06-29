<?php

namespace App\Actions\Auth;

use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RedirectToKeycloakAction
{
    public function execute(): RedirectResponse
    {
        return Socialite::driver('keycloak')->redirect();
    }
}
