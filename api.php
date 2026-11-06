<?php
/**
 * CGM Board Portal — API
 * All data operations. Protected by PHP sessions + CSRF tokens.
 *
 * Usage: POST api.php with JSON body { "action": "...", ...fields }
 * Protected actions require X-CSRF-Token header matching the session token.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

// ── Session hardening ────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
if (!headers_sent()) session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Parse JSON body
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? ($_GET['action'] ?? ''));

// ── Helpers ──────────────────────────────────────────────────────────────────

function respond(mixed $data, int $status = 200): never {
    global $action, $input;
    if ($status < 300 && in_array($action, ['save_doc', 'save_event', 'save_meeting', 'save_finance', 'delete_finance', 'import_monthly_report'], true)) {
        try {
            $data['notifications_queued'] = queueBoardChange($action, $input, $data);
            if (!$data['notifications_queued']) $data['notification_warning'] = 'Saved, but no valid board member email addresses were found.';
        } catch (Throwable $e) {
            error_log('CGM notification queue failed for ' . $action);
            $data['notification_warning'] = 'Saved, but email alerts could not be queued. Ask an administrator to check the notification worker setup.';
        }
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function err(string $msg, int $status = 400): never {
    respond(['error' => $msg], $status);
}

function generateCsrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        err('Invalid or missing CSRF token', 403);
    }
}

function requireSession(): array {
    if (empty($_SESSION['uid'])) err('Unauthorized — please log in', 401);
    try {
        $stmt = getDB()->prepare(
            'SELECT id, first, last, email, phone, title, role FROM cgm_members WHERE id = ?'
        );
        $stmt->execute([$_SESSION['uid']]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        err('Database error', 500);
    }
    if (!$user) { session_destroy(); err('Session expired', 401); }
    return $user;
}

function requireAdmin(): array {
    $user = requireSession();
    if ($user['role'] !== 'admin') err('Admin access required', 403);
    return $user;
}

// ── Rate limiting ────────────────────────────────────────────────────────────

function isLockedOut(string $email): bool {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM cgm_login_attempts
             WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)'
        );
        $stmt->execute([strtolower($email)]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Throwable) { return false; }
}

function recordAttempt(string $email): void {
    try {
        $db = getDB();
        $db->prepare('INSERT INTO cgm_login_attempts (email, ip) VALUES (?, ?)')
           ->execute([strtolower($email), $_SERVER['REMOTE_ADDR'] ?? '']);
        // Clean up old records
        $db->exec("DELETE FROM cgm_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    } catch (Throwable) {}
}

// ── Monthly report import helpers ────────────────────────────────────────────

function ensurePositionTable(): void {
    getDB()->exec(
        "CREATE TABLE IF NOT EXISTS cgm_fin_position (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_end DATE NOT NULL,
            data       MEDIUMTEXT NOT NULL,
            added_by   VARCHAR(120),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_period_end (period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Decode a base64 / data-URI upload and save it under uploads/. Returns the relative link. */
function storeUpload(string $fileName, string $fileData): string {
    if (preg_match('/^data:([^;]+);base64,(.*)$/s', $fileData, $mm)) $fileData = $mm[2];
    $data = base64_decode($fileData, true);
    if ($data === false) err('Invalid file data encoding');
    if (strlen($data) > 12 * 1024 * 1024) err('File is too large (12 MB max): ' . $fileName);

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'doc', 'docx', 'oxps'];
    if (!in_array($ext, $allowed, true)) {
        err('Invalid file type for ' . $fileName . '. Allowed: PDF, PNG, JPG, GIF, DOC, DOCX, OXPS.');
    }
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) err('Failed to create upload directory on server');

    $base = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($fileName, PATHINFO_FILENAME));
    $save = time() . '_' . uniqid() . '_' . $base . '.' . $ext;
    if (file_put_contents($dir . '/' . $save, $data) === false) err('Failed to save uploaded file on server');
    return 'uploads/' . $save;
}

// ════════════════════════════════════════════════════════════════════════════
//  ROUTES
// ════════════════════════════════════════════════════════════════════════════

