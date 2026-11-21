<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();

$filters = ['pending', 'approved', 'rejected', 'all'];
$requested = $_GET['status'] ?? 'pending';
$filter = in_array($requested, $filters, true) ? $requested : 'pending';
$self = 'admin/voters.php?status=' . $filter;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $voterId = (int)($_POST['voter_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';

    if ($voterId && in_array($newStatus, ['approved', 'rejected', 'pending'], true)) {
        $lookup = db()->prepare('SELECT full_name, email FROM voters WHERE id = ?');
        $lookup->execute([$voterId]);
        $voterRecord = $lookup->fetch();
        $approvedAt = $newStatus === 'approved' ? to_db(now_local()) : null;
        db()->prepare('UPDATE voters SET status = ?, approved_at = ? WHERE id = ?')
            ->execute([$newStatus, $approvedAt, $voterId]);
        if ($voterRecord) audit_log('changed_voter_status', 'voter', $voterId, $voterRecord['email'] . ' → ' . $newStatus);
        flash('success', 'Voter status updated to ' . $newStatus . '.');
    } else {
        flash('error', 'That request was not valid.');
    }
    redirect($self);
}

$counts = db()->query('SELECT status, COUNT(*) FROM voters GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$counts['all'] = array_sum($counts);

if ($filter === 'all') {
    $rows = db()->query('SELECT * FROM voters ORDER BY created_at DESC')->fetchAll();
} else {
    $stmt = db()->prepare('SELECT * FROM voters WHERE status = ? ORDER BY created_at DESC');
    $stmt->execute([$filter]);
    $rows = $stmt->fetchAll();
}

render_head('Voters', 'admin');
?>
<div class="page-head">
    <div>
        <h1>Voter approvals</h1>
        <p>Only approved voters can log in to see elections and cast ballots.</p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/export.php?type=voters')) ?>">Export CSV</a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/export.php?type=voters&format=pdf')) ?>">Print / PDF</a>
    </div>
</div>

<div class="tabs tabs-wide">
    <?php foreach ($filters as $f): ?>
        <a href="<?= e(url('admin/voters.php?status=' . $f)) ?>" class="<?= $filter === $f ? 'active' : '' ?>">
            <?= ucfirst($f) ?> <span class="count"><?= (int)($counts[$f] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<section class="card">
    <?php if (!$rows): ?>
        <p class="muted">No voters in this list.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Phone</th><th>Registered</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['full_name']) ?></strong></td>
                    <td><?= e($r['email']) ?></td>
                    <td><?= e($r['phone'] ?? '—') ?></td>
                    <td><?= e(format_when($r['created_at'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="actions">
                        <?php if ($r['status'] !== 'approved'): ?>
                            <form method="post" action="<?= e(url($self)) ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="voter_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="status" value="approved">
                                <button class="btn btn-primary btn-sm">Approve</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($r['status'] !== 'rejected'): ?>
                            <form method="post" action="<?= e(url($self)) ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="voter_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="status" value="rejected">
                                <button class="btn btn-danger btn-sm" data-confirm="Reject or suspend this voter?">Reject</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php
render_foot();
