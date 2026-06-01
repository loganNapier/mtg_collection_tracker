<?php
// add_to_deck.php
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

function back_to_deck(int $deckId, string $msg): void {
  $_SESSION['flash'] = $msg;
  header("Location: ../deck.php?id=" . $deckId);
  exit;
}

function to_nullable_decimal_2(string $raw): ?float {
  $raw = trim($raw);
  if ($raw === '') return null;
  if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) return null;
  return (float)$raw;
}

$deckId = (int)($_POST['deck_id'] ?? 0);
if ($deckId <= 0) {
  header("Location: ../decks.php");
  exit;
}

/* Verify the deck belongs to the current user */
$stmt = $pdo->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$deckId, $uid]);
if (!$stmt->fetch()) {
  back_to_deck($deckId, "Deck not found.");
}

/* Deck card inputs */
$section = strtolower(trim((string)($_POST['section'] ?? 'main')));
if ($section !== 'side') $section = 'main';

$qty = (int)($_POST['qty'] ?? 1);
if ($qty < 1 || $qty > 999) back_to_deck($deckId, "Qty must be 1–999.");

$finish = strtolower(trim((string)($_POST['finish'] ?? 'nonfoil')));
$allowedFinishes = ['nonfoil','foil','etched'];
if (!in_array($finish, $allowedFinishes, true)) back_to_deck($deckId, "Finish must be nonfoil/foil/etched.");

/* Card metadata (from Scryfall) */
$scryfallId = trim((string)($_POST['scryfall_id'] ?? ''));
$oracleId = trim((string)($_POST['oracle_id'] ?? ''));

$name = trim((string)($_POST['name'] ?? ''));
$typeLine = trim((string)($_POST['type_line'] ?? ''));
$setCode = strtoupper(trim((string)($_POST['set_code'] ?? '')));
$setName = trim((string)($_POST['set_name'] ?? ''));
$collector = trim((string)($_POST['collector_number'] ?? ''));

$imageSmall = trim((string)($_POST['image_small'] ?? ''));
$imageNormal = trim((string)($_POST['image_normal'] ?? ''));

$priceUsd = to_nullable_decimal_2((string)($_POST['price_usd'] ?? ''));
$priceUsdFoil = to_nullable_decimal_2((string)($_POST['price_usd_foil'] ?? ''));
$priceUsdEtched = to_nullable_decimal_2((string)($_POST['price_usd_etched'] ?? ''));
$legalitiesRaw = trim((string)($_POST['legalities'] ?? ''));
if ($legalitiesRaw === '') {
  $legalities = null;
} else {
  $legalitiesDecoded = json_decode($legalitiesRaw, true);
  if (!is_array($legalitiesDecoded)) {
    back_to_deck($deckId, "Invalid legalities data.");
  }
  $legalities = json_encode($legalitiesDecoded, JSON_UNESCAPED_SLASHES);
}

if ($scryfallId === '' || strlen($scryfallId) > 36) back_to_deck($deckId, "Missing/invalid Scryfall card id.");
if ($name === '' || strlen($name) > 255) back_to_deck($deckId, "Missing/invalid card name.");

try {
  $pdo->beginTransaction();

  // Upsert into cards (requires UNIQUE on cards.scryfall_id)
  $upsertCard = $pdo->prepare("
    INSERT INTO cards
      (scryfall_id, oracle_id, name, type_line, set_code, set_name, collector_number,
       image_small, image_normal, price_usd, price_usd_foil, price_usd_etched, price_updated_at, legalities)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE
      oracle_id = VALUES(oracle_id),
      name = VALUES(name),
      type_line = VALUES(type_line),
      set_code = VALUES(set_code),
      set_name = VALUES(set_name),
      collector_number = VALUES(collector_number),
      image_small = VALUES(image_small),
      image_normal = VALUES(image_normal),
      price_usd = VALUES(price_usd),
      price_usd_foil = VALUES(price_usd_foil),
      price_usd_etched = VALUES(price_usd_etched),
      price_updated_at = VALUES(price_updated_at),
      legalities = VALUES(legalities)
  ");
  $upsertCard->execute([
    $scryfallId,
    ($oracleId !== '' ? $oracleId : null),
    $name,
    ($typeLine !== '' ? $typeLine : null),
    ($setCode !== '' ? $setCode : null),
    ($setName !== '' ? $setName : null),
    ($collector !== '' ? $collector : null),
    ($imageSmall !== '' ? $imageSmall : null),
    ($imageNormal !== '' ? $imageNormal : null),
    $priceUsd,
    $priceUsdFoil,
    $priceUsdEtched,
    $legalities
  ]);

  $getCardId = $pdo->prepare("SELECT id FROM cards WHERE scryfall_id = ? LIMIT 1");
  $getCardId->execute([$scryfallId]);
  $cardRow = $getCardId->fetch();
  if (!$cardRow) {
    $pdo->rollBack();
    back_to_deck($deckId, "Could not store card in local database.");
  }
  $cardId = (int)$cardRow['id'];

  // Insert or increment deck_cards (requires UNIQUE(deck_id, card_id, section, finish))
  $upsertDeckCard = $pdo->prepare("
    INSERT INTO deck_cards (deck_id, card_id, section, qty, finish)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      qty = qty + VALUES(qty),
      updated_at = CURRENT_TIMESTAMP
  ");
  $upsertDeckCard->execute([$deckId, $cardId, $section, $qty, $finish]);

  $pdo->commit();

  back_to_deck($deckId, "Added to deck.");
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  back_to_deck($deckId, "Database error while adding to deck.");
}