<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = require_voter();
$electionId = (int)($_GET['id'] ?? $_POST['election_id'] ?? 0);
$election = $electionId ? find_election($electionId) : null;
$dashboard = 'voter/dashboard.php';
if (!$election) { flash('error', 'That election could not be found.'); redirect($dashboard); }
if ($voter['status'] !== 'approved') { flash('error', 'Your account must be approved before you can vote.'); redirect($dashboard); }

$pageUrl = 'voter/election.php?id=' . $electionId;
$status = election_status($election);
$electionName = mb_strtoupper(trim($election['title']));
if (!str_contains($electionName, 'ELECTION')) $electionName .= ' ELECTIONS';
if ($status === 'closed') {
    if (!empty($election['results_published_at'])) redirect('voter/results.php?id=' . $electionId);
    flash('info', 'Voting is closed. Results will appear after the administrator publishes them.');
    redirect($dashboard);
}

$pdo = db();
$ballot = election_ballot($electionId);
$stmt = $pdo->prepare('SELECT position_id FROM votes WHERE election_id = ? AND voter_id = ?');
$stmt->execute([$electionId, $voter['id']]);
$votedPositions = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(), 'position_id')), true);
$stmt = $pdo->prepare('SELECT receipt_code FROM participation_receipts WHERE election_id = ? AND voter_id = ?');
$stmt->execute([$electionId, $voter['id']]);
$receipt = $stmt->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($pageUrl);
    if ($status !== 'live') { flash('error', 'Voting is not open for this election.'); redirect($pageUrl); }

    if (($_POST['action'] ?? '') === 'review') {
        $raw = $_POST['choice'] ?? [];
        if (!is_array($raw)) { flash('error', 'Select a candidate for each remaining position.'); redirect($pageUrl); }
        $stmt = $pdo->prepare('SELECT id, position_id FROM candidates WHERE election_id = ?');
        $stmt->execute([$electionId]);
        $candidatePosition = [];
        foreach ($stmt->fetchAll() as $row) $candidatePosition[(int)$row['id']] = (int)$row['position_id'];

        $choices = [];
        foreach ($raw as $positionId => $candidateId) {
            $positionId = (int)$positionId;
            $candidateId = (int)$candidateId;
            if ($candidateId < 1 || isset($votedPositions[$positionId])) continue;
            if (($candidatePosition[$candidateId] ?? 0) !== $positionId) {
                flash('error', 'A selected candidate is not valid for this position.');
                redirect($pageUrl);
            }
            $choices[$positionId] = $candidateId;
        }
        $remaining = [];
        foreach ($ballot as $position) {
            $id = (int)$position['id'];
            if (!isset($votedPositions[$id]) && $position['candidates']) $remaining[] = $id;
        }
        $selected = array_map('intval', array_keys($choices));
        if (!$remaining || !$selected || array_diff($selected, $remaining)) {
            flash('error', 'Select at least one candidate for a position you have not voted in yet.');
            redirect($pageUrl);
        }
        $_SESSION['ballot_reviews'][$electionId] = ['choices' => $choices, 'token' => bin2hex(random_bytes(24))];
        redirect($pageUrl . '&review=1');
    }

    if (($_POST['action'] ?? '') === 'confirm') {
        $review = $_SESSION['ballot_reviews'][$electionId] ?? null;
        if (!$review || !hash_equals((string)$review['token'], (string)($_POST['review_token'] ?? ''))) {
            flash('error', 'Your ballot review expired. Please select your choices again.');
            redirect($pageUrl);
        }
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM voters WHERE id = ? FOR UPDATE');
            $lock->execute([$voter['id']]);
            $check = $pdo->prepare('SELECT position_id FROM votes WHERE election_id = ? AND voter_id = ?');
            $check->execute([$electionId, $voter['id']]);
            $already = array_fill_keys(array_map('intval', array_column($check->fetchAll(), 'position_id')), true);
            $valid = $pdo->prepare('SELECT id FROM candidates WHERE id = ? AND position_id = ? AND election_id = ?');
            $insert = $pdo->prepare('INSERT INTO votes (election_id, position_id, candidate_id, voter_id) VALUES (?, ?, ?, ?)');
            $saved = 0;
            foreach ($review['choices'] as $positionId => $candidateId) {
                $positionId = (int)$positionId; $candidateId = (int)$candidateId;
                if (isset($already[$positionId])) throw new RuntimeException('A vote was already recorded for one of these positions.');
                $valid->execute([$candidateId, $positionId, $electionId]);
                if (!$valid->fetchColumn()) throw new RuntimeException('A selected candidate is no longer available.');
                $insert->execute([$electionId, $positionId, $candidateId, $voter['id']]);
                $saved++;
            }
            if (!$saved) throw new RuntimeException('There are no unvoted positions in this ballot.');
            // Issue one participation receipt for the election, separately from all choices.
            $receiptLookup = $pdo->prepare('SELECT receipt_code FROM participation_receipts WHERE election_id = ? AND voter_id = ?');
            $receiptLookup->execute([$electionId, $voter['id']]);
            $receiptCode = $receiptLookup->fetchColumn();
            if (!$receiptCode) {
                $receiptCode = strtoupper(bin2hex(random_bytes(16)));
                $pdo->prepare('INSERT INTO participation_receipts (election_id, voter_id, receipt_code) VALUES (?, ?, ?)')
                    ->execute([$electionId, $voter['id'], $receiptCode]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            unset($_SESSION['ballot_reviews'][$electionId]);
            flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'The ballot could not be saved. Reload and check your voting status.');
            redirect($pageUrl);
        }
        unset($_SESSION['ballot_reviews'][$electionId]);
        $_SESSION['voter_receipts'][$electionId] = $receiptCode;
        flash('success', 'Vote submitted. Participation receipt: ' . $receiptCode);
        if (count($already) + $saved >= count($ballot)) {
            redirect('voter/dashboard.php?election_id=' . $electionId . '&voted=1');
        }
        redirect($pageUrl . '&receipt=1');
    }

    flash('error', 'That ballot action was not recognized.');
    redirect($pageUrl);
}

