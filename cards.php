<?php
/**
 * cards.php
 * ---------------------------------------------------------------------------
 * Card browsing/search page using the Scryfall API.
 *
 * Features:
 *   - Search MTG cards using Scryfall syntax
 *   - Display images, prices, and oracle text
 *   - Paginated loading ("Load more")
 *   - Add cards directly to user collection with all fields
 *   - Login-aware UI
 *
 * Main Flow:
 *   1. PHP renders base page + login state
 *   2. JS calls Scryfall API
 *   3. Results render dynamically
 *   4. Logged-in users can submit add-to-collection forms
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * Dependencies
 * ---------------------------------------------------------------------------
 * config.php
 *   - DB connection
 *   - Sessions
 *   - Global helpers
 *
 * auth.php
 *   - Authentication helpers
 *   - current_user()
 *   - csrf_token()
 */
require_once (__DIR__ . "/auth/config.php");
require_once (__DIR__ . "/auth/auth.php");

/* ---------------------------------------------------------------------------
 * Authentication State
 * ---------------------------------------------------------------------------
 * current_user() returns user data if logged in, otherwise null.
 */
$user = current_user($pdo);

/* Boolean version used throughout page + JS */
$loggedIn = (bool)$user;

/* ---------------------------------------------------------------------------
 * Flash Message Handling
 * ---------------------------------------------------------------------------
 * Flash messages are temporary session-based notifications.
 */
$flash = null;

if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']); // show once only
}
?>

<!doctype html>
<html lang="en">
<head>

  <!-- Basic meta -->
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />

  <title>Browse Cards (Scryfall)</title>

  <!-- Page styling -->
  <link rel="stylesheet" href="./css/cards.css" />

  <!-- Browser tab icon -->
  <link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>

<body>

  <!-- Shared site header/navigation -->
  <?php require_once __DIR__ . "/partials/header.php"; ?>

  <!-- Accessibility skip link -->
  <a class="skip" href="#main">Skip to main content</a>

  <main id="main">

    <div class="wrap">

      <!-- ==================================================================
           Search Panel
           ================================================================== -->
      <section class="card" aria-labelledby="title">

        <h1 id="title">Browse cards (Scryfall)</h1>

        <p>
          Search Scryfall and show images and prices.

          <!-- Login-aware message -->
          <?php if ($loggedIn): ?>
            You are signed in as
            <span class="pill"><?= h($user['username']) ?></span>.
          <?php else: ?>
            Log in to add cards to your collection.
          <?php endif; ?>
        </p>

        <!-- Flash message -->
        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite">
            <?= h($flash) ?>
          </div>
        <?php endif; ?>

        <!-- ================================================================
             Search Controls
             ================================================================ -->
        <div id="searchForm" class="filters">

          <!-- Search Query -->
          <div class="filterField">

            <label for="q">Search</label>

            <!-- Uses native Scryfall search syntax -->
            <input
              id="q"
              name="q"
              maxlength="200"
              autocomplete="off"
              placeholder="e.g., t:elf set:khm"
            />

            <div class="help">
              Uses Scryfall advanced search syntax.
            </div>
          </div>

          <!-- Unique Mode -->
          <div class="filterField">

            <label for="unique">Unique</label>

            <!--
            cards  = one result per card
            prints = all printings
            art    = unique art versions
            -->
            <select id="unique" name="unique">
              <option value="cards">Cards</option>
              <option value="prints">Prints</option>
              <option value="art">Art</option>
            </select>

            <div class="help"></div>
          </div>

          <!-- Sort Order -->
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

          <!-- Action Buttons -->
          <div class="rowActions">

            <button type="button" id="searchBtn">
              Search
            </button>

            <button
              type="button"
              class="btnSecondary"
              id="clearBtn"
            >
              Clear
            </button>

            <div class="help"></div>
          </div>
        </div>

        <!-- Dynamic status line -->
        <div
          id="status"
          class="statusline"
          role="status"
          aria-live="polite"
        >
          Ready.
        </div>
      </section>

      <!-- ==================================================================
           Results Section
           ================================================================== -->
      <section
        class="card"
        style="margin-top:12px;"
        aria-labelledby="resultsTitle"
      >

        <h2 id="resultsTitle">Results</h2>

        <p class="small">
          Shows up to 20 results per search.
        </p>

        <!-- Search results inserted here -->
        <div
          id="results"
          class="results"
          aria-label="Search results"
        ></div>

        <!-- Pagination buttons inserted here -->
        <div id="pagination"></div>
      </section>
    </div>
  </main>

