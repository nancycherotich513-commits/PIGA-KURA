<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = require_voter();
$electionId = (int)($_GET['id'] ?? 0);
$election = $electionId ? find_election($electionId) : null;
if (!$election || $voter['status'] !== 'approved') redirect('voter/dashboard.php');

$stmt = db()->prepare('SELECT receipt_code, created_at FROM participation_receipts WHERE election_id = ? AND voter_id = ?');
$stmt->execute([$electionId, $voter['id']]);
$receipt = $stmt->fetch();
if (!$receipt) {
    flash('info', 'No participation receipt is recorded for this election.');
    redirect('voter/dashboard.php');
}

render_head('Participation receipt', 'voter');
?>
<div class="page-head"><div><h1>Participation receipt</h1><p><?= e($election['title']) ?> · Issued <?= e(format_when($receipt['created_at'])) ?></p></div><?= status_badge(election_status($election)) ?></div>
<section class="card receipt-card"><span class="receipt-check" aria-hidden="true">✓</span><h2>Your participation is recorded</h2><p>This code proves you participated in this election. It does not identify your candidate or position choices.</p><div class="receipt-code-label">Receipt code</div><code class="receipt-code"><?= e($receipt['receipt_code']) ?></code><p class="hint">Keep this code for your records.</p><a class="btn btn-ghost" href="<?= e(url('voter/dashboard.php')) ?>">Back to my elections</a></section>
<?php render_foot(); ?>
