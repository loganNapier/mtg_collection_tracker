<?php
// cards.php (Scryfall search) — updated nav to include Decks when logged in
declare(strict_types=1);

require_once (__DIR__ . "/auth/config.php");
require_once (__DIR__ . "/auth/auth.php");

$user = current_user($pdo);
$loggedIn = (bool)$user;

$flash = null;
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Browse Cards (Scryfall)</title>
  <link rel="stylesheet" href="./css/cards.css" />
  <link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>
<body>
  <?php require_once __DIR__ . "/partials/header.php"; ?>
  <a class="skip" href="#main">Skip to main content</a>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="title">
        <h1 id="title">Browse cards (Scryfall)</h1>
        <p>
          Search Scryfall and show images and prices.
          <?php if ($loggedIn): ?>
            You are signed in as <span class="pill"><?= h($user['username']) ?></span>.
          <?php else: ?>
            Log in to add cards to your collection.
          <?php endif; ?>
        </p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <div id="searchForm" class="filters">
          <div class="filterField">
            <label for="q">Search</label>
            <input id="q" name="q" maxlength="200" autocomplete="off" placeholder="e.g., t:elf set:khm" />
            <div class="help">Uses Scryfall advanced search syntax.</div>
          </div>

          <div class="filterField">
            <label for="unique">Unique</label>
            <select id="unique" name="unique">
              <option value="cards">Cards</option>
              <option value="prints">Prints</option>
              <option value="art">Art</option>
            </select>
            <div class="help"></div>
          </div>

          <div class="filterField">
            <label for="order">Order</label>
            <select id="order" name="order">
              <option value="name">Name</option>
              <option value="released">Released</option>
              <option value="set">Set</option>
              <option value="rarity">Rarity</option>
              <option value="usd">Price (USD)</option>
            </select>
            <div class="help"></div>
          </div>

          <div class="rowActions">
            <button type="button" id="searchBtn">Search</button>
            <button type="button" class="btnSecondary" id="clearBtn">Clear</button>
            <div class="help"></div>
          </div>
        </div>

        <div id="status" class="statusline" role="status" aria-live="polite">Ready.</div>
      </section>

      <section class="card" style="margin-top:12px;" aria-labelledby="resultsTitle">
        <h2 id="resultsTitle">Results</h2>
        <p class="small">Shows up to 20 results per search.</p>
        <div id="results" class="results" aria-label="Search results"></div>
        <div id="pagination"></div>
      </section>
    </div>
  </main>

