<?php
// import_deck.php
declare(strict_types=1);

require_once (__DIR__ . "/auth/config.php");
require_once (__DIR__ . "/auth/auth.php");

require_login();
$uid = (int)$_SESSION['uid'];

$user = current_user($pdo);
$loggedIn = true;

$flash = null;
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}

function back_with_flash(string $msg, int $deckId = 0): void {
  $_SESSION['flash'] = $msg;
  $to = $deckId > 0 ? ("import_deck.php?deck_id=" . $deckId) : "decks.php";
  header("Location: " . $to);
  exit;
}

function pick_images(array $card): array {
  if (!empty($card['image_uris'])) {
    return [
      'small'  => (string)($card['image_uris']['small']  ?? ''),
      'normal' => (string)($card['image_uris']['normal'] ?? ($card['image_uris']['small'] ?? '')),
    ];
  }
  if (!empty($card['card_faces'][0]['image_uris'])) {
    $f0 = $card['card_faces'][0]['image_uris'];
    return [
      'small'  => (string)($f0['small']  ?? ''),
      'normal' => (string)($f0['normal'] ?? ($f0['small'] ?? '')),
    ];
  }
  return ['small' => '', 'normal' => ''];
}

function loadAllCards(string $path): array {
  if (!is_file($path) || !is_readable($path)) {
    return [];
  }

  $json = @file_get_contents($path);
  if ($json === false) {
    return [];
  }

  // Decompress if gzipped
  $json = @gzdecode($json) ?: $json;

  $data = json_decode($json, true);
  if (!is_array($data)) {
    return [];
  }

  if (isset($data['data']) && is_array($data['data'])) {
    return $data['data'];
  }

  return $data;
}

function findCardNames(array $card): array {
  $names = [];

  if (!empty($card['name'])) {
    $names[] = (string)$card['name'];
  }

  if (!empty($card['card_faces']) && is_array($card['card_faces'])) {
    foreach ($card['card_faces'] as $face) {
      if (!empty($face['name'])) {
        $names[] = (string)$face['name'];
      }
    }
  }

  return array_values(array_unique($names, SORT_STRING));
}

// Layouts that are never legal deck cards and should be skipped during import.
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
    if (!isset($card['name']) || !$isPlayable($card)) {
      continue;
    }

    foreach (findCardNames($card) as $name) {
      if (mb_strtolower($name) === $needle) {
        return $card;
      }
    }
  }

  // Partial match fallback
  foreach ($allCards as $card) {
    if (!isset($card['name']) || !$isPlayable($card)) {
      continue;
    }

    foreach (findCardNames($card) as $name) {
      if (mb_stripos($name, $query) !== false) {
        return $card;
      }
    }
  }

  return null;
}

function parse_decklist_lines(string $raw): array {
  $lines = preg_split("/\r\n|\r|\n/", $raw) ?: [];
  $out = [];

  $section = 'main';

  foreach ($lines as $line) {
    $trimmed = trim($line);

    if ($trimmed === '') {
      if ($section === 'main' && count($out) > 0) {
        $section = 'side';
      }
      continue;
    }

    $line = $trimmed;

    // Ignore comments
    if (str_starts_with($line, '#') || str_starts_with($line, '//')) continue;

    // Detect section headers
    if (preg_match('/^(sideboard|sb)\b/i', $line)) {
      $section = 'side';
      continue;
    }
    if (preg_match('/^(deck|main deck|commander)\b/i', $line)) {
      $section = 'main';
      continue;
    }

    // Force sideboard via prefix
    if (preg_match('/^(SB:|SIDE:)\s*(.+)$/i', $line, $m)) {
      $section = 'side';
      $line = trim($m[2]);
    }

    $qty  = 1;
    $name = $line;

    // Extract quantity
    if (preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
      $qty  = max(1, min(999, (int)$m[1]));
      $name = $m[2];
    }

    // Strip set codes like (M21), [MH2], etc.
    $name = preg_replace('/[\(\[].*?[\)\]]/', '', $name);

    // Strip foil markers like *F*
    $name = str_replace(['*F*', 'FOIL'], '', $name);

    // Clean up collector numbers at end
    $name = preg_replace('/\s+\d+[A-Za-z]*$/', '', $name);

    $name = trim($name);

    if ($name === '') continue;

    $query = '!"' . str_replace('"', '\"', $name) . '"';

    $out[] = [
      'raw'     => $line,
      'section' => $section,
      'qty'     => $qty,
      'name'    => $name,
      'query'   => $query,
    ];
  }

  return $out;
}

