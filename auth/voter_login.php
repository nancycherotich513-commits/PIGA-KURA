<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?tab=voter-login');
}
csrf_verify('index.php?tab=voter-login');

$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$stmt = db()->prepare('SELECT * FROM voters WHERE email = ?');
$stmt->execute([$email]);
$voter = $stmt->fetch();

if (!$voter || !password_verify($password, $voter['password_hash'])) {
    flash('error', 'Email or password is incorrect.');
    redirect('index.php?tab=voter-login');
}

session_regenerate_id(true);
$_SESSION['voter_id'] = (int)$voter['id'];
redirect('voter/dashboard.php');
