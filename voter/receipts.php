<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = require_voter();
$stmt = db()->prepare(
    'SELECT r.receipt_code, r.created_at, e.id AS election_id, e.title, e.start_at, e.end_at, e.voting_override
     FROM participation_receipts r
     JOIN elections e ON e.id = r.election_id
     WHERE r.voter_id = ?
     ORDER BY r.created_at DESC'
);
$stmt->execute([$voter['id']]);
$receipts = $stmt->fetchAll();

render_head('My receipts', 'voter');
?>
<div class="page-head"><div><h1>My receipts</h1><p>Participation records only. Receipts never identify your candidate choices.</p></div></div>
<?php if (!$receipts): ?>
    <section class="card empty"><h2>No receipts yet</h2><p>A receipt will appear here after you submit a ballot.</p></section>
<?php else: ?>
    <div class="grid-cards">
        <?php foreach ($receipts as $receipt): ?>
            <article class="election-card">
                <div class="election-top"><?= status_badge(election_status($receipt)) ?></div>
                <h3><?= e($receipt['title']) ?></h3>
                <p class="muted">Issued <?= e(format_when($receipt['created_at'])) ?></p>
                <div class="receipt-code-label">Participation receipt</div>
                <code class="receipt-code"><?= e($receipt['receipt_code']) ?></code>
                <div class="card-actions"><a class="btn btn-ghost btn-sm" href="<?= e(url('voter/receipt.php?id=' . (int)$receipt['election_id'])) ?>">View receipt</a></div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_foot(); ?>
