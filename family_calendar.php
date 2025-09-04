<?php
/**
 *https://chatgpt.com/share/68ba1035-9414-800c-96e3-4a1e52646243
 * Family Calendar — single file, self-hosted
 *
 * Requirements:
 * - PHP 8+
 * - Either SQLite (pdo_sqlite) or simple CSV file storage
 *
 * Design goals:
 * - White background, black text, simple grid
 * - Accurate year/month/day rendering
 * - Admin-only create, read, update, delete of events
 * - Easy backup (CSV export); storage pluggable via STORAGE_BACKEND
 *
 * Security notes:
 * - Simple session login with a single shared admin password
 * - CSRF token on mutating actions
 * - All output escaped with htmlspecialchars
 *
 * You may adapt this file to use MySQL by switching STORAGE_BACKEND to 'mysql'
 * and filling the MySQL configuration section below. By default, SQLite is used.
 */

// ---------------------------
// Configuration
// ---------------------------
const APP_NAME          = 'Family Calendar';
const TIMEZONE          = 'America/Chicago';       // Change if desired
const STORAGE_BACKEND   = 'sqlite';                // 'sqlite' | 'csv' | 'mysql'

// Admin password (change immediately). Store a password hash, not plaintext.
// Generate a new hash by running: php -r "echo password_hash('your-password', PASSWORD_DEFAULT), \"\n\";"
const ADMIN_PASSWORD_HASH = '$2y$10$Zs5oJrU0xJc4xQ6w2mB9UuJqf8lS6B7w3t8d5k1Wl6pUoQpQmPv2a'; // hash for: ChangeMe123!

// SQLite configuration
const SQLITE_PATH = __DIR__ . '/calendar.sqlite';

// CSV configuration
const CSV_PATH    = __DIR__ . '/events.csv';

// MySQL configuration (only if STORAGE_BACKEND === 'mysql')
const MYSQL_DSN   = 'mysql:host=127.0.0.1;port=3306;dbname=family_calendar;charset=utf8mb4';
const MYSQL_USER  = 'calendar_user';
const MYSQL_PASS  = 'replace_this_password';

// ---------------------------
// Bootstrap
// ---------------------------
ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set(TIMEZONE);
session_start();

// Ensure multibyte functions behave consistently
mb_internal_encoding('UTF-8');

// Routing
$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$year     = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
$month    = isset($_GET['m']) ? max(1, min(12, (int)$_GET['m'])) : (int)date('n');

// CSRF token setup
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// Storage abstraction: SQLite, CSV, or MySQL via simple helpers
interface EventStore {
    public function initSchema(): void;
    public function allForMonth(int $year, int $month): array;         // returns array of events sorted by date/time
    public function getById(int $id): ?array;
    public function create(array $event): int;                          // returns new id
    public function update(int $id, array $event): bool;
    public function delete(int $id): bool;
    public function exportCsv(): string;                                // returns CSV string with header
}

class SqliteStore implements EventStore {
    private \PDO $pdo;

