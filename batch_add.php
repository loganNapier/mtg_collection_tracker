<?php
declare(strict_types=1);

require_once __DIR__ . "/auth/config.php";
require_once __DIR__ . "/auth/auth.php";

require_login();
$user = current_user($pdo);
$loggedIn = (bool)$user;
$uid = (int)$_SESSION['uid'];

function back(string $msg): void {
  $_SESSION['flash'] = $msg;
  if (!headers_sent()) {
    header("Location: batch_add.php");
    exit;
  }
  echo '<script>window.location.href="batch_add.php";</script>';
  exit;
}

function loadAllCards(string $path): array {
  if (!is_file($path) || !is_readable($path)) return [];
  $json = @file_get_contents($path);
  if ($json === false) return [];
  $json = @gzdecode($json) ?: $json;
  $data = json_decode($json, true);
  if (!is_array($data)) return [];
  return $data['data'] ?? $data;
}

function findCardNames(array $card): array {
  $names = [];
  if (!empty($card['name'])) $names[] = (string)$card['name'];
  if (!empty($card['card_faces']) && is_array($card['card_faces'])) {
    foreach ($card['card_faces'] as $face) {
      if (!empty($face['name'])) $names[] = (string)$face['name'];
    }
  }
  return array_values(array_unique($names, SORT_STRING));
}

// Layouts that are never legal deck cards and should be skipped during lookup.
const NON_PLAYABLE_LAYOUTS = [
  'art_series',         // art cards
  'token',              // token cards
  'double_faced_token', // double-faced tokens
  'emblem',             // planeswalker emblems
  'planar',             // Planechase planes
  'scheme',             // Archenemy schemes
  'vanguard',           // Vanguard cards
  'augment',            // Conspiracy augment cards
  'host',               // Unstable host cards
];

function findCardInLocalJson(string $query, array $allCards): ?array {
  $needle = mb_strtolower(trim($query));

  $isPlayable = function(array $card): bool {
    $layout = strtolower((string)($card['layout'] ?? ''));
    return !in_array($layout, NON_PLAYABLE_LAYOUTS, true);
  };

  // Exact match first
  foreach ($allCards as $card) {
    if (!isset($card['name']) || !$isPlayable($card)) continue;
    foreach (findCardNames($card) as $name) {
      if (mb_strtolower($name) === $needle) return $card;
    }
  }

  // Partial match fallback
  foreach ($allCards as $card) {
    if (!isset($card['name']) || !$isPlayable($card)) continue;
    foreach (findCardNames($card) as $name) {
      if (mb_stripos($name, $query) !== false) return $card;
    }
  }

  return null;
}

// Detect if a CSV row matches our collection export format by checking headers
function is_collection_csv(array $headers): bool {
  $required = ['name', 'set code', 'qty', 'condition', 'finish', 'scryfall id'];
  $lower = array_map('strtolower', $headers);
  foreach ($required as $r) {
    if (!in_array($r, $lower, true)) return false;
  }
  return true;
}

