<?php

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
  <title>MTG Collection DB</title>
  <link rel="stylesheet" href="./css/index.css" />
  <link rel="icon" href="/img/mtg_collection_tracker_favicon.ico" type="image/x-icon">
</head>
<body>
  <a class="skip" href="#main">Skip to main content</a>
  <?php require_once __DIR__ . "/partials/header.php"; ?>
  

  <main id="main">
    <div class="wrap">
      <section class="card" aria-labelledby="welcomeTitle">
        <h1 id="welcomeTitle">Track your Magic: The Gathering collection</h1>
        <p>Search cards via Scryfall, then add them to your collection with condition, language, finish, notes, and prices.</p>

        <?php if ($flash): ?>
          <div class="statusline ok" role="status" aria-live="polite"><?= h($flash) ?></div>
        <?php endif; ?>

        <?php if ($loggedIn): ?>
          <p>You are signed in as <span class="pill"><?= h((string)$user['username']) ?></span>.</p>
          <div class="actions">
            <a class="btn" href="collection.php">Go to My collection</a>
            <a class="btn secondary" href="cards.php">Browse cards</a>
            <a class="btn secondary" href="batch_add.php">Batch add</a>
            <a class="btn secondary" href="decks.php">Decks</a>
            <a class="btn secondary" href="/users/logout.php">Logout</a>
          </div>
        <?php else: ?>
          <div class="statusline.bad statusline" role="status" aria-live="polite">Not signed in. Register or log in below.</div>
        <?php endif; ?>
      </section>

      <?php if (!$loggedIn): ?>
        <section class="grid" aria-label="Account actions">
          <section class="card" id="register" aria-labelledby="registerTitle">
            <h2 id="registerTitle">Register</h2>
            <p>Passwords are stored securely using <code>password_hash()</code>.</p>

            <form action="/users/register.php" method="post" autocomplete="on">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

              <div class="row">
                <div>
                  <label for="r_user">Username</label>
                  <input id="r_user" name="username" required maxlength="32" autocomplete="username">
                </div>
                <div>
                  <label for="r_email">Email</label>
                  <input id="r_email" name="email" type="email" required maxlength="255" autocomplete="email">
                </div>
              </div>

              <div class="row">
                <div>
                  <label for="r_pass">Password</label>
                  <input id="r_pass" name="password" type="password" required minlength="8" autocomplete="new-password">
                </div>
                <div>
                  <label for="r_pass2">Confirm password</label>
                  <input id="r_pass2" name="password2" type="password" required minlength="8" autocomplete="new-password">
                </div>
              </div>

              <button class="btn" type="submit">Create account</button>
            </form>
          </section>

          <aside class="card" id="login" aria-labelledby="loginTitle">
            <h2 id="loginTitle">Login</h2>
            <p>Use your username (or email) and password.</p>

            <form action="/users/login.php" method="post" autocomplete="on">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

              <label for="l_id">Username or email</label>
              <input id="l_id" name="identifier" required maxlength="255" autocomplete="username">

              <label for="l_pass">Password</label>
              <input id="l_pass" name="password" type="password" required autocomplete="current-password">

              <button class="btn" type="submit">Login</button>
            </form>

            <div class="actions" style="margin-top:10px;">
              <a class="btn secondary" href="cards.php">Browse without logging in</a>
            </div>
          </aside>
        </section>
      <?php endif; ?>
    </div>
  </main>

  <?php require_once __DIR__ . "/partials/footer.php"; ?>
</body>
</html>
