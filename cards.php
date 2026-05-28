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

</body>

<!-- Shared site footer -->
<?php require_once __DIR__ . "/partials/footer.php"; ?>

</html>