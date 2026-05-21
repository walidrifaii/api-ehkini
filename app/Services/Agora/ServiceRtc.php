<?php

namespace App\Services\Agora;

class ServiceRtc
{
    public const SERVICE_TYPE = 1;

    public const PRIVILEGE_JOIN_CHANNEL = 1;
    public const PRIVILEGE_PUBLISH_AUDIO_STREAM = 2;
    public const PRIVILEGE_PUBLISH_VIDEO_STREAM = 3;
    public const PRIVILEGE_PUBLISH_DATA_STREAM = 4;

    private string $channelName;
    private string $uid;
    private array $privileges = [];

    public function __construct(string $channelName, string $uid)
    {
        $this->channelName = $channelName;
        $this->uid = $uid;
    }

    public function addPrivilege(int $privilege, int $expire): void
    {
        $this->privileges[$privilege] = $expire;
    }

    public function pack(): string
    {
        $content = pack("V", self::SERVICE_TYPE);
        $content .= pack("v", strlen($this->channelName)) . $this->channelName;
        $content .= pack("v", strlen($this->uid)) . $this->uid;
        $content .= pack("v", count($this->privileges));

        foreach ($this->privileges as $k => $v) {
            $content .= pack("V", $k) . pack("V", $v);
        }

        return $content;
    }
}