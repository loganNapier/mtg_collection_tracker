<?php
// update_deck_card.php
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

function back(string $msg, int $deckId): void {
  $_SESSION['flash'] = $msg;
  header("Location: ../deck.php?id=" . $deckId);
  exit;
}

$deckId = (int)($_POST['deck_id'] ?? 0);
$deckCardId = (int)($_POST['deck_card_id'] ?? 0);
$action = (string)($_POST['action'] ?? 'update');

if ($deckId <= 0 || $deckCardId <= 0) {
  header("Location: ../decks.php");
  exit;
}

try {
  // Verify the deck belongs to the current user
  $d = $pdo->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ? LIMIT 1");
  $d->execute([$deckId, $uid]);
  if (!$d->fetch()) back("Deck not found.", $deckId);

  // Verify the deck card belongs to that deck
  $dc = $pdo->prepare("SELECT id FROM deck_cards WHERE id = ? AND deck_id = ? LIMIT 1");
  $dc->execute([$deckCardId, $deckId]);
  if (!$dc->fetch()) back("Deck card not found.", $deckId);

  if ($action === 'delete') {
    $del = $pdo->prepare("DELETE FROM deck_cards WHERE id = ? AND deck_id = ?");
    $del->execute([$deckCardId, $deckId]);
    back("Removed card from deck.", $deckId);
  }

  if ($action !== 'update') back("Invalid action.", $deckId);

  $qty = (int)($_POST['qty'] ?? 1);
  if ($qty < 0 || $qty > 999) back("Qty must be 0–999.", $deckId);

  $section = strtolower(trim((string)($_POST['section'] ?? 'main')));
  if ($section !== 'side') $section = 'main';

  $finish = strtolower(trim((string)($_POST['finish'] ?? 'nonfoil')));
  $allowedFinishes = ['nonfoil','foil','etched'];
  if (!in_array($finish, $allowedFinishes, true)) back("Finish must be nonfoil/foil/etched.", $deckId);

  // If qty = 0, treat as delete (helps users quickly remove a line)
  if ($qty === 0) {
    $del = $pdo->prepare("DELETE FROM deck_cards WHERE id = ? AND deck_id = ?");
    $del->execute([$deckCardId, $deckId]);
    back("Removed card from deck.", $deckId);
  }

  // Update
  $upd = $pdo->prepare("
    UPDATE deck_cards
    SET section = ?, qty = ?, finish = ?, updated_at = CURRENT_TIMESTAMP
    WHERE id = ? AND deck_id = ?
  ");
  $upd->execute([$section, $qty, $finish, $deckCardId, $deckId]);

  back("Deck updated.", $deckId);
} catch (PDOException $e) {
  back("Database error while updating deck.", $deckId);
}