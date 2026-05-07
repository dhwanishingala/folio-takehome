<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        run_migrations();
    }
    return $pdo;
}

function run_migrations(): void {
    // db() is already initialized when this is called from inside db()
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS applied_migrations (
            version TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $files = glob(__DIR__ . '/../migrations/*.php');
    if (!$files) {
        return;
    }
    sort($files);

    foreach ($files as $file) {
        $migration = require $file;
        $version = $migration['version'];

        $stmt = $pdo->prepare('SELECT 1 FROM applied_migrations WHERE version = ?');
        $stmt->execute([$version]);
        if ($stmt->fetchColumn()) {
            continue;
        }

        $pdo->exec($migration['up']);
        $pdo->prepare('INSERT INTO applied_migrations (version) VALUES (?)')->execute([$version]);
    }
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
