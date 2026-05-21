<?php

namespace App\Services\Agora;

class AccessToken2
{
    private string $appId;
    private string $appCertificate;
    private int $expire;
    private array $services = [];

    public function __construct(string $appId, string $appCertificate, int $expire)
    {
        $this->appId = $appId;
        $this->appCertificate = $appCertificate;
        $this->expire = $expire;
    }

    public function addService($service): void
    {
        $this->services[] = $service;
    }

    public function build(): string
    {
        $issueTs = time();
        $salt = random_int(1, 99999999);

        $signing = $this->appId . $issueTs . $salt . $this->expire;

        foreach ($this->services as $service) {
            $signing .= $service->pack();
        }

        $signature = hash_hmac("sha256", $signing, $this->appCertificate, true);

        $content = pack("V", $issueTs) .
            pack("V", $salt) .
            pack("V", $this->expire) .
            pack("v", count($this->services));

        foreach ($this->services as $service) {
            $content .= $service->pack();
        }

        $crc = crc32($content);

        return "007" . $this->appId .
            self::base64UrlEncode($signature) .
            self::base64UrlEncode($content);
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}