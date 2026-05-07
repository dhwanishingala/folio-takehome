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

        $stmts = is_array($migration['up']) ? $migration['up'] : [$migration['up']];
        foreach ($stmts as $sql) {
            $pdo->exec($sql);
        }
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

function generate_readable_id(string $title, int $year): string {
    $words = preg_split('/\s+/', trim($title));
    $slug_words = array_slice($words, 0, 2);
    $slug_parts = array_map(fn($w) => preg_replace('/[^a-z0-9]+/', '', strtolower($w)), $slug_words);
    $slug_parts = array_filter($slug_parts, fn($p) => $p !== '');
    $slug = implode('-', $slug_parts) ?: 'doc';

    $base = $slug . '-' . $year;
    $candidate = $base;
    $suffix = 2;
    while (true) {
        $stmt = db()->prepare('SELECT 1 FROM documents WHERE readable_id = ?');
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            break;
        }
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
    return $candidate;
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
