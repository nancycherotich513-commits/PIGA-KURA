<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?tab=admin-login');
}
csrf_verify('index.php?tab=admin-login');

$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$stmt = db()->prepare('SELECT * FROM admins WHERE email = ?');
$stmt->execute([$email]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($password, $admin['password_hash'])) {
    flash('error', 'Admin email or password is incorrect.');
    redirect('index.php?tab=admin-login');
}

session_regenerate_id(true);
$_SESSION['admin_id'] = (int)$admin['id'];
redirect('admin/dashboard.php');
