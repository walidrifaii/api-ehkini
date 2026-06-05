<?php

namespace App\Services\Agora;

class AccessToken2
{
    private const VERSION = '007';

    private const VERSION_LENGTH = 3;

    private string $appCert;

    private string $appId;

    private int $expire;

    private int $issueTs;

    private int $salt;

    /** @var array<int, Service> */
    private array $services = [];

    public function __construct(string $appId = '', string $appCert = '', int $expire = 900)
    {
        $this->appId = $appId;
        $this->appCert = $appCert;
        $this->expire = $expire;
        $this->issueTs = time();
        $this->salt = random_int(1, 99999999);
    }

    public function addService(Service $service): void
    {
        $this->services[$service->getServiceType()] = $service;
    }

    public function build(): string
    {
        if (! self::isUuid($this->appId) || ! self::isUuid($this->appCert)) {
            return '';
        }

        $signing = $this->getSign();
        $data = Util::packString($this->appId)
            .Util::packUint32($this->issueTs)
            .Util::packUint32($this->expire)
            .Util::packUint32($this->salt)
            .Util::packUint16(count($this->services));

        ksort($this->services);
        foreach ($this->services as $service) {
            $data .= $service->pack();
        }

        $signature = hash_hmac('sha256', $data, $signing, true);

        return self::VERSION.base64_encode(
            zlib_encode(Util::packString($signature).$data, ZLIB_ENCODING_DEFLATE)
        );
    }

    private function getSign(): string
    {
        $hh = hash_hmac('sha256', $this->appCert, Util::packUint32($this->issueTs), true);

        return hash_hmac('sha256', $hh, Util::packUint32($this->salt), true);
    }

    private static function isUuid(string $str): bool
    {
        return strlen($str) === 32 && ctype_xdigit($str);
    }
}
