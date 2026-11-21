<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$self = 'admin/elections.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $start = parse_local_datetime(trim($_POST['start_at'] ?? ''));
        $end = parse_local_datetime(trim($_POST['end_at'] ?? ''));

        if (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
            flash('error', 'The election title must be between 3 and 150 characters.');
        } elseif (!$start) {
            flash('error', 'Please choose a valid start date and time.');
        } elseif ($start < now_minute()) {
            // Server-side rule: never accept a start earlier than the current date and time.
            flash('error', 'The start date and time cannot be earlier than now. Pick today from the current time onwards, or any later day.');
        } elseif (!$end) {
            flash('error', 'Please choose a valid end date and time.');
        } elseif ($end <= $start) {
            flash('error', 'The end date and time must be after the start.');
        } else {
            db()->prepare('INSERT INTO elections (title, description, start_at, end_at, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$title, $description ?: null, to_db($start), to_db($end), $admin['id']]);
            $electionId = (int)db()->lastInsertId();
            audit_log('created', 'election', $electionId, $title);
            flash('success', 'Election created. Now add its positions and candidates.');
        }
    }

    if ($action === 'set_voting') {
        $id = (int)($_POST['id'] ?? 0);
        $state = $_POST['state'] ?? '';
        if ($id && in_array($state, ['open', 'closed'], true) && find_election($id)) {
            if ($state === 'open') {
                db()->prepare('UPDATE elections SET voting_override = ?, results_published_at = NULL WHERE id = ?')->execute([$state, $id]);
            } else {
                db()->prepare('UPDATE elections SET voting_override = ? WHERE id = ?')->execute([$state, $id]);
            }
            audit_log($state === 'open' ? 'opened_voting' : 'closed_voting', 'election', $id);
            flash('success', $state === 'open' ? 'Voting opened.' : 'Voting closed.');
        } else {
            flash('error', 'That voting status change was not valid.');
        }
    }

    if ($action === 'reset_votes') {
        $id = (int)($_POST['id'] ?? 0);
        $target = $id ? find_election($id) : null;
        if (!$target || election_status($target) !== 'upcoming') {
            flash('error', 'Only a scheduled test election can be reset before voting opens.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM votes WHERE election_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM participation_receipts WHERE election_id = ?')->execute([$id]);
                $pdo->commit();
                audit_log('reset_test_election', 'election', $id, 'Votes and participation receipts cleared before voting opened.');
                flash('success', 'Test election votes and receipts were reset.');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'The test election could not be reset.');
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $deletedElection = find_election($id);
        if ($deletedElection) audit_log('deleted', 'election', $id, $deletedElection['title']);
        $stmt = db()->prepare('SELECT photo FROM candidates WHERE election_id = ?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $row) {
            delete_candidate_photo($row['photo']);
        }
        db()->prepare('DELETE FROM elections WHERE id = ?')->execute([$id]);
        flash('success', 'Election deleted.');
    }

    redirect($self);
}

$elections = db()->query(
    'SELECT e.*,
        (SELECT COUNT(*) FROM positions p WHERE p.election_id = e.id) AS positions,
        (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidates,
        (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS votes
     FROM elections e
     ORDER BY e.start_at DESC'
)->fetchAll();

$minNow = now_local()->format('Y-m-d\TH:i');

render_head('Elections', 'admin');
?>
<div class="page-head">
    <div>
        <h1>Elections</h1>
        <p>Create as many elections as you need, then add positions and candidates to each one.</p>
    </div>
</div>

<section class="card">
    <h2>Create a new election</h2>
    <form method="post" class="form grid-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <label class="span-2">Election title
            <input type="text" name="title" required minlength="3" maxlength="150" placeholder="e.g. Student Council Elections 2026">
        </label>
        <label class="span-2">Description <span class="hint">(optional)</span>
            <textarea name="description" rows="3" placeholder="What is this election about?"></textarea>
        </label>
        <label>Starts
            <input type="datetime-local" name="start_at" min="<?= e($minNow) ?>" required>
        </label>
        <label>Ends
            <input type="datetime-local" name="end_at" min="<?= e($minNow) ?>" required>
        </label>
        <p class="hint span-2">Today is allowed only from the current time onwards. Any later day can start at any time. The end must come after the start.</p>
        <div class="span-2">
            <button class="btn btn-primary">Create election</button>
        </div>
    </form>
</section>

<section class="card">
    <h2>All elections</h2>
    <?php if (!$elections): ?>
        <p class="muted">No elections yet. Create your first one above.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Title</th><th>Starts</th><th>Ends</th><th>Status</th>
                    <th>Positions</th><th>Candidates</th><th>Votes</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($elections as $election): $electionStatus = election_status($election); ?>
                <tr>
                    <td><strong><?= e($election['title']) ?></strong></td>
                    <td><?= e(format_when($election['start_at'])) ?></td>
                    <td><?= e(format_when($election['end_at'])) ?></td>
                    <td><?= status_badge(election_status($election)) ?></td>
                    <td><?= (int)$election['positions'] ?></td>
                    <td><?= (int)$election['candidates'] ?></td>
                    <td><?= (int)$election['votes'] ?></td>
                    <td class="actions">
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/positions.php?election_id=' . $election['id'])) ?>">Positions</a>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/candidates.php')) ?>">Candidates</a>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/results.php?id=' . $election['id'])) ?>">Results</a>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_voting">
                            <input type="hidden" name="id" value="<?= (int)$election['id'] ?>">
                            <input type="hidden" name="state" value="<?= $electionStatus === 'live' ? 'closed' : 'open' ?>">
                            <button class="btn btn-ghost btn-sm" data-confirm="<?= $electionStatus === 'live' ? 'Close' : 'Open' ?> voting for this election now?">
                                <?= $electionStatus === 'live' ? 'Close voting' : ($electionStatus === 'closed' ? 'Reopen voting' : 'Open voting') ?>
                            </button>
                        </form>
                        <?php if ($electionStatus === 'upcoming'): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_votes">
                            <input type="hidden" name="id" value="<?= (int)$election['id'] ?>">
                            <button class="btn btn-ghost btn-sm" data-confirm="Clear all test votes and participation receipts for this scheduled election?">Reset test votes</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$election['id'] ?>">
                            <button class="btn btn-danger btn-sm" data-confirm="Delete this election and all its positions, candidates and votes?">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php
render_foot();
