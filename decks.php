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
                    <option value="1">Public (optional)</option>
                  </select>
                </div>
              </div>

              <label for="description">Notes (optional)</label>
              <textarea id="description" name="description" maxlength="800" placeholder="Strategy, budget, upgrade ideas…"></textarea>

              <div class="actions" style="margin-top:10px;">
                <button class="btn" type="submit">Create deck</button>
              </div>
            </form>
          </section>

          <section class="card" aria-labelledby="listTitle">
            <h2 id="listTitle">Your decks</h2>

            <?php if (!$decks): ?>
              <p>You don't have any decks yet. Create one using the form.</p>
            <?php else: ?>
              <div class="list" role="list" aria-label="Deck list">
                <?php foreach ($decks as $d): ?>
                  <article class="deckItem" role="listitem">
                    <div class="deckTop">
                      <div class="deckName">
                        <?= h((string)$d['name']) ?>
                        <?php if (!empty($d['is_fully_owned'])): ?>
                          <span class="owned-icon" title="All cards owned in collection">✓</span>
                        <?php endif; ?>
                      </div>
                      <div class="pill"><?= !empty($d['is_public']) ? 'Public' : 'Private' ?></div>
                    </div>

                    <div class="meta">
                      <?php if (!empty($d['format'])): ?>
                        <?= h((string)$d['format']) ?> •
                      <?php endif; ?>
                      Updated: <?= h((string)$d['updated_at']) ?>
                    </div>

                    <?php if (!empty($d['description'])): ?>
                      <div class="meta"><?= h((string)$d['description']) ?></div>
                    <?php endif; ?>

                    <div class="actions" style="margin-top:10px;">
                      <a class="btn secondary" href="deck.php?id=<?= (int)$d['id'] ?>">Open / edit</a>
                    </div>

                    <div class="deckPreview">
                      <?php if (!empty($d['image_small'])): ?>
                        <img src="<?= h($d['image_small']) ?>" alt="<?= h($d['card_name']) ?> preview">
                      <?php else: ?>
                        <div class="no-image">No cards</div>
                      <?php endif; ?>
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
</body>
</html>