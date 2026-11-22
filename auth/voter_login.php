<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?tab=voter-login');
}
csrf_verify('index.php?tab=voter-login');

$identifier = trim($_POST['identifier'] ?? $_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

$idInput = ltrim($identifier, '#');
$voterId = ctype_digit($idInput) ? (int)$idInput : 0;
$stmt = db()->prepare('SELECT * FROM voters WHERE email = ? OR id = ? LIMIT 1');
$stmt->execute([strtolower($identifier), $voterId]);
$voter = $stmt->fetch();

if (!$voter || !password_verify($password, $voter['password_hash'])) {
    flash('error', 'Voter ID/email or password is incorrect.');
    redirect('index.php?tab=voter-login');
}

if (!empty($voter['must_change_password']) && !empty($voter['temporary_password_expires_at'])
    && new DateTimeImmutable($voter['temporary_password_expires_at'], timezone()) <= now_local()) {
    flash('error', 'That temporary password has expired. Submit a new account help request.');
    redirect('auth/account_help.php');
}

session_regenerate_id(true);
$_SESSION['voter_id'] = (int)$voter['id'];
if (!empty($voter['must_change_password'])) {
    redirect('auth/change_password.php');
}
redirect('voter/dashboard.php');
