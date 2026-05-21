<?php
header("Content-Type: application/json");

// ✅ استدعاء مكتبة Agora
require_once "./Tools-master/DynamicKey/AgoraDynamicKey/php/src/RtcTokenBuilder.php"; // غير المسار حسب مكان الملف

// 🔑 بيانات من Agora Console
$appID = "259eadf11a6a4267b9a997a5c8e0592e";
$appCertificate = "19014fb175f043f4926817812e02fb82"; // ضع الـ Primary Certificate

// ⚡ باراميترات جاي من Flutter (POST أو GET)
$channelName = $_GET['channelName'] ?? null;
$uid = intval($_GET['uid'] ?? 0); // ممكن تخلي كل مستخدم ياخد UID خاص فيه
$expireTimeInSeconds = 3600; // صلاحية التوكين ساعة وحدة

if (!$channelName) {
    echo json_encode([
        "success" => false,
        "message" => "channelName required"
    ]);
    exit;
}
// ⏱️ حساب وقت الانتهاء
$currentTimestamp = (new DateTime("now", new DateTimeZone('UTC')))->getTimestamp();
$privilegeExpiredTs = $currentTimestamp + $expireTimeInSeconds;

// 🛠️ توليد التوكين
$token = RtcTokenBuilder::buildTokenWithUid(
    $appID,
    $appCertificate,
    $channelName,
    $uid,
    RtcTokenBuilder::RolePublisher,
    $privilegeExpiredTs
);

// ✅ رجّع JSON
echo json_encode([
    "success" => true,
    "token" => $token,
    "channelName" => $channelName,
    "uid" => $uid,
    "expiresIn" => $expireTimeInSeconds
]);