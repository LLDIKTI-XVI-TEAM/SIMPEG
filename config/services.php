<?php

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
                'role' => 'atasan_langsung',
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
    ],

    'simpeg' => [
        'disable_employee_api_auth' => filter_var(env('SIMPEG_DISABLE_EMPLOYEE_API_AUTH', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
