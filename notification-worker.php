<?php
// CLI only: run every five minutes on the same host as the portal.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
installNotificationQueue($db);
if (in_array('--install', $argv, true)) { echo "Notification queue installed.\n"; exit; }
$lock = $db->query("SELECT GET_LOCK('cgm_notification_worker', 0)")->fetchColumn();
if (!$lock) exit;
try {
    $timezone = new DateTimeZone(getenv('CGM_TIMEZONE') ?: 'America/Chicago');
    queueMeetingReminders($db, new DateTimeImmutable('now', $timezone));
    $settings = $db->query('SELECT endpoint, secret, url FROM cgm_email_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $endpoint = $settings['endpoint'] ?? '';
    if (str_starts_with($endpoint, '/') && !str_starts_with($endpoint, '//')) {
        $portal = parse_url($settings['url'] ?? '');
        $endpoint = 'https://' . ($portal['host'] ?? '') . $endpoint;
    }
    if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || !parse_url($endpoint, PHP_URL_HOST) || empty($settings['secret'])) {
        throw new RuntimeException('Configure an HTTPS email endpoint, shared secret, and absolute portal URL in Email Settings.');
    }
    $failed = 0;
    foreach ($db->query('SELECT * FROM cgm_notifications WHERE sent_at IS NULL ORDER BY id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) as $job) {
        try {
            $html = '<p>' . htmlspecialchars($job['message'], ENT_QUOTES, 'UTF-8') . '</p>';
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Portal-Secret: ' . $settings['secret']],
                CURLOPT_POSTFIELDS => json_encode(['to' => $job['recipient'], 'subject' => $job['subject'], 'html' => $html], JSON_THROW_ON_ERROR)]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('Email endpoint HTTP ' . $status);
            $db->prepare('UPDATE cgm_notifications SET sent_at=NOW(), attempts=attempts+1, last_error=NULL WHERE id=?')->execute([$job['id']]);
        } catch (Throwable $e) {
            $failed++;
            $db->prepare('UPDATE cgm_notifications SET attempts=attempts+1, last_error=? WHERE id=?')->execute(['Delivery failed; check email endpoint', $job['id']]);
            fwrite(STDERR, "Delivery failed for queue item " . $job['id'] . "\n");
        }
    }
    if ($failed) exit(1);
} finally {
    $db->query("SELECT RELEASE_LOCK('cgm_notification_worker')");
}
