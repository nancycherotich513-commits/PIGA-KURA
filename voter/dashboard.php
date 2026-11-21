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

render_head('Voter dashboard', 'voter');
?>
<div class="voter-dashboard">
    <section class="voter-welcome">
        <div>
            <p class="voter-eyebrow">Voter portal</p>
            <h1>Hi, <?= e($firstName) ?></h1>
            <p class="voter-subtitle">Signed in as <?= e($voter['email']) ?> · <?= e($nowLabel) ?></p>
        </div>
        <div class="voter-welcome-actions">
            <?php if ($primaryElection && $status !== 'closed'): ?>
                <a class="btn voter-primary-btn" href="<?= e($ballotUrl) ?>"><?= $status === 'live' ? 'Continue voting' : 'View election' ?></a>
            <?php endif; ?>
            <?php if ($hasReceipt): ?>
                <a class="btn voter-white-btn" href="<?= e(url('voter/receipt.php?id=' . (int)$primaryElection['id'])) ?>">View receipt</a>
            <?php elseif ($receipts): ?>
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
        <?php if ($status === 'live'): ?>
            <a class="voter-live-banner" href="<?= e($ballotUrl) ?>">
                <span class="voter-live-dot" aria-hidden="true"></span>
                <span><strong>Voting is open.</strong> Closes <?= e(format_when($primaryElection['end_at'])) ?>. You have <?= $leftCount ?> position<?= $leftCount === 1 ? '' : 's' ?> left to vote.</span>
                <span class="voter-banner-button"><span>Go to my ballot</span><span aria-hidden="true">→</span></span>
            </a>
        <?php elseif ($status === 'upcoming'): ?>
            <div class="voter-live-banner is-upcoming"><span class="voter-live-dot" aria-hidden="true"></span><span><strong>Voting opens <?= e(format_when($primaryElection['start_at'])) ?>.</strong> Your ballot will be available then.</span><a class="voter-banner-link" href="#election-info">Election details →</a></div>
        <?php else: ?>
            <div class="voter-live-banner is-closed"><span class="voter-live-dot" aria-hidden="true"></span><span><strong>This election is closed.</strong> Your participation status is shown below.</span>
                <?php if (!empty($primaryElection['results_published_at'])): ?><a class="voter-banner-link" href="<?= e(url('voter/results.php?id=' . (int)$primaryElection['id'])) ?>">View published results →</a><?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="voter-stats" aria-label="Election summary">
            <article class="voter-stat stat-orange"><span class="voter-stat-icon" aria-hidden="true">◷</span><strong><?= $leftCount ?></strong><span>Positions left to vote</span></article>
            <article class="voter-stat stat-green"><span class="voter-stat-icon" aria-hidden="true">✓</span><strong><?= $votedCount ?></strong><span>Positions voted</span></article>
            <article class="voter-stat stat-purple"><span class="voter-stat-icon" aria-hidden="true">☷</span><strong<?= $remainingSeconds !== null ? ' data-countdown-seconds="' . (int)$remainingSeconds . '"' : '' ?>><?= e($timeRemaining) ?></strong><span><?= e($timeRemainingLabel) ?></span></article>
            <article class="voter-stat stat-lilac"><span class="voter-stat-icon" aria-hidden="true">◉</span><strong><?= e(ucfirst($status)) ?></strong><span>Election status</span></article>
        </section>

        <div class="voter-dashboard-columns">
            <section class="voter-panel" id="ballot">
                <div class="voter-panel-heading"><h2>Your ballot</h2>
                    <?php if ($status !== 'closed'): ?><a href="<?= e($ballotUrl) ?>"><?= $status === 'live' ? 'Continue →' : 'View →' ?></a><?php endif; ?>
                </div>
                <?php if (!$positions): ?><p class="voter-empty">No positions have been added to this election yet.</p><?php endif; ?>
                <?php foreach ($positions as $position):
                    $positionVoted = (int)$position['my_votes'] > 0;
                    $positionState = $positionVoted ? 'Voted' : ($status === 'closed' ? 'Closed' : ($votedCount > 0 ? 'In progress' : 'Not started'));
                ?>
                    <div class="voter-ballot-row">
                        <div><strong><?= e($position['title']) ?></strong><small>Choose 1 candidate</small></div>
                        <span class="voter-position-state <?= $positionVoted ? 'state-done' : ($status === 'closed' ? 'state-closed' : ($votedCount ? 'state-progress' : '')) ?>"><?= e($positionState) ?></span>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="voter-panel voter-info-panel" id="election-info">
                <div class="voter-panel-heading"><h2>Election info</h2><span><?= e($primaryElection['title']) ?></span></div>
                <div class="voter-info-row"><span>Opens</span><strong><?= e(format_when($primaryElection['start_at'])) ?></strong></div>
                <div class="voter-info-row"><span>Closes</span><strong><?= e(format_when($primaryElection['end_at'])) ?></strong></div>
                <div class="voter-info-row"><span>Election status</span><strong class="voter-info-status status-<?= e($status) ?>"><?= e(ucfirst($status)) ?></strong></div>
                <?php if (!empty($primaryElection['description'])): ?><p class="voter-election-description"><?= nl2br(e($primaryElection['description'])) ?></p><?php else: ?><p class="voter-election-description">Election schedule and ballot information are shown here.</p><?php endif; ?>
                <a class="voter-info-help" href="<?= e(url('auth/account_help.php')) ?>">Need help accessing your account? <span>Open →</span></a>
            </section>
        </div>

        <section class="voter-panel voter-lower-panel" id="results">
            <div class="voter-panel-heading"><h2>Other elections &amp; results</h2><span>Available elections and published results</span></div>
            <?php $otherElections = array_slice($elections, 1); ?>
            <?php if (!$otherElections): ?><p class="voter-empty">This is your only election at the moment.</p><?php endif; ?>
            <?php foreach ($otherElections as $other): $otherStatus = election_status($other); ?>
                <div class="voter-election-row">
                    <div><strong><?= e($other['title']) ?></strong><small><?= e(ucfirst($otherStatus)) ?> · <?= (int)$other['my_votes'] ?> of <?= (int)$other['position_count'] ?> positions voted</small></div>
                    <?php if ($otherStatus === 'closed' && !empty($other['results_published_at'])): ?><a href="<?= e(url('voter/results.php?id=' . (int)$other['id'])) ?>">Results →</a>
                    <?php elseif ($otherStatus !== 'closed'): ?><a href="<?= e(url('voter/election.php?id=' . (int)$other['id'])) ?>">Open ballot →</a><?php else: ?><span class="voter-muted-label">Results not published</span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="voter-panel voter-lower-panel" id="receipts">
            <div class="voter-panel-heading"><h2>Your receipts</h2><span>Participation records only</span></div>
            <?php if (!$receipts): ?><p class="voter-empty">A receipt will be available here after you submit a ballot.</p><?php endif; ?>
            <?php foreach ($receipts as $receipt): ?>
                <div class="voter-election-row"><div><strong><?= e($receipt['title']) ?></strong><small>Issued <?= e(format_when($receipt['created_at'])) ?> · <?= e($receipt['receipt_code']) ?></small></div><a href="<?= e(url('voter/receipt.php?id=' . (int)$receipt['election_id'])) ?>">View receipt →</a></div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>
<?php render_foot(); ?>
