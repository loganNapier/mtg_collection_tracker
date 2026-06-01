<?php
// collection.php
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

// --- Summary totals (always over full collection, unfiltered) ---
$stmt = $pdo->prepare("
  SELECT
    uc.qty,
    uc.purchase_price,
    uc.finish,
    c.price_usd,
    c.price_usd_foil,
    c.price_usd_etched
  FROM user_collection uc
  JOIN cards c ON c.id = uc.card_id
  WHERE uc.user_id = ?
");
$stmt->execute([$uid]);
$allRows = $stmt->fetchAll();

function money_val($v): string {
  if ($v === null || $v === '') return '';
  return '$' . number_format((float)$v, 2);
}
function finish_price(array $r): ?float {
  $finish = (string)($r['finish'] ?? 'nonfoil');
  if ($finish === 'foil')   return ($r['price_usd_foil']   !== null && $r['price_usd_foil']   !== '') ? (float)$r['price_usd_foil']   : null;
  if ($finish === 'etched') return ($r['price_usd_etched'] !== null && $r['price_usd_etched'] !== '') ? (float)$r['price_usd_etched'] : null;
  return ($r['price_usd'] !== null && $r['price_usd'] !== '') ? (float)$r['price_usd'] : null;
}

$totalQty = 0; $totalPaid = 0.0; $totalPaidKnown = 0;
$totalEst = 0.0; $totalEstKnown = 0;
foreach ($allRows as $r) {
  $qty = (int)$r['qty'];
  $totalQty += $qty;
  if ($r['purchase_price'] !== null && $r['purchase_price'] !== '') {
    $totalPaid += ((float)$r['purchase_price']) * $qty;
    $totalPaidKnown += $qty;
  }
  $p = finish_price($r);
  if ($p !== null && $p >= 0) { $totalEst += $p * $qty; $totalEstKnown += $qty; }
}

// --- Distinct sets for filter dropdown ---
$setsStmt = $pdo->prepare("
  SELECT DISTINCT c.set_code, c.set_name
  FROM user_collection uc
  JOIN cards c ON c.id = uc.card_id
  WHERE uc.user_id = ?
  ORDER BY c.set_name ASC
");
$setsStmt->execute([$uid]);
$sets = $setsStmt->fetchAll();

$hasCards = $totalQty > 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta name="referrer" content="no-referrer">
  <title>My Collection</title>
  <link rel="stylesheet" href="./css/collection.css" />
</head>
<body data-csrf="<?= h(csrf_token()) ?>">
  <a class="skip" href="#main">Skip to main content</a>

  <?php require_once __DIR__ . "/partials/header.php"; ?>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="title">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:end;">
          <div>
            <h1 id="title">My Collection</h1>
            <p>Signed in as <span class="pill"><?= h($user ? $user['username'] : 'User') ?></span></p>
          </div>
          <p class="small" style="margin:0;">Tip: Add cards from <a href="cards.php">Browse cards</a> or <a href="batch_add.php">Batch add</a>.</p>
        </div>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <section class="summary" aria-label="Collection totals">
          <div class="summaryItem">
            <h2>Total cards</h2>
            <div class="big"><?= (int)$totalQty ?></div>
            <div class="small">Sum of quantities across your collection.</div>
          </div>
          <div class="summaryItem">
            <h2>Estimated value (Scryfall)</h2>
            <div class="big"><?= h(money_val((string)$totalEst)) ?></div>
            <div class="small">
              Based on stored Scryfall price for each row's finish.
              <?php if ($totalQty > 0): ?>
                (Price known for <?= (int)$totalEstKnown ?> / <?= (int)$totalQty ?> copies.)
              <?php endif; ?>
            </div>
          </div>
          <div class="summaryItem">
            <h2>Total paid (your entries)</h2>
            <div class="big"><?= h(money_val((string)$totalPaid)) ?></div>
            <div class="small">
              Based on your "Paid" field.
              <?php if ($totalQty > 0): ?>
                (Paid known for <?= (int)$totalPaidKnown ?> / <?= (int)$totalQty ?> copies.)
              <?php endif; ?>
            </div>
          </div>
        </section>
      </section>

      <?php if (!$hasCards): ?>
        <section class="card" style="margin-top:12px;">
          <p>No cards in your collection yet.</p>
        </section>
      <?php else: ?>

      <!-- Filter bar -->
      <section class="card" style="margin-top:12px;" aria-labelledby="filterTitle">
        <h2 id="filterTitle" class="srOnly">Filter collection</h2>
        <div id="filterBar" class="filters">
          <div class="filterField">
            <label for="f_search">Card name</label>
            <input id="f_search" type="text" placeholder="e.g., Lightning Bolt" maxlength="200" autocomplete="off" />
          </div>

          <div class="filterField">
            <label for="f_set">Set</label>
            <select id="f_set">
              <option value="">All sets</option>
              <?php foreach ($sets as $s): ?>
                <option value="<?= h((string)$s['set_code']) ?>">
                  <?= h((string)$s['set_name']) ?> (<?= h(strtoupper((string)$s['set_code'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filterField">
            <label for="f_condition">Condition</label>
            <select id="f_condition">
              <option value="">All conditions</option>
              <option value="NM">NM</option>
              <option value="LP">LP</option>
              <option value="MP">MP</option>
              <option value="HP">HP</option>
              <option value="DMG">DMG</option>
            </select>
          </div>

          <div class="filterField">
            <label for="f_finish">Finish</label>
            <select id="f_finish">
              <option value="">All finishes</option>
              <option value="nonfoil">Non-foil</option>
              <option value="foil">Foil</option>
              <option value="etched">Etched</option>
            </select>
          </div>

          <div class="filterField">
            <label for="f_per_page">Show</label>
            <select id="f_per_page">
              <option value="20" selected>20 per page</option>
              <option value="50">50 per page</option>
              <option value="100">100 per page</option>
            </select>
          </div>

          <div class="rowActions" style="align-self:flex-end;">
            <button type="button" id="filterBtn">Filter</button>
            <button type="button" id="clearFilterBtn" class="btnSecondary">Clear</button>
            <div class="exportWrap">
              <button type="button" id="exportBtn" class="btnSecondary">Export ▾</button>
              <div id="exportMenu" class="exportMenu" hidden>
                <a href="/collection_config/export_collection.php?format=json">JSON</a>
                <a href="/collection_config/export_collection.php?format=csv">CSV</a>
                <a href="/collection_config/export_collection.php?format=dek">DEK (MTGO)</a>
                <a href="/collection_config/export_collection.php?format=txt">Text</a>
              </div>
            </div>
          </div>
        </div>
        <div id="collectionStatus" class="statusline" role="status" aria-live="polite"></div>
      </section>

      <!-- Results table -->
      <section class="card" style="margin-top:12px;">
        <div class="tableWrap" role="region" aria-label="Collection table" tabindex="0">
          <table id="collectionTable">
            <thead>
              <tr>
                <th scope="col">Card</th>
                <th scope="col">Qty</th>
                <th scope="col">Variant</th>
                <th scope="col">Finish</th>
                <th scope="col">Scryfall Price</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody id="collectionBody">
              <!-- Rows injected by JS -->
            </tbody>
          </table>
        </div>
        <div id="loadMoreWrap" style="margin-top:12px;"></div>
      </section>

      <?php endif; ?>
    </div>
  </main>

 

<script>
  const CSRF = document.body.dataset.csrf;

  const statusEl    = document.getElementById('collectionStatus');
  const tbody       = document.getElementById('collectionBody');
  const loadMoreWrap = document.getElementById('loadMoreWrap');

  const fSearch    = document.getElementById('f_search');
  const fSet       = document.getElementById('f_set');
  const fCondition = document.getElementById('f_condition');
  const fFinish    = document.getElementById('f_finish');
  const fPerPage   = document.getElementById('f_per_page');

  // --- State ---
  let currentOffset = 0;
  let currentTotal  = 0;
  let isLoading     = false;

  function getFilters() {
    return {
      search:    fSearch.value.trim(),
      set:       fSet.value,
      condition: fCondition.value,
      finish:    fFinish.value,
      per_page:  fPerPage.value,
    };
  }

  function setStatus(msg, kind = '') {
    statusEl.textContent = msg;
    statusEl.className = 'statusline' + (kind ? ' ' + kind : '');
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[c]));
  }

  // Build a Scryfall small image URL from scryfall_id when image_small is missing
  // Pattern: https://cards.scryfall.io/small/front/{c1}/{c2}/{uuid}.jpg
  function scryfallImg(r) {
    if (r.image_small) return r.image_small;
    if (!r.scryfall_id) return null;
    const id = r.scryfall_id;
    return `https://cards.scryfall.io/small/front/${id[0]}/${id[1]}/${id}.jpg`;
  }

  function finishPrice(r) {
    const finish = r.finish ?? 'nonfoil';
    if (finish === 'foil'   && r.price_usd_foil   != null && r.price_usd_foil   !== '') return parseFloat(r.price_usd_foil);
    if (finish === 'etched' && r.price_usd_etched != null && r.price_usd_etched !== '') return parseFloat(r.price_usd_etched);
    if (r.price_usd != null && r.price_usd !== '') return parseFloat(r.price_usd);
    return null;
  }

  function moneyVal(v) {
    if (v === null || v === '') return '';
    return '$' + parseFloat(v).toFixed(2);
  }

  // ── Toggle an edit row open/closed ───────────────────────────────────────
  function toggleEdit(id) {
    const editRow = document.getElementById('edit-' + id);
    const btn     = document.getElementById('editBtn-' + id);
    if (!editRow || !btn) return;
    const opening = !editRow.classList.contains('is-open');
    editRow.classList.toggle('is-open', opening);
    btn.setAttribute('aria-expanded', String(opening));
    btn.textContent = opening ? 'Close' : 'Edit';
  }

  // ── Build tags (pill badges) for a variant row ────────────────────────────
  function variantTags(r) {
    const tags = [];
    if (r.card_condition) tags.push(`<span class="variantTag">${esc(r.card_condition)}</span>`);
    if (r.finish && r.finish !== 'nonfoil') tags.push(`<span class="variantTag variantTag--finish">${esc(r.finish)}</span>`);
    // Schema default is 'English' — only show language tag if non-English
    const lang = (r.card_language ?? '').trim();
    if (lang !== '' && lang.toLowerCase() !== 'english')
      tags.push(`<span class="variantTag variantTag--lang">${esc(lang)}</span>`);
    if (parseInt(r.is_signed) === 1)  tags.push(`<span class="variantTag variantTag--special">Signed</span>`);
    if (parseInt(r.is_altered) === 1) tags.push(`<span class="variantTag variantTag--special">Altered</span>`);
    return tags.join('');
  }

  // ── One variant: summary sub-row + collapsible edit row ───────────────────
  function variantHTML(r) {
    const itemId = esc(r.collection_id);
    const p = finishPrice(r);
    const priceDisplay = p !== null
      ? `<strong>${esc(moneyVal(String(p)))}</strong>`
      : '<span class="small">—</span>';

    const summaryRow = `
      <tr class="variantRow">
        <td class="variantIndent"></td>
        <td>${esc(r.qty)}</td>
        <td>
          <div class="variantTags">${variantTags(r)}</div>
        </td>
        <td>${r.finish ? r.finish.charAt(0).toUpperCase() + r.finish.slice(1) : 'Nonfoil'}</td>
        <td>${priceDisplay}</td>
        <td>
          <button
            id="editBtn-${itemId}"
            class="btnEdit"
            type="button"
            aria-expanded="false"
            aria-controls="edit-${itemId}"
            onclick="toggleEdit('${itemId}')">Edit</button>
        </td>
      </tr>`;

    const editRow = `
      <tr id="edit-${itemId}" class="editRow" aria-label="Edit variant of ${esc(r.name)}">
        <td colspan="6">
          <form action="/collection_config/update_collection_item.php" method="post">
            <input type="hidden" name="csrf" value="${esc(CSRF)}">
            <input type="hidden" name="collection_id" value="${itemId}">
            <div class="editInner">

              <div>
                <label for="qty-${itemId}">Quantity</label>
                <input id="qty-${itemId}" name="qty" type="number" min="0" max="999" value="${esc(r.qty)}">
              </div>

              <div>
                <label for="cond-${itemId}">Condition</label>
                <select id="cond-${itemId}" name="card_condition">
                  ${['NM','LP','MP','HP','DMG'].map(c =>
                    `<option value="${c}"${r.card_condition === c ? ' selected' : ''}>${c}</option>`
                  ).join('')}
                </select>
              </div>

              <div>
                <label for="lang-${itemId}">Language</label>
                <input id="lang-${itemId}" name="card_language" maxlength="32" value="${esc(r.card_language ?? '')}">
              </div>

              <div>
                <label for="finish-${itemId}">Finish</label>
                <select id="finish-${itemId}" name="finish">
                  ${['nonfoil','foil','etched'].map(f =>
                    `<option value="${f}"${r.finish === f ? ' selected' : ''}>${f.charAt(0).toUpperCase()+f.slice(1)}</option>`
                  ).join('')}
                </select>
              </div>

              <div>
                <label for="acq-${itemId}">Acquired</label>
                <input id="acq-${itemId}" name="acquired_at" type="date" value="${esc(r.acquired_at ?? '')}">
              </div>

              <div>
                <label for="paid-${itemId}">Paid ($)</label>
                <input id="paid-${itemId}" name="purchase_price" type="number" min="0" step="0.01" inputmode="decimal" value="${esc(r.purchase_price ?? '')}">
              </div>

              <div>
                <label class="checkLabel">
                  <input type="checkbox" name="is_signed" value="1"${parseInt(r.is_signed) === 1 ? ' checked' : ''}>
                  <span>Signed</span>
                </label>
                <label class="checkLabel" style="margin-top:6px;">
                  <input type="checkbox" name="is_altered" value="1"${parseInt(r.is_altered) === 1 ? ' checked' : ''}>
                  <span>Altered</span>
                </label>
              </div>

              <div class="notesField">
                <label for="notes-${itemId}">Notes</label>
                <textarea id="notes-${itemId}" name="notes" maxlength="500" rows="2" style="width:100%;">${esc(r.notes ?? '')}</textarea>
                <div class="small">Max 500 characters.</div>
              </div>

              <div class="editFooter">
                <span class="small">Updated: ${esc(r.updated_at)}</span>
                <div class="btnRow">
                  <button type="submit" name="action" value="update">Save</button>
                  <button class="dangerBtn" type="submit" name="action" value="delete"
                          aria-label="Remove this variant from collection">Remove</button>
                </div>
              </div>

            </div>
          </form>
        </td>
      </tr>`;

    return summaryRow + editRow;
  }

  // ── Group rows by card_id (same name+set+collector_number) and render ─────
  function renderRows(rows) {
    // Group consecutive rows that share the same card identity
    const groups = [];
    rows.forEach(r => {
      const key = `${r.name}||${r.set_code}||${r.collector_number}`;
      if (groups.length && groups[groups.length - 1].key === key) {
        groups[groups.length - 1].variants.push(r);
      } else {
        groups.push({ key, card: r, variants: [r] });
      }
    });

    return groups.map(({ card, variants }) => {
      const totalQty = variants.reduce((sum, v) => sum + parseInt(v.qty || 0), 0);

      // Card header row — image, name, set, total qty across all variants
      const headerRow = `
        <tr class="cardGroupHeader">
          <td colspan="6">
            <div class="cellCard">
              ${scryfallImg(card)
                ? `<div class="thumbWrap">
                    <img class="thumb" src="${esc(scryfallImg(card))}" alt="${esc(card.name)}" loading="lazy" referrerpolicy="no-referrer" onerror="this.closest('.thumbWrap').replaceWith(Object.assign(document.createElement('div'),{className:'thumb-placeholder',title:'No image available'}))">
                    <div class="pop">
                      <img src="${esc(scryfallImg(card).replace('/small/', '/normal/'))}" alt="${esc(card.name)}" referrerpolicy="no-referrer">
                    </div>
                  </div>`
                : `<div class="thumb-placeholder" role="img" aria-label="No image"></div>`
              }
              <div>
                <div class="cardGroupName">${esc(card.name)}</div>
                <div class="small">${esc(card.set_name ?? '')}${card.set_code ? ' (' + esc(card.set_code.toUpperCase()) + ')' : ''}${card.collector_number ? ' #' + esc(card.collector_number) : ''}</div>
                ${card.price_updated_at ? `<div class="small">Price updated: ${esc(card.price_updated_at)}</div>` : ''}
              </div>
              <span class="groupQtyBadge" title="Total copies">${totalQty} ${totalQty === 1 ? 'copy' : 'copies'}</span>
            </div>
          </td>
        </tr>`;

      // One variant sub-row per collection entry
      const variantRows = variants.map(variantHTML).join('');

      return headerRow + variantRows;
    }).join('');
  }

  async function loadRows(reset = true) {
    if (isLoading) return;
    isLoading = true;

    if (reset) {
      currentOffset = 0;
      currentTotal  = 0;
      tbody.innerHTML = '';
      loadMoreWrap.innerHTML = '';
    }

    const filters = getFilters();
    const params  = new URLSearchParams({
      search:    filters.search,
      set:       filters.set,
      condition: filters.condition,
      finish:    filters.finish,
      per_page:  filters.per_page,
      offset:    currentOffset,
    });

    setStatus('Loading…');

    try {
      const res  = await fetch('/collection_config/collection_api.php?' + params.toString());
      const data = await res.json();

      if (!res.ok) {
        setStatus('Failed to load collection.', 'bad');
        isLoading = false;
        return;
      }

      currentTotal   = data.total;
      currentOffset += data.rows.length;

      if (reset && data.rows.length === 0) {
        setStatus('No cards match your filters.', 'bad');
        isLoading = false;
        return;
      }

      tbody.insertAdjacentHTML('beforeend', renderRows(data.rows));
      setStatus(`Showing ${currentOffset} of ${currentTotal} entr${currentTotal !== 1 ? 'ies' : 'y'}.`, 'ok');

      loadMoreWrap.innerHTML = '';
      if (data.has_more) {
        loadMoreWrap.innerHTML = `<button type="button" id="loadMoreBtn">Load more</button>`;
        document.getElementById('loadMoreBtn').addEventListener('click', () => loadRows(false));
      }

    } catch (e) {
      setStatus('Network error loading collection.', 'bad');
      console.error(e);
    }

    isLoading = false;
  }

  // ── Position the fixed .pop next to its thumbnail on hover ───────────────
  document.getElementById('collectionTable').addEventListener('mouseover', e => {
    const wrap = e.target.closest('.thumbWrap');
    if (!wrap) return;
    const pop = wrap.querySelector('.pop');
    if (!pop) return;
    const rect = wrap.getBoundingClientRect();
    pop.style.top  = Math.round(rect.top + rect.height / 2 - pop.offsetHeight / 2) + 'px';
    pop.style.left = Math.round(rect.right + 8) + 'px';
  });

  // Wire up filter controls
  document.getElementById('filterBtn').addEventListener('click', () => loadRows(true));

  document.getElementById('clearFilterBtn').addEventListener('click', () => {
    fSearch.value    = '';
    fSet.value       = '';
    fCondition.value = '';
    fFinish.value    = '';
    fPerPage.value   = '20';
    loadRows(true);
  });

  fSearch.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') loadRows(true);
  });

  fPerPage.addEventListener('change', () => loadRows(true));

  // Export dropdown toggle
  const exportBtn  = document.getElementById('exportBtn');
  const exportMenu = document.getElementById('exportMenu');
  exportBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    exportMenu.hidden = !exportMenu.hidden;
  });
  document.addEventListener('click', () => { exportMenu.hidden = true; });

  loadRows(true);
</script>
</body>
<?php include 'partials/footer.php'; ?>
</html>
