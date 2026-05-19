<?php
// register.php
declare(strict_types=1);

require_once __DIR__ . "/../auth/config.php";
require_once __DIR__ . "/../auth/auth.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: ../index.php#register");
  exit;
}

if (!csrf_check($_POST['csrf'] ?? null)) {
  http_response_code(400);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Bad CSRF token.");
}

$username = trim((string)($_POST['username'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));
$pass     = (string)($_POST['password'] ?? '');
$pass2    = (string)($_POST['password2'] ?? '');

$errors = [];
if ($username === '' || strlen($username) > 32) $errors[] = "Invalid username.";
if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email.";
if (strlen($pass) < 8) $errors[] = "Password must be at least 8 characters.";
if ($pass !== $pass2) $errors[] = "Passwords do not match.";

if ($errors) {
  // Minimal: redirect back. (Later we can add a safe flash message system.)
  header("Location: ../index.php#register");
  exit;
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

try {
  // Optional: basic duplicate pre-check (still rely on UNIQUE constraints)
  $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
  $check->execute([$username, $email]);
  if ($check->fetch()) {
    header("Location: ../index.php#register");
    exit;
  }

  $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
  $stmt->execute([$username, $email, $hash]);

  $_SESSION['uid'] = (int)$pdo->lastInsertId();
  session_regenerate_id(true);

  header("Location: ../collection.php");
  exit;
} catch (PDOException $e) {
  header("Location: ../index.php#register");
  exit;
}