<script>
  const loggedIn = <?= $loggedIn ? 'true' : 'false' ?>;
  const csrfToken = <?= $loggedIn ? json_encode(csrf_token()) : '""' ?>;

  const qEl = document.getElementById('q');
  const uniqueEl = document.getElementById('unique');
  const orderEl = document.getElementById('order');
  const statusEl = document.getElementById('status');
  const resultsEl = document.getElementById('results');
  const paginationEl = document.getElementById('pagination');
  const clearBtn = document.getElementById('clearBtn');

  function setStatus(msg, kind=""){
    statusEl.textContent = msg;
    statusEl.className = "statusline" + (kind ? (" " + kind) : "");
  }
  function esc(s){
    return String(s ?? "").replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[c]));
  }
  function pickImage(card){
    if (card?.image_uris?.small || card?.image_uris?.normal) {
      return { small: card.image_uris.small || "", normal: card.image_uris.normal || card.image_uris.small || "" };
    }
    const f0 = card?.card_faces?.[0];
    if (f0?.image_uris?.small || f0?.image_uris?.normal) {
      return { small: f0.image_uris.small || "", normal: f0.image_uris.normal || f0.image_uris.small || "" };
    }
    return { small:"", normal:"" };
  }
  function priceText(label, value){
    if (!value) return "";
    return `<span class="priceTag"><strong>${esc(label)}:</strong> $${esc(value)}</span>`;
  }

  function resultHTML(card){
    const name = card.name ?? "Unknown";
    const typeLine = card.type_line ?? "";
    const setCode = (card.set ?? "").toUpperCase();
    const setName = card.set_name ?? "";
    const cn = card.collector_number ?? "";
    const scryfallUrl = card.scryfall_uri ?? "";
    const oracle = card.oracle_text ?? "";
    const scryfallId = card.id ?? "";
    const oracleId = card.oracle_id ?? "";
    const img = pickImage(card);

    const usd = card?.prices?.usd ?? "";
    const usdFoil = card?.prices?.usd_foil ?? "";
    const usdEtched = card?.prices?.usd_etched ?? "";

    const imgThumb = img.small
      ? `<img class="thumb" src="${esc(img.small)}" loading="lazy" alt="Card image: ${esc(name)}">`
      : `<div class="thumb" role="img" aria-label="No image available"></div>`;

    const pop = img.normal
      ? `<div class="pop" aria-hidden="true"><img src="${esc(img.normal)}" alt=""></div>`
      : ``;

    let addBlock = `<div class="small">Log in to add this card to your collection.</div>`;
    if (loggedIn) {
      addBlock = `
        <form class="addForm" action="collection_config/add_to_collection.php" method="post">
          <input type="hidden" name="csrf" value="${esc(csrfToken)}">

          <input type="hidden" name="scryfall_id" value="${esc(scryfallId)}">
          <input type="hidden" name="oracle_id" value="${esc(oracleId)}">
          <input type="hidden" name="name" value="${esc(name)}">
          <input type="hidden" name="type_line" value="${esc(typeLine)}">
          <input type="hidden" name="set_code" value="${esc(setCode)}">
          <input type="hidden" name="set_name" value="${esc(setName)}">
          <input type="hidden" name="collector_number" value="${esc(cn)}">
          <input type="hidden" name="image_small" value="${esc(img.small)}">
          <input type="hidden" name="image_normal" value="${esc(img.normal)}">

          <input type="hidden" name="price_usd" value="${esc(usd)}">
          <input type="hidden" name="price_usd_foil" value="${esc(usdFoil)}">
          <input type="hidden" name="price_usd_etched" value="${esc(usdEtched)}">

          <div>
            <label for="qty-${esc(scryfallId)}">Qty</label>
            <input id="qty-${esc(scryfallId)}" name="qty" type="number" min="1" max="999" value="1">
          </div>

          <div>
            <label for="cond-${esc(scryfallId)}">Condition</label>
            <select id="cond-${esc(scryfallId)}" name="card_condition">
              <option value="NM">NM</option><option value="LP">LP</option><option value="MP">MP</option>
              <option value="HP">HP</option><option value="DMG">DMG</option>
            </select>
          </div>

          <div>
            <label for="lang-${esc(scryfallId)}">Language</label>
            <input id="lang-${esc(scryfallId)}" name="card_language" value="English" maxlength="32">
          </div>

          <div>
            <label for="finish-${esc(scryfallId)}">Finish</label>
            <select id="finish-${esc(scryfallId)}" name="finish">
              <option value="nonfoil">Non-foil</option>
              <option value="foil">Foil</option>
              <option value="etched">Etched</option>
            </select>
          </div>

          <div class="full checkRow" aria-label="Card flags">
            <label><input type="checkbox" name="is_signed" value="1"> Signed</label>
            <label><input type="checkbox" name="is_altered" value="1"> Altered</label>
          </div>

          <div class="full">
            <label for="notes-${esc(scryfallId)}">Notes (optional)</label>
            <textarea id="notes-${esc(scryfallId)}" name="notes" maxlength="500"></textarea>
          </div>

          <div>
            <label for="acq-${esc(scryfallId)}">Acquired date</label>
            <input id="acq-${esc(scryfallId)}" name="acquired_at" type="date">
          </div>

          <div>
            <label for="paid-${esc(scryfallId)}">Purchase price (USD)</label>
            <input id="paid-${esc(scryfallId)}" name="purchase_price" type="number" min="0" step="0.01" placeholder="0.00" inputmode="decimal">
          </div>

          <div class="full rowActions">
            <button type="submit">Add to my collection</button>
          </div>
        </form>
      `;
    }

    return `
      <article class="result">
        <div class="resultGrid">
          <div class="thumbWrap">
            <a href="${esc(scryfallUrl)}" target="_blank" rel="noreferrer" aria-label="Open ${esc(name)} on Scryfall">
              ${imgThumb}
            </a>
            ${pop}
          </div>

          <div>
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;">
              <div class="name">${esc(name)}</div>
              <div class="small">${setCode ? esc(setCode) : ""}${cn ? " #" + esc(cn) : ""}</div>
            </div>

            ${typeLine ? `<div class="meta">${esc(typeLine)}</div>` : ``}
            ${setName ? `<div class="small">Set: ${esc(setName)}</div>` : ``}

            <div class="priceRow" aria-label="Prices from Scryfall">
              ${priceText("USD", usd)}
              ${priceText("Foil", usdFoil)}
              ${priceText("Etched", usdEtched)}
              ${(!usd && !usdFoil && !usdEtched) ? `<span class="priceTag">No price listed</span>` : ``}
            </div>

            ${oracle ? `<details style="margin-top:8px;">
              <summary>Rules text</summary>
              <div class="meta">${esc(oracle)}</div>
            </details>` : ``}

            <div style="margin-top:10px;">${addBlock}</div>
          </div>
        </div>
      </article>
    `;
  }

  // --- State ---
  let allResults = [];
  let visibleCount = 0;
  let nextPageUrl = null;
  let totalCards = 0; // FIX: module-level so closures always see the latest value

  async function runSearch(reset = true){

    console.debug('[MTG] runSearch called, reset=', reset);

    const q = qEl.value.trim();

    if (!q){
      resultsEl.innerHTML = "";
      paginationEl.innerHTML = "";
      setStatus("Enter a search query.", "bad");
      qEl.focus();
      return;
    }

    if (reset){
      setStatus("Searching Scryfall…");
      resultsEl.innerHTML = "";
      paginationEl.innerHTML = "";
      allResults = [];
      visibleCount = 0;
      nextPageUrl = null;
      totalCards = 0; // FIX: reset alongside other state
    }

    let url;

    if (nextPageUrl && !reset){
      url = nextPageUrl;
    } else {
      const api = new URL("https://api.scryfall.com/cards/search");
      api.searchParams.set("q", q);
      api.searchParams.set("unique", uniqueEl.value);
      api.searchParams.set("order", orderEl.value);
      api.searchParams.set("dir", "auto");
      url = api.toString();
    }

    try {

      console.debug('[MTG] fetching url:', url);

      const res = await fetch(url, {
        headers: { "Accept": "application/json" }
      });

      console.debug('[MTG] fetch status:', res.status, res.ok);

      const data = await res.json();

      console.debug('[MTG] parsed data keys:', Object.keys(data ?? {}));
      console.debug('[MTG] cards in page:', data?.data?.length, '| has_more:', data?.has_more, '| total_cards:', data?.total_cards);

      if (!data || typeof data !== 'object'){
        setStatus("Invalid response from Scryfall.", "bad");
        return;
      }

      if (!res.ok){
        setStatus(data?.details || "Scryfall request failed.", "bad");
        return;
      }

      const newCards = Array.isArray(data?.data) ? data.data : [];

      allResults.push(...newCards);

      nextPageUrl = data.has_more ? data.next_page : null;

      totalCards = data.total_cards ?? allResults.length; // FIX: update module-level var

      renderMoreResults(); // FIX: no argument — reads totalCards from outer scope

    } catch (err) {
      console.error('[MTG] exception in runSearch:', err);
      setStatus("Network error talking to Scryfall.", "bad");
    }
  }

  function renderMoreResults(){

    console.debug('[MTG] renderMoreResults called', {
      allResults: allResults.length,
      visibleCount,
      nextPageUrl,
      totalCards
    });

    const nextChunk = allResults.slice(visibleCount, visibleCount + 20);

    if (nextChunk.length === 0 && !nextPageUrl){
      // Nothing buffered and no more remote pages — we are truly done.
      setStatus(`Showing all ${visibleCount} result(s).`, "ok");
      paginationEl.innerHTML = "";
      return;
    }

    if (nextChunk.length > 0){
      const html = nextChunk.map(resultHTML).join("");
      resultsEl.insertAdjacentHTML('beforeend', html);
      visibleCount += nextChunk.length;
    }

    setStatus(`Found ${totalCards}. Showing ${visibleCount}.`, "ok");

    paginationEl.innerHTML = "";

    const stillHiddenLocal = visibleCount < allResults.length;
    const hasMoreRemote = nextPageUrl !== null;

    console.debug('[MTG] pagination check', { stillHiddenLocal, hasMoreRemote });

    if (stillHiddenLocal || hasMoreRemote){
      paginationEl.innerHTML = `
        <button id="loadMore" style="margin-top:10px;">
          Load more
        </button>
      `;

      document.getElementById('loadMore').addEventListener('click', async () => {
        if (visibleCount < allResults.length){
          renderMoreResults();
        } else if (nextPageUrl){
          await runSearch(false);
        }
      });
    }
  }

  document.getElementById('searchBtn').addEventListener('click', () => {
    runSearch(true);
  });

  qEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') runSearch(true);
  });

  clearBtn.addEventListener('click', () => {
    qEl.value = "";
    resultsEl.innerHTML = "";
    paginationEl.innerHTML = "";
    setStatus("Cleared.");
    qEl.focus();
  });
</script>
</body>
<?php require_once __DIR__ . "/partials/footer.php"; ?>
</html>
