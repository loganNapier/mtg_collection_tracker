<?php
// delete_deck.php
declare(strict_types=1);

require_once (__DIR__ . "/../auth/config.php");
require_once (__DIR__ . "/../auth/auth.php");

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
$deckId = (int)($_POST['deck_id'] ?? 0);

function back(string $msg): void {
  $_SESSION['flash'] = $msg;
  header("Location: ../decks.php");
  exit;
}

if ($deckId <= 0) back("Missing deck id.");

try {
  // Ensure the deck belongs to the current user
  $own = $pdo->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ? LIMIT 1");
  $own->execute([$deckId, $uid]);

  if (!$own->fetch()) {
    back("Deck not found.");
  }

  // Deleting the deck will delete its deck cards if deck_cards.deck_id has ON DELETE CASCADE
  $del = $pdo->prepare("DELETE FROM decks WHERE id = ? AND user_id = ?");
  $del->execute([$deckId, $uid]);

  $_SESSION['flash'] = "Deck deleted.";
  header("Location: ../decks.php");
  exit;
} catch (PDOException $e) {
  back("Database error while deleting deck.");
}
