<?php
// update_collection_item.php
declare(strict_types=1);

require_once __DIR__ . "/../auth/config.php";
require_once __DIR__ . "/../auth/auth.php";

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: ../collection.php");
  exit;
}

if (!csrf_check($_POST['csrf'] ?? null)) {
  http_response_code(400);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Bad CSRF token.");
}

$uid = (int)$_SESSION['uid'];

function back_with_flash(string $msg): void {
  $_SESSION['flash'] = $msg;
  header("Location: ../collection.php");
  exit;
}

$itemId = (int)($_POST['collection_id'] ?? 0);
if ($itemId <= 0) back_with_flash("Missing collection item id.");

$action = (string)($_POST['action'] ?? 'update');
if ($action !== 'update' && $action !== 'delete') back_with_flash("Invalid action.");

try {
  $own = $pdo->prepare("SELECT id FROM user_collection WHERE id = ? AND user_id = ? LIMIT 1");
  $own->execute([$itemId, $uid]);
  if (!$own->fetch()) back_with_flash("That collection item was not found.");
} catch (PDOException $e) {
  back_with_flash("Database error.");
}

if ($action === 'delete') {
  try {
    $del = $pdo->prepare("DELETE FROM user_collection WHERE id = ? AND user_id = ?");
    $del->execute([$itemId, $uid]);
    back_with_flash("Removed from your collection.");
  } catch (PDOException $e) {
    back_with_flash("Database error while deleting.");
  }
}

$qty = (int)($_POST['qty'] ?? 1);
$condition = strtoupper(trim((string)($_POST['card_condition'] ?? 'NM')));
$language = trim((string)($_POST['card_language'] ?? 'English'));
$finish = strtolower(trim((string)($_POST['finish'] ?? 'nonfoil')));

$isSigned = !empty($_POST['is_signed']) ? 1 : 0;
$isAltered = !empty($_POST['is_altered']) ? 1 : 0;

$notes = trim((string)($_POST['notes'] ?? ''));
$acquiredAt = trim((string)($_POST['acquired_at'] ?? '')); // YYYY-MM-DD
$purchasePriceRaw = trim((string)($_POST['purchase_price'] ?? ''));

$allowedConditions = ['NM','LP','MP','HP','DMG'];
$allowedFinishes = ['nonfoil','foil','etched'];

if ($qty < 0 || $qty > 999) back_with_flash("Qty must be 0–999.");
if (!in_array($condition, $allowedConditions, true)) back_with_flash("Condition must be NM/LP/MP/HP/DMG.");
if ($language === '' || mb_strlen($language) > 32) back_with_flash("Language must be 1–32 characters.");
if (!in_array($finish, $allowedFinishes, true)) back_with_flash("Finish must be nonfoil/foil/etched.");
if (mb_strlen($notes) > 500) back_with_flash("Notes must be 500 characters or less.");

$acquiredAtDb = null;
if ($acquiredAt !== '') {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquiredAt)) back_with_flash("Acquired date must be YYYY-MM-DD.");
  $acquiredAtDb = $acquiredAt;
}

$purchasePriceDb = null;
if ($purchasePriceRaw !== '') {
  if (!is_numeric($purchasePriceRaw)) back_with_flash("Purchase price must be a number.");
  $purchasePriceDb = (float)$purchasePriceRaw;
  if ($purchasePriceDb < 0) back_with_flash("Purchase price cannot be negative.");
}

try {
  $upd = $pdo->prepare("
    UPDATE user_collection
    SET
      qty = ?,
      card_condition = ?,
      card_language = ?,
      finish = ?,
      is_signed = ?,
      is_altered = ?,
      notes = ?,
      acquired_at = ?,
      purchase_price = ?,
      updated_at = CURRENT_TIMESTAMP
    WHERE id = ? AND user_id = ?
  ");
  $upd->execute([
    $qty,
    $condition,
    $language,
    $finish,
    $isSigned,
    $isAltered,
    ($notes !== '' ? $notes : null),
    $acquiredAtDb,
    $purchasePriceDb,
    $itemId,
    $uid
  ]);

  back_with_flash("Collection item updated.");
} catch (PDOException $e) {
  back_with_flash("Database error while updating.");
}