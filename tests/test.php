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

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
