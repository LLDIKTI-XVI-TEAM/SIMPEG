<?php

namespace App\Actions\Auth;

use App\Http\Requests\Auth\DemoLoginRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class HandleDemoLoginAction
{
    public function execute(DemoLoginRequest $request): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $payload = $request->validated();
        $username = strtolower(trim($payload['username']));

        if (! $this->isConfiguredDemoUser($username)) {
            throw $this->invalidCredentials();
        }

        $user = User::query()
            ->whereRaw('lower(keycloak_username) = ?', [$username])
            ->first();

        if (! $user || ! $user->password || ! Hash::check($payload['password'], $user->password)) {
            throw $this->invalidCredentials();
        }

        Auth::login($user);
        $request->session()->regenerate();
        session(['active_role' => $user->role]);

        AuditService::logAs($user->id, $user->name, 'LOGIN', 'User', $user->id, null, null, $request);

        return redirect()
            ->intended(route('dashboard'))
            ->with('login_success', 'Selamat Datang, '.$user->name.'! Anda berhasil masuk ke dalam sistem (Mode Demo).');
    }

    private function isConfiguredDemoUser(string $username): bool
    {
        foreach (config('services.keycloak.demo_users', []) as $demoUser) {
            if (strtolower($demoUser['username'] ?? '') === $username) {
                return true;
            }
        }

        return false;
    }

    private function invalidCredentials(): ValidationException
    {
        return ValidationException::withMessages([
            'username' => 'Username atau password demo tidak sesuai.',
        ]);
    }
}
