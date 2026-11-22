<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = require_voter();
$isApproved = $voter['status'] === 'approved';
$elections = [];
$primaryElection = null;
$positions = [];
$receipts = [];

if ($isApproved) {
    $stmt = db()->prepare(
        'SELECT e.*,
            (SELECT COUNT(*) FROM positions p WHERE p.election_id = e.id) AS position_count,
            (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id AND v.voter_id = ?) AS my_votes,
            (SELECT COUNT(*) FROM participation_receipts r WHERE r.election_id = e.id AND r.voter_id = ?) AS has_receipt
         FROM elections e
         ORDER BY e.start_at DESC'
    );
    $stmt->execute([$voter['id'], $voter['id']]);
    $elections = $stmt->fetchAll();

    // Put the election needing the voter's attention first: live, then upcoming, then latest closed.
    usort($elections, static function (array $a, array $b): int {
        $rank = ['live' => 0, 'upcoming' => 1, 'closed' => 2];
        $byStatus = ($rank[election_status($a)] ?? 3) <=> ($rank[election_status($b)] ?? 3);
        return $byStatus ?: strcmp($b['start_at'], $a['start_at']);
    });
    $primaryElection = $elections[0] ?? null;
    $requestedElectionId = (int)($_GET['election_id'] ?? 0);
    if ($requestedElectionId > 0) {
        foreach ($elections as $listedElection) {
            if ((int)$listedElection['id'] === $requestedElectionId) {
                $primaryElection = $listedElection;
                break;
            }
        }
    }

    if ($primaryElection) {
        $stmt = db()->prepare(
            'SELECT p.id, p.title, COUNT(v.id) AS my_votes
             FROM positions p
             LEFT JOIN votes v ON v.position_id = p.id AND v.election_id = p.election_id AND v.voter_id = ?
             WHERE p.election_id = ?
             GROUP BY p.id, p.title
             ORDER BY p.id'
        );
        $stmt->execute([$voter['id'], $primaryElection['id']]);
        $positions = $stmt->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT r.receipt_code, r.created_at, e.id AS election_id, e.title
         FROM participation_receipts r JOIN elections e ON e.id = r.election_id
         WHERE r.voter_id = ? ORDER BY r.created_at DESC'
    );
    $stmt->execute([$voter['id']]);
    $receipts = $stmt->fetchAll();
}

