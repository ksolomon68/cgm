<?php
/** Durable board email queue. Requires the existing getDB() MySQL connection. */
function installNotificationQueue(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS cgm_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(180) NOT NULL, recipient VARCHAR(254) NOT NULL,
        subject VARCHAR(255) NOT NULL, message TEXT NOT NULL,
        sent_at DATETIME NULL, attempts INT NOT NULL DEFAULT 0,
        last_error VARCHAR(255) NULL,
        UNIQUE KEY notification_recipient (event_key, recipient)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function queueBoardNotification(PDO $db, string $key, string $title, string $message): int {
    $stmt = $db->prepare('INSERT IGNORE INTO cgm_notifications (event_key, recipient, subject, message) VALUES (?,?,?,?)');
    $count = 0;
    foreach ($db->query('SELECT DISTINCT email FROM cgm_members')->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $stmt->execute([$key, $email, 'CGM Board Portal — ' . substr($title, 0, 180), $message]);
        $count += $stmt->rowCount();
    }
    return $count;
}
function queueBoardChange(string $action, array $input, array $result): int {
    $types = ['save_doc' => 'Document added', 'save_event' => 'Event updated',
        'save_meeting' => 'Meeting scheduled', 'save_finance' => 'Financials updated',
        'delete_finance' => 'Financials updated', 'import_monthly_report' => 'Financial reports published'];
    $title = $types[$action];
    $detail = $input['name'] ?? $input['title'] ?? $input['month'] ?? '';
    // Financial details and documents remain behind portal authentication.
    $message = $title . ($detail ? ': ' . $detail : '') . '. Please sign in to the board portal to review.';
    return queueBoardNotification(getDB(), bin2hex(random_bytes(16)), $title, $message);
}
function queueMeetingReminders(PDO $db, DateTimeImmutable $now): int {
    $count = 0;
    foreach ($db->query("SELECT id, title, date, time FROM cgm_meetings WHERE status = 'Scheduled' AND date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $meeting) {
        $start = new DateTimeImmutable($meeting['date'] . ' ' . ($meeting['time'] ?: '09:00:00'), $now->getTimezone());
        $remaining = $start->getTimestamp() - $now->getTimestamp();
        // One-hour delivery windows avoid sending stale reminders after downtime.
        foreach ([168, 24] as $hours) {
            if ($remaining <= 0 || $remaining > $hours * 3600 || $remaining <= ($hours - 1) * 3600) continue;
            $key = 'meeting:' . $meeting['id'] . ':' . $start->getTimestamp() . ':' . $hours;
            $count += queueBoardNotification($db, $key, 'Upcoming board meeting',
                $meeting['title'] . ' is scheduled for ' . $start->format('F j, Y g:i A T') . '. Sign in to the board portal for details.');
        }
    }
    return $count;
}
