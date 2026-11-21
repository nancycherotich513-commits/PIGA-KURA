<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = require_voter();
$dashboard = 'voter/dashboard.php';
$electionId = (int)($_GET['id'] ?? 0);
$election = $electionId ? find_election($electionId) : null;

if ($voter['status'] !== 'approved' || !$election) {
    redirect($dashboard);
}
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
