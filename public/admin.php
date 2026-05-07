<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publish_at_raw = trim($_POST['publish_at'] ?? '');
    $publish_at = null;

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } elseif ($publish_at_raw !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $publish_at_raw);
        if ($dt === false) {
            $error = 'Invalid publish date.';
        } else {
            $publish_at = $dt->format('Y-m-d H:i:s');
        }
    }

    if (!$error) {
        $readable_id = generate_readable_id($title, (int) date('Y'));
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at, readable_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publish_at, $readable_id]);
        $docId = (int) db()->lastInsertId();

        $details = ['title' => $title, 'readable_id' => $readable_id];
        if ($publish_at) {
            $details['publish_at'] = $publish_at;
        }
        audit_log('create_document', 'document', $docId, $details);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$q = trim($_GET['q'] ?? '');
$docs = search_documents($q);

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <?php
    $cd_stmt = db()->prepare('SELECT readable_id FROM documents WHERE id = ?');
    $cd_stmt->execute([(int) $_GET['created']]);
    $cd = $cd_stmt->fetch();
    $readable_label = ($cd && $cd['readable_id']) ? h($cd['readable_id']) : '#' . (int) $_GET['created'];
    ?>
    <div class="banner banner-success">Document created: <strong><?= $readable_label ?></strong></div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at <span class="optional">(leave blank to publish immediately)</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <div class="card-title-row">
        <h2 class="card-title">Documents</h2>
        <form method="get" class="search-form">
            <input type="search" name="q" placeholder="Search by title…" value="<?= h($q) ?>" class="search-input">
            <button type="submit" class="btn btn-sm">Search</button>
            <?php if ($q !== ''): ?>
                <a href="/admin.php" class="btn-link">Clear</a>
            <?php endif ?>
        </form>
    </div>
    <?php if (empty($docs)): ?>
        <p class="empty"><?= $q !== '' ? 'No documents match "' . h($q) . '".' : 'No documents yet.' ?></p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Readable ID</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Publish At</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td class="readable-id"><?= $d['readable_id'] ? h($d['readable_id']) : '<span class="muted">—</span>' ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><?= $d['publish_at'] ? h($d['publish_at']) : '<em>immediate</em>' ?></td>
                        <td><a href="/share.php?doc=<?= $d['readable_id'] ? h($d['readable_id']) : (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
