<?php

return [

    // Falls back to APP_KEY so Lebanon SMS OTP works without a separate OTP_PEPPER on deploy.
    'pepper' => env('OTP_PEPPER') ?: env('APP_KEY', ''),

    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),

    /*
    | Message Central = VerifyNow SMS OTP only (flowType=SMS)
    | WhatsApp → WhatsApp node only (OTP_WHATSAPP_NODE_ENABLED)
    |
    | OTP_CHANNEL: auto | whatsapp_node | sms
    | auto = use channel from app request (whatsapp → node, sms → Message Central)
    */
    'channel' => env('OTP_CHANNEL', 'auto'),

    'whatsapp_node' => [
        'enabled' => filter_var(env('OTP_WHATSAPP_NODE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'url' => rtrim((string) env('WHATSAPP_NODE_URL', ''), '/'),
        'token' => (string) env('WHATSAPP_NODE_TOKEN', ''),
        'client_id' => (string) env('WHATSAPP_NODE_CLIENT_ID', ''),
        'delivery' => env('WHATSAPP_NODE_DELIVERY', 'otp'), // otp | campaign | send-campaign
        'timeout' => (int) env('WHATSAPP_NODE_TIMEOUT', 35),
        'connect_timeout' => (int) env('WHATSAPP_NODE_CONNECT_TIMEOUT', 5),
        'phone_format' => env('WHATSAPP_NODE_PHONE_FORMAT', 'DIGITS'), // DIGITS (E164 without +) | E164
    ],

    'message_central' => [
        'enabled' => filter_var(env('OTP_MESSAGE_CENTRAL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'base_url' => rtrim((string) env('MESSAGE_CENTRAL_BASE_URL', 'https://cpaas.messagecentral.com'), '/'),
        'customer_id' => (string) env('MESSAGE_CENTRAL_CUSTOMER_ID', ''),
        'auth_token' => (string) env('MESSAGE_CENTRAL_AUTH_TOKEN', ''),
        'sms_enabled' => filter_var(env('OTP_MESSAGE_CENTRAL_SMS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'whatsapp_enabled' => filter_var(env('OTP_MESSAGE_CENTRAL_WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOL),
        'otp_length' => (int) env('MESSAGE_CENTRAL_OTP_LENGTH', 6),
    ],

    /*
    | Lebanon SMS OTP → UnoSMS (sms.unosms.net). Other countries → Message Central.
    */
    'unosms' => [
        'enabled' => filter_var(env('OTP_UNOSMS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'url' => (string) env('UNOSMS_URL', 'https://sms.unosms.net/api.php'),
        'user' => (string) env('UNOSMS_USER', ''),
        'pass' => (string) env('UNOSMS_PASS', ''),
        'from' => (string) env('UNOSMS_FROM', 'Ehkini'),
        'timeout' => (int) env('UNOSMS_TIMEOUT', 15),
        'country_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OTP_UNOSMS_COUNTRY_CODES', '961')),
        ))),
    ],

];
