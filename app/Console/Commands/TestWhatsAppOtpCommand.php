<?php

namespace App\Console\Commands;

use App\Services\WhatsAppNodeCampaignOtpService;
use Illuminate\Console\Command;

class TestWhatsAppOtpCommand extends Command
{
    protected $signature = 'otp:test-whatsapp
        {country_code : e.g. +961 or 961}
        {phone : mobile without country code, e.g. 70657961}
        {--code= : optional fixed OTP code (default: random 6 digits)}';

    protected $description = 'Test WhatsApp node OTP send via POST /api/otp/send';

    public function handle(WhatsAppNodeCampaignOtpService $whatsApp): int
    {
        $cc = (string) $this->argument('country_code');
        if ($cc !== '' && $cc[0] !== '+') {
            $cc = '+' . ltrim($cc, '+');
        }

        $mobile = ltrim(preg_replace('/\D+/', '', (string) $this->argument('phone')) ?? '', '0');
        $phoneE164 = $cc . $mobile;
        $code = (string) ($this->option('code') ?: random_int(100000, 999999));

        $cfg = config('otp.whatsapp_node');

        $this->line('WhatsApp node OTP test');
        $this->line('  url: ' . ($cfg['url'] ?? ''));
        $this->line('  phone_e164: ' . $phoneE164);
        $this->line('  phone_for_node: ' . $whatsApp->formatPhoneForNode($phoneE164));
        $this->line('  code: ' . $code);
        $this->line('  configured: ' . (
            ($cfg['url'] ?? '') !== '' && ($cfg['token'] ?? '') !== '' && ($cfg['client_id'] ?? '') !== ''
                ? 'yes'
                : 'no'
        ));
        $this->newLine();

        if (($cfg['url'] ?? '') === '' || ($cfg['token'] ?? '') === '' || ($cfg['client_id'] ?? '') === '') {
            $this->error('WhatsApp node not configured. Set WHATSAPP_NODE_URL, WHATSAPP_NODE_TOKEN, WHATSAPP_NODE_CLIENT_ID in .env');

            return self::FAILURE;
        }

        $result = $whatsApp->sendOtpViaNodeCampaign($phoneE164, $code, 'test');

        if ($result['ok'] ?? false) {
            $this->info('SUCCESS — channel: ' . ($result['channel'] ?? 'whatsapp_node'));
            if (isset($result['body'])) {
                $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        }

        $this->error('FAILED — error: ' . ($result['error'] ?? 'unknown'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
