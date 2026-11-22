<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (current_admin()) {
    redirect('admin/dashboard.php');
}
if (current_voter()) {
    redirect('voter/dashboard.php');
}

$tab = $_GET['tab'] ?? 'voter-login';
if (!in_array($tab, ['voter-login', 'voter-register', 'admin-login'], true)) {
    $tab = 'voter-login';
}

render_head('Welcome', 'public');
?>
<section class="hero">
    <div class="hero-inner">
        <div class="hero-copy">
            <span class="eyebrow">Official online elections</span>
            <h1>VOTE<span>NOW</span></h1>
            <p>Your voice shapes the future. Register, get verified by the administrator, then cast your ballot securely.</p>
            <ul class="hero-points">
                <li>✔ Verified voters only</li>
                <li>✔ One vote per position</li>
                <li>✔ Results after close</li>
            </ul>
        </div>

        <div class="auth-card">
            <div class="tabs">
                <a href="?tab=voter-login" class="<?= $tab === 'voter-login' ? 'active' : '' ?>">Voter login</a>
                <a href="?tab=voter-register" class="<?= $tab === 'voter-register' ? 'active' : '' ?>">Register</a>
                <a href="?tab=admin-login" class="<?= $tab === 'admin-login' ? 'active' : '' ?>">Admin</a>
            </div>
            <p class="account-help-link"><a href="<?= e(url('auth/account_help.php')) ?>">Lost access? Request account help</a></p>

            <?php if ($tab === 'voter-login'): ?>
                <h2>Welcome back, voter</h2>
                <form method="post" action="<?= e(url('auth/voter_login.php')) ?>" class="form">
                    <?= csrf_field() ?>
                    <label>Voter ID or email
                        <input type="text" name="identifier" required autocomplete="username">
                    </label>
                    <label>Password
                        <input type="password" name="password" required autocomplete="current-password">
                    </label>
                    <button class="btn login-submit btn-block">Log in to vote</button>
                </form>
                <p class="hint">New here? <a href="?tab=voter-register">Create a voter account</a>.</p>

            <?php elseif ($tab === 'voter-register'): ?>
                <h2>Register to vote</h2>
                <form method="post" action="<?= e(url('auth/voter_register.php')) ?>" class="form">
                    <?= csrf_field() ?>
                    <label>Full name
                        <input type="text" name="full_name" required minlength="3" maxlength="120">
                    </label>
                    <label>Email
                        <input type="email" name="email" required autocomplete="email">
                    </label>
                    <label>Phone <span class="hint">(optional)</span>
                        <input type="tel" name="phone" maxlength="30">
                    </label>
                    <label>Password <span class="hint">(at least 8 characters)</span>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    </label>
                    <label>Confirm password
                        <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </label>
                    <button class="btn btn-primary btn-block">Submit registration</button>
                    <p class="hint">An administrator will approve your account before you can vote.</p>
                </form>

            <?php else: ?>
                <h2>Administrator access</h2>
                <form method="post" action="<?= e(url('auth/admin_login.php')) ?>" class="form">
                    <?= csrf_field() ?>
                    <label>Admin email
                        <input type="email" name="email" required autocomplete="username">
                    </label>
                    <label>Password
                        <input type="password" name="password" required autocomplete="current-password">
                    </label>
                    <button class="btn login-submit btn-block">Enter admin panel</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
render_foot();