    public function __construct(string $path) {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Enable foreign keys just in case of future expansion
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function initSchema(): void {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT,
                date TEXT NOT NULL,            -- YYYY-MM-DD
                start_time TEXT,               -- HH:MM
                end_time TEXT,                 -- HH:MM
                all_day INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_date ON events(date)');
    }

    public function allForMonth(int $year, int $month): array {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = (new DateTime($start))->modify('first day of next month')->format('Y-m-d');
        $stmt  = $this->pdo->prepare('SELECT * FROM events WHERE date >= :start AND date < :end ORDER BY date ASC, COALESCE(start_time, ""), title ASC');
        $stmt->execute([':start' => $start, ':end' => $end]);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $event): int {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO events (title, description, date, start_time, end_time, all_day, created_at, updated_at) VALUES (:title, :description, :date, :start_time, :end_time, :all_day, :created_at, :updated_at)');
        $stmt->execute([
            ':title'       => $event['title'],
            ':description' => $event['description'] ?? '',
            ':date'        => $event['date'],
            ':start_time'  => $event['start_time'] ?: null,
            ':end_time'    => $event['end_time'] ?: null,
            ':all_day'     => !empty($event['all_day']) ? 1 : 0,
            ':created_at'  => $now,
            ':updated_at'  => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $event): bool {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE events SET title=:title, description=:description, date=:date, start_time=:start_time, end_time=:end_time, all_day=:all_day, updated_at=:updated_at WHERE id=:id');
        return $stmt->execute([
            ':title'       => $event['title'],
            ':description' => $event['description'] ?? '',
            ':date'        => $event['date'],
            ':start_time'  => $event['start_time'] ?: null,
            ':end_time'    => $event['end_time'] ?: null,
            ':all_day'     => !empty($event['all_day']) ? 1 : 0,
            ':updated_at'  => $now,
            ':id'          => $id,
        ]);
    }

    public function delete(int $id): bool {
        $stmt = $this->pdo->prepare('DELETE FROM events WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function exportCsv(): string {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['id','title','description','date','start_time','end_time','all_day','created_at','updated_at']);
        $stmt = $this->pdo->query('SELECT * FROM events ORDER BY date ASC, COALESCE(start_time, ""), title ASC');
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['id'], $row['title'], $row['description'], $row['date'],
                $row['start_time'], $row['end_time'], $row['all_day'],
                $row['created_at'], $row['updated_at']
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}

class CsvStore implements EventStore {
    private string $path;

    public function __construct(string $path) { $this->path = $path; }

    public function initSchema(): void {
        if (!file_exists($this->path)) {
            $fh = fopen($this->path, 'w');
            if ($fh === false) { throw new RuntimeException('Cannot create CSV file'); }
            // Header
            fputcsv($fh, ['id','title','description','date','start_time','end_time','all_day','created_at','updated_at']);
            fclose($fh);
        }
    }

    public function allForMonth(int $year, int $month): array {
        $all = $this->readAll();
        $prefix = sprintf('%04d-%02d-', $year, $month);
        $out = [];
        foreach ($all as $e) {
            if (str_starts_with($e['date'], $prefix)) { $out[] = $e; }
        }
        usort($out, function($a,$b){
            $k1 = $a['date'] . ' ' . ($a['start_time'] ?? '') . ' ' . $a['title'];
            $k2 = $b['date'] . ' ' . ($b['start_time'] ?? '') . ' ' . $b['title'];
            return $k1 <=> $k2;
        });
        return $out;
    }

    public function getById(int $id): ?array {
        $all = $this->readAll();
        foreach ($all as $e) { if ((int)$e['id'] === $id) { return $e; } }
        return null;
    }

    public function create(array $event): int {
        $all = $this->readAll();
        $max = 0; foreach ($all as $e) { $max = max($max, (int)$e['id']); }
        $id  = $max + 1;
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $row = [
            'id' => $id,
            'title' => $event['title'],
            'description' => $event['description'] ?? '',
            'date' => $event['date'],
            'start_time' => $event['start_time'] ?: '',
            'end_time' => $event['end_time'] ?: '',
            'all_day' => !empty($event['all_day']) ? '1' : '0',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $all[] = $row;
        $this->writeAll($all);
        return $id;
    }

    public function update(int $id, array $event): bool {
        $all = $this->readAll();
        $updated = false;
        foreach ($all as &$e) {
            if ((int)$e['id'] === $id) {
                $e['title'] = $event['title'];
                $e['description'] = $event['description'] ?? '';
                $e['date'] = $event['date'];
                $e['start_time'] = $event['start_time'] ?: '';
                $e['end_time'] = $event['end_time'] ?: '';
                $e['all_day'] = !empty($event['all_day']) ? '1' : '0';
                $e['updated_at'] = (new DateTime())->format('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }
        if ($updated) { $this->writeAll($all); }
        return $updated;
    }

    public function delete(int $id): bool {
        $all = $this->readAll();
        $n = count($all);
        $all = array_values(array_filter($all, fn($e) => (int)$e['id'] !== $id));
        if (count($all) !== $n) { $this->writeAll($all); return true; }
        return false;
    }

    public function exportCsv(): string { return file_get_contents($this->path) ?: ''; }

    private function readAll(): array {
        if (!file_exists($this->path)) { return []; }
        $fh = fopen($this->path, 'r'); if ($fh === false) { return []; }
        $out = [];
        $header = fgetcsv($fh);
        if ($header === false) { fclose($fh); return []; }
        while (($row = fgetcsv($fh)) !== false) {
            $e = array_combine($header, $row);
            if ($e) { $out[] = $e; }
        }
        fclose($fh);
        return $out;
    }

    private function writeAll(array $rows): void {
        $fh = fopen($this->path, 'c+');
        if ($fh === false) { throw new RuntimeException('Cannot open CSV for writing'); }
        if (!flock($fh, LOCK_EX)) { throw new RuntimeException('Cannot lock CSV for writing'); }
        ftruncate($fh, 0);
        rewind($fh);
        fputcsv($fh, ['id','title','description','date','start_time','end_time','all_day','created_at','updated_at']);
        foreach ($rows as $e) {
            fputcsv($fh, [
                $e['id'], $e['title'], $e['description'], $e['date'],
                $e['start_time'], $e['end_time'], $e['all_day'],
                $e['created_at'] ?? '', $e['updated_at'] ?? ''
            ]);
        }
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

class MysqlStore implements EventStore {
    private \PDO $pdo;

    public function __construct(string $dsn, string $user, string $pass) {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function initSchema(): void {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                date DATE NOT NULL,
                start_time TIME NULL,
                end_time TIME NULL,
                all_day TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function allForMonth(int $year, int $month): array {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = (new DateTime($start))->modify('first day of next month')->format('Y-m-d');
        $stmt  = $this->pdo->prepare('SELECT id, title, description, DATE_FORMAT(date, "%Y-%m-%d") AS date, DATE_FORMAT(start_time, "%H:%i") AS start_time, DATE_FORMAT(end_time, "%H:%i") AS end_time, all_day, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS created_at, DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:%s") AS updated_at FROM events WHERE date >= :start AND date < :end ORDER BY date ASC, start_time ASC, title ASC');
        $stmt->execute([':start' => $start, ':end' => $end]);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->pdo->prepare('SELECT id, title, description, DATE_FORMAT(date, "%Y-%m-%d") AS date, DATE_FORMAT(start_time, "%H:%i") AS start_time, DATE_FORMAT(end_time, "%H:%i") AS end_time, all_day, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS created_at, DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:%s") AS updated_at FROM events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $event): int {
        $stmt = $this->pdo->prepare('INSERT INTO events (title, description, date, start_time, end_time, all_day) VALUES (:title, :description, :date, :start_time, :end_time, :all_day)');
        $stmt->execute([
            ':title'       => $event['title'],
            ':description' => $event['description'] ?? '',
            ':date'        => $event['date'],
            ':start_time'  => $event['start_time'] ?: null,
            ':end_time'    => $event['end_time'] ?: null,
            ':all_day'     => !empty($event['all_day']) ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $event): bool {
        $stmt = $this->pdo->prepare('UPDATE events SET title=:title, description=:description, date=:date, start_time=:start_time, end_time=:end_time, all_day=:all_day WHERE id=:id');
        return $stmt->execute([
            ':title'       => $event['title'],
            ':description' => $event['description'] ?? '',
            ':date'        => $event['date'],
            ':start_time'  => $event['start_time'] ?: null,
            ':end_time'    => $event['end_time'] ?: null,
            ':all_day'     => !empty($event['all_day']) ? 1 : 0,
            ':id'          => $id,
        ]);
    }

    public function delete(int $id): bool {
        $stmt = $this->pdo->prepare('DELETE FROM events WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function exportCsv(): string {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['id','title','description','date','start_time','end_time','all_day','created_at','updated_at']);
        $stmt = $this->pdo->query('SELECT id, title, description, DATE_FORMAT(date, "%Y-%m-%d") AS date, DATE_FORMAT(start_time, "%H:%i") AS start_time, DATE_FORMAT(end_time, "%H:%i") AS end_time, all_day, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS created_at, DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:%s") AS updated_at FROM events ORDER BY date ASC, start_time ASC, title ASC');
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['id'], $row['title'], $row['description'], $row['date'],
                $row['start_time'], $row['end_time'], $row['all_day'],
                $row['created_at'], $row['updated_at']
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}

function store(): EventStore {
    static $store = null;
    if ($store) { return $store; }
    if (STORAGE_BACKEND === 'sqlite') {
        $store = new SqliteStore(SQLITE_PATH);
    } elseif (STORAGE_BACKEND === 'csv') {
        $store = new CsvStore(CSV_PATH);
    } elseif (STORAGE_BACKEND === 'mysql') {
        $store = new MysqlStore(MYSQL_DSN, MYSQL_USER, MYSQL_PASS);
    } else {
        throw new RuntimeException('Unsupported STORAGE_BACKEND');
    }
    $store->initSchema();
    return $store;
}

// ---------------------------
// Helpers
// ---------------------------
function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_admin(): bool { return !empty($_SESSION['is_admin']); }
function require_csrf(string $token): void { if (!hash_equals($_SESSION['csrf'] ?? '', $token)) { http_response_code(400); exit('Bad CSRF'); } }

function parse_event_from_post(): array {
    $title = trim((string)($_POST['title'] ?? ''));
    $date  = trim((string)($_POST['date'] ?? ''));
    $start = trim((string)($_POST['start_time'] ?? ''));
    $end   = trim((string)($_POST['end_time'] ?? ''));
    $desc  = trim((string)($_POST['description'] ?? ''));
    $all   = isset($_POST['all_day']) ? 1 : 0;

    // Basic validation
    if ($title === '' || $date === '') { return ['__error' => 'Title and date are required.']; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return ['__error' => 'Date must be YYYY-MM-DD.']; }
    if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) { return ['__error' => 'Start time must be HH:MM.']; }
    if ($end   !== '' && !preg_match('/^\d{2}:\d{2}$/', $end))   { return ['__error' => 'End time must be HH:MM.']; }

    return [
        'title'       => $title,
        'description' => $desc,
        'date'        => $date,
        'start_time'  => $all ? '' : $start,
        'end_time'    => $all ? '' : $end,
        'all_day'     => $all,
    ];
}

function month_grid(int $year, int $month): array {
    // Returns a 6x7 grid (weeks x days) where each cell is ['y'=>int,'m'=>int,'d'=>int,'in_month'=>bool]
    $first = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $start = clone $first;
    // Week starts on Sunday; adjust to previous Sunday
    $dow   = (int)$first->format('w'); // 0=Sun..6=Sat
    if ($dow > 0) { $start->modify('-' . $dow . ' days'); }

    $grid = [];
    for ($week = 0; $week < 6; $week++) {
        $row = [];
        for ($day = 0; $day < 7; $day++) {
            $cell = [
                'y' => (int)$start->format('Y'),
                'm' => (int)$start->format('n'),
                'd' => (int)$start->format('j'),
                'in_month' => ((int)$start->format('n') === $month)
            ];
            $row[] = $cell;
            $start->modify('+1 day');
        }
        $grid[] = $row;
    }
    return $grid;
}

// ---------------------------
// Actions
// ---------------------------
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!password_verify($_POST['password'] ?? '', ADMIN_PASSWORD_HASH)) {
        $_SESSION['flash'] = 'Invalid password.';
    } else {
        $_SESSION['is_admin'] = true;
        $_SESSION['flash'] = 'Welcome, administrator.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (is_admin()) {
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf($_POST['csrf'] ?? '');
        $data = parse_event_from_post();
        if (isset($data['__error'])) { $_SESSION['flash'] = $data['__error']; }
        else { $id = store()->create($data); $_SESSION['flash'] = 'Event #' . $id . ' created.'; }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?y=' . (int)($_GET['y'] ?? date('Y')) . '&m=' . (int)($_GET['m'] ?? date('n')));
        exit;
    }
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $data = parse_event_from_post();
        if (isset($data['__error'])) { $_SESSION['flash'] = $data['__error']; }
        else { store()->update($id, $data); $_SESSION['flash'] = 'Event #' . $id . ' updated.'; }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?y=' . (int)($_GET['y'] ?? date('Y')) . '&m=' . (int)($_GET['m'] ?? date('n')));
        exit;
    }
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf($_POST['csrf'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        store()->delete($id);
        $_SESSION['flash'] = 'Event #' . $id . ' deleted.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?y=' . (int)($_GET['y'] ?? date('Y')) . '&m=' . (int)($_GET['m'] ?? date('n')));
        exit;
    }
    if ($action === 'export_csv') {
        $csv = store()->exportCsv();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendar_export_' . date('Ymd_His') . '.csv"');
        echo $csv;
        exit;
    }
}

// ---------------------------
// View helpers (HTML)
// ---------------------------
function header_html(string $title): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1" />';
    echo '<title>' . h($title) . '</title>';
    echo '<style>';
    echo 'body{font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#fff; color:#000; margin:0; padding:0;}';
    echo 'header,footer{padding:12px 16px; border-bottom:1px solid #00000022;}';
    echo 'h1{font-size:20px; margin:0;}';
    echo '.container{padding:16px;}';
    echo '.flash{background:#ffffcc; border:1px solid #ccc; padding:8px 12px; margin-bottom:12px;}';
    echo '.nav a{margin-right:8px; color:#000; text-decoration:underline;}';
    echo 'table.calendar{width:100%; border-collapse:collapse; table-layout:fixed;}';
    echo 'table.calendar th, table.calendar td{border:1px solid #00000022; vertical-align:top; padding:6px; height:100px;}';
    echo 'table.calendar th{background:#f5f5f5; font-weight:bold; text-align:center;}';
    echo '.daynum{font-weight:bold; margin-bottom:6px;}';
    echo '.muted{color:#00000066;}';
    echo '.event{padding:3px 4px; margin:2px 0; border:1px solid #00000022;}';
    echo '.controls{margin:8px 0;}';
    echo 'form.inline{display:inline;}';
    echo 'input,button,textarea,select{font:inherit; color:#000; background:#fff; border:1px solid #000; padding:6px; border-radius:0;}';
    echo 'label{display:block; margin:8px 0 4px;}';
    echo '.grid{display:grid; grid-template-columns:1fr 1fr; gap:12px;}';
    echo '@media (max-width:700px){ .grid{grid-template-columns:1fr;} table.calendar th,table.calendar td{height:auto;} }';
    echo '</style></head><body>';
    echo '<header><div style="display:flex; align-items:center; justify-content:space-between;">';
    echo '<h1>' . h(APP_NAME) . '</h1>';
    echo '<div class="nav">';
    echo '<a href="?y=' . (int)date('Y') . '&m=' . (int)date('n') . '">Today</a>';
    echo is_admin() ? '<a href="?action=export_csv">Backup (CSV)</a>' : '';
    echo is_admin() ? ' <a href="?action=logout">Log out</a>' : '';
    echo !is_admin() ? '<form class="inline" method="post" action=""><input type="hidden" name="action" value="login"><input type="password" name="password" placeholder="Admin password" required> <button>Log in</button></form>' : '';
    echo '</div></div></header>';
}

function footer_html(): void { echo '<footer><small>&copy; ' . date('Y') . ' Family Calendar</small></footer></body></html>'; }

function month_title(int $year, int $month): string {
    $dt = DateTime::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
    return $dt->format('F Y');
}

function render_calendar(int $year, int $month): void {
    $grid   = month_grid($year, $month);
    $events = store()->allForMonth($year, $month);
    $byDate = [];
    foreach ($events as $e) { $byDate[$e['date']][] = $e; }

    $prev = (new DateTime(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');
    $next = (new DateTime(sprintf('%04d-%02d-01', $year, $month)))->modify('+1 month');

    echo '<div class="container">';
    if (!empty($_SESSION['flash'])) { echo '<div class="flash">' . h($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); }

    echo '<div class="controls">';
    echo '<a href="?y=' . (int)$prev->format('Y') . '&m=' . (int)$prev->format('n') . '">&larr; ' . h($prev->format('M Y')) . '</a> | ';
    echo '<strong>' . h(month_title($year, $month)) . '</strong> | ';
    echo '<a href="?y=' . (int)$next->format('Y') . '&m=' . (int)$next->format('n') . '">' . h($next->format('M Y')) . ' &rarr;</a>';
    echo '</div>';

    echo '<table class="calendar">';
    echo '<thead><tr>';
    foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d) { echo '<th>' . $d . '</th>'; }
    echo '</tr></thead><tbody>';

    foreach ($grid as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            $dateStr = sprintf('%04d-%02d-%02d', $cell['y'], $cell['m'], $cell['d']);
            $in = $cell['in_month'];
            echo '<td' . ($in ? '' : ' class="muted"') . '>';
            echo '<div class="daynum">' . (int)$cell['d'] . '</div>';
            if ($in) {
                if (!empty($byDate[$dateStr])) {
                    foreach ($byDate[$dateStr] as $e) {
                        $label = $e['all_day'] ? 'All day' : trim(($e['start_time'] ?? '') . (isset($e['end_time']) && $e['end_time'] ? '–' . $e['end_time'] : ''));
                        echo '<div class="event">' . ($label ? '<strong>' . h($label) . '</strong> — ' : '') . h($e['title']);
                        if (is_admin()) {
                            echo '<div>';
                            echo '<details><summary>Manage</summary>';
                            echo edit_form_inline($e, $dateStr);
                            echo delete_form_inline((int)$e['id']);
                            echo '</details>';
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                }
                if (is_admin()) { echo add_form_inline($dateStr); }
            }
            echo '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

function add_form_inline(string $date): string {
    $y = (int)($_GET['y'] ?? date('Y'));
    $m = (int)($_GET['m'] ?? date('n'));
    $html = '';
    $html .= '<details><summary>Add event</summary>';
    $html .= '<form method="post" action="?y='.$y.'&m='.$m.'">';
    $html .= '<input type="hidden" name="action" value="create">';
    $html .= '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
    $html .= '<div class="grid">';
    $html .= '<div><label>Title<input name="title" required></label>'; 
    $html .= '<label>Date<input name="date" value="' . h($date) . '" placeholder="YYYY-MM-DD" required></label>';
    $html .= '<label>All day <input type="checkbox" name="all_day" value="1"></label></div>';
    $html .= '<div><label>Start (HH:MM)<input name="start_time" placeholder="09:00"></label>';
    $html .= '<label>End (HH:MM)<input name="end_time" placeholder="10:00"></label>';
    $html .= '<label>Description<textarea name="description" rows="3"></textarea></label></div>';
    $html .= '</div><div><button>Add</button></div></form>';
    $html .= '</details>';
    return $html;
}

function edit_form_inline(array $e, string $dateDefault): string {
    $y = (int)($_GET['y'] ?? date('Y'));
    $m = (int)($_GET['m'] ?? date('n'));
    $html = '';
    $html .= '<form class="inline" method="post" action="?y='.$y.'&m='.$m.'" style="margin-top:6px;">';
    $html .= '<input type="hidden" name="action" value="update">';
    $html .= '<input type="hidden" name="id" value="' . (int)$e['id'] . '">';
    $html .= '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
    $html .= '<div class="grid">';
    $html .= '<div><label>Title<input name="title" value="' . h($e['title']) . '" required></label>';
    $html .= '<label>Date<input name="date" value="' . h($e['date'] ?: $dateDefault) . '" required></label>';
    $html .= '<label>All day <input type="checkbox" name="all_day" value="1"' . (!empty($e['all_day']) ? ' checked' : '') . '></label></div>';
    $html .= '<div><label>Start<input name="start_time" value="' . h($e['start_time'] ?? '') . '"></label>';
    $html .= '<label>End<input name="end_time" value="' . h($e['end_time'] ?? '') . '"></label>';
    $html .= '<label>Description<textarea name="description" rows="3">' . h($e['description'] ?? '') . '</textarea></label></div>';
    $html .= '</div><div><button>Save</button></div></form>';
    return $html;
}

function delete_form_inline(int $id): string {
    $y = (int)($_GET['y'] ?? date('Y'));
    $m = (int)($_GET['m'] ?? date('n'));
    $html = '';
    $html .= '<form class="inline" method="post" action="?y='.$y.'&m='.$m.'" onsubmit="return confirm(\'Delete this event?\');">';
    $html .= '<input type="hidden" name="action" value="delete">';
    $html .= '<input type="hidden" name="id" value="' . $id . '">';
    $html .= '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
    $html .= ' <button>Delete</button>';
    $html .= '</form>';
    return $html;
}

// ---------------------------
// Render
// ---------------------------
header_html(APP_NAME);
render_calendar($year, $month);
footer_html();

?>
