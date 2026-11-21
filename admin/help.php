<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

require_admin();
$self = 'admin/help.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id && in_array($status, ['open', 'resolved'], true)) {
        db()->prepare('UPDATE account_help_requests SET status = ?, resolved_at = ? WHERE id = ?')
            ->execute([$status, $status === 'resolved' ? to_db(now_local()) : null, $id]);
        audit_log($status === 'resolved' ? 'resolved_account_help' : 'reopened_account_help', 'help_request', $id);
        flash('success', $status === 'resolved' ? 'Help request marked resolved.' : 'Help request reopened.');
    }
    redirect($self);
}

$rows = db()->query('SELECT * FROM account_help_requests ORDER BY (status = \'open\') DESC, created_at DESC LIMIT 250')->fetchAll();
render_head('Account help', 'admin');
?>
<div class="page-head"><div><h1>Account help</h1><p>Respond to access requests from voters who cannot sign in.</p></div></div>
<section class="card">
<?php if (!$rows): ?><p class="muted">No account help requests yet.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Received</th><th>Voter</th><th>Reason</th><th>Message</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= e(format_when($row['created_at'])) ?></td><td><strong><?= e($row['full_name']) ?></strong><br><a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a><?php if ($row['phone']): ?><br><?= e($row['phone']) ?><?php endif; ?></td><td><?= e($row['reason']) ?></td><td><?= nl2br(e($row['message'] ?? '—')) ?></td><td><?= status_badge($row['status']) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="<?= $row['status'] === 'open' ? 'resolved' : 'open' ?>"><button class="btn btn-ghost btn-sm"><?= $row['status'] === 'open' ? 'Mark resolved' : 'Reopen' ?></button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<?php render_foot(); ?>
