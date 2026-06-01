<?php
// deck.php (editable deck cards + totals + inline Scryfall search)

declare(strict_types=1);

require_once (__DIR__ . "/auth/config.php");
require_once (__DIR__ . "/auth/auth.php");

require_login();
$uid = (int)$_SESSION['uid'];

$user = current_user($pdo);

$flash = null;
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}

$deckId = (int)($_GET['id'] ?? 0);
if ($deckId <= 0) {
  http_response_code(400);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Missing deck id.");
}

$stmt = $pdo->prepare("
  SELECT id, name, format, description, is_public, updated_at
  FROM decks
  WHERE id = ? AND user_id = ?
  LIMIT 1
");
$stmt->execute([$deckId, $uid]);
$deck = $stmt->fetch();
if (!$deck) {
  http_response_code(404);
  header("Content-Type: text/plain; charset=utf-8");
  exit("Deck not found.");
}

$stmt = $pdo->prepare("
  SELECT
    dc.id AS deck_card_id,
    dc.section,
    dc.qty,
    dc.finish,
    dc.updated_at,

    c.id AS card_id,
    c.scryfall_id,
    c.name,
    c.type_line,
    c.set_code,
    c.set_name,
    c.collector_number,
    c.image_small,
    c.image_normal,
    c.price_usd,
    c.price_usd_foil,
    c.price_usd_etched,
    c.price_updated_at,
    c.legalities,

    COALESCE(owned.owned_qty, 0) AS owned_qty
  FROM deck_cards dc
  JOIN cards c ON c.id = dc.card_id
  LEFT JOIN (
    SELECT card_id, finish, SUM(qty) AS owned_qty
    FROM user_collection
    WHERE user_id = ?
    GROUP BY card_id, finish
  ) owned ON owned.card_id = dc.card_id AND owned.finish = dc.finish
  WHERE dc.deck_id = ?
  ORDER BY dc.section ASC, c.name ASC
");
$stmt->execute([$uid, $deckId]);
$deckCards = $stmt->fetchAll();

$main = [];
$side = [];
foreach ($deckCards as $r) {
  if (($r['section'] ?? 'main') === 'side') $side[] = $r;
  else $main[] = $r;
}

function money_val($v): string {
  if ($v === null || $v === '') return '';
  return '$' . number_format((float)$v, 2);
}
function finish_price(array $r): ?float {
  $finish = (string)($r['finish'] ?? 'nonfoil');
  if ($finish === 'foil') return ($r['price_usd_foil'] !== null && $r['price_usd_foil'] !== '') ? (float)$r['price_usd_foil'] : null;
  if ($finish === 'etched') return ($r['price_usd_etched'] !== null && $r['price_usd_etched'] !== '') ? (float)$r['price_usd_etched'] : null;
  return ($r['price_usd'] !== null && $r['price_usd'] !== '') ? (float)$r['price_usd'] : null;
}
function sum_qty(array $rows): int {
  $t = 0; foreach ($rows as $r) $t += (int)$r['qty']; return $t;
}

$mainQty  = sum_qty($main);
$sideQty  = sum_qty($side);

$mainEst = 0.0; $mainKnown = 0;
foreach ($main as $r) { $p = finish_price($r); if ($p !== null) { $mainEst += $p * (int)$r['qty']; $mainKnown += (int)$r['qty']; } }
$sideEst = 0.0; $sideKnown = 0;
foreach ($side as $r) { $p = finish_price($r); if ($p !== null) { $sideEst += $p * (int)$r['qty']; $sideKnown += (int)$r['qty']; } }
$deckEst   = $mainEst + $sideEst;
$deckKnown = $mainKnown + $sideKnown;

// ── Legality checker ─────────────────────────────────────────────────────────
// Maps our format names to Scryfall's legalities keys
const FORMAT_SCRYFALL_KEY = [
  'Standard'    => 'standard',
  'Pioneer'     => 'pioneer',
  'Modern'      => 'modern',
  'Legacy'      => 'legacy',
  'Vintage'     => 'vintage',
  'Pauper'      => 'pauper',
  'Commander'   => 'commander',
  'Oathbreaker' => 'oathbreaker',
  'Brawl'       => 'brawl',
  'Explorer'    => 'explorer',
  'Historic'    => 'historic',
  'Alchemy'     => 'alchemy',
  'Timeless'    => 'timeless',
];

function check_legality(string $format, array $main, array $side, int $mainQty, int $sideQty): array {
  if ($format === '') return ['violations' => [], 'warnings' => []];

  $violations = [];
  $warnings   = [];

  $isBasicLand = fn(string $tl): bool => (bool)preg_match('/\bBasic\b.*\bLand\b/i', $tl);
  $isLegendary = fn(string $tl): bool => (bool)preg_match('/\bLegendary\b/i', $tl);

  $scryfallKey = FORMAT_SCRYFALL_KEY[$format] ?? null;

  // Name → qty maps
  $mainCounts = [];
  foreach ($main as $r) {
    $n = (string)$r['name'];
    $mainCounts[$n] = ($mainCounts[$n] ?? 0) + (int)$r['qty'];
  }
  $combinedCounts = $mainCounts;
  foreach ($side as $r) {
    $n = (string)$r['name'];
    $combinedCounts[$n] = ($combinedCounts[$n] ?? 0) + (int)$r['qty'];
  }

  // name → row lookup for type_line and legalities.
  // Prefer rows that have legality data over those that don't,
  // to avoid a stale/empty row silently overwriting a good one.
  $cardRows = [];
  foreach (array_merge($main, $side) as $r) {
    $name = (string)$r['name'];
    $hasLegality = !empty($r['legalities']) && $r['legalities'] !== '{}';

    if (!isset($cardRows[$name])) {
      // First time we see this name — always store it.
      $cardRows[$name] = $r;
    } elseif ($hasLegality) {
      // We already have a row for this name, but the new row has legality
      // data and the existing one might not — prefer the one with data.
      $existingHasLegality = !empty($cardRows[$name]['legalities']) && $cardRows[$name]['legalities'] !== '{}';
      if (!$existingHasLegality) {
        $cardRows[$name] = $r;
      }
    }
    // If both have legality data, keep the first one (they should be identical
    // for the same card name).
  }

  $typeLine = fn(string $name): string => (string)($cardRows[$name]['type_line'] ?? '');

  // ── Ban/restriction check (all formats with a Scryfall key) ──────────────
  if ($scryfallKey !== null) {
    foreach ($cardRows as $name => $r) {
      $legalities = json_decode((string)($r['legalities'] ?? '{}'), true);
      if (!is_array($legalities)) $legalities = [];

      $status = $legalities[$scryfallKey] ?? null;

      if ($status === null) {
        // No legality data stored yet — skip silently, warn once below
        continue;
      }

      if ($status === 'banned') {
        $violations[] = "{$name} is banned in {$format}.";
      } elseif ($status === 'not_legal') {
        $violations[] = "{$name} is not legal in {$format}.";
      } elseif ($status === 'restricted') {
        // Vintage restricted = max 1 copy across main + side combined
        $combined = $combinedCounts[$name] ?? 0;
        if ($combined > 1) {
          $violations[] = "{$name} is restricted in {$format} — only 1 copy allowed across main + sideboard (you have {$combined}).";
        }
      }
    }

    // If any card has no legality data, surface a single warning
    $missingLegality = array_filter($cardRows, fn($r) =>
      ($r['legalities'] === null || $r['legalities'] === '' || $r['legalities'] === '{}')
    );
    if ($missingLegality) {
      $names = array_slice(array_keys($missingLegality), 0, 3);
      $extra = count($missingLegality) > 3 ? ' and ' . (count($missingLegality) - 3) . ' more' : '';
      $warnings[] = "Legality data missing for: " . implode(', ', $names) . $extra . ". Re-import these cards to populate ban data.";
    }
  }

  // ── Commander / Oathbreaker / Brawl structure rules ──────────────────────
  if (in_array($format, ['Commander', 'Oathbreaker', 'Brawl'], true)) {
    $deckSize = $format === 'Brawl' ? 60 : 100;
    $commanderSlots = $side;
    $commanderCount = count($commanderSlots);

    if ($commanderCount === 0) {
      $violations[] = "No commander. Add your commander to the Commander slot (sideboard, qty 1).";
    } elseif ($commanderCount > 2) {
      $violations[] = "Too many commander entries ({$commanderCount}). Maximum is 2 (Partner commanders).";
    } else {
      foreach ($commanderSlots as $r) {
        if (!$isLegendary((string)($r['type_line'] ?? ''))) {
          $warnings[] = h((string)$r['name']) . " may not be a valid commander (not Legendary).";
        }
        if ((int)$r['qty'] !== 1) {
          $violations[] = h((string)$r['name']) . ": commander qty must be exactly 1.";
        }
      }
    }

    $expectedMain = $deckSize - $commanderCount;
    if ($mainQty < $expectedMain) {
      $violations[] = "Mainboard has {$mainQty} cards — needs {$expectedMain} ({$deckSize} total minus {$commanderCount} commander).";
    } elseif ($mainQty > $expectedMain) {
      $violations[] = "Mainboard has {$mainQty} cards — too many. Should be {$expectedMain} for {$format}.";
    }

    // Singleton check (main only — commander is already in side)
    foreach ($mainCounts as $name => $qty) {
      if (!$isBasicLand($typeLine($name)) && $qty > 1) {
        $violations[] = "{$name}: {$qty} copies — only 1 allowed in {$format} (singleton).";
      }
    }

    return ['violations' => $violations, 'warnings' => $warnings];
  }

  // ── Standard constructed structure rules ──────────────────────────────────
  if ($mainQty < 60) {
    $violations[] = "Mainboard has {$mainQty} cards — minimum is 60.";
  }
  if ($sideQty > 15) {
    $violations[] = "Sideboard has {$sideQty} cards — maximum is 15.";
  }

  // 4-copy limit (combined), except basic lands
  foreach ($combinedCounts as $name => $qty) {
    if (!$isBasicLand($typeLine($name)) && $qty > 4) {
      $violations[] = "{$name}: {$qty} copies across main + sideboard (max 4).";
    }
  }

  if ($format === 'Standard' && $mainQty > 60) {
    $warnings[] = "Mainboard has {$mainQty} cards. Competitive Standard decks are typically exactly 60.";
  }
  if ($format === 'Pauper') {
    $warnings[] = "Pauper requires all cards to be common rarity. Rarity is not tracked here — verify manually.";
  }

  return ['violations' => $violations, 'warnings' => $warnings];
}

$format    = (string)($deck['format'] ?? '');
$isCmdrFmt = in_array($format, ['Commander', 'Oathbreaker', 'Brawl']);
$legality  = check_legality($format, $main, $side, $mainQty, $sideQty);
$legalViolations = $legality['violations'];
$legalWarnings   = $legality['warnings'];
$isLegal = empty($legalViolations);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= h((string)$deck['name']) ?> — Deck</title>
  <link rel="stylesheet" href="./css/deck.css" />
  <link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>
<body data-csrf="<?= h(csrf_token()) ?>" data-deck-id="<?= (int)$deckId ?>">
  <a class="skip" href="#main">Skip to main content</a>

<?php
$loggedIn = true;
$activeNav = "decks";
include 'partials/header.php';
?>
  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="deckTitle">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:end;">
          <div>
            <h1 id="deckTitle"><?= h((string)$deck['name']) ?></h1>
            <p>
              <?php if ($format !== ''): ?>
                <span class="pill">Format: <?= h($format) ?></span>
              <?php else: ?>
                <span class="pill">No format set</span>
              <?php endif; ?>
              <span class="pill">Main: <?= (int)$mainQty ?></span>
              <span class="pill"><?= $isCmdrFmt ? 'Commander' : 'Side' ?>: <?= (int)$sideQty ?></span>
              <?php if ($format !== ''): ?>
                <span class="pill <?= $isLegal ? 'pill--ok' : 'pill--bad' ?>">
                  <?= $isLegal ? '✓ Legal' : '✗ Illegal' ?>
                </span>
              <?php endif; ?>
            </p>
          </div>
          <p class="small" style="margin:0;">Signed in as <span class="pill"><?= h((string)($user['username'] ?? 'User')) ?></span></p>
        </div>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <?php if ($format !== ''): ?>
          <?php if (!$isLegal || $legalWarnings): ?>
            <div class="legalityPanel <?= $isLegal ? 'legalityPanel--warn' : 'legalityPanel--bad' ?>"
                 role="region" aria-label="Legality check">
              <strong class="legalityTitle">
                <?= $isLegal ? '⚠ Warnings for ' . h($format) : '✗ Not legal for ' . h($format) ?>
              </strong>
              <?php if ($legalViolations): ?>
                <ul class="legalityList">
                  <?php foreach ($legalViolations as $v): ?>
                    <li><?= h($v) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php if ($legalWarnings): ?>
                <ul class="legalityList legalityList--warn">
                  <?php foreach ($legalWarnings as $w): ?>
                    <li><?= $w ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="legalityPanel legalityPanel--ok" role="status">
              ✓ Deck meets all <?= h($format) ?> construction rules.
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <section class="summary" aria-label="Deck totals">
          <div class="summaryItem">
            <div class="small">Estimated value (Scryfall)</div>
            <div class="big"><?= h(money_val((string)$deckEst)) ?></div>
            <div class="small">Known for <?= (int)$deckKnown ?> cards (main+side).</div>
          </div>
          <div class="summaryItem">
            <div class="small">Mainboard value</div>
            <div class="big"><?= h(money_val((string)$mainEst)) ?></div>
            <div class="small">Known for <?= (int)$mainKnown ?> / <?= (int)$mainQty ?>.</div>
          </div>
          <div class="summaryItem">
            <div class="small">Sideboard value</div>
            <div class="big"><?= h(money_val((string)$sideEst)) ?></div>
            <div class="small">Known for <?= (int)$sideKnown ?> / <?= (int)$sideQty ?>.</div>
          </div>
        </section>

        <?php if (!empty($deck['description'])): ?>
          <p style="margin-top:12px;"><?= h((string)$deck['description']) ?></p>
        <?php endif; ?>

        <form action="/deck_config/delete_deck.php" method="post" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
          <button type="submit" class="dangerBtn" aria-label="Delete deck <?= h((string)$deck['name']) ?>">
            Delete this deck
          </button>
          <p class="small" style="margin-top:8px;">Deleting a deck removes its decklist. It does not delete your collection.</p>
        </form>
      </section>

      <section class="grid" aria-label="Deck tools and list">
        <section class="card" aria-labelledby="addTitle">
          <h2 id="addTitle">Add cards to this deck</h2>
          <p class="small">Search uses Scryfall syntax. Example: <code>!"Lightning Bolt"</code> or <code>t:elf set:khm</code>.</p>

          <div id="deckSearchForm" class="addControls">
            <label class="srOnly" for="q">Search</label>
            <input id="q" name="q" maxlength="200" placeholder='e.g., !"Sol Ring"' />

            <label class="srOnly" for="section">Section</label>
            <select id="section" name="section">
              <option value="main">Mainboard</option>
              <option value="side"><?= $isCmdrFmt ? 'Commander slot' : 'Sideboard' ?></option>
            </select>

            <label class="srOnly" for="qty">Qty</label>
            <input id="qty" name="qty" type="number" min="1" max="999" value="1" style="max-width:120px;">

            <label class="srOnly" for="finish">Finish</label>
            <select id="finish" name="finish">
              <option value="nonfoil">Non-foil</option>
              <option value="foil">Foil</option>
              <option value="etched">Etched</option>
            </select>

            <button type="button" id="deckSearchBtn">Search</button>

            <a class="btnSecondary" href="import_deck.php?deck_id=<?= (int)$deckId ?>"
               style="text-decoration:none;padding:10px 12px;border-radius:12px;border:1px solid var(--border);">
              Import decklist
            </a>
          </div>

          <?php if ($isCmdrFmt): ?>
            <p class="small" style="margin-top:8px;">
              Tip: Add your commander to the <strong>Commander slot</strong> (sideboard, qty 1).
              The legality check validates it there.
            </p>
          <?php endif; ?>

          <div id="searchStatus" class="statusline" role="status" aria-live="polite">Ready.</div>
          <div id="searchResults" class="results" aria-label="Scryfall search results"></div>
        </section>

        <section class="card" aria-labelledby="listTitle">
          <h2 id="listTitle">Decklist (editable)</h2>

          <h3>Mainboard (<?= (int)$mainQty ?>)</h3>
          <?php if (!$main): ?>
            <p class="small">No mainboard cards yet.</p>
          <?php else: ?>
            <div class="list" role="list" aria-label="Mainboard cards">
              <?php foreach ($main as $r):
                $p = finish_price($r);
                $deckCardId = (int)$r['deck_card_id'];
              ?>
                <article class="rowCard" role="listitem">
                  <div class="rowGrid">
                    <div>
                      <?php if (!empty($r['image_small'])): ?>
                        <img class="thumb" src="<?= h((string)$r['image_small']) ?>" alt="<?= h((string)$r['name']) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="thumb" role="img" aria-label="No image"></div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="name">
                        <?= h((string)$r['name']) ?>
                        <?php if ((int)$r['owned_qty'] >= (int)$r['qty']): ?>
                          <span class="owned-icon" title="Owned">✓</span>
                        <?php endif; ?>
                      </div>
                      <div class="small"><?= h((string)($r['type_line'] ?? '')) ?></div>
                      <div class="small">Price: <?= $p === null ? '—' : h(money_val((string)$p)) ?></div>
                      <form action="deck_config/update_deck_card.php" method="post" style="margin-top:10px;">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
                        <input type="hidden" name="deck_card_id" value="<?= $deckCardId ?>">
                        <div class="editGrid">
                          <div>
                            <label for="qty-<?= $deckCardId ?>">Qty</label>
                            <input id="qty-<?= $deckCardId ?>" name="qty" type="number" min="0" max="999" value="<?= (int)$r['qty'] ?>">
                          </div>
                          <div>
                            <label for="sec-<?= $deckCardId ?>">Section</label>
                            <select id="sec-<?= $deckCardId ?>" name="section">
                              <option value="main" selected>Mainboard</option>
                              <option value="side"><?= $isCmdrFmt ? 'Commander' : 'Sideboard' ?></option>
                            </select>
                          </div>
                          <div>
                            <label for="fin-<?= $deckCardId ?>">Finish</label>
                            <select id="fin-<?= $deckCardId ?>" name="finish">
                              <option value="nonfoil"<?= $r['finish']==='nonfoil'?' selected':'' ?>>Non-foil</option>
                              <option value="foil"<?= $r['finish']==='foil'?' selected':'' ?>>Foil</option>
                              <option value="etched"<?= $r['finish']==='etched'?' selected':'' ?>>Etched</option>
                            </select>
                          </div>
                          <div class="btnRow">
                            <button type="submit" name="action" value="update">Save</button>
                            <button type="submit" name="action" value="delete" class="dangerBtn">Remove</button>
                          </div>
                        </div>
                        <div class="small" style="margin-top:8px;">Updated: <?= h((string)$r['updated_at']) ?></div>
                      </form>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <h3 style="margin-top:14px;">
            <?= $isCmdrFmt ? 'Commander / Command Zone' : 'Sideboard' ?> (<?= (int)$sideQty ?>)
          </h3>
          <?php if (!$side): ?>
            <p class="small">
              <?= $isCmdrFmt
                ? 'No commander yet. Search for your commander and add it to the Commander slot.'
                : 'No sideboard cards yet.' ?>
            </p>
          <?php else: ?>
            <div class="list" role="list" aria-label="<?= $isCmdrFmt ? 'Commander zone' : 'Sideboard' ?>">
              <?php foreach ($side as $r):
                $p = finish_price($r);
                $deckCardId = (int)$r['deck_card_id'];
                $isCommander = $isCmdrFmt && (int)$r['qty'] === 1;
              ?>
                <article class="rowCard <?= $isCommander ? 'rowCard--commander' : '' ?>" role="listitem">
                  <div class="rowGrid">
                    <div>
                      <?php if (!empty($r['image_small'])): ?>
                        <img class="thumb" src="<?= h((string)$r['image_small']) ?>" alt="<?= h((string)$r['name']) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="thumb" role="img" aria-label="No image"></div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="name">
                        <?= h((string)$r['name']) ?>
                        <?php if ($isCommander): ?>
                          <span class="commander-badge">Commander</span>
                        <?php elseif ((int)$r['owned_qty'] >= (int)$r['qty']): ?>
                          <span class="owned-icon" title="Owned">✓</span>
                        <?php endif; ?>
                      </div>
                      <div class="small"><?= h((string)($r['type_line'] ?? '')) ?></div>
                      <div class="small">Price: <?= $p === null ? '—' : h(money_val((string)$p)) ?></div>
                      <form action="deck_config/update_deck_card.php" method="post" style="margin-top:10px;">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="deck_id" value="<?= (int)$deckId ?>">
                        <input type="hidden" name="deck_card_id" value="<?= $deckCardId ?>">
                        <div class="editGrid">
                          <div>
                            <label for="qty-<?= $deckCardId ?>">Qty</label>
                            <input id="qty-<?= $deckCardId ?>" name="qty" type="number" min="0" max="999" value="<?= (int)$r['qty'] ?>">
                          </div>
                          <div>
                            <label for="sec-<?= $deckCardId ?>">Section</label>
                            <select id="sec-<?= $deckCardId ?>" name="section">
                              <option value="main">Mainboard</option>
                              <option value="side" selected><?= $isCmdrFmt ? 'Commander' : 'Sideboard' ?></option>
                            </select>
                          </div>
                          <div>
                            <label for="fin-<?= $deckCardId ?>">Finish</label>
                            <select id="fin-<?= $deckCardId ?>" name="finish">
                              <option value="nonfoil"<?= $r['finish']==='nonfoil'?' selected':'' ?>>Non-foil</option>
                              <option value="foil"<?= $r['finish']==='foil'?' selected':'' ?>>Foil</option>
                              <option value="etched"<?= $r['finish']==='etched'?' selected':'' ?>>Etched</option>
                            </select>
                          </div>
                          <div class="btnRow">
                            <button type="submit" name="action" value="update">Save</button>
                            <button type="submit" name="action" value="delete" class="dangerBtn">Remove</button>
                          </div>
                        </div>
                        <div class="small" style="margin-top:8px;">Updated: <?= h((string)$r['updated_at']) ?></div>
                      </form>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </section>

      <?php require_once __DIR__ . "/partials/footer.php"; ?>
    </div>
  </main>

  <script>
  window.CSRF_TOKEN = document.body.dataset.csrf;
  window.DECK_ID    = document.body.dataset.deckId;
  </script>
  
  <script src="./js/deck.js"></script>

</body>
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

</html>