<script>

/* ============================================================================
 * PHP → JavaScript State
 * ========================================================================== */

/* Whether user is logged in */
const loggedIn = <?= $loggedIn ? 'true' : 'false' ?>;

/* CSRF token used by add-to-collection forms */
const csrfToken =
  <?= $loggedIn ? json_encode(csrf_token()) : '""' ?>;

/* ============================================================================
 * Cached DOM Elements
 * ========================================================================== */

const qEl = document.getElementById('q');
const uniqueEl = document.getElementById('unique');
const orderEl = document.getElementById('order');

const statusEl = document.getElementById('status');
const resultsEl = document.getElementById('results');
const paginationEl = document.getElementById('pagination');

const clearBtn = document.getElementById('clearBtn');

/* ============================================================================
 * UI Helpers
 * ========================================================================== */

/**
 * Updates the visible status message.
 */
function setStatus(msg, kind=""){
  statusEl.textContent = msg;
  statusEl.className =
    "statusline" + (kind ? (" " + kind) : "");
}

/**
 * Escapes HTML characters to prevent XSS.
 */
function esc(s){
  return String(s ?? "").replace(/[&<>"']/g, c => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#039;'
  }[c]));
}

/* ============================================================================
 * Image Helpers
 * ========================================================================== */

/**
 * Handles both:
 *   - standard cards
 *   - double-faced cards
 */
function pickImage(card){

  /* Standard image path */
  if (card?.image_uris?.small || card?.image_uris?.normal) {
    return {
      small: card.image_uris.small || "",
      normal:
        card.image_uris.normal ||
        card.image_uris.small ||
        ""
    };
  }

  /* Double-faced fallback */
  const f0 = card?.card_faces?.[0];

  if (f0?.image_uris?.small || f0?.image_uris?.normal) {
    return {
      small: f0.image_uris.small || "",
      normal:
        f0.image_uris.normal ||
        f0.image_uris.small ||
        ""
    };
  }

  /* No image available */
  return {
    small:"",
    normal:""
  };
}

/**
 * Creates formatted price badge HTML.
 */
function priceText(label, value){

  if (!value) return "";

  return `
    <span class="priceTag">
      <strong>${esc(label)}:</strong>
      $${esc(value)}
    </span>
  `;
}

/* ============================================================================
 * Result Renderer
 * ========================================================================== */

/**
 * Converts a Scryfall card object into HTML.
 */
