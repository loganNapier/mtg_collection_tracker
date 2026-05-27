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
</html>