function to_nullable_decimal_2(string $raw): ?float {
  $raw = trim($raw);
  if ($raw === '') return null;
  if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) return null;
  return (float)$raw;
}

$deckId = (int)($_GET['deck_id'] ?? ($_POST['deck_id'] ?? 0));
if ($deckId <= 0) {
  back_with_flash("Missing deck_id. Open a deck, then choose Import decklist.", 0);
}

// Verify ownership
$stmt = $pdo->prepare("SELECT id, name, format FROM decks WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$deckId, $uid]);
$deck = $stmt->fetch();
if (!$deck) {
  back_with_flash("Deck not found.", 0);
}

$mode           = 'form'; // form | preview
$preview        = [];
$rawDecklist    = '';
$defaultFinish  = 'nonfoil';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(400);
    header("Content-Type: text/plain; charset=utf-8");
    exit("Bad CSRF token.");
  }

  $action        = (string)($_POST['action'] ?? 'preview');
  $defaultFinish = strtolower(trim((string)($_POST['finish'] ?? 'nonfoil')));
  if (!in_array($defaultFinish, ['nonfoil', 'foil', 'etched'], true)) {
    back_with_flash("Finish must be nonfoil/foil/etched.", $deckId);
  }

  $rawDecklist = (string)($_POST['decklist'] ?? '');

  // ── Preview ───────────────────────────────────────────────────────────────
  if ($action === 'preview') {
    $items = parse_decklist_lines($rawDecklist);
    if (!$items) back_with_flash("Paste a decklist first.", $deckId);

    $allCardsPath = __DIR__ . '/oracle-cards.json';
    $allCards = loadAllCards($allCardsPath);
    if (empty($allCards)) {
      back_with_flash("Bulk data file not found. Please download oracle-cards.json from Scryfall bulk data.", $deckId);
    }

    $mode = 'preview';

    foreach ($items as $it) {
      $card = findCardInLocalJson($it['name'], $allCards);
      if (!$card) {
        $preview[] = [
          'ok'      => false,
          'raw'     => $it['raw'],
          'section' => $it['section'],
          'qty'     => $it['qty'],
          'name'    => $it['name'],
          'error'   => 'No match in local JSON.',
        ];
        continue;
      }

      $img = pick_images($card);

      // Encode legalities JSON; store null when absent so the DB column stays
      // accurate and the legality checker in deck.php can trust the data.
      $legalitiesRaw = !empty($card['legalities']) && is_array($card['legalities'])
        ? json_encode($card['legalities'])
        : null;

      $preview[] = [
        'ok'               => true,
        'raw'              => $it['raw'],
        'section'          => $it['section'],
        'qty'              => $it['qty'],

        'scryfall_id'      => (string)($card['id']               ?? ''),
        'oracle_id'        => (string)($card['oracle_id']         ?? ''),
        // Always use the top-level name — never construct from card_faces,
        // which would produce "Name // Name" for single-faced cards.
        'name'             => (string)($card['name']              ?? $it['name']),
        'type_line'        => (string)($card['type_line']         ?? ''),
        'set_code'         => strtoupper((string)($card['set']    ?? '')),
        'set_name'         => (string)($card['set_name']          ?? ''),
        'collector_number' => (string)($card['collector_number']  ?? ''),

        'image_small'      => $img['small'],
        'image_normal'     => $img['normal'],

        'price_usd'        => (string)($card['prices']['usd']        ?? ''),
        'price_usd_foil'   => (string)($card['prices']['usd_foil']   ?? ''),
        'price_usd_etched' => (string)($card['prices']['usd_etched'] ?? ''),

        'legalities'       => $legalitiesRaw,
      ];
    }

    $_SESSION['deck_preview'] = $preview;
  }

  // ── Import ────────────────────────────────────────────────────────────────
  if ($action === 'import') {
    $items = $_SESSION['deck_preview'] ?? [];
    if (empty($items)) back_with_flash("No preview data. Use Preview first.", $deckId);

    unset($_SESSION['deck_preview']);

    try {
      $pdo->beginTransaction();

      $upsertCard = $pdo->prepare("
        INSERT INTO cards
          (scryfall_id, oracle_id, name, type_line, set_code, set_name, collector_number,
           image_small, image_normal, price_usd, price_usd_foil, price_usd_etched,
           legalities, price_updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          oracle_id          = VALUES(oracle_id),
          name               = VALUES(name),
          type_line          = VALUES(type_line),
          set_code           = VALUES(set_code),
          set_name           = VALUES(set_name),
          collector_number   = VALUES(collector_number),
          image_small        = VALUES(image_small),
          image_normal       = VALUES(image_normal),
          price_usd          = VALUES(price_usd),
          price_usd_foil     = VALUES(price_usd_foil),
          price_usd_etched   = VALUES(price_usd_etched),
          legalities         = VALUES(legalities),
          price_updated_at   = NOW()
      ");

      $getCardId = $pdo->prepare("SELECT id FROM cards WHERE scryfall_id = ? LIMIT 1");

      $upsertDeckCard = $pdo->prepare("
        INSERT INTO deck_cards (deck_id, card_id, section, qty, finish)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          qty        = qty + VALUES(qty),
          updated_at = CURRENT_TIMESTAMP
      ");

      $added = 0;

      foreach ($items as $it) {
        if (!is_array($it) || empty($it['ok'])) continue;

        $scryfallId = trim((string)($it['scryfall_id'] ?? ''));
        $name       = trim((string)($it['name']        ?? ''));
        $section    = (string)($it['section']           ?? 'main');
        $qty        = (int)($it['qty']                  ?? 1);

        if ($scryfallId === '' || $name === '') continue;
        if ($section !== 'side') $section = 'main';
        if ($qty < 1)   $qty = 1;
        if ($qty > 999) $qty = 999;

        $upsertCard->execute([
          $scryfallId,
          (($it['oracle_id']          ?? '') !== '' ? (string)$it['oracle_id']          : null),
          $name,
          (($it['type_line']          ?? '') !== '' ? (string)$it['type_line']           : null),
          (($it['set_code']           ?? '') !== '' ? (string)$it['set_code']            : null),
          (($it['set_name']           ?? '') !== '' ? (string)$it['set_name']            : null),
          (($it['collector_number']   ?? '') !== '' ? (string)$it['collector_number']    : null),
          (($it['image_small']        ?? '') !== '' ? (string)$it['image_small']         : null),
          (($it['image_normal']       ?? '') !== '' ? (string)$it['image_normal']        : null),
          to_nullable_decimal_2((string)($it['price_usd']        ?? '')),
          to_nullable_decimal_2((string)($it['price_usd_foil']   ?? '')),
          to_nullable_decimal_2((string)($it['price_usd_etched'] ?? '')),
          (($it['legalities']         ?? null) !== null ? (string)$it['legalities']      : null),
        ]);

        $getCardId->execute([$scryfallId]);
        $row = $getCardId->fetch();
        if (!$row) continue;

        $cardId = (int)$row['id'];

        $upsertDeckCard->execute([
          $deckId,
          $cardId,
          $section,
          $qty,
          $defaultFinish,
        ]);

        $added++;
      }

      $pdo->commit();

      $_SESSION['flash'] = "Imported {$added} line(s) into the deck.";
      header("Location: deck.php?id=" . $deckId);
      exit;
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      back_with_flash("Database error while importing decklist.", $deckId);
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Import decklist — <?= h((string)$deck['name']) ?></title>
  <link rel="stylesheet" href="/css/import_deck.css" />
  <link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>
<body>
  <a class="skip" href="#main">Skip to main content</a>

  <?php require_once __DIR__ . "/partials/header.php"; ?>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="t">
        <h1 id="t">Import decklist</h1>
        <p>
          Deck: <strong><?= h((string)$deck['name']) ?></strong>
          <?php if (!empty($deck['format'])): ?>
            <span class="small">(<?= h((string)$deck['format']) ?>)</span>
          <?php endif; ?>
        </p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <p class="small">
          Paste lines like <code>4 Lightning Bolt</code> or <code>SB: 2 Disenchant</code>.
          Use a blank line to separate mainboard from sideboard when no explicit header is provided.
          We'll look up each card in the local Scryfall bulk data.
        </p>

        <form method="post" action="import_deck.php">
          <input type="hidden" name="csrf"    value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">

          <label for="finish">Default finish for imported cards</label>
          <select id="finish" name="finish">
            <option value="nonfoil"<?= $defaultFinish === 'nonfoil' ? ' selected' : '' ?>>Non-foil</option>
            <option value="foil"<?=    $defaultFinish === 'foil'    ? ' selected' : '' ?>>Foil</option>
            <option value="etched"<?=  $defaultFinish === 'etched'  ? ' selected' : '' ?>>Etched</option>
          </select>

          <label for="decklist">Decklist text</label>
          <textarea id="decklist" name="decklist" required><?= h($rawDecklist) ?></textarea>

          <div class="btnRow">
            <button type="submit" name="action" value="preview">Preview</button>
            <a class="btnSecondary" href="deck.php?id=<?= (int)$deckId ?>">Cancel</a>
          </div>
        </form>

        <?php if ($mode === 'preview'): ?>
          <h2 style="margin-top:14px;">Preview</h2>
          <div class="preview" aria-label="Preview results">
            <?php $lastSection = ''; ?>
            <?php foreach ($preview as $p): ?>
              <?php if ($p['section'] !== $lastSection): ?>
                <?php $lastSection = $p['section']; ?>
                <div class="sectionHeader" style="margin-top:16px; font-weight:700;">
                  <?= $lastSection === 'side' ? 'Sideboard' : 'Mainboard' ?>
                </div>
              <?php endif; ?>
              <div class="item">
                <?php if (!$p['ok']): ?>
                  <div><strong><?= h((string)$p['raw']) ?></strong></div>
                  <div class="statusline bad" role="status">Not found: <?= h((string)$p['name']) ?></div>
                <?php else: ?>
                  <div class="itemGrid">
                    <div>
                      <?php if (!empty($p['image_small'])): ?>
                        <img class="thumb" src="<?= h((string)$p['image_small']) ?>"
                             alt="Card image: <?= h((string)$p['name']) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="thumb" role="img" aria-label="No image available"></div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div style="font-weight:900;"><?= h((string)$p['name']) ?></div>
                      <div class="small"><?= h((string)$p['type_line']) ?></div>
                      <div class="small">Section: <?= h((string)$p['section']) ?> • Qty: <?= (int)$p['qty'] ?></div>
                      <div class="small"><?= h((string)$p['set_name']) ?> <?= h((string)$p['set_code']) ?> #<?= h((string)$p['collector_number']) ?></div>
                      <div class="small">USD: <?= h((string)($p['price_usd'] ?: '—')) ?> • Foil: <?= h((string)($p['price_usd_foil'] ?: '—')) ?> • Etched: <?= h((string)($p['price_usd_etched'] ?: '—')) ?></div>
                      <div class="small">Legalities: <?= $p['legalities'] !== null ? '<span style="color:var(--ok)">✓ stored</span>' : '<span style="color:var(--bad)">missing</span>' ?></div>
                      <div class="small">From: <code><?= h((string)$p['raw']) ?></code></div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <form method="post" action="import_deck.php" style="margin-top:12px;">
            <input type="hidden" name="csrf"    value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
            <input type="hidden" name="finish"  value="<?= h($defaultFinish) ?>">
            <input type="hidden" name="action"  value="import">

            <div class="btnRow">
              <button type="submit">Import into deck</button>
              <a class="btnSecondary" href="deck.php?id=<?= (int)$deckId ?>">Cancel</a>
            </div>

            <p class="small" style="margin-top:10px;">
              Only preview rows that were found will be imported.
            </p>
          </form>
        <?php endif; ?>

      </section>

      <?php require_once __DIR__ . "/partials/footer.php"; ?>
    </div>
  </main>
  <style>
  #sr-widget {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    width: 280px;
    background: var(--surface, #1a1d2e);
    border: 1px solid var(--border, #2a2f45);
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.5);
    font-size: 0.82rem;
    color: var(--text, #e2e8f0);
  }

  /* Header bar — acts as the collapse toggle */
  #sr-widget-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid var(--border, #2a2f45);
    border-radius: 12px 12px 0 0;
    user-select: none;
    background: var(--surface-raised, #1e2235);
  }

  #sr-widget-header:focus {
    outline: 2px solid var(--accent, #6366f1);
    outline-offset: -2px;
  }

  #sr-widget-title {
    font-weight: 700;
    font-size: 0.75rem;
    letter-spacing: 0.06em;
    color: var(--text-muted, #94a3b8);
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* Pulsing dot shows speaking state */
  #sr-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--text-muted, #94a3b8);
    transition: background 0.25s;
    flex-shrink: 0;
  }
  #sr-dot.speaking {
    background: var(--success, #22c55e);
    animation: sr-pulse 1.1s infinite;
  }
  #sr-dot.paused { background: var(--warning, #f59e0b); }

  @keyframes sr-pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.3; }
  }

  #sr-toggle-btn {
    background: none;
    border: none;
    color: var(--text-muted, #94a3b8);
    cursor: pointer;
    font-size: 0.85rem;
    padding: 2px 4px;
    line-height: 1;
    border-radius: 4px;
  }
  #sr-toggle-btn:hover,
  #sr-toggle-btn:focus { color: var(--text, #e2e8f0); outline: none; }

  /* Collapsible body */
  #sr-widget-body {
    padding: 12px 14px;
  }
  #sr-widget.sr-collapsed #sr-widget-body { display: none; }
  #sr-widget.sr-collapsed { border-radius: 12px; }
  #sr-widget.sr-collapsed #sr-widget-header { border-bottom: none; border-radius: 12px; }

  /* Currently-reading display */
  #sr-display {
    background: var(--surface-sunken, #13151f);
    border: 1px solid var(--border, #2a2f45);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 0.72rem;
    color: var(--text-muted, #94a3b8);
    min-height: 44px;
    max-height: 72px;
    overflow: hidden;
    line-height: 1.55;
    margin-bottom: 10px;
  }
  #sr-display.active { color: var(--text, #e2e8f0); }

  /* Thin progress bar */
  #sr-progress-track {
    height: 3px;
    background: var(--border, #2a2f45);
    border-radius: 2px;
    margin-bottom: 10px;
    overflow: hidden;
  }
  #sr-progress-fill {
    height: 100%;
    width: 0%;
    background: var(--accent, #6366f1);
    border-radius: 2px;
    transition: width 0.3s;
  }

  /* Button grid */
  #sr-controls {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-bottom: 10px;
  }

  .sr-btn {
    background: var(--surface-raised, #1e2235);
    border: 1px solid var(--border, #2a2f45);
    color: var(--text, #e2e8f0);
    border-radius: 8px;
    padding: 7px 4px;
    font-family: inherit;
    font-size: 0.65rem;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    transition: background 0.15s, border-color 0.15s;
  }
  .sr-btn:hover { background: var(--surface-hover, #252a3d); border-color: var(--accent, #6366f1); }
  .sr-btn:focus { outline: 2px solid var(--accent, #6366f1); outline-offset: 1px; }
  .sr-btn.sr-active { border-color: var(--success, #22c55e); color: var(--success, #22c55e); }
  .sr-btn .sr-icon { font-size: 1rem; line-height: 1; }
  .sr-btn .sr-lbl  { color: var(--text-muted, #94a3b8); font-size: 0.6rem; }
  .sr-btn.sr-active .sr-lbl { color: var(--success, #22c55e); }

  /* Speed slider */
  #sr-speed-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.68rem;
    color: var(--text-muted, #94a3b8);
    margin-bottom: 8px;
  }
  #sr-speed-row label { flex-shrink: 0; }
  #sr-speed-val { flex-shrink: 0; width: 28px; text-align: right; color: var(--text, #e2e8f0); }
  #sr-rate {
    flex: 1;
    -webkit-appearance: none;
    appearance: none;
    height: 3px;
    background: var(--border, #2a2f45);
    border-radius: 2px;
    outline: none;
    cursor: pointer;
  }
  #sr-rate::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 12px; height: 12px;
    border-radius: 50%;
    background: var(--accent, #6366f1);
    cursor: pointer;
  }
  #sr-rate:focus { outline: 2px solid var(--accent, #6366f1); border-radius: 2px; }

  /* Keyboard hint */
  #sr-hint {
    font-size: 0.6rem;
    color: var(--text-muted, #94a3b8);
    text-align: center;
    border-top: 1px solid var(--border, #2a2f45);
    padding-top: 8px;
    margin-top: 2px;
    line-height: 1.6;
  }
</style>

<!-- Widget markup — full ARIA support -->
<div id="sr-widget"
     role="region"
     aria-label="Screen reader controls"
     aria-live="off">

  <div id="sr-widget-header"
       role="button"
       tabindex="0"
       aria-expanded="true"
       aria-controls="sr-widget-body"
       title="Toggle screen reader panel">
    <div id="sr-widget-title">
      <div id="sr-dot" aria-hidden="true"></div>
      Screen Reader
    </div>
    <button id="sr-toggle-btn"
            aria-label="Collapse screen reader panel"
            tabindex="-1"
            aria-hidden="true">▼</button>
  </div>

  <div id="sr-widget-body">

    <!-- Live region — screen readers announce text changes here -->
    <div id="sr-display"
         role="status"
         aria-live="polite"
         aria-atomic="true"
         aria-label="Currently reading">
      Press Play to read this page aloud.
    </div>

    <!-- Progress bar -->
    <div id="sr-progress-track" aria-hidden="true">
      <div id="sr-progress-fill"></div>
    </div>

    <!-- Controls -->
    <div id="sr-controls" role="group" aria-label="Playback controls">
      <button class="sr-btn" id="sr-btn-play"   aria-label="Play — read page aloud">
        <span class="sr-icon" aria-hidden="true">▶</span>
        <span class="sr-lbl">Play</span>
      </button>
      <button class="sr-btn" id="sr-btn-pause"  aria-label="Pause reading">
        <span class="sr-icon" aria-hidden="true">⏸</span>
        <span class="sr-lbl">Pause</span>
      </button>
      <button class="sr-btn" id="sr-btn-stop"   aria-label="Stop reading">
        <span class="sr-icon" aria-hidden="true">⏹</span>
        <span class="sr-lbl">Stop</span>
      </button>
      <button class="sr-btn" id="sr-btn-prev"   aria-label="Previous section">
        <span class="sr-icon" aria-hidden="true">⏮</span>
        <span class="sr-lbl">Prev</span>
      </button>
      <button class="sr-btn" id="sr-btn-next"   aria-label="Next section">
        <span class="sr-icon" aria-hidden="true">⏭</span>
        <span class="sr-lbl">Next</span>
      </button>
      <button class="sr-btn" id="sr-btn-repeat" aria-label="Repeat current section">
        <span class="sr-icon" aria-hidden="true">🔁</span>
        <span class="sr-lbl">Repeat</span>
      </button>
    </div>

    <!-- Speed control -->
    <div id="sr-speed-row">
      <label for="sr-rate">Speed</label>
      <input type="range"
             id="sr-rate"
             min="0.5" max="2" step="0.1" value="1"
             aria-label="Reading speed"
             aria-valuemin="0.5"
             aria-valuemax="2"
             aria-valuenow="1">
      <span id="sr-speed-val" aria-live="polite" aria-atomic="true">1.0×</span>
    </div>

    <!-- Keyboard shortcuts hint -->
    <div id="sr-hint" aria-label="Keyboard shortcuts">
      Alt+R Play · Alt+P Pause · Alt+S Stop<br>
      Alt+← Prev · Alt+→ Next
    </div>

  </div>
</div>

<script>
(function () {
  'use strict';

  var synth = window.speechSynthesis;
  var display    = document.getElementById('sr-display');

  // Graceful fallback if browser doesn't support speech
  if (!synth) {
    display.textContent = '⚠ Your browser does not support speech synthesis.';
    return;
  }

  var chunks     = [];
  var idx        = 0;
  var playing    = false;
  var paused     = false;
  var collapsed  = false;

  var dot        = document.getElementById('sr-dot');
  var fill       = document.getElementById('sr-progress-fill');
  var btnPlay    = document.getElementById('sr-btn-play');
  var btnPause   = document.getElementById('sr-btn-pause');
  var btnStop    = document.getElementById('sr-btn-stop');
  var btnPrev    = document.getElementById('sr-btn-prev');
  var btnNext    = document.getElementById('sr-btn-next');
  var btnRepeat  = document.getElementById('sr-btn-repeat');
  var rateSlider = document.getElementById('sr-rate');
  var rateVal    = document.getElementById('sr-speed-val');
  var widget     = document.getElementById('sr-widget');
  var header     = document.getElementById('sr-widget-header');
  var toggleBtn  = document.getElementById('sr-toggle-btn');

  // ── Collect readable text from the page ──────────────────────────────────
  // Reads headings, paragraphs, list items, labels, table cells,
  // ARIA labels on buttons/links, and flash messages
  function collectChunks() {
    var out  = [];
    var seen = new Set();

    // Elements with meaningful text content
    var textEls = document.querySelectorAll(
      'h1, h2, h3, h4, p, li, label, td, th, .statusline, [role="status"]'
    );

    textEls.forEach(function (el) {
      if (el.closest('#sr-widget')) return;          // skip widget itself
      if (el.closest('script, style')) return;       // skip code
      var t = (el.innerText || '').trim().replace(/\s+/g, ' ');
      if (!t || t.length < 2 || seen.has(t)) return;
      seen.add(t);

      // Prefix headings so the listener knows hierarchy
      var tag = el.tagName.toLowerCase();
      var prefix = { h1:'Heading 1: ', h2:'Heading 2: ', h3:'Heading 3: ', h4:'Heading 4: ' };
      out.push((prefix[tag] || '') + t);
    });

    // Also pick up aria-label attributes on interactive elements
    // so buttons/links with no visible text are still announced
    var ariaEls = document.querySelectorAll(
      'button[aria-label], a[aria-label], [role="button"][aria-label]'
    );
    ariaEls.forEach(function (el) {
      if (el.closest('#sr-widget')) return;
      var t = el.getAttribute('aria-label').trim();
      if (!t || seen.has(t)) return;
      seen.add(t);
      out.push('Button: ' + t);
    });

    return out;
  }

  // ── Speak one chunk then auto-advance ────────────────────────────────────
  function speakAt(i) {
    if (i < 0 || i >= chunks.length) { stop(); return; }
    synth.cancel();
    idx = i;

    var text = chunks[i];
    display.textContent = text;
    display.classList.add('active');
    fill.style.width = ((i / chunks.length) * 100) + '%';

    var utt = new SpeechSynthesisUtterance(text);
    utt.rate = parseFloat(rateSlider.value);

    // Auto-advance when this chunk finishes
    utt.onend = function () {
      if (playing && !paused) speakAt(idx + 1);
    };
    utt.onerror = function (e) {
      if (e.error !== 'interrupted') {
        display.textContent = '⚠ Speech error: ' + e.error;
      }
    };

    synth.speak(utt);
    setDot('speaking');
  }

  // ── Controls ─────────────────────────────────────────────────────────────
  function play() {
    chunks  = collectChunks();
    if (!chunks.length) { display.textContent = 'Nothing readable found on this page.'; return; }
    playing = true;
    paused  = false;
    btnPlay.classList.add('sr-active');
    speakAt(idx);
  }

  function pause() {
    if (!playing) return;
    if (paused) {
      // Resume
      paused = false;
      synth.resume();
      setDot('speaking');
      btnPause.classList.remove('sr-active');
      btnPause.querySelector('.sr-icon').textContent = '⏸';
      btnPause.querySelector('.sr-lbl').textContent  = 'Pause';
      btnPause.setAttribute('aria-label', 'Pause reading');
    } else {
      // Pause
      paused = true;
      synth.pause();
      setDot('paused');
      btnPause.classList.add('sr-active');
      btnPause.querySelector('.sr-icon').textContent = '▶';
      btnPause.querySelector('.sr-lbl').textContent  = 'Resume';
      btnPause.setAttribute('aria-label', 'Resume reading');
    }
  }

  function stop() {
    playing = false;
    paused  = false;
    synth.cancel();
    setDot('idle');
    fill.style.width = '0%';
    display.textContent = 'Stopped.';
    display.classList.remove('active');
    btnPlay.classList.remove('sr-active');
    btnPause.classList.remove('sr-active');
    btnPause.querySelector('.sr-icon').textContent = '⏸';
    btnPause.querySelector('.sr-lbl').textContent  = 'Pause';
    btnPause.setAttribute('aria-label', 'Pause reading');
  }

  function prev() {
    var i = Math.max(0, idx - 1);
    if (playing) { speakAt(i); }
    else { idx = i; display.textContent = chunks[i] || ''; }
  }

  function next() {
    var i = Math.min(chunks.length - 1, idx + 1);
    if (playing) { speakAt(i); }
    else { idx = i; display.textContent = chunks[i] || ''; }
  }

  function repeat() {
    if (!chunks.length) chunks = collectChunks();
    playing = true; paused = false;
    btnPlay.classList.add('sr-active');
    speakAt(idx);
  }

  // ── Dot state ─────────────────────────────────────────────────────────────
  function setDot(state) {
    dot.className = 'sr-dot' +
      (state === 'speaking' ? ' speaking' : state === 'paused' ? ' paused' : '');
  }

  // ── Collapse toggle ───────────────────────────────────────────────────────
  function toggleCollapse() {
    collapsed = !collapsed;
    widget.classList.toggle('sr-collapsed', collapsed);
    toggleBtn.textContent = collapsed ? '▲' : '▼';
    header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggleBtn.setAttribute('aria-label',
      collapsed ? 'Expand screen reader panel' : 'Collapse screen reader panel');
  }

  // ── Speed slider ──────────────────────────────────────────────────────────
  rateSlider.addEventListener('input', function () {
    var v = parseFloat(rateSlider.value).toFixed(1);
    rateVal.textContent = v + '×';
    rateSlider.setAttribute('aria-valuenow', v);
  });

  // ── Button events ─────────────────────────────────────────────────────────
  btnPlay.addEventListener('click',   play);
  btnPause.addEventListener('click',  pause);
  btnStop.addEventListener('click',   stop);
  btnPrev.addEventListener('click',   prev);
  btnNext.addEventListener('click',   next);
  btnRepeat.addEventListener('click', repeat);

  // ── Header collapse (click + keyboard) ───────────────────────────────────
  header.addEventListener('click', toggleCollapse);
  header.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleCollapse(); }
  });

  // ── Global keyboard shortcuts ─────────────────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if (['INPUT','SELECT','TEXTAREA'].includes(e.target.tagName)) return;
    if (!e.altKey) return;
    var map = { r: play, p: pause, s: stop, ArrowLeft: prev, ArrowRight: next };
    var fn  = map[e.key] || map[e.key.toLowerCase()];
    if (fn) { e.preventDefault(); fn(); }
  });

})();
</script>
<!-- ============================================================
     end of screen reader
     ============================================================ -->

</body>
</html>
