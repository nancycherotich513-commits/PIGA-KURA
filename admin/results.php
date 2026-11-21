<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$electionId = (int)($_GET['id'] ?? 0);
$election = $electionId ? find_election($electionId) : null;

if (!$election) {
    flash('error', 'Election not found.');
    redirect('admin/elections.php');
}

$status = election_status($election);
$self = 'admin/results.php?id=' . $electionId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $action = $_POST['action'] ?? '';
    if ($action === 'publish' && $status === 'closed') {
        db()->prepare('UPDATE elections SET results_published_at = ? WHERE id = ?')
            ->execute([to_db(now_local()), $electionId]);
        audit_log('published_results', 'election', $electionId, $election['title']);
        flash('success', 'Final results are now visible to voters.');
    } elseif ($action === 'unpublish' && !empty($election['results_published_at'])) {
        db()->prepare('UPDATE elections SET results_published_at = NULL WHERE id = ?')->execute([$electionId]);
        audit_log('unpublished_results', 'election', $electionId, $election['title']);
        flash('success', 'Results are no longer visible to voters.');
    } else {
        flash('error', 'Results can only be published after voting closes.');
    }
    redirect($self);
}

$results = election_results($electionId);
$published = !empty($election['results_published_at']);
$finalResults = $status === 'closed';

render_head('Results: ' . $election['title'], 'admin');
?>
<div class="page-head">
    <div>
        <h1>Results: <?= e($election['title']) ?></h1>
        <p>
            <?php if ($status === 'live'): ?>
                Live counts. These numbers will keep changing until voting closes on <?= e(format_when($election['end_at'])) ?>.
            <?php elseif ($status === 'upcoming'): ?>
                Voting has not started yet, so there are no votes to show.
            <?php else: ?>
                Final results. Voting closed on <?= e(format_when($election['end_at'])) ?>.
            <?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <?= status_badge($status) ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/export.php?type=results&election_id=' . $electionId)) ?>">Export CSV</a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/export.php?type=results&format=pdf&election_id=' . $electionId)) ?>">Print / PDF</a>
        <?php if ($published): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><button class="btn btn-ghost btn-sm">Unpublish results</button></form>
        <?php elseif ($status === 'closed'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><button class="btn btn-primary btn-sm">Publish to voters</button></form>
        <?php else: ?>
            <span class="muted">Publish after voting closes</span>
        <?php endif; ?>
    </div>
</div>
<div class="notice">Voter visibility: <strong><?= $published ? 'Published' : 'Hidden until you publish' ?></strong></div>
<?php if ($finalResults): ?>
<section class="card winner-summary">
    <h2>Final winners by position</h2>
    <div class="winner-summary-grid">
        <?php foreach ($results['positions'] as $position): ?>
            <article class="winner-summary-item">
                <span><?= e($position['title']) ?></span>
                <?php if ((int)$position['total'] === 0): ?>
                    <strong class="no-winner">No votes cast</strong>
                <?php elseif ($position['is_tie']): ?>
                    <strong class="tie-winner">Tie: <?= e(implode(' & ', $position['winners'])) ?></strong>
                    <small><?= (int)$position['candidates'][0]['votes'] ?> votes each</small>
                <?php else: ?>
                    <strong><?= e($position['winners'][0]) ?></strong>
                    <small><?= (int)$position['candidates'][0]['votes'] ?> vote<?= (int)$position['candidates'][0]['votes'] === 1 ? '' : 's' ?> · <?= e((string)$position['candidates'][0]['percent']) ?>%</small>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php render_results_block($results, $finalResults); ?>
<?php
render_foot();
