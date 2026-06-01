<?php
declare(strict_types=1);

require_once __DIR__ . "/../auth/config.php";
require_once __DIR__ . "/../auth/auth.php";

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../cards.php");
    exit;
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(400);
    exit("Bad CSRF token.");
}

$uid = (int)$_SESSION['uid'];

function back(string $msg): void {
    $_SESSION['flash'] = $msg;
    header("Location: ../cards.php");
    exit;
}

function decimal_2_or_null(string $value): ?float {
    $value = trim($value);
    if ($value === '') return null;
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) return null;
    return (float)$value;
}

/* ======================
   CARD METADATA
====================== */

$scryfallId = trim($_POST['scryfall_id'] ?? '');
if ($scryfallId === '' || strlen($scryfallId) !== 36) {
    back("Invalid Scryfall ID.");
}

$name = trim($_POST['name'] ?? '');
if ($name === '' || strlen($name) > 255) {
    back("Invalid card name.");
}

$typeLine = trim($_POST['type_line'] ?? '') ?: null;
$setCode = strtoupper(trim($_POST['set_code'] ?? '')) ?: null;
$setName = trim($_POST['set_name'] ?? '') ?: null;
$collector = trim($_POST['collector_number'] ?? '') ?: null;

$imageSmall = trim($_POST['image_small'] ?? '') ?: null;
$imageNormal = trim($_POST['image_normal'] ?? '') ?: null;

$priceUsd = decimal_2_or_null($_POST['price_usd'] ?? '');
$priceUsdFoil = decimal_2_or_null($_POST['price_usd_foil'] ?? '');
$priceUsdEtched = decimal_2_or_null($_POST['price_usd_etched'] ?? '');
$legalitiesRaw = trim((string)($_POST['legalities'] ?? ''));
if ($legalitiesRaw === '') {
    $legalities = null;
} else {
    $legalitiesDecoded = json_decode($legalitiesRaw, true);
    if (!is_array($legalitiesDecoded)) {
        back("Invalid legalities data.");
    }
    $legalities = json_encode($legalitiesDecoded, JSON_UNESCAPED_SLASHES);
}

/* ======================
   COLLECTION FIELDS
====================== */

$qty = (int)($_POST['qty'] ?? 1);
if ($qty < 1 || $qty > 999) {
    back("Quantity must be between 1 and 999.");
}

$allowedConditions = ['NM','LP','MP','HP','DMG'];
$condition = strtoupper(trim($_POST['card_condition'] ?? 'NM'));
if (!in_array($condition, $allowedConditions, true)) {
    back("Invalid condition.");
}

$allowedFinishes = ['nonfoil','foil','etched'];
$finish = strtolower(trim($_POST['finish'] ?? 'nonfoil'));
if (!in_array($finish, $allowedFinishes, true)) {
    back("Invalid finish.");
}

$language = trim($_POST['card_language'] ?? 'English');
if ($language === '' || strlen($language) > 32) {
    back("Invalid language.");
}

$isSigned = !empty($_POST['is_signed']) ? 1 : 0;
$isAltered = !empty($_POST['is_altered']) ? 1 : 0;

$notes = trim($_POST['notes'] ?? '');
if (strlen($notes) > 500) {
    back("Notes too long.");
}
$notes = $notes !== '' ? $notes : null;

$acquiredAt = trim($_POST['acquired_at'] ?? '');
if ($acquiredAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquiredAt)) {
    back("Invalid acquired date.");
}
$acquiredAt = $acquiredAt !== '' ? $acquiredAt : null;

$purchasePrice = decimal_2_or_null($_POST['purchase_price'] ?? '');
if ($purchasePrice !== null && $purchasePrice < 0) {
    back("Purchase price cannot be negative.");
}

/* ======================
   DATABASE TRANSACTION
====================== */

try {
    $pdo->beginTransaction();

    // Upsert card (scryfall_id is sole unique key)
    $stmt = $pdo->prepare("
        INSERT INTO cards
        (scryfall_id, oracle_id, name, type_line, set_code, set_name,
         collector_number, image_small, image_normal,
         price_usd, price_usd_foil, price_usd_etched, price_updated_at, legalities)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
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
            price_updated_at = NOW(),
            legalities = VALUES(legalities)
    ");

    $stmt->execute([
        $scryfallId,
        $name,
        $typeLine,
        $setCode,
        $setName,
        $collector,
        $imageSmall,
        $imageNormal,
        $priceUsd,
        $priceUsdFoil,
        $priceUsdEtched,
        $legalities
    ]);

    $cardId = $pdo->lastInsertId();
    if (!$cardId) {
        $stmt = $pdo->prepare("SELECT id FROM cards WHERE scryfall_id = ?");
        $stmt->execute([$scryfallId]);
        $cardId = $stmt->fetchColumn();
    }

    if (!$cardId) {
        throw new Exception("Card lookup failed.");
    }

    // Upsert user collection
    $stmt = $pdo->prepare("
        INSERT INTO user_collection
        (user_id, card_id, qty, card_condition, card_language,
         finish, is_signed, is_altered, notes, acquired_at, purchase_price)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            qty = qty + VALUES(qty),
            notes = VALUES(notes),
            acquired_at = VALUES(acquired_at),
            purchase_price = VALUES(purchase_price)
    ");

    $stmt->execute([
        $uid,
        $cardId,
        $qty,
        $condition,
        $language,
        $finish,
        $isSigned,
        $isAltered,
        $notes,
        $acquiredAt,
        $purchasePrice
    ]);

    $pdo->commit();

    $_SESSION['flash'] = "Added to your collection.";
    header("Location: ../cards.php");
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash'] = "Database error.";
    header("Location: ../cards.php");
    exit;
}