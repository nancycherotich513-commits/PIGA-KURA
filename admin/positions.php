<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$elections = db()->query('SELECT * FROM elections ORDER BY start_at DESC')->fetchAll();

$electionId = (int)($_GET['election_id'] ?? $_POST['election_id'] ?? 0);
if (!$electionId && $elections) {
    $electionId = (int)$elections[0]['id'];
}
$election = $electionId ? find_election($electionId) : null;
$self = 'admin/positions.php?election_id=' . $electionId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $action = $_POST['action'] ?? '';

    if (!$election) {
        flash('error', 'Create an election first.');
    } elseif ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $stmt = db()->prepare('SELECT id FROM positions WHERE election_id = ? AND LOWER(title) = LOWER(?)');
        $stmt->execute([$electionId, $title]);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            flash('error', 'Position name must be between 2 and 120 characters.');
        } elseif ($stmt->fetch()) {
            flash('error', 'This election already has a position with that name.');
        } else {
            db()->prepare('INSERT INTO positions (election_id, title) VALUES (?, ?)')->execute([$electionId, $title]);
            audit_log('added_position', 'election', $electionId, $title);
            flash('success', "Position \"$title\" added.");
        }
    } elseif ($action === 'delete') {
        $positionId = (int)($_POST['id'] ?? 0);
        $old = db()->prepare('SELECT title FROM positions WHERE id = ? AND election_id = ?');
        $old->execute([$positionId, $electionId]);
        if ($position = $old->fetch()) audit_log('removed_position', 'election', $electionId, $position['title']);
        db()->prepare('DELETE FROM positions WHERE id = ? AND election_id = ?')
            ->execute([$positionId, $electionId]);
        flash('success', 'Position removed.');
    }
    redirect($self);
}

$positions = [];
if ($election) {
    $stmt = db()->prepare(
        'SELECT p.*, (SELECT COUNT(*) FROM candidates c WHERE c.position_id = p.id) AS candidate_count
         FROM positions p WHERE p.election_id = ? ORDER BY p.id'
    );
    $stmt->execute([$electionId]);
    $positions = $stmt->fetchAll();
}

render_head('Positions', 'admin');
?>
<div class="page-head">
    <div>
        <h1>Positions</h1>
        <p>Positions are the seats or roles people vote for, such as President or Treasurer.</p>
    </div>
</div>

<?php if (!$elections): ?>
    <div class="card empty">
        <p>You have no elections yet.</p>
        <a class="btn btn-primary" href="<?= e(url('admin/elections.php')) ?>">Create an election</a>
    </div>
<?php else: ?>
    <form method="get" class="card toolbar">
        <label>Election
            <select name="election_id" onchange="this.form.submit()">
                <?php foreach ($elections as $e_): ?>
                    <option value="<?= (int)$e_['id'] ?>" <?= (int)$e_['id'] === $electionId ? 'selected' : '' ?>><?= e($e_['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <div class="two-col">
        <section class="card">
            <h2>Add a position<?= $election ? ' to ' . e($election['title']) : '' ?></h2>
            <form method="post" action="<?= e(url($self)) ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="election_id" value="<?= $electionId ?>">
                <label>Position name
                    <input type="text" name="title" required minlength="2" maxlength="120" placeholder="e.g. President">
                </label>
                <button class="btn btn-primary">Add position</button>
            </form>
        </section>

        <section class="card">
            <h2>Current positions</h2>
            <?php if (!$positions): ?>
                <p class="muted">No positions yet for this election.</p>
            <?php endif; ?>
            <?php foreach ($positions as $p): ?>
                <div class="pending-row">
                    <div>
                        <strong><?= e($p['title']) ?></strong><br>
                        <small class="muted"><?= (int)$p['candidate_count'] ?> candidate(s)</small>
                    </div>
                    <form method="post" action="<?= e(url($self)) ?>" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="election_id" value="<?= $electionId ?>">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-danger btn-sm" data-confirm="Remove this position and its candidates?">Remove</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
    </div>
<?php endif; ?>
<?php
render_foot();
