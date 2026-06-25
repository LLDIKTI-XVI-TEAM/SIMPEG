<?php

namespace App\Actions\Auth;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

class RedirectToKeycloakAction
{
    public function execute(): RedirectResponse
    {
        return Socialite::driver('keycloak')->redirect();
    }
}