try {
    switch ($action) {

        // ── PUBLIC ──────────────────────────────────────────────────────────

        case 'get_public_events':
            $db     = getDB();
            $events = $db->query('SELECT id, name, date, end_date, time, type, loc, description FROM cgm_events ORDER BY date ASC, time ASC')->fetchAll();
            foreach ($events as &$evt) {
                $evt['id'] = (int)$evt['id'];
            }
            respond($events);

        case 'check_session':
            $user = null;
            if (!empty($_SESSION['uid'])) {
                $stmt = getDB()->prepare(
                    'SELECT id, first, last, email, phone, title, role FROM cgm_members WHERE id = ?'
                );
                $stmt->execute([$_SESSION['uid']]);
                $user = $stmt->fetch() ?: null;
            }
            respond(['user' => $user, 'csrf' => generateCsrf()]);

        case 'login':
            $email = strtolower(trim($input['email'] ?? ''));
            $pass  = $input['pass'] ?? '';
            if (!$email || !$pass) err('Email and password are required');
            if (isLockedOut($email)) err('Too many failed attempts. Try again in 60 seconds.', 429);

            $stmt = getDB()->prepare('SELECT * FROM cgm_members WHERE LOWER(email) = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($pass, $user['pass'])) {
                recordAttempt($email);
                err('Invalid email or password', 401);
            }

            // Rehash if cost is outdated
            if (password_needs_rehash($user['pass'], PASSWORD_BCRYPT, ['cost' => 12])) {
                getDB()->prepare('UPDATE cgm_members SET pass = ? WHERE id = ?')
                       ->execute([password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
            }

            session_regenerate_id(true);
            $_SESSION['uid'] = $user['id'];
            unset($user['pass']);
            respond(['user' => $user, 'csrf' => generateCsrf()]);

        case 'logout':
            session_unset();
            session_destroy();
            respond(['ok' => true]);

        case 'forgot_password':
            // Unauthenticated: verify email, then set new password
            $email   = strtolower(trim($input['email'] ?? ''));
            $newPass = $input['new_pass']     ?? '';
            if (!$email || !$newPass) err('Email address and new password are required');
            if (strlen($newPass) < 8) err('New password must be at least 8 characters');
            if (isLockedOut($email)) err('Too many failed attempts. Try again later.', 429);

            $stmt = getDB()->prepare('SELECT id FROM cgm_members WHERE LOWER(email) = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                recordAttempt($email);
                err('No account found with this email address.', 404);
            }

            getDB()->prepare('UPDATE cgm_members SET pass = ? WHERE id = ?')
                   ->execute([password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
            respond(['ok' => true]);

        // ── MEMBERS ─────────────────────────────────────────────────────────

        case 'get_members':
            requireSession();
            $rows = getDB()->query(
                'SELECT id, first, last, email, phone, title, role FROM cgm_members ORDER BY last, first'
            )->fetchAll();
            respond($rows);

        case 'save_member':
            $me    = requireAdmin(); verifyCsrf();
            $first = trim($input['first'] ?? '');
            $last  = trim($input['last']  ?? '');
            $email = trim($input['email'] ?? '');
            $pass  = trim($input['pass']  ?? '');
            if (!$first || !$last || !$email || !$pass) err('First name, last name, email and password are required');
            if (strlen($pass) < 8) err('Password must be at least 8 characters');

            $db  = getDB();
            $chk = $db->prepare('SELECT id FROM cgm_members WHERE LOWER(email) = ?');
            $chk->execute([strtolower($email)]);
            if ($chk->fetch()) err('A member with this email already exists');

            $db->prepare(
                'INSERT INTO cgm_members (first, last, email, phone, title, role, pass) VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $first, $last, $email,
                trim($input['phone'] ?? ''),
                trim($input['title'] ?? ''),
                in_array($input['role'] ?? '', ['admin','member']) ? $input['role'] : 'member',
                password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]),
            ]);
            respond(['id' => (int)$db->lastInsertId()]);

        case 'delete_member':
            $me = requireAdmin(); verifyCsrf();
            $id = (int)($input['id'] ?? 0);
            if (!$id) err('Invalid member ID');
            if ($id === (int)$me['id']) err("You cannot delete your own account");
            getDB()->prepare('DELETE FROM cgm_members WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'reset_member_password':
            requireAdmin(); verifyCsrf();
            $id      = (int)($input['id']       ?? 0);
            $newPass = $input['new_pass'] ?? '';
            if (!$id || strlen($newPass) < 8) err('Member ID and new password (min 8 chars) required');
            getDB()->prepare('UPDATE cgm_members SET pass = ? WHERE id = ?')
                   ->execute([password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]), $id]);
            respond(['ok' => true]);

        case 'change_password':
            $me      = requireSession(); verifyCsrf();
            $current = $input['current_pass'] ?? '';
            $newPass = $input['new_pass']     ?? '';
            if (!$current || strlen($newPass) < 8) err('Current password and new password (min 8 chars) required');

            $stmt = getDB()->prepare('SELECT pass FROM cgm_members WHERE id = ?');
            $stmt->execute([$me['id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['pass'])) err('Current password is incorrect', 401);

            getDB()->prepare('UPDATE cgm_members SET pass = ? WHERE id = ?')
                   ->execute([password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]), $me['id']]);
            respond(['ok' => true]);

        // ── DOCUMENTS ───────────────────────────────────────────────────────

        case 'get_docs':
            requireSession();
            respond(getDB()->query('SELECT * FROM cgm_docs ORDER BY created_at DESC')->fetchAll());

        case 'save_doc':
            $me   = requireSession(); verifyCsrf();
            $name = trim($input['name'] ?? '');
            if (!$name) err('Document name is required');
            
            $link = trim($input['link'] ?? '');
            
            // Check if file payload is provided
            if (!empty($input['fileData'])) {
                $fileData = $input['fileData'];
                if (preg_match('/^data:([^;]+);base64,(.*)$/', $fileData, $matches)) {
                    $mimeType = $matches[1];
                    $data     = base64_decode($matches[2]);
                } else {
                    $data     = base64_decode($fileData);
                }
                
                if ($data === false) err('Invalid file data encoding');
                
                // Extract file extension
                $fileName = trim($input['fileName'] ?? 'document');
                $ext = pathinfo($fileName, PATHINFO_EXTENSION);
                if (empty($ext)) {
                    $mimeMap = [
                        'application/pdf' => 'pdf',
                        'image/png' => 'png',
                        'image/jpeg' => 'jpg',
                        'image/gif' => 'gif',
                        'application/msword' => 'doc',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
                    ];
                    $ext = $mimeMap[$mimeType] ?? 'bin';
                }
                
                // Extension whitelisting security check
                $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'doc', 'docx', 'oxps'];
                $ext = strtolower($ext);
                if (!in_array($ext, $allowed)) {
                    err('Invalid file type. Only PDF, PNG, JPG, JPEG, GIF, DOC, DOCX, and OXPS are allowed.');
                }
                
                // Create uploads directory if missing
                $uploadsDir = __DIR__ . '/uploads';
                if (!is_dir($uploadsDir)) {
                    if (!mkdir($uploadsDir, 0755, true)) {
                        err('Failed to create upload directory on server');
                    }
                }
                
                // Sanitize filename & assign a collision-resistant unique name
                $safeBaseName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($fileName, PATHINFO_FILENAME));
                $saveName = time() . '_' . uniqid() . '_' . $safeBaseName . '.' . $ext;
                $savePath = $uploadsDir . '/' . $saveName;
                
                if (file_put_contents($savePath, $data) === false) {
                    err('Failed to save uploaded file on server');
                }
                
                $link = 'uploads/' . $saveName;
            }
            
            $db = getDB();
            $db->prepare(
                'INSERT INTO cgm_docs (name, cat, date, link, notes, added_by) VALUES (?,?,?,?,?,?)'
            )->execute([
                $name,
                $input['cat']   ?? '',
                $input['date']  ?: null,
                $link,
                $input['notes'] ?? '',
                $me['first'] . ' ' . $me['last'],
            ]);
            respond(['id' => (int)$db->lastInsertId()]);

        case 'delete_doc':
            requireSession(); verifyCsrf();
            $id = (int)($input['id'] ?? 0);
            if (!$id) err('Invalid ID');
            getDB()->prepare('DELETE FROM cgm_docs WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);

        // ── MEETINGS ────────────────────────────────────────────────────────

        case 'get_meetings':
            requireSession();
            $db   = getDB();
            $rows = $db->query('SELECT * FROM cgm_meetings ORDER BY created_at DESC')->fetchAll();
            $mAttRows = $db->query('SELECT meeting_id, member_id, checked_in FROM cgm_meeting_attendance')->fetchAll();
            $mAttMap  = [];
            foreach ($mAttRows as $a) {
                $mAttMap[(int)$a['meeting_id']][(int)$a['member_id']] = (bool)$a['checked_in'];
            }
            foreach ($rows as &$r) {
                $r['id']         = (int)$r['id'];
                if ($r['time'] && strlen($r['time']) > 5) $r['time'] = substr($r['time'], 0, 5);
                $r['attendance'] = $mAttMap[$r['id']] ?? (object)[];
            }
            respond($rows);

        case 'save_meeting':
            $me    = requireSession(); verifyCsrf();
            $title = trim($input['title'] ?? '');
            if (!$title) err('Meeting title is required');
            $loc   = trim($input['loc'] ?? '');
            if (empty($loc)) $loc = 'CGM';
            $db    = getDB();
            $db->prepare(
                'INSERT INTO cgm_meetings (title, date, time, format, loc, vlink, vid, vpass, agenda, status) 
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $title,
                $input['date']   ?: null,
                $input['time']   ?: null,
                $input['format'] ?? 'in-person',
                $loc,
                $input['vlink']  ?? '',
                $input['vid']    ?? '',
                $input['vpass']  ?? '',
                $input['agenda'] ?? '',
                $input['status'] ?? 'Scheduled',
            ]);
            respond(['id' => (int)$db->lastInsertId()]);

        case 'delete_meeting':
            requireSession(); verifyCsrf();
            $id = (int)($input['id'] ?? 0);
            if (!$id) err('Invalid ID');
            $db = getDB();
            $db->prepare('DELETE FROM cgm_meetings WHERE id = ?')->execute([$id]);
            $db->prepare('DELETE FROM cgm_meeting_attendance WHERE meeting_id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'update_meeting_status':
            requireSession(); verifyCsrf();
            $id     = (int)($input['id'] ?? 0);
            $status = trim($input['status'] ?? '');
            if (!$id || !in_array($status, ['Scheduled', 'Completed', 'Cancelled'])) err('Invalid status');
            getDB()->prepare('UPDATE cgm_meetings SET status = ? WHERE id = ?')->execute([$status, $id]);
            respond(['ok' => true]);

        case 'update_meeting_agenda':
            requireSession(); verifyCsrf();
            $id     = (int)($input['id'] ?? 0);
            $agenda = trim($input['agenda'] ?? '');
            if (!$id) err('Invalid meeting ID');
            getDB()->prepare('UPDATE cgm_meetings SET agenda = ? WHERE id = ?')->execute([$agenda, $id]);
            respond(['ok' => true]);

        case 'toggle_meeting_attendance':
            requireSession(); verifyCsrf();
            $meetingId = (int)($input['meeting_id'] ?? 0);
            $memberId  = (int)($input['member_id']  ?? 0);
            if (!$meetingId || !$memberId) err('meeting_id and member_id required');
            $db   = getDB();
            $stmt = $db->prepare(
                'SELECT checked_in FROM cgm_meeting_attendance WHERE meeting_id = ? AND member_id = ?'
            );
            $stmt->execute([$meetingId, $memberId]);
            $row = $stmt->fetch();
            if ($row === false) {
                $db->prepare('INSERT INTO cgm_meeting_attendance (meeting_id, member_id, checked_in) VALUES (?,?,1)')
                   ->execute([$meetingId, $memberId]);
                respond(['checked_in' => true]);
            } else {
                $new = $row['checked_in'] ? 0 : 1;
                $db->prepare('UPDATE cgm_meeting_attendance SET checked_in = ? WHERE meeting_id = ? AND member_id = ?')
                   ->execute([$new, $meetingId, $memberId]);
                respond(['checked_in' => (bool)$new]);
            }



        // ── EVENTS & ATTENDANCE ─────────────────────────────────────────────

        case 'get_events':
            requireSession();
            $db     = getDB();
            $events = $db->query('SELECT * FROM cgm_events ORDER BY date ASC, id ASC')->fetchAll();
            // Load all attendance rows
            $attRows = $db->query('SELECT event_id, member_id, checked_in FROM cgm_attendance')->fetchAll();
            $attMap  = [];
            foreach ($attRows as $a) {
                $attMap[(int)$a['event_id']][(int)$a['member_id']] = (bool)$a['checked_in'];
            }
            foreach ($events as &$evt) {
                $evt['id']         = (int)$evt['id'];
                $evt['attendance'] = $attMap[$evt['id']] ?? (object)[];
            }
            respond($events);

        case 'save_event':
            $me   = requireSession(); verifyCsrf();
            $name = trim($input['name'] ?? '');
            if (!$name) err('Event name is required');
            $id   = (int)($input['id'] ?? 0);
            $db   = getDB();
            $date = $input['date'] ?: null;
            $endDate = $input['end_date'] ?: $date;
            if ($id) {
                $db->prepare(
                    'UPDATE cgm_events SET name = ?, date = ?, end_date = ?, time = ?, type = ?, loc = ?, description = ? WHERE id = ?'
                )->execute([
                    $name,
                    $date,
                    $endDate,
                    $input['time']        ?: null,
                    $input['type']        ?? 'Other',
                    $input['loc']         ?? '',
                    $input['description'] ?? $input['desc'] ?? '',
                    $id
                ]);
                respond(['id' => $id]);
            } else {
                $db->prepare(
                    'INSERT INTO cgm_events (name, date, end_date, time, type, loc, description) VALUES (?,?,?,?,?,?,?)'
                )->execute([
                    $name,
                    $date,
                    $endDate,
                    $input['time']        ?: null,
                    $input['type']        ?? 'Other',
                    $input['loc']         ?? '',
                    $input['description'] ?? $input['desc'] ?? '',
                ]);
                respond(['id' => (int)$db->lastInsertId()]);
            }

        case 'delete_event':
            requireSession(); verifyCsrf();
            $id = (int)($input['id'] ?? 0);
            if (!$id) err('Invalid ID');
            $db = getDB();
            $db->prepare('DELETE FROM cgm_events WHERE id = ?')->execute([$id]);
            $db->prepare('DELETE FROM cgm_attendance WHERE event_id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'toggle_attendance':
            requireSession(); verifyCsrf();
            $eventId  = (int)($input['event_id']  ?? 0);
            $memberId = (int)($input['member_id'] ?? 0);
            if (!$eventId || !$memberId) err('event_id and member_id required');
            $db   = getDB();
            $stmt = $db->prepare(
                'SELECT checked_in FROM cgm_attendance WHERE event_id = ? AND member_id = ?'
            );
            $stmt->execute([$eventId, $memberId]);
            $row = $stmt->fetch();
            if ($row === false) {
                $db->prepare('INSERT INTO cgm_attendance (event_id, member_id, checked_in) VALUES (?,?,1)')
                   ->execute([$eventId, $memberId]);
                respond(['checked_in' => true]);
            } else {
                $new = $row['checked_in'] ? 0 : 1;
                $db->prepare('UPDATE cgm_attendance SET checked_in = ? WHERE event_id = ? AND member_id = ?')
                   ->execute([$new, $eventId, $memberId]);
                respond(['checked_in' => (bool)$new]);
            }

        // ── FINANCE ─────────────────────────────────────────────────────────

        case 'get_finance':
            requireSession();
            $rows = getDB()->query(
                'SELECT * FROM cgm_finance ORDER BY date DESC, created_at DESC'
            )->fetchAll();
            foreach ($rows as &$r) {
                $r['id']     = (int)$r['id'];
                $r['amount'] = (float)$r['amount'];
                // Alias: frontend uses 'desc'
                $r['desc'] = $r['description'];
            }
            respond($rows);

        case 'save_finance':
            $me     = requireSession(); verifyCsrf();
            $desc   = trim($input['desc'] ?? $input['description'] ?? '');
            $amount = (float)($input['amount'] ?? 0);
            $type   = ($input['type'] ?? '') === 'expense' ? 'expense' : 'income';
            if (!$desc || $amount <= 0) err('Description and valid amount are required');
            $db = getDB();
            $db->prepare(
                'INSERT INTO cgm_finance (date, description, cat, type, amount, notes, added_by) 
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $input['date']  ?: null,
                $desc,
                $input['cat']   ?? '',
                $type,
                $amount,
                $input['notes'] ?? '',
                $me['first'] . ' ' . $me['last'],
            ]);
            respond(['id' => (int)$db->lastInsertId()]);

        case 'delete_finance':
            requireSession(); verifyCsrf();
            $id = (int)($input['id'] ?? 0);
            if (!$id) err('Invalid ID');
            getDB()->prepare('DELETE FROM cgm_finance WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'get_positions':
            requireSession();
            ensurePositionTable();
            $out = [];
            foreach (getDB()->query('SELECT * FROM cgm_fin_position ORDER BY period_end DESC')->fetchAll() as $r) {
                $d = json_decode($r['data'], true) ?: [];
                $d['id']         = (int)$r['id'];
                $d['period_end'] = $r['period_end'];
                $d['added_by']   = $r['added_by'];
                $d['created_at'] = $r['created_at'];
                $out[] = $d;
            }
            respond($out);

        case 'import_monthly_report':
            $me    = requireSession(); verifyCsrf();
            $month = (string)($input['month'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) err('A valid reporting month is required');
            $monthStart = $month . '-01';
            $monthEnd   = date('Y-m-t', strtotime($monthStart));
            $who        = $me['first'] . ' ' . $me['last'];
            $replace    = !empty($input['replace']);
            $note       = mb_substr(trim((string)($input['notes'] ?? '')), 0, 500);

            // Validate everything before touching the database or disk
            $entries = [];
            foreach ((array)($input['entries'] ?? []) as $e) {
                $desc   = trim((string)($e['desc'] ?? ''));
                $amount = round((float)($e['amount'] ?? 0), 2);
                $type   = ($e['type'] ?? '') === 'expense' ? 'expense' : 'income';
                if ($desc === '' || $amount == 0.0) continue;
                $date = (string)($e['date'] ?? $monthEnd);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $monthEnd;
                $entries[] = [$date, mb_substr($desc, 0, 255), mb_substr((string)($e['cat'] ?? 'Other'), 0, 60), $type, $amount];
            }
            if (count($entries) > 500) err('Too many ledger entries in one import');

            $position = $input['position'] ?? null;
            if ($position !== null && !is_array($position)) err('Invalid financial position data');
            if ($position) ensurePositionTable();

            $savedLinks = [];
            $docRows    = [];
            foreach (array_slice((array)($input['files'] ?? []), 0, 12) as $f) {
                $dname = mb_substr(trim((string)($f['name'] ?? '')), 0, 255);
                if ($dname === '' || empty($f['fileData'])) continue;
                $link = storeUpload((string)($f['fileName'] ?? $dname), (string)$f['fileData']);
                $savedLinks[] = $link;
                $ddate = (string)($f['date'] ?? '');
                $docRows[] = [
                    $dname,
                    mb_substr((string)($f['cat'] ?? 'Financial Report'), 0, 60),
                    preg_match('/^\d{4}-\d{2}-\d{2}$/', $ddate) ? $ddate : $monthEnd,
                    $link,
                    mb_substr((string)($f['notes'] ?? ''), 0, 1000),
                ];
            }

            $db      = getDB();
            $removed = 0;
            $oldLinks = [];
            try {
                $db->beginTransaction();

                if ($replace && $entries) {
                    $del = $db->prepare('DELETE FROM cgm_finance WHERE date BETWEEN ? AND ?');
                    $del->execute([$monthStart, $monthEnd]);
                    $removed = $del->rowCount();
                }
                $ins = $db->prepare(
                    'INSERT INTO cgm_finance (date, description, cat, type, amount, notes, added_by) VALUES (?,?,?,?,?,?,?)'
                );
                foreach ($entries as [$d, $desc, $cat, $type, $amount]) {
                    $ins->execute([$d, $desc, $cat, $type, $amount, $note, $who]);
                }

                if ($position) {
                    $db->prepare(
                        'INSERT INTO cgm_fin_position (period_end, data, added_by) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE data = VALUES(data), added_by = VALUES(added_by), created_at = CURRENT_TIMESTAMP'
                    )->execute([$monthEnd, json_encode($position, JSON_UNESCAPED_UNICODE), $who]);
                }

                // Re-uploading the same report replaces the earlier library entry
                $find = $db->prepare('SELECT link FROM cgm_docs WHERE name = ? AND cat = ?');
                $drop = $db->prepare('DELETE FROM cgm_docs WHERE name = ? AND cat = ?');
                foreach ($docRows as $row) {
                    $find->execute([$row[0], $row[1]]);
                    foreach ($find->fetchAll() as $o) $oldLinks[] = $o['link'];
                    $drop->execute([$row[0], $row[1]]);
                }
                $insDoc = $db->prepare(
                    'INSERT INTO cgm_docs (name, cat, date, link, notes, added_by) VALUES (?,?,?,?,?,?)'
                );
                foreach ($docRows as $row) {
                    $insDoc->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $who]);
                }
                $db->commit();
            } catch (Throwable $t) {
                if ($db->inTransaction()) $db->rollBack();
                foreach ($savedLinks as $l) @unlink(__DIR__ . '/' . $l);
                err('Import failed: ' . $t->getMessage(), 500);
            }

            // Tidy up superseded uploads (only files we manage under uploads/)
            foreach ($oldLinks as $l) {
                if (is_string($l) && preg_match('#^uploads/[A-Za-z0-9_.\-]+$#', $l)) @unlink(__DIR__ . '/' . $l);
            }
            respond(['entries' => count($entries), 'removed' => $removed, 'docs' => count($docRows), 'position' => (bool)$position]);

        // ── EMAIL SETTINGS ───────────────────────────────────────────────────

        case 'get_email_settings':
            requireAdmin();
            $row = getDB()->query(
                'SELECT endpoint, secret, url FROM cgm_email_settings LIMIT 1'
            )->fetch();
            respond($row ?: ['endpoint' => '', 'secret' => '', 'url' => '']);

        case 'save_email_settings':
            requireAdmin(); verifyCsrf();
            $endpoint = trim($input['endpoint'] ?? '');
            $secret   = trim($input['secret']   ?? '');
            $url      = trim($input['url']      ?? '');
            if (!$endpoint || !$secret) err('Endpoint URL and shared secret are required');
            $db  = getDB();
            $chk = $db->query('SELECT id FROM cgm_email_settings LIMIT 1')->fetch();
            if ($chk) {
                $db->prepare('UPDATE cgm_email_settings SET endpoint=?, secret=?, url=? WHERE id=?')
                   ->execute([$endpoint, $secret, $url, $chk['id']]);
            } else {
                $db->prepare('INSERT INTO cgm_email_settings (endpoint, secret, url) VALUES (?,?,?)')
                   ->execute([$endpoint, $secret, $url]);
            }
            respond(['ok' => true]);

        // ── ANALYTICS ────────────────────────────────────────────────────────

        case 'track_visit':
            $db = getDB();
            // Ensure table exists
            $db->exec("CREATE TABLE IF NOT EXISTS cgm_page_visits (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page        VARCHAR(255) NOT NULL,
                title       VARCHAR(255),
                visitor_id  VARCHAR(64) NOT NULL,
                ip          VARCHAR(45),
                device      ENUM('desktop', 'mobile', 'tablet') DEFAULT 'desktop',
                referrer    VARCHAR(255),
                visited_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_visited_at (visited_at),
                INDEX idx_page (page),
                INDEX idx_visitor (visitor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $page      = substr(trim($input['page'] ?? $_GET['page'] ?? '/'), 0, 250);
            $title     = substr(trim($input['title'] ?? $_GET['title'] ?? ''), 0, 250);
            $visitorId = substr(trim($input['visitor_id'] ?? $_GET['visitor_id'] ?? bin2hex(random_bytes(16))), 0, 64);
            $referrer  = substr(trim($input['referrer'] ?? $_GET['referrer'] ?? 'Direct / Bookmark'), 0, 250);
            $device    = in_array($input['device'] ?? $_GET['device'] ?? '', ['desktop', 'mobile', 'tablet'], true) ? ($input['device'] ?? $_GET['device']) : 'desktop';
            $ip        = substr($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 0, 45);

            if (empty($page)) $page = '/index.html';

            $stmt = $db->prepare('INSERT INTO cgm_page_visits (page, title, visitor_id, ip, device, referrer) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$page, $title, $visitorId, $ip, $device, $referrer]);

            respond(['ok' => true]);

        case 'get_analytics':
            requireSession();
            $db = getDB();

            // Ensure cgm_page_visits table exists
            $db->exec("CREATE TABLE IF NOT EXISTS cgm_page_visits (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page        VARCHAR(255) NOT NULL,
                title       VARCHAR(255),
                visitor_id  VARCHAR(64) NOT NULL,
                ip          VARCHAR(45),
                device      ENUM('desktop', 'mobile', 'tablet') DEFAULT 'desktop',
                referrer    VARCHAR(255),
                visited_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_visited_at (visited_at),
                INDEX idx_page (page),
                INDEX idx_visitor (visitor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $members   = $db->query('SELECT id, first, last, email, role, title FROM cgm_members ORDER BY last, first')->fetchAll();
            $finance   = $db->query('SELECT * FROM cgm_finance ORDER BY date ASC, id ASC')->fetchAll();
            foreach ($finance as &$f) {
                $f['id']     = (int)$f['id'];
                $f['amount'] = (float)$f['amount'];
                $f['desc']   = $f['description'];
            }
            $docs      = $db->query('SELECT * FROM cgm_docs ORDER BY created_at DESC')->fetchAll();
            $meetings  = $db->query('SELECT * FROM cgm_meetings ORDER BY date ASC')->fetchAll();
            $events    = $db->query('SELECT * FROM cgm_events ORDER BY date ASC')->fetchAll();
            $attRows   = $db->query('SELECT event_id, member_id, checked_in FROM cgm_attendance')->fetchAll();
            $mAttRows  = $db->query('SELECT meeting_id, member_id, checked_in FROM cgm_meeting_attendance')->fetchAll();
            $visits    = $db->query('SELECT id, page, title, visitor_id, ip, device, referrer, visited_at FROM cgm_page_visits ORDER BY visited_at ASC')->fetchAll();

            respond([
                'members'            => $members,
                'finance'            => $finance,
                'docs'               => $docs,
                'meetings'           => $meetings,
                'events'             => $events,
                'attendance'         => $attRows,
                'meeting_attendance' => $mAttRows,
                'visits'             => $visits
            ]);

        default:
            err("Unknown action: {$action}", 404);
    }

} catch (PDOException $e) {
    error_log('CGM API DB error: ' . $e->getMessage());
    err('Database error. Please try again.', 500);
} catch (Throwable $e) {
    error_log('CGM API error: ' . $e->getMessage());
    err('Server error. Please try again.', 500);
}
