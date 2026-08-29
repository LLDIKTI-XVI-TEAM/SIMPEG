<?php

use App\Services\Notifications\WhatsApp\WhatsAppTemplateConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;

$whatsAppDefaultEventTemplates = WhatsAppTemplateContract::eventTemplateArchetypes();
$whatsAppTemplateConfiguration = env('SIMPEG_WHATSAPP_TEMPLATE_CONFIGURATION');
$whatsAppRuntimeTemplates = WhatsAppTemplateConfiguration::decode(
    $whatsAppTemplateConfiguration,
    array_keys($whatsAppDefaultEventTemplates),
);
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
        'dev_usernames' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
            ? array_filter(array_map('trim', explode(',', env('SSO_DEV_USERNAMES', 'demo-klabat,demo-klabat-kepeg,demo-klabat-kabag,demo-klabat-pimpinan,demo-klabat-pegawai'))))
            : [],
        'test_username' => env('KEYCLOAK_TEST_USERNAME', 'demo-klabat'),
        'demo_users' => [
            [
                'username' => 'demo-klabat',
                'password' => 'demo-klabat',
                'name' => 'Demo Klabat (Super Admin)',
                'email' => 'demo-klabat@example.test',
                'role' => 'super_admin',
                'label' => 'Super Admin',
            ],
            [
                'username' => 'demo-klabat-kepeg',
                'password' => 'demo-klabat-kepeg',
                'name' => 'Demo Klabat (Admin Kepegawaian)',
                'email' => 'demo-klabat-kepeg@example.test',
                'role' => 'admin_kepegawaian',
                'label' => 'Admin Kepegawaian',
            ],
            [
                'username' => 'demo-klabat-kabag',
                'password' => 'demo-klabat-kabag',
                'name' => 'Demo Klabat (Kepala Bagian)',
                'email' => 'demo-klabat-kabag@example.test',
                'role' => 'kepala_bagian',
                'label' => 'Kepala Bagian',
            ],
            [
                'username' => 'demo-klabat-pimpinan',
                'password' => 'demo-klabat-pimpinan',
                'name' => 'Demo Klabat (Pimpinan)',
                'email' => 'demo-klabat-pimpinan@example.test',
                'role' => 'pimpinan',
                'label' => 'Pimpinan',
            ],
            [
                'username' => 'demo-klabat-pegawai',
                'password' => 'demo-klabat-pegawai',
                'name' => 'Demo Klabat (Pegawai)',
                'email' => 'demo-klabat-pegawai@example.test',
                'role' => 'pegawai',
                'label' => 'Pegawai',
            ],
        ],
        // Fixture akun UAT (HANYA local/testing) — dikonsumsi SsoRoleMappedAccountSeeder
        // untuk menanam pegawai + user placeholder agar login SSO pertama menemukan tepat
        // satu pegawai. BUKAN sumber otorisasi: auth callback tidak pernah membaca config
        // ini; role internal ditentukan aplikasi SIMPEG (blank role → pegawai, akun pertama
        // sistem → super_admin sebagai bootstrap).
        'role_mapping' => [
            'dayensite@gmail.com' => 'super_admin',
            'sitedayen@gmail.com' => 'admin_kepegawaian',
            'dionkobi08@gmail.com' => 'pimpinan',
            'dayen6153@gmail.com' => 'kepala_bagian',
            'dionleonn05@gmail.com' => 'pegawai',
        ],
    ],

    'simpeg' => [
        'disable_employee_api_auth' => filter_var(env('SIMPEG_DISABLE_EMPLOYEE_API_AUTH', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Seluruh nilai default sengaja nonaktif. Nilai konkret baru boleh dipasang setelah
    // provider WhatsApp dan artefak sandbox resmi diverifikasi oleh LLDIKTI.
    'whatsapp' => [
        'enabled' => filter_var(env('SIMPEG_WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'sandbox_verified' => filter_var(env('SIMPEG_WHATSAPP_SANDBOX_VERIFIED', false), FILTER_VALIDATE_BOOLEAN),
        'recipient_source_verified' => filter_var(env('SIMPEG_WHATSAPP_RECIPIENT_SOURCE_VERIFIED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('SIMPEG_WHATSAPP_PROVIDER'),
        'base_url' => env('SIMPEG_WHATSAPP_BASE_URL'),
        'credential_reference' => env('SIMPEG_WHATSAPP_CREDENTIAL_REFERENCE'),
        'channel_id' => env('SIMPEG_WHATSAPP_CHANNEL_ID'),
        // JSON kontrak resmi provider: event_templates dan templates (id, language,
        // variables_map, button). Bila kosong/tidak valid, readiness tetap false.
        'template_configuration' => $whatsAppTemplateConfiguration,
        'runtime_configuration_valid' => $whatsAppRuntimeTemplates['valid'],
        'canonical_url' => env('SIMPEG_WHATSAPP_CANONICAL_URL'),
        'event_templates' => array_replace($whatsAppDefaultEventTemplates, $whatsAppRuntimeTemplates['event_templates']),
        'templates' => array_replace($whatsAppDefaultTemplates, $whatsAppRuntimeTemplates['templates']),
    ],

];
