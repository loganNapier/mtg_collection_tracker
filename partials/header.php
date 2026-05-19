<?php
// partials/header.php
declare(strict_types=1);

if (!isset($activeNav)) $activeNav = "";   // home | cards | collection | batch | decks
if (!isset($loggedIn)) $loggedIn = false;
?>

<a class="skip" href="#main">Skip to main content</a>

<header class="siteHeader">
  <div class="wrap">
    <div class="top">

      
      <div class="left">
        <img src="/img/mtg_collection_tracker_logo.png" alt="MTG Collection DB Logo">
        <div class="brand">MTG Collection DB</div>
      </div>

      
      <nav aria-label="Primary navigation">
        <ul class="navList">
          <li><a href="index.php"<?= $activeNav === "home" ? ' aria-current="page"' : '' ?>>Home</a></li>
          <li><a href="cards.php"<?= $activeNav === "cards" ? ' aria-current="page"' : '' ?>>Browse cards</a></li>

          <?php if ($loggedIn): ?>
            <li><a href="collection.php"<?= $activeNav === "collection" ? ' aria-current="page"' : '' ?>>My collection</a></li>
            <li><a href="batch_add.php"<?= $activeNav === "batch" ? ' aria-current="page"' : '' ?>>Batch add</a></li>
            <li><a href="decks.php"<?= $activeNav === "decks" ? ' aria-current="page"' : '' ?>>Decks</a></li>
            <li><a href="./users/logout.php">Logout</a></li>
          <?php else: ?>
            <li><a href="index.php#login">Login</a></li>
            <li><a href="index.php#register">Register</a></li>
          <?php endif; ?>
        </ul>
      </nav>

    </div>
  </div>
</header>

<style>


.siteHeader {
  border-bottom: 1px solid var(--border);
  background: linear-gradient(90deg, var(--panel), var(--bg));
}

.top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}


.left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.left img {
  height: 40px;
  width: auto;
  display: block;
}

.brand {
  font-weight: 900;
  letter-spacing: 0.2px;
}


.navList {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.navList a {
  display: inline-block;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid transparent;
  text-decoration: none;
  color: inherit;
}

.navList a:hover {
  background: rgba(255,255,255,0.03);
  border-color: var(--border);
}



.skip {
  position: absolute;
  left: -9999px;
}

.skip:focus {
  position: static;
  display: inline-block;
  margin: 10px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--panel);
}
</style>