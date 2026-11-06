<?php
require __DIR__ . '/../notifications.php';
class Rows extends PDOStatement {
    public array $rows = [];
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class Insert extends PDOStatement {
    public FakeDB $db;
    private int $added = 0;
    public function execute(?array $params = null): bool {
        $key = $params[0] . ':' . $params[1];
        $this->added = isset($this->db->jobs[$key]) ? 0 : 1;
        $this->db->jobs[$key] = $params;
        return true;
    }
    public function rowCount(): int { return $this->added; }
}
class FakeDB extends PDO {
    public array $jobs = [];
    public array $meetings = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { $s = new Insert(); $s->db = $this; return $s; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        $s = new Rows();
        $s->rows = str_contains($query, 'cgm_members') ? ['author@example.org', 'board@example.org', 'invalid', 'board@example.org'] : $this->meetings;
        return $s;
    }
}
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$db = new FakeDB();
check(queueBoardNotification($db, 'doc:1', 'Document added', 'Review portal') === 2, 'Includes author and all valid unique recipients');
check(queueBoardNotification($db, 'doc:1', 'Document added', 'Review portal') === 0, 'Duplicate queue inserts prevented');
$db->meetings = [['id'=>1, 'title'=>'Board meeting', 'date'=>'2026-11-14', 'time'=>'12:00:00']];
$zone = new DateTimeZone('America/Chicago');
check(queueMeetingReminders($db, new DateTimeImmutable('2026-11-07 12:05:00', $zone)) === 2, 'Seven-day reminder');
check(queueMeetingReminders($db, new DateTimeImmutable('2026-11-07 12:10:00', $zone)) === 0, 'Repeated worker does not duplicate reminder');
check(queueMeetingReminders($db, new DateTimeImmutable('2026-11-13 12:05:00', $zone)) === 2, '24-hour reminder');
check(queueMeetingReminders($db, new DateTimeImmutable('2026-11-15 12:05:00', $zone)) === 0, 'Past meetings excluded');
echo "6 notification checks passed.\n";
