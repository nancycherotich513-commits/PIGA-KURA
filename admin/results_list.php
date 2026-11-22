<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

require_admin();
$elections = db()->query(
    'SELECT e.id, e.title, e.start_at, e.end_at, e.results_published_at, e.voting_override,
        (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS vote_count,
        (SELECT COUNT(DISTINCT v.voter_id) FROM votes v WHERE v.election_id = e.id) AS voter_count
     FROM elections e ORDER BY e.start_at DESC'
)->fetchAll();

render_head('Election results', 'admin');
?>
<div class="page-head">
    <div><h1>Election results</h1><p>Open any election to review its tally, winners, turnout, and publication status.</p></div>
</div>
<?php if (!$elections): ?>
    <section class="card empty"><h2>No elections yet</h2><p>Create an election before reviewing results.</p></section>
<?php else: ?>
    <div class="election-grid">
        <?php foreach ($elections as $election): $status = election_status($election); ?>
            <article class="election-card">
                <div class="election-card-head"><span class="badge badge-<?= e($status) ?>"><?= e(ucfirst($status)) ?></span><span class="muted"><?= !empty($election['results_published_at']) ? 'Published to voters' : 'Hidden from voters' ?></span></div>
                <h3><?= e($election['title']) ?></h3>
                <p><?= e(format_when($election['start_at'])) ?> · <?= e(format_when($election['end_at'])) ?></p>
                <p><?= (int)$election['voter_count'] ?> voter<?= (int)$election['voter_count'] === 1 ? '' : 's' ?> · <?= (int)$election['vote_count'] ?> vote<?= (int)$election['vote_count'] === 1 ? '' : 's' ?></p>
                <a class="btn btn-primary btn-sm" href="<?= e(url('admin/results.php?id=' . (int)$election['id'])) ?>">View results</a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_foot(); ?>
