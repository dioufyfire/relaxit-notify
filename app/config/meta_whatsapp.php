<?php

return [
    'enabled' => (bool) env('META_WHATSAPP_ENABLED', false),
    'version' => env('META_WHATSAPP_VERSION', 'v25.0'),
    'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('META_WHATSAPP_ACCESS_TOKEN'),
    'tenant_code' => env('META_WHATSAPP_TENANT_CODE', 'GLOBALE_SANTE'),
    'recipient' => env('META_WHATSAPP_TEST_RECIPIENT'),
    'enabled_after' => env('META_WHATSAPP_ENABLED_AFTER'),
    'template' => env('META_WHATSAPP_TEMPLATE'),
    'language' => env('META_WHATSAPP_LANGUAGE', 'en_US'),
    'body_variables' => array_values(array_filter(array_map('trim', explode(',', (string) env('META_WHATSAPP_BODY_VARIABLES', ''))))),
];
