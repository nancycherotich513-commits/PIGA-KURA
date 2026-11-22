<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = require_voter();
if ($voter['status'] !== 'approved') redirect('voter/dashboard.php');

$elections = db()->query(
    'SELECT e.*,
        (SELECT COUNT(*) FROM positions p WHERE p.election_id = e.id) AS position_count,
        (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count
     FROM elections e ORDER BY e.start_at DESC'
)->fetchAll();
usort($elections, static function (array $a, array $b): int {
    $rank = ['live' => 0, 'upcoming' => 1, 'closed' => 2];
    $byStatus = ($rank[election_status($a)] ?? 3) <=> ($rank[election_status($b)] ?? 3);
    return $byStatus ?: strcmp($b['start_at'], $a['start_at']);
});

$requestedId = (int)($_GET['id'] ?? 0);
if ($requestedId > 0) {
    $elections = array_values(array_filter($elections, static fn(array $election): bool => (int)$election['id'] === $requestedId));
}

render_head('Election info', 'voter');
?>
<div class="page-head"><div><h1>Election info</h1><p>Election schedules, status, positions, and candidate totals.</p></div></div>
<?php if (!$elections): ?>
    <section class="card empty"><h2>No election information available</h2><p>There are no elections to show.</p></section>
<?php else: ?>
    <div class="grid-cards">
        <?php foreach ($elections as $election): $status = election_status($election); $electionName = mb_strtoupper(trim($election['title'])); if (!str_contains($electionName, 'ELECTION')) $electionName .= ' ELECTIONS'; ?>
            <article class="election-card">
                <div class="election-top"><?= status_badge($status) ?></div>
                <h3><?= e($electionName) ?></h3>
                <p><strong>Opens:</strong> <?= e(format_when($election['start_at'])) ?></p>
                <p><strong>Closes:</strong> <?= e(format_when($election['end_at'])) ?></p>
                <p><strong>Status:</strong> <?= e(ucfirst($status)) ?></p>
                <p><?= (int)$election['position_count'] ?> position<?= (int)$election['position_count'] === 1 ? '' : 's' ?> · <?= (int)$election['candidate_count'] ?> candidate<?= (int)$election['candidate_count'] === 1 ? '' : 's' ?></p>
                <?php if ($status === 'live' || $status === 'upcoming'): ?><div class="card-actions"><a class="btn btn-primary btn-sm" href="<?= e(url('voter/election.php?id=' . (int)$election['id'])) ?>"><?= $status === 'live' ? 'Start voting' : 'View ballot status' ?></a></div><?php elseif (!empty($election['results_published_at'])): ?><div class="card-actions"><a class="btn btn-ghost btn-sm" href="<?= e(url('voter/results.php?id=' . (int)$election['id'])) ?>">View published results</a></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_foot(); ?>
