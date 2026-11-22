<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$voter = current_voter();
if (!$voter) redirect('index.php?tab=voter-login');
if (empty($voter['must_change_password'])) redirect('voter/dashboard.php');

if (!empty($voter['temporary_password_expires_at'])
    && new DateTimeImmutable($voter['temporary_password_expires_at'], timezone()) <= now_local()) {
    unset($_SESSION['voter_id']);
    session_regenerate_id(true);
    flash('error', 'That temporary password has expired. Submit a new account help request.');
    redirect('auth/account_help.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('auth/change_password.php');
    $password = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (strlen($password) < 8) {
        flash('error', 'Your new password must be at least 8 characters.');
    } elseif (!hash_equals($password, $confirm)) {
        flash('error', 'The new passwords do not match.');
    } elseif (password_verify($password, $voter['password_hash'])) {
        flash('error', 'Choose a password different from the temporary password.');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE voters SET password_hash = ?, must_change_password = 0, temporary_password_expires_at = NULL WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $voter['id']]);
            $pdo->prepare("UPDATE account_help_requests
                SET status = 'resolved', resolved_at = ?, resolved_by = NULL
                WHERE voter_id = ? AND status = 'open' AND temp_password_issued_at IS NOT NULL")
                ->execute([to_db(now_local()), $voter['id']]);
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Your password could not be updated. Please try again.');
            redirect('auth/change_password.php');
        }
        session_regenerate_id(true);
        flash('success', 'Your new password is saved. Your account help request has been resolved.');
        redirect('voter/dashboard.php');
    }
}

render_head('Set a new password', 'public');
?>
<section class="auth-card account-help-card">
    <p class="eyebrow">Secure your account</p>
    <h1>Choose a new password</h1>
    <p class="muted">Your temporary password can only be used once to sign in. Set a new password to continue to your voter dashboard.</p>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <label>New password<input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block">Save new password</button>
    </form>
</section>
<?php render_foot(); ?>