// Parse a collection-format CSV into rich preview rows (no oracle-cards.json lookup needed)
function parse_collection_csv(string $tmpPath): array {
  $rows = [];
  $h = fopen($tmpPath, 'r');
  if (!$h) return [];

  // Strip UTF-8 BOM if present
  $bom = fread($h, 3);
  if ($bom !== "\xEF\xBB\xBF") rewind($h);

  $headers = fgetcsv($h, 0, ',', '"', '\\');
  if (!$headers) { fclose($h); return []; }
  $headers = array_map('trim', $headers);
  $lower   = array_map('strtolower', $headers);

  $col = fn(string $name) => array_search($name, $lower, true);

  while (($data = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
    if (count($data) < 2) continue;
    $get = fn(string $name) => isset($data[$col($name)]) ? trim((string)$data[$col($name)]) : '';

    $name = $get('name');
    if ($name === '') continue;

    $rows[] = [
      'source'         => 'csv',
      'query'          => $name,
      'qty'            => max(1, min(999, (int)($get('qty') ?: 1))),
      'card_condition' => $get('condition')  ?: 'NM',
      'card_language'  => $get('language')   ?: 'English',
      'finish'         => $get('finish')     ?: 'nonfoil',
      'is_signed'      => strtolower($get('signed'))  === 'yes' ? 1 : 0,
      'is_altered'     => strtolower($get('altered')) === 'yes' ? 1 : 0,
      'purchase_price' => $get('purchase price') !== '' ? $get('purchase price') : null,
      'acquired_at'    => $get('acquired')   ?: null,
      'notes'          => $get('notes')      ?: null,
      'scryfall_id'    => $get('scryfall id'),
      // Pre-filled price data from the CSV (no lookup needed)
      'price_usd'        => $get('price (usd)')        ?: null,
      'price_usd_foil'   => $get('price (usd foil)')   ?: null,
      'price_usd_etched' => $get('price (usd etched)') ?: null,
      'set_code'         => strtoupper($get('set code')),
      'set_name'         => $get('set name'),
      'collector_number' => $get('collector #'),
    ];
  }

  fclose($h);
  return $rows;
}

/* FLASH */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$batchErrors = $_SESSION['batch_errors'] ?? [];
unset($_SESSION['batch_errors']);

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!csrf_check($_POST['csrf'] ?? null)) back("Bad CSRF token.");

  $action    = $_POST['action'] ?? '';
  $condition = $_POST['card_condition'] ?? 'NM';
  $language  = $_POST['card_language']  ?? 'English';
  $finish    = $_POST['finish']         ?? 'nonfoil';

  /* -------- PREVIEW -------- */
  if ($action === 'preview') {
    $rows = [];
    $csvMode = false;

    // --- CSV upload ---
    if (!empty($_FILES['csv_file']['tmp_name'])) {
      $tmp = $_FILES['csv_file']['tmp_name'];

      // Peek at headers to determine format
      $fh = fopen($tmp, 'r');
      $bom = fread($fh, 3);
      if ($bom !== "\xEF\xBB\xBF") rewind($fh);
      $headers = fgetcsv($fh, 0, ',', '"', '\\') ?: [];
      fclose($fh);

      if (is_collection_csv($headers)) {
        // Rich collection CSV — parse with full field mapping
        $rows    = parse_collection_csv($tmp);
        $csvMode = true;
      } else {
        // Simple CSV — just Name, Qty columns
        if (($fh = fopen($tmp, 'r'))) {
          while (($data = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (!isset($data[0]) || trim($data[0]) === '') continue;
            $rows[] = [
              'source' => 'simple',
              'query'  => trim($data[0]),
              'qty'    => isset($data[1]) ? max(1, (int)$data[1]) : 1,
            ];
          }
          fclose($fh);
        }
      }
    }

    // --- Plaintext lines (only if no CSV) ---
    if (!$rows) {
      $lines = trim($_POST['lines'] ?? '');
      foreach (explode("\n", $lines) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $qty   = 1;
        $query = $line;
        if (preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
          $qty   = max(1, min(999, (int)$m[1]));
          $query = trim($m[2]);
        }
        $rows[] = ['source' => 'text', 'query' => $query, 'qty' => $qty];
      }
    }

    if (!$rows) back("No valid input.");

    // For text/simple CSV rows, resolve the oracle lookup NOW (once) and
    // store the scryfall_id + image URL so the display and confirm steps
    // never need to touch oracle-cards.json again.
    $needsLookup = array_filter($rows, fn($r) => ($r['source'] ?? '') !== 'csv');
    if ($needsLookup) {
      $allCards = loadAllCards(__DIR__ . '/oracle-cards.json');
      if (empty($allCards)) back("Missing oracle-cards.json — needed for text/simple CSV rows.");
      $rows = array_map(function($r) use ($allCards) {
        if (($r['source'] ?? '') === 'csv') return $r;
        $card = findCardInLocalJson($r['query'], $allCards);
        if (!$card) {
          $r['resolved'] = false;
          return $r;
        }
        $id = $card['id'];
        $r['resolved']    = true;
        $r['scryfall_id'] = $id;
        $r['oracle_id']   = $card['oracle_id'] ?? null;
        // Always use top-level name — never construct from card_faces,
        // which produces "Name // Name" for single-faced cards.
        $r['name']        = $card['name'];
        $r['type_line']   = $card['type_line'] ?? null;
        $r['set_code']    = strtoupper($card['set'] ?? '');
        $r['set_name']    = $card['set_name'] ?? null;
        $r['collector_number'] = $card['collector_number'] ?? null;
        $r['image_small'] = $card['image_uris']['small']
                         ?? $card['card_faces'][0]['image_uris']['small']
                         ?? "https://cards.scryfall.io/small/front/{$id[0]}/{$id[1]}/{$id}.jpg";
        $r['image_normal'] = $card['image_uris']['normal']
                          ?? $card['card_faces'][0]['image_uris']['normal']
                          ?? "https://cards.scryfall.io/normal/front/{$id[0]}/{$id[1]}/{$id}.jpg";
        $r['price_usd']        = $card['prices']['usd']        ?? null;
        $r['price_usd_foil']   = $card['prices']['usd_foil']   ?? null;
        $r['price_usd_etched'] = $card['prices']['usd_etched'] ?? null;
        // Store legalities JSON so the legality checker has accurate data.
        $r['legalities'] = !empty($card['legalities']) && is_array($card['legalities'])
          ? json_encode($card['legalities'])
          : null;
        return $r;
      }, $rows);
      // Re-index after array_map
      $rows = array_values($rows);
    }

    $_SESSION['batch_preview'] = [
      'rows'      => $rows,
      'csv_mode'  => $csvMode,
      'condition' => $condition,
      'language'  => $language,
      'finish'    => $finish,
    ];

    back("Preview ready (" . count($rows) . " rows).");
  }

  /* -------- CONFIRM -------- */
  if ($action === 'confirm') {
    $batch = $_SESSION['batch_preview'] ?? null;
    if (!$batch) back("No batch to import.");

    $rows      = $batch['rows'];
    $csvMode   = (bool)($batch['csv_mode'] ?? false);
    $condition = $batch['condition'];
    $language  = $batch['language'];
    $finish    = $batch['finish'];

    // Apply per-row overrides from the edit panels
    $overrides = $_POST['overrides'] ?? [];
    foreach ($overrides as $idx => $ov) {
      $idx = (int)$idx;
      if (!isset($rows[$idx])) continue;
      if (isset($ov['qty']))              $rows[$idx]['qty']              = max(1, min(999, (int)$ov['qty']));
      if (isset($ov['card_condition']))   $rows[$idx]['card_condition']   = $ov['card_condition'];
      if (isset($ov['finish']))           $rows[$idx]['finish']           = $ov['finish'];
      if (isset($ov['set_code']))         $rows[$idx]['set_code']         = strtoupper(trim($ov['set_code']));
      if (isset($ov['set_name']))         $rows[$idx]['set_name']         = trim($ov['set_name']);
      if (isset($ov['collector_number'])) $rows[$idx]['collector_number'] = trim($ov['collector_number']);
      if (isset($ov['purchase_price']))   $rows[$idx]['purchase_price']   = $ov['purchase_price'] !== '' ? $ov['purchase_price'] : null;
      if (isset($ov['acquired_at']))      $rows[$idx]['acquired_at']      = $ov['acquired_at'] !== '' ? $ov['acquired_at'] : null;
      if (isset($ov['notes']))            $rows[$idx]['notes']            = $ov['notes'] !== '' ? $ov['notes'] : null;
      // If the user picked a different printing, swap in the new scryfall_id and
      // clear cached image URLs so the upsert uses the correct card row.
      if (!empty($ov['scryfall_id']) && $ov['scryfall_id'] !== ($rows[$idx]['scryfall_id'] ?? '')) {
        $rows[$idx]['scryfall_id']  = trim($ov['scryfall_id']);
        $rows[$idx]['image_small']  = null; // will be rebuilt from scryfall_id
        $rows[$idx]['image_normal'] = null;
      }
    }

    $batchId = bin2hex(random_bytes(8));
    $added   = 0;
    $errors  = [];

    // Oracle data was resolved during preview — no file load needed here
    $allCards = [];

    $upsertCard = $pdo->prepare("
      INSERT INTO cards (
        scryfall_id, oracle_id, name, type_line,
        set_code, set_name, collector_number,
        image_small, image_normal,
        price_usd, price_usd_foil, price_usd_etched,
        legalities, price_updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        name             = VALUES(name),
        legalities       = VALUES(legalities),
        price_usd        = COALESCE(VALUES(price_usd),        price_usd),
        price_usd_foil   = COALESCE(VALUES(price_usd_foil),   price_usd_foil),
        price_usd_etched = COALESCE(VALUES(price_usd_etched), price_usd_etched)
    ");

    $getCardId = $pdo->prepare("SELECT id FROM cards WHERE scryfall_id = ? LIMIT 1");

    $upsertCollection = $pdo->prepare("
      INSERT INTO user_collection (
        user_id, card_id, qty,
        card_condition, card_language, finish,
        is_signed, is_altered,
        purchase_price, acquired_at, notes,
        batch_id
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
    ");

    foreach ($rows as $r) {
      try {
        $pdo->beginTransaction();

        if (($r['source'] ?? '') === 'csv') {
          // Collection CSV: use stored scryfall_id, look up card by it or upsert minimal
          $scryfallId = $r['scryfall_id'] ?? '';
          if ($scryfallId === '') {
            $errors[] = "{$r['query']}: missing Scryfall ID";
            $pdo->rollBack();
            continue;
          }

          // Try to find existing card row
          $getCardId->execute([$scryfallId]);
          $existingId = $getCardId->fetchColumn();

          if (!$existingId) {
            // Card not in DB yet — build image URLs from scryfall_id directly
            $id   = $scryfallId;
            $imgs = [
              "https://cards.scryfall.io/small/front/{$id[0]}/{$id[1]}/{$id}.jpg",
              "https://cards.scryfall.io/normal/front/{$id[0]}/{$id[1]}/{$id}.jpg",
            ];

            $upsertCard->execute([
              $scryfallId,
              null,
              $r['query'],
              null,
              $r['set_code']         ?: null,
              $r['set_name']         ?: null,
              $r['collector_number'] ?: null,
              $imgs[0],
              $imgs[1],
              $r['price_usd']        ?: null,
              $r['price_usd_foil']   ?: null,
              $r['price_usd_etched'] ?: null,
              null, // legalities not available from collection CSV export
            ]);
            $getCardId->execute([$scryfallId]);
            $existingId = $getCardId->fetchColumn();
          }

          if (!$existingId) {
            $errors[] = "{$r['query']}: could not resolve card ID";
            $pdo->rollBack();
            continue;
          }

          $upsertCollection->execute([
            $uid,
            (int)$existingId,
            $r['qty'],
            $r['card_condition'] ?: $condition,
            $r['card_language']  ?: $language,
            $r['finish']         ?: $finish,
            $r['is_signed']  ?? 0,
            $r['is_altered'] ?? 0,
            $r['purchase_price'] ?: null,
            $r['acquired_at']    ?: null,
            $r['notes']          ?: null,
            $batchId,
          ]);

        } else {
          // Text / simple CSV: oracle data was resolved during preview
          if (empty($r['resolved'])) {
            $errors[] = "{$r['query']}: not found in oracle data";
            $pdo->rollBack();
            continue;
          }

          $sid = $r['scryfall_id'];
          // Rebuild image URLs if they were cleared by a set override
          $imgSmall  = $r['image_small']  ?? null;
          $imgNormal = $r['image_normal'] ?? null;
          if (!$imgSmall || !$imgNormal) {
            $imgSmall  = "https://cards.scryfall.io/small/front/{$sid[0]}/{$sid[1]}/{$sid}.jpg";
            $imgNormal = "https://cards.scryfall.io/normal/front/{$sid[0]}/{$sid[1]}/{$sid}.jpg";
          }

          $upsertCard->execute([
            $sid,
            $r['oracle_id']        ?? null,
            $r['name'],
            $r['type_line']        ?? null,
            $r['set_code']         ?? null,
            $r['set_name']         ?? null,
            $r['collector_number'] ?? null,
            $imgSmall,
            $imgNormal,
            $r['price_usd']        ?? null,
            $r['price_usd_foil']   ?? null,
            $r['price_usd_etched'] ?? null,
            $r['legalities']       ?? null,
          ]);

          $getCardId->execute([$sid]);
          $cardId = (int)$getCardId->fetchColumn();

          $upsertCollection->execute([
            $uid,
            $cardId,
            $r['qty'],
            $condition,
            $language,
            $finish,
            0, 0, null, null, null,
            $batchId,
          ]);
        }

        $pdo->commit();
        $added++;

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = "{$r['query']}: DB error — " . $e->getMessage();
      }
    }

    unset($_SESSION['batch_preview']);

    $msg = "Imported {$added} card" . ($added !== 1 ? 's' : '') . ".";
    if ($errors) {
      $_SESSION['batch_errors'] = $errors;
      $msg .= " (" . count($errors) . " error" . (count($errors) !== 1 ? 's' : '') . ")";
    }

    back($msg);
  }

  if ($action === 'undo') {
    unset($_SESSION['batch_preview']);
    back("Cleared.");
  }
}

// Build preview display data — all data is already resolved in session, no file load needed
$previewRows    = [];
$previewCsvMode = false;
if (!empty($_SESSION['batch_preview'])) {
  $previewCsvMode = (bool)($_SESSION['batch_preview']['csv_mode'] ?? false);
  foreach ($_SESSION['batch_preview']['rows'] as $r) {
    $previewRows[] = $r;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <title>Batch Add</title>
  <link rel="stylesheet" href="./css/batch_add.css">
  <link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>
<body>
  <?php require_once __DIR__ . "/partials/header.php"; ?>

  <main>
    <div class="wrap">

      <?php if ($flash): ?>
        <div class="statusline ok" role="status"><?= h($flash) ?></div>
      <?php endif; ?>

      <?php if ($batchErrors): ?>
        <div class="statusline bad" role="status">
          <strong>Import errors:</strong>
          <ul style="margin:6px 0 0;padding-left:18px;">
            <?php foreach ($batchErrors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <h1>Batch Add</h1>
        <p class="small">
          Paste card names (one per line, optionally prefixed with quantity like <code>4 Lightning Bolt</code>),
          upload a simple CSV (Name, Qty columns), or upload a
          <strong>collection export CSV</strong> to restore a full backup including condition,
          finish, language, notes, and purchase prices.
        </p>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

          <label for="lines">Card names (text)</label>
          <textarea id="lines" name="lines" placeholder="4 Lightning Bolt&#10;2 Counterspell&#10;1 Black Lotus"></textarea>

          <label for="csv_file">Or upload CSV</label>
          <input id="csv_file" type="file" name="csv_file" accept=".csv,.tsv,text/csv">
          <p class="small" style="margin-top:4px;">
            Accepts a simple Name/Qty CSV <em>or</em> a full collection export CSV
            (from the Export button on your collection page).
          </p>

          <div class="defaults" id="defaultsBlock">
            <p class="small" style="margin:10px 0 4px;"><strong>Defaults</strong> — used for text/simple CSV rows only. Collection CSV rows use their own values.</p>
            <div class="defaultsGrid">
              <div>
                <label for="card_condition">Condition</label>
                <select id="card_condition" name="card_condition">
                  <?php foreach (['NM','LP','MP','HP','DMG'] as $c): ?>
                    <option value="<?= $c ?>"><?= $c ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="card_language">Language</label>
                <input id="card_language" name="card_language" value="English" maxlength="32">
              </div>
              <div>
                <label for="finish">Finish</label>
                <select id="finish" name="finish">
                  <option value="nonfoil">Non-foil</option>
                  <option value="foil">Foil</option>
                  <option value="etched">Etched</option>
                </select>
              </div>
            </div>
          </div>

          <div class="actions">
            <button name="action" value="preview">Preview</button>
            <button class="btnSecondary" name="action" value="undo">Clear</button>
          </div>
        </form>
      </section>

      <?php if ($previewRows): ?>
        <section class="card" style="margin-top:12px;">
          <h2>Preview
            <span class="small" style="font-weight:400;">
              — <?= count($previewRows) ?> row<?= count($previewRows) !== 1 ? 's' : '' ?>
              <?= $previewCsvMode ? ' (collection CSV — full data preserved)' : '' ?>
            </span>
          </h2>

          <div class="previewList">
            <?php foreach ($previewRows as $idx => $r):
              $id     = $r['scryfall_id'] ?? '';
              $imgSrc = $r['image_small'] ?? ($id !== ''
                ? "https://cards.scryfall.io/small/front/{$id[0]}/{$id[1]}/{$id}.jpg"
                : null);
              $resolved = ($r['resolved'] ?? null) !== false && $id !== '';
              $isCsv    = ($r['source'] ?? '') === 'csv';
            ?>
              <div class="previewItem <?= $resolved ? 'found' : 'notfound' ?>" id="pitem-<?= $idx ?>">
                <div class="previewItemTop">
                <div class="previewThumbWrap">
                  <?php if ($imgSrc): ?>
                    <div class="thumbWrap">
                      <img class="previewThumb" src="<?= h($imgSrc) ?>" alt="<?= h($r['query']) ?>" loading="lazy" referrerpolicy="no-referrer">
                      <div class="pop">
                        <img src="<?= h(str_replace('/small/', '/normal/', $imgSrc)) ?>" alt="<?= h($r['name'] ?? $r['query']) ?>" referrerpolicy="no-referrer">
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="previewThumb noImg"></div>
                  <?php endif; ?>
                </div>

                <div class="previewMeta">
                  <div class="previewName"><?= h($r['name'] ?? $r['query']) ?></div>
                  <?php if (!empty($r['set_name']) || !empty($r['set_code'])): ?>
                    <div class="small" id="printingMeta-<?= $idx ?>">
                      <?= h($r['set_name'] ?? '') ?><?= !empty($r['set_code']) ? ' (' . h($r['set_code']) . ')' : '' ?>
                      <?= !empty($r['collector_number']) ? ' #' . h($r['collector_number']) : '' ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($isCsv): ?>
                    <div class="small variantPills">
                      <span class="variantTag"><?= h($r['card_condition'] ?? 'NM') ?></span>
                      <?php if (!empty($r['finish']) && $r['finish'] !== 'nonfoil'): ?>
                        <span class="variantTag variantTag--finish"><?= h($r['finish']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($r['card_language']) && strtolower($r['card_language']) !== 'english'): ?>
                        <span class="variantTag variantTag--lang"><?= h($r['card_language']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($r['is_signed'])): ?>
                        <span class="variantTag variantTag--special">Signed</span>
                      <?php endif; ?>
                      <?php if (!empty($r['is_altered'])): ?>
                        <span class="variantTag variantTag--special">Altered</span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($r['purchase_price'])): ?>
                      <div class="small">Paid: $<?= h($r['purchase_price']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['notes'])): ?>
                      <div class="small">Notes: <?= h($r['notes']) ?></div>
                    <?php endif; ?>
                  <?php elseif (!$resolved): ?>
                    <div class="small notFoundLabel">Not found in oracle-cards.json</div>
                  <?php endif; ?>
                  <div class="previewSummary small">Qty: <?= (int)$r['qty'] ?></div>

                  <button type="button" class="btnEdit" style="margin-top:6px;"
                    onclick="toggleBatchEdit(<?= $idx ?>)"
                    id="editBtn-<?= $idx ?>"
                    aria-expanded="false"
                    aria-controls="batchEdit-<?= $idx ?>">Edit</button>

                  <!-- Hidden input so PHP receives the chosen scryfall_id on confirm -->
                  <input type="hidden"
                    id="ov_scryfall_id_<?= $idx ?>"
                    name="overrides[<?= $idx ?>][scryfall_id]"
                    value="<?= h($r['scryfall_id'] ?? '') ?>"
                    form="confirmForm">
                  <!-- Hidden input so PHP receives the chosen set_name on confirm -->
                  <input type="hidden"
                    id="ov_set_name_<?= $idx ?>"
                    name="overrides[<?= $idx ?>][set_name]"
                    value="<?= h($r['set_name'] ?? '') ?>"
                    form="confirmForm">
                </div><!-- /.previewMeta -->
                </div><!-- /.previewItemTop -->

                <!-- Collapsible edit panel -->
                <div class="batchEditPanel" id="batchEdit-<?= $idx ?>"
                  data-oracle-id="<?= h($r['oracle_id'] ?? '') ?>"
                  data-current-set="<?= h($r['set_code'] ?? '') ?>">
                  <div class="batchEditInner">

                    <div class="batchEditGrid">
                      <div>
                        <label for="ov_qty_<?= $idx ?>">Qty</label>
                        <input id="ov_qty_<?= $idx ?>" name="overrides[<?= $idx ?>][qty]"
                          type="number" min="1" max="999"
                          value="<?= (int)$r['qty'] ?>" form="confirmForm">
                      </div>
                      <div>
                        <label for="ov_cond_<?= $idx ?>">Condition</label>
                        <select id="ov_cond_<?= $idx ?>" name="overrides[<?= $idx ?>][card_condition]" form="confirmForm">
                          <?php foreach (['NM','LP','MP','HP','DMG'] as $c): ?>
                            <option value="<?= $c ?>"<?= ($r['card_condition'] ?? 'NM') === $c ? ' selected' : '' ?>><?= $c ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div>
                        <label for="ov_finish_<?= $idx ?>">Finish</label>
                        <select id="ov_finish_<?= $idx ?>" name="overrides[<?= $idx ?>][finish]" form="confirmForm">
                          <?php foreach (['nonfoil','foil','etched'] as $f): ?>
                            <option value="<?= $f ?>"<?= ($r['finish'] ?? 'nonfoil') === $f ? ' selected' : '' ?>><?= ucfirst($f) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="batchEditSetWrap">
                        <label for="ov_set_<?= $idx ?>">Set</label>
                        <select id="ov_set_<?= $idx ?>"
                          name="overrides[<?= $idx ?>][set_code]"
                          data-cn-target="ov_cn_<?= $idx ?>"
                          form="confirmForm"
                          disabled>
                          <option value="">Loading…</option>
                        </select>
                      </div>
                      <div>
                        <label for="ov_cn_<?= $idx ?>">Collector #</label>
                        <input id="ov_cn_<?= $idx ?>" name="overrides[<?= $idx ?>][collector_number]"
                          maxlength="32" value="<?= h($r['collector_number'] ?? '') ?>" form="confirmForm">
                      </div>
                      <div>
                        <label for="ov_paid_<?= $idx ?>">Paid ($)</label>
                        <input id="ov_paid_<?= $idx ?>" name="overrides[<?= $idx ?>][purchase_price]"
                          type="number" min="0" step="0.01" inputmode="decimal"
                          value="<?= h($r['purchase_price'] ?? '') ?>" form="confirmForm">
                      </div>
                      <div>
                        <label for="ov_acq_<?= $idx ?>">Acquired</label>
                        <input id="ov_acq_<?= $idx ?>" name="overrides[<?= $idx ?>][acquired_at]"
                          type="date" value="<?= h($r['acquired_at'] ?? '') ?>" form="confirmForm">
                      </div>
                      <div class="batchEditFull">
                        <label for="ov_notes_<?= $idx ?>">Notes</label>
                        <textarea id="ov_notes_<?= $idx ?>" name="overrides[<?= $idx ?>][notes]"
                          maxlength="500" rows="2" form="confirmForm"><?= h($r['notes'] ?? '') ?></textarea>
                      </div>
                    </div>

                  </div>
                </div>

              </div>
            <?php endforeach; ?>
          </div>

          <form method="post" style="margin-top:14px;" id="confirmForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="card_condition" value="<?= h($_SESSION['batch_preview']['condition'] ?? 'NM') ?>">
            <input type="hidden" name="card_language"  value="<?= h($_SESSION['batch_preview']['language']  ?? 'English') ?>">
            <input type="hidden" name="finish"         value="<?= h($_SESSION['batch_preview']['finish']    ?? 'nonfoil') ?>">
            <div class="actions">
              <button name="action" value="confirm">Confirm Import</button>
              <button class="btnSecondary" name="action" value="undo">Cancel</button>
            </div>
          </form>
        </section>
      <?php endif; ?>

    </div>
  </main>

  <script>
    // Position the fixed .pop next to its thumbnail on hover
    document.addEventListener('mouseover', e => {
      const wrap = e.target.closest('.thumbWrap');
      if (!wrap) return;
      const pop = wrap.querySelector('.pop');
      if (!pop) return;
      const rect = wrap.getBoundingClientRect();
      pop.style.top  = Math.round(rect.top + rect.height / 2 - pop.offsetHeight / 2) + 'px';
      pop.style.left = Math.round(rect.right + 8) + 'px';
    });

    // Cache oracle_id → printings so we only fetch once per card
    const printingsCache = {};

    async function loadPrintings(panel) {
      const oracleId  = panel.dataset.oracleId  || '';
      const currentSet = panel.dataset.currentSet || '';
      const setSelect  = panel.querySelector('select[name$="[set_code]"]');
      const cnInput    = panel.querySelector('input[name$="[collector_number]"]');

      if (!setSelect) return;

      // No oracle_id — nothing to look up (e.g. collection CSV rows)
      if (!oracleId) {
        setSelect.innerHTML = '<option value="">— unknown —</option>';
        setSelect.disabled = false;
        return;
      }

      // Already loaded for this oracle_id
      if (printingsCache[oracleId]) {
        populateSetSelect(setSelect, printingsCache[oracleId], currentSet, cnInput, panel);
        return;
      }

      setSelect.innerHTML = '<option value="">Loading…</option>';
      setSelect.disabled = true;

      try {
        // Fetch all printings for this oracle_id from Scryfall
        const url = `https://api.scryfall.com/cards/search?order=released&q=oracleid%3A${encodeURIComponent(oracleId)}&unique=prints`;
        const res  = await fetch(url);
        if (!res.ok) throw new Error('Scryfall error ' + res.status);
        const data = await res.json();

        const printings = (data.data || []).map(c => {
          const id = c.id || '';
          return {
            set_code:         (c.set || '').toUpperCase(),
            set_name:         c.set_name || '',
            collector_number: c.collector_number || '',
            released_at:      c.released_at || '',
            scryfall_id:      id,
            image_small:      c.image_uris?.small
                           ?? c.card_faces?.[0]?.image_uris?.small
                           ?? (id ? `https://cards.scryfall.io/small/front/${id[0]}/${id[1]}/${id}.jpg` : ''),
            image_normal:     c.image_uris?.normal
                           ?? c.card_faces?.[0]?.image_uris?.normal
                           ?? (id ? `https://cards.scryfall.io/normal/front/${id[0]}/${id[1]}/${id}.jpg` : ''),
          };
        });

        printingsCache[oracleId] = printings;
        populateSetSelect(setSelect, printings, currentSet, cnInput, panel);

      } catch (err) {
        console.error('Failed to load printings:', err);
        setSelect.innerHTML = '<option value="">— fetch failed —</option>';
        setSelect.disabled = false;
      }
    }

    function applyPrinting(printing, panel, cnInput) {
      if (!panel) return;
      const item = panel.closest('.previewItem');

      // Update hidden scryfall_id override input
      const sidInput = item?.querySelector('input[name$="[scryfall_id]"]');
      if (sidInput) sidInput.value = printing.scryfall_id || '';

      // Update hidden set_name override input
      const snInput = item?.querySelector('input[name$="[set_name]"]');
      if (snInput) snInput.value = printing.set_name || '';

      // Update collector number
      if (cnInput) cnInput.value = printing.collector_number || '';

      // Update the visible set/collector description line under the card name
      const metaEl = item?.querySelector('[id^="printingMeta-"]');
      if (metaEl) {
        const cn = printing.collector_number ? ` #${printing.collector_number}` : '';
        metaEl.textContent = `${printing.set_name} (${printing.set_code})${cn}`;
      }

      // Swap card art in the preview thumbnail and hover pop
      if (item) {
        const thumb = item.querySelector('.previewThumb');
        const pop   = item.querySelector('.pop img');
        if (thumb && printing.image_small)  thumb.src = printing.image_small;
        if (pop   && printing.image_normal) pop.src   = printing.image_normal;
      }
    }

    function populateSetSelect(setSelect, printings, currentSet, cnInput, panel) {
      setSelect.innerHTML = '';

      if (!printings.length) {
        setSelect.innerHTML = '<option value="">— no printings found —</option>';
        setSelect.disabled = false;
        return;
      }

      printings.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.set_code;
        opt.textContent = `${p.set_name} (${p.set_code}) #${p.collector_number}`;
        // Store all printing data on the option element
        opt.dataset.collectorNumber = p.collector_number;
        opt.dataset.scryfallId      = p.scryfall_id;
        opt.dataset.setName         = p.set_name;
        opt.dataset.imageSmall      = p.image_small;
        opt.dataset.imageNormal     = p.image_normal;
        if (p.set_code === currentSet.toUpperCase()) opt.selected = true;
        setSelect.appendChild(opt);
      });

      setSelect.disabled = false;

      // When the user picks a different printing, update art + scryfall_id + collector #
      setSelect.addEventListener('change', () => {
        const sel = setSelect.options[setSelect.selectedIndex];
        if (!sel) return;
        applyPrinting({
          scryfall_id:      sel.dataset.scryfallId      || '',
          set_name:         sel.dataset.setName         || '',
          set_code:         sel.value                   || '',
          collector_number: sel.dataset.collectorNumber || '',
          image_small:      sel.dataset.imageSmall      || '',
          image_normal:     sel.dataset.imageNormal     || '',
        }, panel, cnInput);
      });

      // Apply the currently-selected option so collector # and scryfall_id
      // are in sync from the moment the panel opens
      const sel = setSelect.options[setSelect.selectedIndex];
      if (sel) {
        applyPrinting({
          scryfall_id:      sel.dataset.scryfallId      || '',
          set_name:         sel.dataset.setName         || '',
          set_code:         sel.value                   || '',
          collector_number: sel.dataset.collectorNumber || '',
          image_small:      sel.dataset.imageSmall      || '',
          image_normal:     sel.dataset.imageNormal     || '',
        }, panel, cnInput);
      }
    }

    function toggleBatchEdit(idx) {
      const panel = document.getElementById('batchEdit-' + idx);
      const btn   = document.getElementById('editBtn-' + idx);
      if (!panel || !btn) return;

      const opening = !panel.classList.contains('is-open');
      panel.classList.toggle('is-open', opening);
      btn.setAttribute('aria-expanded', String(opening));
      btn.textContent = opening ? 'Close' : 'Edit';

      // Load printings the first time the panel is opened
      if (opening && !panel.dataset.printingsLoaded) {
        panel.dataset.printingsLoaded = '1';
        loadPrintings(panel);
      }
    }
  </script>
</body>
</html>