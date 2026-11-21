<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?tab=voter-register');
}
csrf_verify('index.php?tab=voter-register');

$back = 'index.php?tab=voter-register';
$name = trim($_POST['full_name'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$phone = trim($_POST['phone'] ?? '');
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
    flash('error', 'Full name must be between 3 and 120 characters.');
    redirect($back);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Please enter a valid email address.');
    redirect($back);
}
if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) {
    flash('error', 'Please enter a valid phone number.');
    redirect($back);
}
if (strlen($password) < 8) {
    flash('error', 'Password must be at least 8 characters.');
    redirect($back);
}
if (!hash_equals($password, $confirm)) {
    flash('error', 'The passwords do not match.');
    redirect($back);
}

$stmt = db()->prepare('SELECT id FROM voters WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    flash('error', 'An account with this email already exists. Try logging in.');
    redirect($back);
}

db()->prepare("INSERT INTO voters (full_name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, 'pending')")
    ->execute([$name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT)]);

flash('success', 'Registration received! An administrator must approve your account before you can vote.');
redirect('index.php?tab=voter-login');
