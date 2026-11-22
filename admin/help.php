<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$self = 'admin/help.php';
$oneTimePassword = null;
$oneTimeNotice = null;
$pendingDisplay = $_SESSION['one_time_temp_password'] ?? null;
if (isset($_GET['issued']) && is_array($pendingDisplay)
    && (int)($_GET['issued']) === (int)($pendingDisplay['request_id'] ?? 0)
    && time() - (int)($pendingDisplay['created_at'] ?? 0) <= 300) {
    $oneTimePassword = (string)$pendingDisplay['password'];
    $oneTimeNotice = ['request_id' => (int)$pendingDisplay['request_id'], 'expires_at' => (string)$pendingDisplay['expires_at']];
    unset($_SESSION['one_time_temp_password']);
} elseif ($pendingDisplay && time() - (int)($pendingDisplay['created_at'] ?? 0) > 300) {
    unset($_SESSION['one_time_temp_password']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    $requestId = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $pdo = db();

    if ($action === 'issue_temporary_password' && $requestId > 0) {
        $verificationMethod = (string)($_POST['verification_method'] ?? '');
        if (($_POST['identity_verified'] ?? '') !== 'yes' || !in_array($verificationMethod, ['phone', 'register'], true)) {
            flash('error', 'Confirm that you verified the voter before issuing a temporary password.');
            redirect($self);
        }
        $requestStmt = $pdo->prepare("SELECT * FROM account_help_requests WHERE id = ? AND status = 'open'");
        $requestStmt->execute([$requestId]);
        $request = $requestStmt->fetch();
        if (!$request) {
            flash('error', 'This help request is no longer open.');
            redirect($self);
        }

        $normalizedName = mb_strtolower(trim($request['full_name']));
        $voterStmt = $pdo->prepare('SELECT id, full_name, email, phone, status, must_change_password, temporary_password_expires_at FROM voters WHERE LOWER(email) = ? AND LOWER(TRIM(full_name)) = ? LIMIT 1');
        $voterStmt->execute([strtolower(trim($request['email'])), $normalizedName]);
        $voter = $voterStmt->fetch();
        $requestPhone = preg_replace('/\D+/', '', (string)($request['phone'] ?? ''));
        $registeredPhone = preg_replace('/\D+/', '', (string)($voter['phone'] ?? ''));
        $phoneMatches = $requestPhone === '' || ($registeredPhone !== '' && hash_equals($registeredPhone, $requestPhone));
        if (!$voter || $voter['status'] === 'rejected' || (!$phoneMatches && $verificationMethod === 'register')
            || ($verificationMethod === 'phone' && $registeredPhone === '')) {
            flash('error', 'The request could not be verified using the selected method. Confirm the registered phone exists or match the request to the voter register.');
            redirect($self);
        }

        $pdo->beginTransaction();
        try {
            $lockedRequest = $pdo->prepare("SELECT id FROM account_help_requests WHERE id = ? AND status = 'open' FOR UPDATE");
            $lockedRequest->execute([$requestId]);
            if (!$lockedRequest->fetchColumn()) throw new RuntimeException('This request is no longer open.');
            $lockedVoter = $pdo->prepare('SELECT must_change_password, temporary_password_expires_at FROM voters WHERE id = ? FOR UPDATE');
            $lockedVoter->execute([$voter['id']]);
            $account = $lockedVoter->fetch();
            $now = now_local();
            if (!empty($account['must_change_password']) && (empty($account['temporary_password_expires_at'])
                || new DateTimeImmutable($account['temporary_password_expires_at'], timezone()) > $now)) {
                throw new RuntimeException('An active temporary password has already been issued. It cannot be shown again.');
            }

            $temporaryPassword = bin2hex(random_bytes(12));
            $expiresAt = $now->modify('+24 hours');
            $pdo->prepare('UPDATE voters SET password_hash = ?, must_change_password = 1, temporary_password_expires_at = ? WHERE id = ?')
                ->execute([password_hash($temporaryPassword, PASSWORD_DEFAULT), to_db($expiresAt), $voter['id']]);
            $pdo->prepare('UPDATE account_help_requests SET voter_id = ?, identity_verified_at = ?, identity_verified_by = ?, verification_method = ?, temp_password_issued_at = ?, temp_password_issued_by = ?, resolved_by = NULL, resolution_reason = NULL WHERE id = ?')
                ->execute([$voter['id'], to_db($now), $admin['id'], $verificationMethod, to_db($now), $admin['id'], $requestId]);
            audit_log('issued_temporary_password', 'help_request', $requestId, 'Temporary password issued for voter #' . (int)$voter['id'] . ' after identity verification by ' . $verificationMethod . '; expires ' . to_db($expiresAt));
            $pdo->commit();

            $_SESSION['one_time_temp_password'] = [
                'request_id' => $requestId,
                'password' => $temporaryPassword,
                'expires_at' => to_db($expiresAt),
                'created_at' => time(),
            ];
            redirect($self . '?issued=' . $requestId);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'The temporary password could not be issued.');
            redirect($self);
        }
    }

    if ($action === 'reject_request' && $requestId > 0) {
        $reason = trim((string)($_POST['rejection_reason'] ?? ''));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            flash('error', 'Enter a rejection reason between 5 and 255 characters.');
            redirect($self);
        }
        $reject = $pdo->prepare("UPDATE account_help_requests SET status = 'rejected', resolved_at = ?, resolved_by = ?, resolution_reason = ? WHERE id = ? AND status = 'open' AND temp_password_issued_at IS NULL");
        $reject->execute([to_db(now_local()), $admin['id'], $reason, $requestId]);
        if ($reject->rowCount()) {
            audit_log('rejected_account_help', 'help_request', $requestId, 'Request rejected: ' . $reason);
            flash('success', 'The help request was rejected and the reason was recorded.');
        } else {
            flash('error', 'This request is no longer open or already has a temporary password attached.');
        }
        redirect($self);
    }

    flash('error', 'That account help action was not recognized.');
    redirect($self);
}