function resultHTML(card){

  /* Safely extract fields */
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

  /* Pricing */
  const usd = card?.prices?.usd ?? "";
  const usdFoil = card?.prices?.usd_foil ?? "";
  const usdEtched = card?.prices?.usd_etched ?? "";

  /* Thumbnail */
  const imgThumb = img.small
    ? `
      <img
        class="thumb"
        src="${esc(img.small)}"
        loading="lazy"
        alt="Card image: ${esc(name)}"
      >
    `
    : `
      <div
        class="thumb"
        role="img"
        aria-label="No image available"
      ></div>
    `;

  /* Hover popup image */
  const pop = img.normal
    ? `
      <div class="pop" aria-hidden="true">
        <img src="${esc(img.normal)}" alt="">
      </div>
    `
    : ``;

  /* ------------------------------------------------------------------------
   * Add-to-Collection Block
   * ---------------------------------------------------------------------- */

  /* Default guest message */
  let addBlock = `
    <div class="small">
      Log in to add this card to your collection.
    </div>
  `;

  /* Logged-in users get the full form */
  if (loggedIn) {

    addBlock = `
      <form
        class="addForm"
        action="collection_config/add_to_collection.php"
        method="post"
      >

        <!-- CSRF Protection -->
        <input
          type="hidden"
          name="csrf"
          value="${esc(csrfToken)}"
        >

        <!-- Card identifiers -->
        <input type="hidden" name="scryfall_id" value="${esc(scryfallId)}">
        <input type="hidden" name="oracle_id" value="${esc(oracleId)}">

        <!-- Metadata -->
        <input type="hidden" name="name" value="${esc(name)}">
        <input type="hidden" name="type_line" value="${esc(typeLine)}">

        <input type="hidden" name="set_code" value="${esc(setCode)}">
        <input type="hidden" name="set_name" value="${esc(setName)}">

        <input type="hidden" name="collector_number" value="${esc(cn)}">

        <!-- Images -->
        <input type="hidden" name="image_small" value="${esc(img.small)}">
        <input type="hidden" name="image_normal" value="${esc(img.normal)}">

        <!-- Price snapshot -->
        <input type="hidden" name="price_usd" value="${esc(usd)}">
        <input type="hidden" name="price_usd_foil" value="${esc(usdFoil)}">
        <input type="hidden" name="price_usd_etched" value="${esc(usdEtched)}">

        <!-- Quantity -->
        <div>
          <label for="qty-${esc(scryfallId)}">Qty</label>

          <input
            id="qty-${esc(scryfallId)}"
            name="qty"
            type="number"
            min="1"
            max="999"
            value="1"
          >
        </div>

        <!-- Condition -->
        <div>
          <label for="cond-${esc(scryfallId)}">
            Condition
          </label>

          <select
            id="cond-${esc(scryfallId)}"
            name="card_condition"
          >
            <option value="NM">NM</option>
            <option value="LP">LP</option>
            <option value="MP">MP</option>
            <option value="HP">HP</option>
            <option value="DMG">DMG</option>
          </select>
        </div>

        <!-- Language -->
        <div>
          <label for="lang-${esc(scryfallId)}">
            Language
          </label>

          <input
            id="lang-${esc(scryfallId)}"
            name="card_language"
            value="English"
            maxlength="32"
          >
        </div>

        <!-- Finish -->
        <div>
          <label for="finish-${esc(scryfallId)}">
            Finish
          </label>

          <select
            id="finish-${esc(scryfallId)}"
            name="finish"
          >
            <option value="nonfoil">Non-foil</option>
            <option value="foil">Foil</option>
            <option value="etched">Etched</option>
          </select>
        </div>

        <!-- Card flags -->
        <div
          class="full checkRow"
          aria-label="Card flags"
        >
          <label>
            <input
              type="checkbox"
              name="is_signed"
              value="1"
            >
            Signed
          </label>

          <label>
            <input
              type="checkbox"
              name="is_altered"
              value="1"
            >
            Altered
          </label>
        </div>

        <!-- Notes -->
        <div class="full">

          <label for="notes-${esc(scryfallId)}">
            Notes (optional)
          </label>

          <textarea
            id="notes-${esc(scryfallId)}"
            name="notes"
            maxlength="500"
          ></textarea>
        </div>

        <!-- Acquisition date -->
        <div>

          <label for="acq-${esc(scryfallId)}">
            Acquired date
          </label>

          <input
            id="acq-${esc(scryfallId)}"
            name="acquired_at"
            type="date"
          >
        </div>

        <!-- Purchase price -->
        <div>

          <label for="paid-${esc(scryfallId)}">
            Purchase price (USD)
          </label>

          <input
            id="paid-${esc(scryfallId)}"
            name="purchase_price"
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
            inputmode="decimal"
          >
        </div>

        <!-- Submit -->
        <div class="full rowActions">

          <button type="submit">
            Add to my collection
          </button>
        </div>
      </form>
    `;
  }

  /* Final result card */
  return `
    <article class="result">

      <div class="resultGrid">

        <!-- Card Image -->
        <div class="thumbWrap">

          <a
            href="${esc(scryfallUrl)}"
            target="_blank"
            rel="noreferrer"
            aria-label="Open ${esc(name)} on Scryfall"
          >
            ${imgThumb}
          </a>

          ${pop}
        </div>

        <!-- Card Details -->
        <div>

          <!-- Name + Set -->
          <div
            style="
              display:flex;
              justify-content:space-between;
              gap:10px;
              flex-wrap:wrap;
              align-items:baseline;
            "
          >
            <div class="name">${esc(name)}</div>

            <div class="small">
              ${setCode ? esc(setCode) : ""}
              ${cn ? " #" + esc(cn) : ""}
            </div>
          </div>

          <!-- Type line -->
          ${
            typeLine
              ? `<div class="meta">${esc(typeLine)}</div>`
              : ``
          }

          <!-- Set name -->
          ${
            setName
              ? `<div class="small">Set: ${esc(setName)}</div>`
              : ``
          }

          <!-- Prices -->
          <div
            class="priceRow"
            aria-label="Prices from Scryfall"
          >

            ${priceText("USD", usd)}
            ${priceText("Foil", usdFoil)}
            ${priceText("Etched", usdEtched)}

            ${
              (!usd && !usdFoil && !usdEtched)
              ? `
                <span class="priceTag">
                  No price listed
                </span>
              `
              : ``
            }
          </div>

          <!-- Oracle text -->
          ${
            oracle
              ? `
                <details style="margin-top:8px;">
                  <summary>Rules text</summary>

                  <div class="meta">
                    ${esc(oracle)}
                  </div>
                </details>
              `
              : ``
          }

          <!-- Add form -->
          <div style="margin-top:10px;">
            ${addBlock}
          </div>
        </div>
      </div>
    </article>
  `;
}

