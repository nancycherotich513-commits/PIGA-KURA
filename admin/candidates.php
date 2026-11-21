<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$self = 'admin/candidates.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $positionId = (int)($_POST['position_id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');

            $stmt = db()->prepare('SELECT p.id, p.election_id, e.start_at, e.end_at, e.voting_override FROM positions p JOIN elections e ON e.id = p.election_id WHERE p.id = ?');
            $stmt->execute([$positionId]);
            $position = $stmt->fetch();

            if (!$position) {
                throw new RuntimeException('Please choose a valid position.');
            }
            if (election_status($position) === 'closed') {
                throw new RuntimeException('Candidates can only be added to upcoming or open elections.');
            }
            if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
                throw new RuntimeException('Candidate name must be between 3 and 120 characters.');
            }
            if (mb_strlen($bio) > 1000) {
                throw new RuntimeException('The bio must be 1000 characters or fewer.');
            }

            $photo = save_candidate_photo($_FILES['photo'] ?? []);

            db()->prepare('INSERT INTO candidates (election_id, position_id, full_name, bio, photo) VALUES (?, ?, ?, ?, ?)')
                ->execute([$position['election_id'], $positionId, $name, $bio ?: null, $photo]);
            audit_log('added_candidate', 'election', (int)$position['election_id'], $name);
            flash('success', "Candidate \"$name\" added.");
        }

        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $lookup = db()->prepare('SELECT c.election_id, c.photo, e.start_at, e.end_at, e.voting_override FROM candidates c JOIN elections e ON e.id = c.election_id WHERE c.id = ?');
            $lookup->execute([$id]);
            $existing = $lookup->fetch();
            if (!$existing) throw new RuntimeException('Candidate not found.');
            if (election_status($existing) === 'closed') throw new RuntimeException('Candidates in a completed election are archived and cannot be changed.');
            if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new RuntimeException('Candidate name must be between 3 and 120 characters.');
            if (mb_strlen($bio) > 1000) throw new RuntimeException('The bio must be 1000 characters or fewer.');

            $photo = $existing['photo'];
            $newPhoto = null;
            if (isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newPhoto = save_candidate_photo($_FILES['photo']);
                $photo = $newPhoto;
            }
            db()->prepare('UPDATE candidates SET full_name = ?, bio = ?, photo = ? WHERE id = ?')
                ->execute([$name, $bio ?: null, $photo, $id]);
            if ($newPhoto) delete_candidate_photo($existing['photo']);
            audit_log('edited_candidate', 'election', (int)$existing['election_id'], $name);
            flash('success', 'Candidate details updated.');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT c.photo, c.full_name, c.election_id, e.start_at, e.end_at, e.voting_override FROM candidates c JOIN elections e ON e.id = c.election_id WHERE c.id = ?');
            $stmt->execute([$id]);
            if ($row = $stmt->fetch()) {
                if (election_status($row) === 'closed') throw new RuntimeException('Candidates in a completed election are archived and cannot be removed.');
                $hasVotes = db()->prepare('SELECT COUNT(*) FROM votes WHERE candidate_id = ?');
                $hasVotes->execute([$id]);
                if ((int)$hasVotes->fetchColumn() > 0) throw new RuntimeException('This candidate has recorded votes and cannot be removed.');
                audit_log('removed_candidate', 'election', (int)$row['election_id'], $row['full_name']);
                delete_candidate_photo($row['photo']);
                db()->prepare('DELETE FROM candidates WHERE id = ?')->execute([$id]);
                flash('success', 'Candidate removed.');
            }
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect($self);
}

$positions = db()->query(
    'SELECT p.id, p.title AS position_title, e.id AS election_id, e.title AS election_title,
            e.start_at, e.end_at, e.voting_override
     FROM positions p JOIN elections e ON e.id = p.election_id
     ORDER BY e.start_at DESC, p.id'
)->fetchAll();
$positions = array_values(array_filter($positions, static fn(array $p): bool => election_status($p) !== 'closed'));
$hasAnyPositions = (int)db()->query('SELECT COUNT(*) FROM positions')->fetchColumn() > 0;

