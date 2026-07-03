<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_NAME') ?: 'job_application_tracker_demo',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],

    'google' => [
        'places_api_key' => getenv('GOOGLE_PLACES_API_KEY') ?: 'YOUR_GOOGLE_PLACES_API_KEY',
        'default_language' => 'tr',
        'default_terms' => [
            'Nicosia IVF clinic',
            'Nicosia fertility clinic',
            'Nicosia private hospital nurse',
        ],
    ],

    'whatsapp' => [
        // Supported providers in this project: generic, evolution, ultramsg.
        'provider' => getenv('WHATSAPP_PROVIDER') ?: 'generic',
        'endpoint' => getenv('WHATSAPP_ENDPOINT') ?: 'https://example-whatsapp-gateway.local/send',
        'token' => getenv('WHATSAPP_TOKEN') ?: 'YOUR_WHATSAPP_GATEWAY_TOKEN',
        'instance_id' => getenv('WHATSAPP_INSTANCE_ID') ?: '',
        'delay_seconds' => (int)(getenv('WHATSAPP_DELAY_SECONDS') ?: 5),
        'limit' => (int)(getenv('WHATSAPP_BATCH_LIMIT') ?: 20),
    ],

    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: 'smtp.example.com',
        'port' => (int)(getenv('SMTP_PORT') ?: 587),
        'username' => getenv('SMTP_USERNAME') ?: 'info@example.com',
        'password' => getenv('SMTP_PASSWORD') ?: 'YOUR_SMTP_PASSWORD',
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'info@example.com',
        'from_name' => getenv('SMTP_FROM_NAME') ?: 'YourAgency',
        'reply_to' => getenv('SMTP_REPLY_TO') ?: 'info@example.com',
        'limit' => (int)(getenv('EMAIL_BATCH_LIMIT') ?: 20),
    ],
];
