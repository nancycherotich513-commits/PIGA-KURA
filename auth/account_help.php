<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$reasons = ['Forgot password', 'Request a temporary password', 'Account details changed', 'Other access problem'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('auth/account_help.php');
    $name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($reason, $reasons, true) || mb_strlen($message) > 1000) {
        flash('error', 'Enter your name, a valid email, and a support reason.');
    } else {
        $recent = db()->prepare('SELECT COUNT(*) FROM account_help_requests WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $recent->execute([$email]);
        if ((int)$recent->fetchColumn() >= 2) {
            flash('error', 'A recent request is already with the administrator. Please wait for a reply.');
        } else {
            db()->prepare('INSERT INTO account_help_requests (full_name, email, phone, reason, message) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $email, $phone ?: null, $reason, $message ?: null]);
            flash('success', 'Your request was sent to the election administrator. They will verify your details and contact you through a trusted channel.');
            redirect('auth/account_help.php?sent=1');
        }
    }
}

render_head('Account help', 'public');
?>
<section class="auth-card account-help-card">
    <p class="eyebrow">Voter support</p>
    <h1>Need help signing in?</h1>
    <p class="muted">Request access help. After verifying your identity against the voter register or by phone, the administrator can issue a one-time temporary password. It is delivered outside this site.</p>
    <form method="post" class="form">
        <?= csrf_field() ?>
        <label>Full name<input type="text" name="full_name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e($_POST['full_name'] ?? '') ?>"></label>
        <label>Email<input type="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Phone <span class="hint">(optional)</span><input type="tel" name="phone" maxlength="30" value="<?= e($_POST['phone'] ?? '') ?>"></label>
        <label>What do you need help with?
            <select name="reason" required><option value="">Choose one…</option><?php foreach ($reasons as $reason): ?><option value="<?= e($reason) ?>" <?= ($_POST['reason'] ?? '') === $reason ? 'selected' : '' ?>><?= e($reason) ?></option><?php endforeach; ?></select>
        </label>
        <label>Message <span class="hint">(optional)</span><textarea name="message" rows="3" maxlength="1000"><?= e($_POST['message'] ?? '') ?></textarea></label>
        <button class="btn btn-primary btn-block">Send help request</button>
    </form>
    <p class="hint"><a href="<?= e(url('index.php?tab=voter-login')) ?>">Return to voter login</a></p>
</section>
<?php render_foot(); ?>