$firstName = explode(' ', trim($voter['full_name']))[0];
$status = $primaryElection ? election_status($primaryElection) : null;
$positionCount = $primaryElection ? (int)$primaryElection['position_count'] : 0;
$votedCount = $primaryElection ? (int)$primaryElection['my_votes'] : 0;
$leftCount = $status === 'closed' ? 0 : max(0, $positionCount - $votedCount);
$timeRemaining = '—';
$timeRemainingLabel = 'Voting window ended';
$remainingSeconds = null;
if ($primaryElection && $status === 'live') {
    $targetAt = new DateTimeImmutable($primaryElection['end_at'], timezone());
    $remainingSeconds = max(0, $targetAt->getTimestamp() - now_local()->getTimestamp());
    $timeRemaining = (string)(int)ceil($remainingSeconds / 60);
    $timeRemainingLabel = 'Minutes until voting closes';
} elseif ($primaryElection && $status === 'upcoming') {
    $targetAt = new DateTimeImmutable($primaryElection['start_at'], timezone());
    $remainingSeconds = max(0, $targetAt->getTimestamp() - now_local()->getTimestamp());
    $timeRemaining = (string)(int)ceil($remainingSeconds / 60);
    $timeRemainingLabel = 'Minutes until voting opens';
}
$nowLabel = (new DateTimeImmutable('now', timezone()))->format('l j F Y');
$ballotUrl = $primaryElection ? url('voter/election.php?id=' . (int)$primaryElection['id']) : '#ballot';
$hasReceipt = $primaryElection && (int)$primaryElection['has_receipt'] > 0;
$primaryReceipt = null;
if ($primaryElection) {
    foreach ($receipts as $receipt) {
        if ((int)$receipt['election_id'] === (int)$primaryElection['id']) {
            $primaryReceipt = $receipt;
            break;
        }
    }
}
$ballotComplete = $positionCount > 0 && $votedCount >= $positionCount;
$showReceiptDashboard = $hasReceipt && ($ballotComplete || $status === 'closed');
$closingLabel = $primaryElection ? (new DateTimeImmutable($primaryElection['end_at'], timezone()))->format('D j M, g:i A') : '';
$openingLabel = $primaryElection ? (new DateTimeImmutable($primaryElection['start_at'], timezone()))->format('D j M, g:i A') : '';
$voterStatusLabel = $status === 'upcoming' ? 'Not voted' : ($status === 'live' ? 'Voting' : ($votedCount > 0 ? 'Voted' : 'Not voted'));
$keyElectionTime = $status === 'upcoming' ? $primaryElection['start_at'] : ($primaryElection['end_at'] ?? '');
$keyElectionTimeLabel = $status === 'upcoming' ? 'Opens at' : 'Closes at';
$keyElectionDateLabel = $status === 'upcoming' ? $openingLabel : $closingLabel;
$ballotElectionName = mb_strtoupper(trim($primaryElection['title'] ?? ''));
if ($ballotElectionName !== '' && !str_contains($ballotElectionName, 'ELECTION')) {
    $ballotElectionName .= ' ELECTIONS';
}
$timeRemainingText = $remainingSeconds === null ? '—' : (intdiv($remainingSeconds, 3600) > 0
    ? intdiv($remainingSeconds, 3600) . 'h ' . intdiv($remainingSeconds % 3600, 60) . 'm'
    : max(1, (int)ceil($remainingSeconds / 60)) . 'm');

