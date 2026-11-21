<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

require_admin();
$tables = ['admins', 'voters', 'elections', 'positions', 'candidates', 'votes', 'participation_receipts', 'admin_audit_log', 'account_help_requests'];
$self = 'admin/backup.php';

if (isset($_GET['download'])) {
    audit_log('downloaded_backup', 'database');
    $data = ['format' => 'votehub-backup', 'version' => 1, 'created_at' => to_db(now_local()), 'tables' => []];
    foreach ($tables as $table) $data['tables'][$table] = db()->query("SELECT * FROM `$table`")->fetchAll();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="votehub-backup-' . date('Ymd-His') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $file = $_FILES['backup_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 25 * 1024 * 1024) {
        flash('error', 'Choose a valid VoteHub backup file smaller than 25 MB.');
        redirect($self);
    }
    try {
        $backup = json_decode((string)file_get_contents($file['tmp_name']), true, 64, JSON_THROW_ON_ERROR);
        if (($backup['format'] ?? '') !== 'votehub-backup' || !is_array($backup['tables'] ?? null)) {
            throw new RuntimeException('This is not a VoteHub backup.');
        }
        foreach ($tables as $table) {
            if (!isset($backup['tables'][$table]) || !is_array($backup['tables'][$table])) {
                throw new RuntimeException('The backup is missing the ' . $table . ' table.');
            }
        }
        $pdo = db();
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_reverse($tables) as $table) $pdo->exec("DELETE FROM `$table`");
            foreach ($tables as $table) {
                $columns = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
                foreach ($backup['tables'][$table] as $record) {
                    if (!is_array($record)) throw new RuntimeException('The backup contains an invalid row.');
                    $row = array_intersect_key($record, array_flip($columns));
                    if (!$row) continue;
                    $names = array_keys($row);
                    $quoted = implode(',', array_map(fn($name) => '`' . str_replace('`', '', $name) . '`', $names));
                    $marks = implode(',', array_fill(0, count($names), '?'));
                    $insert = $pdo->prepare("INSERT INTO `$table` ($quoted) VALUES ($marks)");
                    $insert->execute(array_values($row));
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        // Keep a restore event even when the restored audit history predates this action.
        $pdo->prepare('INSERT INTO admin_audit_log (admin_id, action, entity_type, details) VALUES (NULL, ?, ?, ?)')
            ->execute(['restored_backup', 'database', 'Restore initiated by admin #' . $adminId]);
        session_regenerate_id(true);
        flash('success', 'Backup restored. Sign in again if the restored admin accounts differ from this session.');
        redirect('index.php?tab=admin-login');
    } catch (Throwable $ex) {
        flash('error', 'Restore failed: ' . $ex->getMessage());
        redirect($self);
    }
}

render_head('Data safety', 'admin');
?>
<div class="page-head"><div><h1>Data safety</h1><p>Download a complete backup or restore VoteHub from a previous backup.</p></div></div>
<div class="two-col safety-grid">
    <section class="card"><h2>Create a backup</h2><p class="muted">Includes administrator accounts, voters, elections, ballots, receipts, audit history, and support requests.</p><a class="btn btn-primary" href="<?= e(url('admin/backup.php?download=1')) ?>">Download JSON backup</a></section>
    <section class="card"><h2>Restore a backup</h2><p class="muted">Restore replaces all current VoteHub records with the records in the selected backup. Save a backup of the current data first.</p>
        <form method="post" enctype="multipart/form-data" class="form" data-confirm="Restore this backup? It will replace current voters, elections, ballots, accounts, receipts and logs.">
            <?= csrf_field() ?><label>VoteHub backup file<input type="file" name="backup_file" accept="application/json,.json" required></label>
            <button class="btn btn-danger">Restore backup</button>
        </form>
    </section>
</div>
<?php render_foot(); ?>