$rows = db()->query(
    'SELECT r.*, v.id AS matched_voter_id, v.full_name AS matched_voter_name,
        v.email AS matched_voter_email, v.phone AS matched_voter_phone,
        v.status AS matched_voter_status, v.must_change_password, v.temporary_password_expires_at
     FROM account_help_requests r
     LEFT JOIN voters v ON LOWER(v.email) = LOWER(r.email) AND LOWER(TRIM(v.full_name)) = LOWER(TRIM(r.full_name))
     ORDER BY (r.status = \'open\') DESC, r.created_at DESC LIMIT 250'
)->fetchAll();

if ($oneTimePassword !== null) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
}
render_head('Account help', 'admin');
?>
<div class="page-head"><div><h1>Account help</h1><p>Verify the voter before issuing a temporary password. The password is shown once and expires after 24 hours.</p></div></div>
<?php if ($oneTimePassword !== null): ?>
    <section class="card temp-password-once" aria-live="polite">
        <h2>Temporary password — shown once</h2>
        <p>Give this password to the verified voter by phone, in person, or through your trusted channel. They must set a new password before they can use the voter system. Expires <?= e(format_when($oneTimeNotice['expires_at'])) ?>.</p>
        <div class="temp-password-copy"><input id="temporary-password-once" type="text" readonly autocomplete="off" value="<?= e($oneTimePassword) ?>" aria-label="Temporary password"><button class="btn btn-primary" type="button" data-copy-target="#temporary-password-once" data-copy-label="Copy password">Copy password</button></div>
    </section>
