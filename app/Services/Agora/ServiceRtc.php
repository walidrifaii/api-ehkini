<?php

namespace App\Services\Agora;

class ServiceRtc extends Service
{
    public const SERVICE_TYPE = 1;

    public const PRIVILEGE_JOIN_CHANNEL = 1;
    public const PRIVILEGE_PUBLISH_AUDIO_STREAM = 2;
    public const PRIVILEGE_PUBLISH_VIDEO_STREAM = 3;
    public const PRIVILEGE_PUBLISH_DATA_STREAM = 4;

    public string $channelName;

    public string $uid;

    public function __construct(string $channelName = '', string $uid = '')
    {
        parent::__construct(self::SERVICE_TYPE);
        $this->channelName = $channelName;
        $this->uid = $uid;
    }

    public function pack(): string
    {
        return parent::pack().Util::packString($this->channelName).Util::packString($this->uid);
    }
}
