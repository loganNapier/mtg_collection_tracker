<?php
// auth.php
declare(strict_types=1);

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool {
  return isset($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function require_login(): void {
  if (empty($_SESSION['uid'])) {
    header("Location: ../index.php#login");
    exit;
  }
}

function current_user(PDO $pdo): ?array {
  if (empty($_SESSION['uid'])) return null;

  $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([(int)$_SESSION['uid']]);
  $u = $stmt->fetch();

  return $u ?: null;
}
