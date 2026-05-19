<?php
// login.php
declare(strict_types=1);

require_once (__DIR__ . "/../auth/config.php");
require_once (__DIR__ . "/../auth/auth.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: ../index.php#login");
  exit;
}

if (!csrf_check($_POST['csrf'] ?? null)) {
  http_response_code(400);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Bad CSRF token.");
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$pass       = (string)($_POST['password'] ?? '');

if ($identifier === '' || $pass === '') {
  header("Location: ../index.php#login");
  exit;
}

$stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1");
$stmt->execute([$identifier, $identifier]);
$row = $stmt->fetch();

if (!$row || !password_verify($pass, (string)$row['password_hash'])) {
  header("Location: ../index.php#login");
  exit;
}

$_SESSION['uid'] = (int)$row['id'];
session_regenerate_id(true);

header("Location: ../collection.php");
exit;