$review = $_SESSION['ballot_reviews'][$electionId] ?? null;
$showReview = isset($_GET['review']) && $review && !empty($review['choices']);
$receiptCode = $receipt ?: ($_SESSION['voter_receipts'][$electionId] ?? null);
render_head($election['title'], 'voter');
?>
<div class="page-head">
    <div><h1><?= e($election['title']) ?></h1>
        <?php if (!empty($election['description'])): ?><p><?= e($election['description']) ?></p><?php endif; ?>
        <p>Opens <?= e(format_when($election['start_at'])) ?> · Closes <?= e(format_when($election['end_at'])) ?></p>
    </div><div class="actions"><?= status_badge($status) ?><?php if ($status === 'live' && $ballot && count($votedPositions) < count($ballot)): ?><a class="btn btn-primary btn-sm" href="#ballot"><?= $votedPositions ? 'Continue voting' : 'Start voting' ?></a><?php endif; ?></div>
</div>
<?php if ($receiptCode && $votedPositions): ?>
<section class="card receipt-card"><h2>Vote submitted</h2>
<p>This receipt confirms participation only. It does not show or identify your choices.</p>
<div class="receipt-code-label">Participation receipt</div><code class="receipt-code"><?= e($receiptCode) ?></code>
<p class="hint">Save this code for your records.</p></section>
<?php endif; ?>
<?php if ($status === 'closed'): ?>
<div class="notice" role="status">This election is closed. Ballot choices are no longer available.</div>
<?php else: ?>
<?php if ($status === 'upcoming'): ?><div class="notice"><?= e($electionName) ?> have not started. Voting opens <?= e(format_when($election['start_at'])) ?>.</div><?php endif; ?>
<?php if ($votedPositions): ?><div class="notice voter-status" role="status">You have voted in <?= count($votedPositions) ?> of <?= count($ballot) ?> positions. Your choices are hidden for privacy.</div><?php endif; ?>