/* ============================================================================
 * Pagination/Search State
 * ========================================================================== */

let allResults = [];
let visibleCount = 0;

let nextPageUrl = null;

/* Total results from Scryfall */
let totalCards = 0;

/* ============================================================================
 * Search Runner
 * ========================================================================== */

/**
 * Executes a Scryfall search.
 *
 * reset=true
 *   Fresh search
 *
 * reset=false
 *   Continue pagination
 */
async function runSearch(reset = true){

  console.debug('[MTG] runSearch called, reset=', reset);

  const q = qEl.value.trim();

  /* Empty query validation */
  if (!q){

    resultsEl.innerHTML = "";
    paginationEl.innerHTML = "";

    setStatus("Enter a search query.", "bad");

    qEl.focus();

    return;
  }

  /* Reset search state */
  if (reset){

    setStatus("Searching Scryfall…");

    resultsEl.innerHTML = "";
    paginationEl.innerHTML = "";

    allResults = [];

    visibleCount = 0;

    nextPageUrl = null;

    totalCards = 0;
  }

  let url;

  /* Continue from existing next_page URL */
  if (nextPageUrl && !reset){

    url = nextPageUrl;

  } else {

    /* Build fresh Scryfall query */
    const api =
      new URL("https://api.scryfall.com/cards/search");

    api.searchParams.set("q", q);

    api.searchParams.set("unique", uniqueEl.value);

    api.searchParams.set("order", orderEl.value);

    api.searchParams.set("dir", "auto");

    url = api.toString();
  }

  try {

    console.debug('[MTG] fetching url:', url);

    /* Fetch API response */
    const res = await fetch(url, {
      headers: {
        "Accept": "application/json"
      }
    });

    console.debug(
      '[MTG] fetch status:',
      res.status,
      res.ok
    );

    /* Parse JSON body */
    const data = await res.json();

    console.debug(
      '[MTG] parsed data keys:',
      Object.keys(data ?? {})
    );

    console.debug(
      '[MTG] cards in page:',
      data?.data?.length,
      '| has_more:',
      data?.has_more,
      '| total_cards:',
      data?.total_cards
    );

    /* Defensive validation */
    if (!data || typeof data !== 'object'){

      setStatus(
        "Invalid response from Scryfall.",
        "bad"
      );

      return;
    }

    /* Handle failed responses */
    if (!res.ok){

      setStatus(
        data?.details ||
        "Scryfall request failed.",
        "bad"
      );

      return;
    }

    /* Safely extract card list */
    const newCards =
      Array.isArray(data?.data)
      ? data.data
      : [];

    /* Append to local cache */
    allResults.push(...newCards);

    /* Save next page URL if more results exist */
    nextPageUrl =
      data.has_more
      ? data.next_page
      : null;

    /* Track total result count */
    totalCards =
      data.total_cards ??
      allResults.length;

    /* Render next batch */
    renderMoreResults();

  } catch (err) {

    console.error(
      '[MTG] exception in runSearch:',
      err
    );

    setStatus(
      "Network error talking to Scryfall.",
      "bad"
    );
  }
}

