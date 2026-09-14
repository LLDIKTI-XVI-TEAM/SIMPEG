<?php

use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;

$whatsAppDefaultEventTemplates = WhatsAppTemplateContract::eventTemplateArchetypes();
$whatsAppDefaultTemplates = [
    'simpeg_cuti_perlu_tindakan' => [
        'id' => null,
        'language' => null,
        'variables_map' => [],
        'button' => null,
    ],
    'simpeg_cuti_status' => [
        'id' => null,
        'language' => null,
        'variables_map' => [],
        'button' => null,
    ],
    'simpeg_ews_pengingat' => [
        'id' => null,
        'language' => null,
        'variables_map' => [],
        'button' => null,
    ],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'keycloak' => [
        'base_url' => env('KEYCLOAK_BASE_URL'),
        'realms' => env('KEYCLOAK_REALM'),
        'realm' => env('KEYCLOAK_REALM'),
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI'),
        'employee_match_field' => env('SSO_EMPLOYEE_MATCH_FIELD', 'email'),
        // Tidak ada mapping email/username → role di sini: Keycloak hanya autentikasi
        // (K-MTG-02). Persona SSO UAT lokal hidup di
        // SsoRoleMappedAccountSeeder (local/testing only); callback tidak memberi
        // role dari email maupun claim SSO.
    ],

    'simpeg' => [
        'disable_employee_api_auth' => filter_var(env('SIMPEG_DISABLE_EMPLOYEE_API_AUTH', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Artefak Qontak hanya berasal dari setting aplikasi (ref_notification_channels.config).
    // Environment dibatasi pada kill-switch deployment; ia tidak menjadi fallback credential,
    // endpoint, Channel Integration ID, canonical URL, atau kontrak template.
    'whatsapp' => [
        'enabled' => filter_var(env('SIMPEG_WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'sandbox_verified' => filter_var(env('SIMPEG_WHATSAPP_SANDBOX_VERIFIED', false), FILTER_VALIDATE_BOOLEAN),
        'recipient_source_verified' => filter_var(env('SIMPEG_WHATSAPP_RECIPIENT_SOURCE_VERIFIED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => null,
        'base_url' => null,
        'channel_integration_id' => null,
        'access_token' => null,
        'template_configuration' => null,
        'runtime_configuration_valid' => false,
        'canonical_url' => null,
        'event_templates' => $whatsAppDefaultEventTemplates,
        'templates' => $whatsAppDefaultTemplates,
    ],

];
