<?php

return [

    'pepper' => env('OTP_PEPPER', ''),

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
        // otp = POST /api/otp/send (single contact, non-blocking)
        // campaign = legacy 3-step flow (create → add contact → start)
        'delivery' => env('WHATSAPP_NODE_DELIVERY', 'otp'),
        'timeout_seconds' => (int) env('WHATSAPP_NODE_TIMEOUT', 30),
        'campaign_start_timeout_seconds' => (int) env('WHATSAPP_NODE_CAMPAIGN_START_TIMEOUT', 90),
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

];