// Build a dropdown grouped by election.
$positionOptions = '';
$currentElection = null;
foreach ($positions as $p) {
    if ($currentElection !== $p['election_id']) {
        if ($currentElection !== null) {
            $positionOptions .= '</optgroup>';
        }
        $positionOptions .= '<optgroup label="' . e($p['election_title']) . '">';
        $currentElection = $p['election_id'];
    }
    $positionOptions .= '<option value="' . (int)$p['id'] . '">' . e($p['position_title']) . '</option>';
}
if ($currentElection !== null) {
    $positionOptions .= '</optgroup>';
}

$candidates = db()->query(
    'SELECT c.*, p.title AS position_title, e.title AS election_title,
            e.start_at, e.end_at, e.voting_override
     FROM candidates c
     JOIN positions p ON p.id = c.position_id
     JOIN elections e ON e.id = c.election_id
     ORDER BY e.start_at DESC, p.id, c.full_name'
)->fetchAll();
$candidates = array_values(array_filter($candidates, static fn(array $c): bool => election_status($c) !== 'closed'));

render_head('Candidates', 'admin');
?>
<div class="page-head">
    <div>
        <h1>Candidates</h1>
        <p>Manage candidates for upcoming and open elections. Candidates from closed elections are automatically archived here and remain in historical results.</p>
    </div>
</div>

<?php if (!$positions): ?>
    <div class="card empty">
        <?php if ($hasAnyPositions): ?>
            <p>There are no upcoming or open elections for candidate management. Candidates from completed elections are archived and remain available in their results.</p>
            <a class="btn btn-primary" href="<?= e(url('admin/elections.php')) ?>">View elections</a>
        <?php else: ?>
            <p>Add an election and its positions before adding candidates.</p>
            <a class="btn btn-primary" href="<?= e(url('admin/positions.php')) ?>">Go to positions</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="card">
        <h2>Add a candidate</h2>
        <form method="post" action="<?= e(url($self)) ?>" enctype="multipart/form-data" class="form grid-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <label>Position
                <select name="position_id" required>
                    <option value="">Choose a position…</option>
                    <?= $positionOptions ?>
                </select>
            </label>
            <label>Candidate full name
                <input type="text" name="full_name" required minlength="3" maxlength="120">
            </label>
            <label class="span-2">Short bio <span class="hint">(optional, 1000 characters max)</span>
                <textarea name="bio" rows="3" maxlength="1000" placeholder="A few words about the candidate and their goals"></textarea>
            </label>
            <label class="span-2">Candidate photo <span class="hint">(JPG, PNG or WEBP, max 2 MB)</span>
                <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/webp" required>
            </label>
            <div class="span-2 preview-wrap">
                <img id="photo-preview" class="photo-preview" alt="Preview" style="display:none">
                <button class="btn btn-primary">Add candidate</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Upcoming and active candidates</h2>
        <?php if (!$candidates): ?>
            <p class="muted">No candidates yet.</p>
        <?php endif; ?>
        <div class="cand-grid">
            <?php foreach ($candidates as $c): ?>
                <div class="cand-admin">
                    <img src="<?= e(candidate_photo_url($c['photo'])) ?>" alt="<?= e($c['full_name']) ?>">
                    <div class="cand-admin-body">
                        <strong><?= e($c['full_name']) ?></strong>
                        <small><?= e($c['position_title']) ?> · <?= e($c['election_title']) ?></small>
                        <?php if (!empty($c['bio'])): ?><p><?= e(mb_strimwidth($c['bio'], 0, 110, '…')) ?></p><?php endif; ?>
                        <details class="candidate-edit"><summary>Edit candidate</summary>
                            <form method="post" action="<?= e(url($self)) ?>" enctype="multipart/form-data" class="form">
                                <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <label>Full name<input type="text" name="full_name" value="<?= e($c['full_name']) ?>" required minlength="3" maxlength="120"></label>
                                <label>Bio<textarea name="bio" rows="3" maxlength="1000"><?= e($c['bio'] ?? '') ?></textarea></label>
                                <label>Replace photo <span class="hint">(optional)</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
                                <button class="btn btn-primary btn-sm">Save candidate</button>
                            </form>
                        </details>
                        <form method="post" action="<?= e(url($self)) ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-danger btn-sm" data-confirm="Remove this candidate?">Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php
render_foot();
