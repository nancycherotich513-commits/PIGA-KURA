<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = require_voter();
$dashboard = 'voter/dashboard.php';
$electionId = (int)($_GET['id'] ?? 0);

if ($voter['status'] !== 'approved') {
    redirect($dashboard);
}

if (!$electionId) {
    $publishedElections = db()->query(
        'SELECT id, title, end_at FROM elections WHERE results_published_at IS NOT NULL ORDER BY end_at DESC'
    )->fetchAll();
    render_head('Published results', 'voter');
    ?>
    <div class="page-head"><div><h1>Published results</h1><p>Results appear here after the administrator publishes them.</p></div></div>
    <?php if (!$publishedElections): ?>
        <section class="card empty"><h2>No results published yet</h2><p>Check back after the election administrator releases the results.</p></section>
    <?php else: ?>
        <div class="grid-cards">
            <?php foreach ($publishedElections as $published): ?>
                <article class="election-card">
                    <div class="election-top"><?= status_badge('closed') ?></div>
                    <h3><?= e($published['title']) ?></h3>
                    <p class="muted">Closed <?= e(format_when($published['end_at'])) ?></p>
                    <div class="card-actions"><a class="btn btn-primary btn-sm" href="<?= e(url('voter/results.php?id=' . (int)$published['id'])) ?>">View results</a></div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php render_foot(); ?>
    <?php exit;
}

$election = find_election($electionId);
if (!$election) redirect($dashboard);
if (election_status($election) !== 'closed' || empty($election['results_published_at'])) {
    flash('info', 'Final results will appear here after voting closes and the administrator publishes them.');
    redirect($dashboard);
}

render_head('Results: ' . $election['title'], 'voter');
?>
<div class="page-head">
    <div>
        <h1>Results: <?= e($election['title']) ?></h1>
        <p>Final results after voting closed on <?= e(format_when($election['end_at'])) ?></p>
    </div>
    <?= status_badge('closed') ?>
</div>
<?php render_results_block(election_results($electionId)); ?>
<?php
render_foot();
