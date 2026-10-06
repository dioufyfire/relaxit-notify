<?php

return [
    'webhook_enabled' => (bool) env('META_WHATSAPP_WEBHOOK_ENABLED', false),
    'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('META_WHATSAPP_APP_SECRET'),
    'waba_id' => env('META_WHATSAPP_WABA_ID'),
    'mode' => env('META_WHATSAPP_MODE', 'pilot'),
    'application' => env('META_WHATSAPP_APPLICATION', 'dolibarr'),
    'max_attempts_per_24h' => env('META_WHATSAPP_MAX_ATTEMPTS_PER_24H', 250),
    'enabled' => (bool) env('META_WHATSAPP_ENABLED', false),
    'version' => env('META_WHATSAPP_VERSION', 'v25.0'),
    'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('META_WHATSAPP_ACCESS_TOKEN'),
    'tenant_code' => env('META_WHATSAPP_TENANT_CODE', 'GLOBALE_SANTE'),
    'recipient' => env('META_WHATSAPP_TEST_RECIPIENT'),
    'enabled_after' => env('META_WHATSAPP_ENABLED_AFTER'),
    'template' => env('META_WHATSAPP_TEMPLATE'),
    'additional_templates' => array_values(array_filter(array_map('trim', explode(',', (string) env('META_WHATSAPP_ADDITIONAL_TEMPLATES', ''))))),
    'language' => env('META_WHATSAPP_LANGUAGE', 'en_US'),
    'header_image_url' => env('META_WHATSAPP_HEADER_IMAGE_URL'),
    'body_variables' => array_values(array_filter(array_map('trim', explode(',', (string) env('META_WHATSAPP_BODY_VARIABLES', ''))))),
];