render_head('Voter dashboard', 'voter');
?>
<div class="voter-dashboard">
    <section class="voter-welcome">
        <div>
            <p class="voter-eyebrow">Voter portal</p>
            <h1>Hi, <?= e($firstName) ?></h1>
            <p class="voter-subtitle"><?php if ($primaryElection): ?><?= e($primaryElection['title']) ?> · <?= $status === 'live' ? 'Voting is open until ' . e($closingLabel) : ($status === 'upcoming' ? 'Voting opens ' . e($openingLabel) : 'Voting closed on ' . e($closingLabel)) ?><?php else: ?>Voter portal · Signed in as <?= e($voter['email']) ?> · <?= e($nowLabel) ?><?php endif; ?></p>
        </div>
        <div class="voter-welcome-actions">
            <?php if ($showReceiptDashboard): ?>
                <a class="btn voter-white-btn" href="<?= e(url('voter/receipt.php?id=' . (int)$primaryElection['id'])) ?>">View receipt</a>
            <?php elseif (!$primaryElection && $receipts): ?>
                <a class="btn voter-white-btn" href="#receipts">View receipts</a>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$isApproved): ?>
        <section class="voter-notice <?= $voter['status'] === 'rejected' ? 'is-rejected' : '' ?>">
            <span class="voter-notice-icon" aria-hidden="true">!</span>
            <div><strong><?= $voter['status'] === 'rejected' ? 'Your account was not approved.' : 'Your account is awaiting approval.' ?></strong>
                <p><?= $voter['status'] === 'rejected' ? 'Please contact the election administrator for assistance.' : 'Once approved, your elections and ballot will appear here.' ?></p></div>
            <a href="<?= e(url('auth/account_help.php')) ?>">Account help →</a>
        </section>
    <?php elseif (!$primaryElection): ?>
        <section class="voter-notice"><span class="voter-notice-icon" aria-hidden="true">i</span><div><strong>No elections are available yet.</strong><p>Check back here when an election has been scheduled.</p></div></section>
    <?php else: ?>
        <?php if ($showReceiptDashboard): ?>
            <section class="voter-receipt-summary" id="receipts">
                <span class="voter-receipt-check" aria-hidden="true">✓</span>
                <div class="voter-receipt-main"><h2>You voted in this election</h2><p>Your choices are recorded and kept private.</p>
                    <label for="dashboard-receipt-code">Receipt code</label>
                    <div class="voter-receipt-copy"><input id="dashboard-receipt-code" type="text" readonly value="<?= e($primaryReceipt['receipt_code'] ?? '') ?>" aria-label="Participation receipt code"><button class="btn voter-copy-btn" type="button" data-copy-target="#dashboard-receipt-code">Copy code</button></div>
                </div>
                <p class="voter-receipt-privacy">Nothing else to do.<br>Keep this code. It proves you took part without showing who you chose.</p>
            </section>
            <section class="voter-summary-stats is-complete" id="election-info" aria-label="Election status">
                <article><small>Voting</small><strong><?= $status === 'closed' ? 'Closed' : 'Open' ?></strong></article>
                <article><small><?= $status === 'closed' ? 'Closed on' : 'Closes on' ?></small><strong><?= e($closingLabel) ?></strong></article>
                <article><small>Results</small><strong><?= !empty($primaryElection['results_published_at']) ? 'Published' : 'Not published yet' ?></strong></article>
            </section>
            <div class="voter-dashboard-columns is-complete-columns">
                <section class="voter-panel voter-ballot-status" id="ballot"><h2>Ballot status</h2><strong class="voter-completion-count"><?= $votedCount ?> of <?= $positionCount ?></strong><b>positions recorded</b><p><?= $ballotComplete ? 'Your vote counted in every position on your ballot.' : 'Your recorded votes are private. Voting closed before all positions were completed.' ?></p></section>
                <section class="voter-panel voter-results-status" id="results"><h2>Results</h2><span class="voter-results-pill"><?= !empty($primaryElection['results_published_at']) ? 'Published' : 'Not published' ?></span>
                    <?php if (!empty($primaryElection['results_published_at'])): ?><p>Final results are available now.</p><a href="<?= e(url('voter/results.php?id=' . (int)$primaryElection['id'])) ?>">View results →</a><?php else: ?><p>Results appear here once the admin publishes them.</p><span class="voter-results-skeleton"><i></i><i></i><i></i></span><?php endif; ?>
                </section>
            </div>
        <?php else: ?>
        <?php if ($status === 'live'): ?>
            <section class="voter-ready-banner">
                <div class="voter-ready-copy"><span class="voter-ready-tag">Voting is open</span><h2><?= $votedCount > 0 ? 'Continue your ballot' : 'Your ballot is ready' ?></h2><p>Vote for one candidate in each position before voting closes.</p><div class="voter-ready-actions"><a class="btn voter-primary-btn" href="<?= e($ballotUrl) ?>"><?= $votedCount > 0 ? 'Continue voting' : 'Start voting' ?></a><small>Votes are final once you confirm.</small></div></div>
                <div class="voter-ready-countdown"><small>Time left</small><strong data-countdown-seconds="<?= (int)$remainingSeconds ?>"><?= e($timeRemainingText) ?></strong><span>until voting closes</span></div>
            </section>
        <?php else: ?>
            <section class="voter-ready-banner <?= $status === 'closed' ? 'is-ended' : 'is-upcoming' ?>">
                <div class="voter-ready-copy"><span class="voter-ready-tag"><?= $status === 'closed' ? 'Voting closed' : 'Upcoming election' ?></span><h2><?= $status === 'closed' ? 'This election has ended' : 'Your ballot is almost ready' ?></h2><p><?= $status === 'closed' ? 'Your participation receipt is available in Receipts.' : 'Voting opens ' . e(format_when($primaryElection['start_at'])) . '.' ?></p></div>
                <?php if ($status === 'upcoming'): ?><div class="voter-ready-countdown"><small>Time until opens</small><strong data-countdown-seconds="<?= (int)$remainingSeconds ?>"><?= e($timeRemainingText) ?></strong><span>until you can vote</span></div><?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="voter-summary-stats" aria-label="Election status">
            <article><small><?= $status === 'closed' ? 'Voting' : e($keyElectionTimeLabel) ?></small><strong><?= $status === 'closed' ? 'Closed' : e((new DateTimeImmutable($keyElectionTime, timezone()))->format('g:i A')) ?></strong><span><?= $status === 'closed' ? 'Voting window ended' : e($keyElectionDateLabel) ?></span></article>
            <article><small><?= $status === 'upcoming' ? 'Ballot opens in' : 'Ballot' ?></small><strong><?= $status === 'upcoming' ? e($timeRemainingText) : $leftCount ?></strong><span><?= $status === 'upcoming' ? 'Until voting begins' : 'Positions to vote' ?></span></article>
            <article><small>Your status</small><strong><?= e($voterStatusLabel) ?></strong><span><?= $status === 'live' ? 'Ballot is open' : ($status === 'upcoming' ? 'Waiting for ballot to open' : 'Election participation') ?></span></article>
            <article><small>Election</small><strong><?= e(ucfirst($status)) ?></strong><span>Election status</span></article>
        </section>

        <div class="voter-dashboard-columns">
            <section class="voter-panel" id="ballot">
                <div class="voter-panel-heading"><div><h2>Your ballot</h2><small><?= $positionCount ?> position<?= $positionCount === 1 ? '' : 's' ?> in this election</small></div><?php if ($status === 'live'): ?><a class="voter-panel-continue" href="<?= e($ballotUrl) ?>"><?= $votedCount > 0 ? 'Continue voting →' : 'Start voting →' ?></a><?php endif; ?></div>
                <?php if (!$positions): ?><p class="voter-empty">No positions have been added to this election yet.</p><?php else: ?>
                    <div class="voter-ballot-row">
                        <div><strong><?= e($ballotElectionName) ?></strong><small><?= $status === 'upcoming' ? 'Your ballot will open at ' . e(format_when($primaryElection['start_at'])) : ($status === 'closed' ? 'Voting has ended' : $positionCount . ' position' . ($positionCount === 1 ? '' : 's') . ' available to vote') ?></small></div>
                        <span class="voter-position-state <?= $status === 'upcoming' ? '' : ($status === 'closed' ? 'state-closed' : 'state-progress') ?>"><?= $status === 'upcoming' ? 'Not started' : ($status === 'live' ? 'Voting' : e($voterStatusLabel)) ?></span>
                    </div>
                <?php endif; ?>
            </section>

            <section class="voter-panel voter-info-panel" id="election-info">
                <div class="voter-panel-heading"><h2>Election info</h2><span><?= e($primaryElection['title']) ?></span></div>
                <div class="voter-info-row"><span>Opens</span><strong><?= e(format_when($primaryElection['start_at'])) ?></strong></div>
                <div class="voter-info-row"><span>Closes</span><strong><?= e(format_when($primaryElection['end_at'])) ?></strong></div>
                <div class="voter-info-row"><span>Eligibility</span><strong>Approved voter</strong></div>
                <?php if (!empty($primaryElection['description'])): ?><p class="voter-election-description"><?= nl2br(e($primaryElection['description'])) ?></p><?php else: ?><p class="voter-election-description">Election schedule and ballot information are shown here.</p><?php endif; ?>
                <a class="voter-info-help" href="<?= e(url('auth/account_help.php')) ?>">Need help accessing your account? <span>Open →</span></a>
            </section>
        </div>

        <section class="voter-panel voter-lower-panel" id="receipts">
            <div class="voter-panel-heading"><h2>Your receipts</h2><span>Participation records only</span></div>
            <?php if (!$receipts): ?><p class="voter-empty">A receipt will be available here after you submit a ballot.</p><?php endif; ?>
            <?php foreach ($receipts as $receipt): ?>
                <div class="voter-election-row"><div><strong><?= e($receipt['title']) ?></strong><small>Issued <?= e(format_when($receipt['created_at'])) ?> · <?= e($receipt['receipt_code']) ?></small></div><a href="<?= e(url('voter/receipt.php?id=' . (int)$receipt['election_id'])) ?>">View receipt →</a></div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php render_foot(); ?>
