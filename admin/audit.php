<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

require_admin();
$rows = db()->query(
    'SELECT l.*, a.email AS admin_email
     FROM admin_audit_log l LEFT JOIN admins a ON a.id = l.admin_id
     ORDER BY l.created_at DESC, l.id DESC LIMIT 300'
)->fetchAll();

render_head('Audit log', 'admin');
?>
<div class="page-head"><div><h1>Audit log</h1><p>Recent administrative changes, including who made them and when.</p></div></div>
<section class="card">
    <?php if (!$rows): ?><p class="muted">No administrative actions have been recorded yet.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>When</th><th>Administrator</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr><td><?= e(format_when($row['created_at'])) ?></td><td><?= e($row['admin_email'] ?? 'Former administrator') ?></td><td><?= e(str_replace('_', ' ', ucfirst($row['action']))) ?></td><td><?= e(ucfirst($row['entity_type'])) ?><?= $row['entity_id'] ? ' #' . (int)$row['entity_id'] : '' ?></td><td><?= e($row['details'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php render_foot(); ?>
