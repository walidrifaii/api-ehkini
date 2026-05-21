<?php

namespace App\Services\Agora;

class RtcTokenBuilder2
{
    public const ROLE_PUBLISHER = 1;
    public const ROLE_SUBSCRIBER = 2;

    public static function buildTokenWithUid(
        string $appId,
        string $appCertificate,
        string $channelName,
        int $uid,
        int $role,
        int $tokenExpire,
        int $privilegeExpire
    ): string {
        $token = new AccessToken2($appId, $appCertificate, $tokenExpire);

        $serviceRtc = new ServiceRtc($channelName, (string)$uid);

        $serviceRtc->addPrivilege(ServiceRtc::PRIVILEGE_JOIN_CHANNEL, $privilegeExpire);
        if ($role === self::ROLE_PUBLISHER) {
            $serviceRtc->addPrivilege(ServiceRtc::PRIVILEGE_PUBLISH_AUDIO_STREAM, $privilegeExpire);
            $serviceRtc->addPrivilege(ServiceRtc::PRIVILEGE_PUBLISH_VIDEO_STREAM, $privilegeExpire);
            $serviceRtc->addPrivilege(ServiceRtc::PRIVILEGE_PUBLISH_DATA_STREAM, $privilegeExpire);
        }

        $token->addService($serviceRtc);
        return $token->build();
    }
}