/* ============================================================================
 * Result Renderer + Pagination
 * ========================================================================== */

function renderMoreResults(){

  console.debug('[MTG] renderMoreResults called', {
    allResults: allResults.length,
    visibleCount,
    nextPageUrl,
    totalCards
  });

  /* Render results in chunks of 20 */
  const nextChunk =
    allResults.slice(
      visibleCount,
      visibleCount + 20
    );

  /* Fully done */
  if (
    nextChunk.length === 0 &&
    !nextPageUrl
  ){

    setStatus(
      `Showing all ${visibleCount} result(s).`,
      "ok"
    );

    paginationEl.innerHTML = "";

    return;
  }

  /* Render current chunk */
  if (nextChunk.length > 0){

    const html =
      nextChunk.map(resultHTML).join("");

    resultsEl.insertAdjacentHTML(
      'beforeend',
      html
    );

    visibleCount += nextChunk.length;
  }

  setStatus(
    `Found ${totalCards}. Showing ${visibleCount}.`,
    "ok"
  );

  paginationEl.innerHTML = "";

  /* More local results not yet rendered */
  const stillHiddenLocal =
    visibleCount < allResults.length;

  /* More results exist remotely */
  const hasMoreRemote =
    nextPageUrl !== null;

  console.debug('[MTG] pagination check', {
    stillHiddenLocal,
    hasMoreRemote
  });

  /* Add pagination button if needed */
  if (stillHiddenLocal || hasMoreRemote){

    paginationEl.innerHTML = `
      <button
        id="loadMore"
        style="margin-top:10px;"
      >
        Load more
      </button>
    `;

    document
      .getElementById('loadMore')
      .addEventListener('click', async () => {

        /* Use buffered results first */
        if (visibleCount < allResults.length){

          renderMoreResults();

        /* Otherwise fetch next API page */
        } else if (nextPageUrl){

          await runSearch(false);
        }
      });
  }
}

/* ============================================================================
 * Event Listeners
 * ========================================================================== */

/* Search button */
document
  .getElementById('searchBtn')
  .addEventListener('click', () => {

    runSearch(true);
  });

/* Enter key support */
qEl.addEventListener('keydown', (e) => {

  if (e.key === 'Enter') {

    runSearch(true);
  }
});

/* Clear button */
clearBtn.addEventListener('click', () => {

  qEl.value = "";

  resultsEl.innerHTML = "";
  paginationEl.innerHTML = "";

  setStatus("Cleared.");

  qEl.focus();
});
</script>
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

<!-- Shared site footer -->
<?php require_once __DIR__ . "/partials/footer.php"; ?>

</html>
