<?php
// decks.php
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

// WotC sanctioned formats
const FORMATS = [
  ''           => 'No format',
  'Standard'   => 'Standard',
  'Pioneer'    => 'Pioneer',
  'Modern'     => 'Modern',
  'Legacy'     => 'Legacy',
  'Vintage'    => 'Vintage',
  'Pauper'     => 'Pauper',
  'Commander'  => 'Commander (EDH)',
  'Oathbreaker'=> 'Oathbreaker',
  'Brawl'      => 'Brawl',
  'Explorer'   => 'Explorer',
  'Historic'   => 'Historic',
  'Alchemy'    => 'Alchemy',
  'Timeless'   => 'Timeless',
];

$stmt = $pdo->prepare("
  SELECT
    d.id,
    d.name,
    d.format,
    d.description,
    d.updated_at,
    d.is_public,

    c.image_small,
    c.name AS card_name,

    CASE WHEN (
      SELECT COUNT(*)
      FROM deck_cards dc2
      LEFT JOIN (
        SELECT card_id, finish, SUM(qty) AS owned_qty
        FROM user_collection
        WHERE user_id = d.user_id
        GROUP BY card_id, finish
      ) owned ON owned.card_id = dc2.card_id AND owned.finish = dc2.finish
      WHERE dc2.deck_id = d.id AND (owned.owned_qty IS NULL OR owned.owned_qty < dc2.qty)
    ) = 0 THEN 1 ELSE 0 END AS is_fully_owned

  FROM decks d

  LEFT JOIN deck_cards dc
    ON dc.id = (
      SELECT dc2.id
      FROM deck_cards dc2
      WHERE dc2.deck_id = d.id
      ORDER BY dc2.updated_at DESC
      LIMIT 1
    )

  LEFT JOIN cards c
    ON c.id = dc.card_id

  WHERE d.user_id = ?

  ORDER BY d.updated_at DESC
");
$stmt->execute([$uid]);
$decks = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>My Decks</title>
  <link rel="stylesheet" href="./css/decks.css" />
  <link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>
<body>
<?php
$loggedIn = true;
$activeNav = "decks";
include 'partials/header.php';
?>

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="title">
        <h1 id="title">My Decks</h1>
        <p>Create and manage decklists connected to your account.</p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <div class="grid" aria-label="Deck management">

          <!-- ── Create deck form ── -->
          <section class="card" aria-labelledby="createTitle">
            <h2 id="createTitle">Create a deck</h2>

            <form action="deck_config/create_deck.php" method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

              <label for="name">Deck name</label>
              <input id="name" name="name" required maxlength="80" autocomplete="off" />

              <div class="row">
                <div>
                  <label for="format">Format (optional)</label>
                  <select id="format" name="format">
                    <?php foreach (FORMATS as $value => $label): ?>
                      <option value="<?= h($value) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="is_public">Visibility</label>
                  <select id="is_public" name="is_public">
                    <option value="0">Private</option>
                    <option value="1">Public</option>
                  </select>
                </div>
              </div>

              <label for="description">Notes (optional)</label>
              <textarea id="description" name="description" maxlength="800"
                        placeholder="Strategy, budget, upgrade ideas…"></textarea>

              <div class="actions" style="margin-top:1rem;">
                <button class="btn" type="submit">Create deck</button>
              </div>
            </form>
          </section>

          <!-- ── Deck list ── -->
          <section class="card" aria-labelledby="listTitle">
            <h2 id="listTitle">Your decks</h2>

            <?php if (!$decks): ?>
              <p style="color:var(--text-muted);font-size:.9rem;">
                You don't have any decks yet. Create one using the form.
              </p>
            <?php else: ?>
              <div class="list" role="list" aria-label="Deck list">
                <?php foreach ($decks as $d): ?>
                <article class="deckItem" role="listitem">

                  <!-- Card art thumbnail -->
                  <div class="deckThumb">
                    <?php if (!empty($d['image_small'])): ?>
                      <img src="<?= h($d['image_small']) ?>"
                           alt="<?= h($d['card_name']) ?> card art">
                    <?php else: ?>
                      <div class="no-image">?</div>
                    <?php endif; ?>
                  </div>

                  <!-- Name, badges, meta -->
                  <div class="deckInfo">
                    <div class="deckNameRow">
                      <span class="deckName"><?= h((string)$d['name']) ?></span>
                      <?php if (!empty($d['is_fully_owned'])): ?>
                        <span class="owned-icon" title="All cards owned">✓</span>
                      <?php endif; ?>
                      <span class="pill"><?= !empty($d['is_public']) ? 'Public' : 'Private' ?></span>
                    </div>
                    <div class="meta">
                      <?php if (!empty($d['format'])): ?>
                        <span><?= h((string)$d['format']) ?></span>
                      <?php endif; ?>
                      <span>Updated <?= h(date('M j, Y', strtotime((string)$d['updated_at']))) ?></span>
                    </div>
                  </div>

                  <!-- Open button -->
                  <div class="deckActions">
                    <a class="btn secondary" href="deck.php?id=<?= (int)$d['id'] ?>">Open</a>
                  </div>

                </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

        </div>
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
