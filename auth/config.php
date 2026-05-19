<?php
// config.php 
declare(strict_types=1);

$DB_HOST = "127.0.0.1";   // try 127.0.0.1 instead of localhost
$DB_PORT = 3306;          // change if DataGrip uses a different port (often 3307 in XAMPP)
$DB_NAME = "mtg";
$DB_USER = "root";        // must match your DataGrip connection user
$DB_PASS = "root";            // must match your DataGrip connection password

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
  http_response_code(500);
  header("Content-Type: text/plain; charset=utf-8");
  exit(
    "Database connection failed.\n\n" .
    "Check these in config.php:\n" .
    "- DB_HOST={$DB_HOST}\n" .
    "- DB_PORT={$DB_PORT}\n" .
    "- DB_NAME={$DB_NAME}\n" .
    "- DB_USER={$DB_USER}\n\n" .
    "PDO error message:\n" .
    $e->getMessage() . "\n"
  );
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}