<?php if ($showReview): ?>
<section class="card ballot-review"><h2>Review my choices</h2><p class="muted">Check your choices before submitting. A vote cannot be changed after confirmation.</p>
<?php foreach ($ballot as $position): $positionId=(int)$position['id']; $candidateId=(int)($review['choices'][$positionId]??0); if (!$candidateId) continue; $name=''; foreach ($position['candidates'] as $candidate) if ((int)$candidate['id']===$candidateId) {$name=$candidate['full_name'];break;} ?>
<div class="review-choice"><span><?= e($position['title']) ?></span><strong><?= e($name) ?></strong></div>
<?php endforeach; ?>
<div class="actions review-actions"><a class="btn btn-ghost" href="<?= e(url($pageUrl)) ?>">Edit choices</a>
<form method="post" action="<?= e(url($pageUrl)) ?>"><?= csrf_field() ?><input type="hidden" name="election_id" value="<?= $electionId ?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="review_token" value="<?= e($review['token']) ?>"><button class="btn btn-primary" data-confirm="Submit this ballot? Your choices cannot be changed afterwards.">Confirm and submit vote</button></form></div></section>
<?php else: ?>
<form id="ballot" method="post" action="<?= e(url($pageUrl)) ?>" class="ballot"><?= csrf_field() ?><input type="hidden" name="election_id" value="<?= $electionId ?>"><input type="hidden" name="action" value="review">
<?php if (!$ballot): ?><div class="card empty">No positions have been set up for this election yet.</div><?php endif; ?>
<?php foreach ($ballot as $position): $positionId=(int)$position['id']; $hasVoted=isset($votedPositions[$positionId]); ?>
<section class="position"><div class="position-head"><h2><?= e($position['title']) ?></h2><?php if($hasVoted): ?><span class="badge badge-approved">✓ Vote recorded</span><?php endif; ?></div>
<?php if($hasVoted): ?><p class="muted">Your vote is recorded. Your choice is hidden for privacy.</p><?php elseif(!$position['candidates']): ?><p class="muted">No candidates have been added for this position yet.</p><?php endif; ?>
<?php if (!$hasVoted): ?>
<div class="candidates"><?php foreach($position['candidates'] as $candidate): $candidateId=(int)$candidate['id']; $disabled=$hasVoted||$status!=='live'; $checked=!$disabled&&isset($review['choices'][$positionId])&&(int)$review['choices'][$positionId]===$candidateId; ?>
<label class="candidate <?= $disabled?'is-locked':'' ?>"><input type="radio" name="choice[<?= $positionId ?>]" value="<?= $candidateId ?>" <?= $checked?'checked':'' ?> <?= $disabled?'disabled':'' ?>>
<div class="candidate-card"><img src="<?= e(candidate_photo_url($candidate['photo'])) ?>" alt="Photo of <?= e($candidate['full_name']) ?>"><div class="candidate-body"><h3><?= e($candidate['full_name']) ?></h3><?php if(!empty($candidate['bio'])): ?><p><?= e($candidate['bio']) ?></p><?php endif; ?></div></div></label>
<?php endforeach; ?></div><?php endif; ?></section>
<?php endforeach; ?>
<?php if($status==='live'&&$ballot&&count($votedPositions)<count($ballot)): ?><div class="submit-bar"><p>Select a candidate for each position you want to vote in now, review your choices, then confirm. You can return for remaining positions before voting closes.</p><button class="btn btn-primary btn-lg">Next</button></div><?php endif; ?></form>
<?php endif; ?>
<?php endif; ?>
<?php render_foot(); ?>
