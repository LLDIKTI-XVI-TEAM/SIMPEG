<?php

namespace App\Services\Notifications\WhatsApp;

/** Kontrak baca artefak runtime yang dibutuhkan readiness dan adapter provider. */
interface WhatsAppRuntimeConfiguration
{
    /** @return array<string, mixed> */
    public function all(): array;

    /** @return array{config: array<string, mixed>, access_token: ?string, channel_integration_id: ?string} */
    public function providerSnapshot(): array;

    /** @return array{access_token: ?string, channel_integration_id: ?string} */
    public function providerCredentials(): array;

    public function accessToken(): ?string;
}
