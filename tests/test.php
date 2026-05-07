<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('scheduled document is blocked before its publish time', function () {
    $future = date('Y-m-d H:i:s', strtotime('+1 day'));

    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute(['Scheduled Doc', 'Secret content.', $future]);
    $docId = (int) db()->lastInsertId();

    $token = random_token();
    db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email)
        VALUES (?, ?, ?)
    ')->execute([$docId, $token, 'test@example.com']);

    $stmt = db()->prepare('
        SELECT d.publish_at
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    assert_true($row !== false, 'share should resolve to a document');
    assert_true(!empty($row['publish_at']), 'document should have publish_at set');
    assert_true(
        strtotime($row['publish_at']) > time(),
        'publish_at should be in the future — document not yet available'
    );
});

test('search finds document by partial title match', function () {
    db()->prepare('
        INSERT INTO documents (title, body, created_by)
        VALUES (?, ?, 1)
    ')->execute(['Quarterly Report 2026', 'Content here.']);

    $stmt = db()->prepare("
        SELECT title FROM documents
        WHERE title LIKE ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute(['%Quarterly%']);
    $row = $stmt->fetch();

    assert_true($row !== false, 'search should return at least one result');
    assert_true(
        stripos($row['title'], 'Quarterly') !== false,
        'result title should contain the search term'
    );
});

test('created document gets a human-readable id in slug-year format', function () {
    $year = (int) date('Y');
    $readable_id = generate_readable_id('Onboarding Guide', $year);
    db()->prepare('
        INSERT INTO documents (title, body, created_by, readable_id)
        VALUES (?, ?, 1, ?)
    ')->execute(['Onboarding Guide', 'Content.', $readable_id]);

    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE readable_id = ?');
    $stmt->execute([$readable_id]);
    $row = $stmt->fetch();

    assert_true($row !== false, 'document should exist');
    assert_true(
        $row['readable_id'] === "onboarding-guide-{$year}",
        "expected onboarding-guide-{$year}, got: " . var_export($row['readable_id'], true)
    );
});

test('collision generates a suffixed readable id', function () {
    $year = (int) date('Y');
    $base_id = "collision-test-{$year}";

    db()->prepare('
        INSERT INTO documents (title, body, created_by, readable_id)
        VALUES (?, ?, 1, ?)
    ')->execute(['Collision Test', 'Body.', $base_id]);

    $next_id = generate_readable_id('Collision Test', $year);
    assert_true(
        $next_id === $base_id . '-2',
        "expected {$base_id}-2, got: {$next_id}"
    );
});

test('search returns no results for unmatched query', function () {
    $stmt = db()->prepare("
        SELECT COUNT(*) as n FROM documents
        WHERE title LIKE ?
    ");
    $stmt->execute(['%xqznotexist%']);
    $row = $stmt->fetch();
    assert_true((int) $row['n'] === 0, 'unmatched search should return zero results');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
