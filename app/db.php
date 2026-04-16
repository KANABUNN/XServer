<?php
function db(): PDO
{
    static $pdo = null;
    global $config;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $config['db'] ?? [];
    $driver = $db['driver'] ?? 'sqlite';

    if ($driver !== 'sqlite') {
        throw new RuntimeException('This starter pack currently supports only sqlite.');
    }

    $path = $db['database'] ?? null;
    if (!$path) {
        throw new RuntimeException('Database path is not configured.');
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create database directory: ' . $dir);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function db_init(): void
{
    $pdo = db();

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS meetings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    meeting_date TEXT NOT NULL,
    start_time TEXT,
    end_time TEXT,
    location TEXT,
    facilitator TEXT,
    status TEXT NOT NULL DEFAULT 'scheduled',
    summary TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS agenda_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meeting_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    category TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    priority TEXT NOT NULL DEFAULT 'medium',
    decision TEXT,
    result_note TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meeting_id INTEGER,
    agenda_item_id INTEGER,
    title TEXT NOT NULL,
    description TEXT,
    assignee TEXT,
    status TEXT NOT NULL DEFAULT 'todo',
    priority TEXT NOT NULL DEFAULT 'medium',
    due_date TEXT,
    progress INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(meeting_id) REFERENCES meetings(id) ON DELETE SET NULL,
    FOREIGN KEY(agenda_item_id) REFERENCES agenda_items(id) ON DELETE SET NULL
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER,
    agenda_item_id INTEGER,
    author TEXT,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY(agenda_item_id) REFERENCES agenda_items(id) ON DELETE CASCADE
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action_type TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id INTEGER,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM meetings')->fetchColumn();
    if ($count === 0) {
        $now = now();
        $stmt = $pdo->prepare('INSERT INTO meetings (title, meeting_date, start_time, end_time, location, facilitator, status, summary, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            '第1回 定例会議',
            date('Y-m-d', strtotime('next monday')),
            '18:00',
            '19:30',
            '学生会館 会議室A',
            '議長',
            'scheduled',
            'ここに会議の要旨を記録します。',
            $now,
            $now,
        ]);
        $meetingId = (int)$pdo->lastInsertId();

        $stmt2 = $pdo->prepare('INSERT INTO agenda_items (meeting_id, title, description, category, status, priority, decision, result_note, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt2->execute([$meetingId, '新歓イベント準備の確認', '担当割り振りと広報物の進捗確認', '運営', 'pending', 'high', '', '', 1, $now, $now]);
        $agendaId = (int)$pdo->lastInsertId();

        $stmt3 = $pdo->prepare('INSERT INTO tasks (meeting_id, agenda_item_id, title, description, assignee, status, priority, due_date, progress, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt3->execute([$meetingId, $agendaId, 'ポスター案の最終確認', '提出前の確認を実施', '広報担当', 'todo', 'high', date('Y-m-d', strtotime('+5 days')), 0, $now, $now]);

        log_activity('seed', 'meeting', $meetingId, '初期サンプルデータを投入しました。');
    }
}
