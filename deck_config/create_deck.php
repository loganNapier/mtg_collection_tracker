<?php
// create_deck.php
declare(strict_types=1);

require_once __DIR__ . "/../auth/config.php";
require_once __DIR__ . "/../auth/auth.php";

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: ../decks.php");
  exit;
}

if (!csrf_check($_POST['csrf'] ?? null)) {
  http_response_code(400);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Bad CSRF token.");
}

$uid = (int)$_SESSION['uid'];

$name        = trim((string)($_POST['name']        ?? ''));
$format      = trim((string)($_POST['format']      ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$isPublic    = (int)($_POST['is_public'] ?? 0);
$isPublic    = ($isPublic === 1) ? 1 : 0;

const ALLOWED_FORMATS = [
  '', 'Standard', 'Pioneer', 'Modern', 'Legacy', 'Vintage',
  'Pauper', 'Commander', 'Oathbreaker', 'Brawl',
  'Explorer', 'Historic', 'Alchemy', 'Timeless',
];

function back(string $msg): void {
  $_SESSION['flash'] = $msg;
  header("Location: ../decks.php");
  exit;
}

if ($name === '' || mb_strlen($name) > 80)
  back("Deck name is required (max 80 characters).");

if (!in_array($format, ALLOWED_FORMATS, true))
  back("Invalid format selected.");

if ($description !== '' && mb_strlen($description) > 800)
  back("Notes must be 800 characters or less.");

try {
  $stmt = $pdo->prepare("
    INSERT INTO decks (user_id, name, format, description, is_public)
    VALUES (?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $uid,
    $name,
    ($format !== '' ? $format : null),
    ($description !== '' ? $description : null),
    $isPublic,
  ]);

  $_SESSION['flash'] = "Deck created.";
  $newId = (int)$pdo->lastInsertId();
  header("Location: ../deck.php?id=" . $newId);
  exit;
} catch (PDOException $e) {
  back("Database error: could not create deck.");
}