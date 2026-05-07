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

function search_documents(string $q): array {
    $q = trim($q);
    if ($q === '') {
        return db()->query('
            SELECT d.*, s.name AS creator_name
            FROM documents d
            JOIN staff s ON s.id = d.created_by
            ORDER BY d.created_at DESC
        ')->fetchAll();
    }

    // FTS5 trigram: indexed, handles substrings at scale.
    // Wrap in quotes so the query is treated as a literal phrase, not FTS5 operators.
    try {
        $fts_q = '"' . str_replace('"', '""', $q) . '"';
        $stmt = db()->prepare('
            SELECT d.*, s.name AS creator_name
            FROM documents d
            JOIN staff s ON s.id = d.created_by
            WHERE d.id IN (SELECT rowid FROM documents_fts WHERE documents_fts MATCH ?)
            ORDER BY d.created_at DESC
        ');
        $stmt->execute([$fts_q]);
        $results = $stmt->fetchAll();
        if (!empty($results)) {
            return $results;
        }
    } catch (\PDOException $e) {
        // FTS unavailable or bad query syntax — fall through to fuzzy
    }

    // Fuzzy fallback: word-level levenshtein for single-char typos.
    // Only kicks in when FTS returns nothing.
    $all = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();

    $q_lower = strtolower($q);
    // Allow more edits for longer queries; short queries must be exact
    $threshold = strlen($q_lower) >= 8 ? 2 : (strlen($q_lower) >= 4 ? 1 : 0);

    return array_values(array_filter($all, function ($doc) use ($q_lower, $threshold) {
        foreach (preg_split('/\s+/', strtolower($doc['title'])) as $word) {
            if (levenshtein($word, $q_lower) <= $threshold) {
                return true;
            }
        }
        return false;
    }));
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