<?php endif; ?>
<?php if (!$rows): ?><section class="card"><p class="muted">No account help requests yet.</p></section><?php else: ?>
    <div class="help-request-list">
    <?php foreach ($rows as $row):
        $phoneDigits = preg_replace('/\D+/', '', (string)($row['phone'] ?? ''));
        $registeredPhoneDigits = preg_replace('/\D+/', '', (string)($row['matched_voter_phone'] ?? ''));
        $phoneMatches = $phoneDigits === '' || ($registeredPhoneDigits !== '' && hash_equals($registeredPhoneDigits, $phoneDigits));
        $voterRecordMatches = !empty($row['matched_voter_id']) && $row['matched_voter_status'] !== 'rejected';
    ?>
        <article class="card help-request-card">
            <div class="help-request-heading"><div><strong><?= e($row['full_name']) ?></strong><span><?= e($row['email']) ?><?= $row['phone'] ? ' · ' . e($row['phone']) : '' ?></span></div><?= status_badge($row['status']) ?></div>
            <div class="help-request-meta"><span>Received <?= e(format_when($row['created_at'])) ?></span><span><?= e($row['reason']) ?></span></div>
            <?php if ($row['message']): ?><p class="help-request-message"><?= nl2br(e($row['message'])) ?></p><?php endif; ?>
            <?php if ($voterRecordMatches && $phoneMatches): ?>
                <p class="help-match is-matched">Registration match: voter #<?= (int)$row['matched_voter_id'] ?> · <?= e($row['matched_voter_name']) ?> · <?= e($row['matched_voter_email']) ?> · <?= e($row['matched_voter_phone'] ?: 'No phone on file') ?> · <?= e(ucfirst($row['matched_voter_status'])) ?></p>
            <?php elseif ($voterRecordMatches && !$phoneMatches): ?>
                <p class="help-match is-unmatched">Name and email match, but the supplied phone differs. Verify by calling the registered phone <?= e($row['matched_voter_phone'] ?: '(no phone on file)') ?> before issuing.</p>
            <?php else: ?>
                <p class="help-match is-unmatched">No matching voter registration was found for this name and email.</p>
            <?php endif; ?>
            <?php if ($row['temp_password_issued_at']): ?><p class="muted">Temporary password issued <?= e(format_when($row['temp_password_issued_at'])) ?>. The password is not stored for display and cannot be shown again.</p><?php endif; ?>
            <?php if ($row['status'] === 'rejected'): ?><p class="help-resolution">Rejected: <?= e($row['resolution_reason'] ?? '') ?></p>
            <?php elseif ($row['status'] === 'resolved'): ?><p class="help-resolution">Resolved <?= e(format_when($row['resolved_at'])) ?><?= $row['temp_password_issued_at'] ? ' after the voter changed their password.' : '.' ?></p>
            <?php else: ?>
                <div class="help-request-actions">
                    <?php if ($voterRecordMatches): ?>
                        <form method="post" class="form help-issue-form">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="issue_temporary_password">
                            <label>Verification method<select name="verification_method" required><option value="">Select verification method</option><option value="phone">Called the registered phone number</option><option value="register">Compared with the voter register</option></select></label>
                            <label class="verify-check"><input type="checkbox" name="identity_verified" value="yes" required> I completed the selected identity check.</label>
                            <?php if (!empty($row['must_change_password']) && (empty($row['temporary_password_expires_at']) || new DateTimeImmutable($row['temporary_password_expires_at'], timezone()) > now_local())): ?>
                                <p class="hint">An active temporary password already exists. It cannot be displayed again.</p>
                            <?php else: ?>
                                <button class="btn btn-primary btn-sm">Issue temporary password</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <?php if (!$row['temp_password_issued_at']): ?>
                        <form method="post" class="form help-reject-form">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="reject_request">
                            <label>Reject with reason<input type="text" name="rejection_reason" minlength="5" maxlength="255" required placeholder="Short reason for the audit log"></label>
                            <button class="btn btn-danger btn-sm">Reject request</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_foot(); ?>
