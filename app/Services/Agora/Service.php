<?php

namespace App\Services\Agora;

class Service
{
    public int $type;

    /** @var array<int, int> */
    public array $privileges = [];

    public function __construct(int $serviceType)
    {
        $this->type = $serviceType;
    }

    public function addPrivilege(int $privilege, int $expire): void
    {
        $this->privileges[$privilege] = $expire;
    }

    public function getServiceType(): int
    {
        return $this->type;
    }

    public function pack(): string
    {
        return Util::packUint16($this->type).Util::packMapUint32($this->privileges);
    }
}
