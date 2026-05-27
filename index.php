<?php
/******************************************************************************
 * File: index.php
 *
 * Purpose:
 * ----------------------------------------------------------------------------
 * This file serves as the main landing page / homepage for the
 * MTG Collection Database web application.
 *
 * Primary Responsibilities:
 * ----------------------------------------------------------------------------
 * - Detect whether a user is logged in
 * - Display navigation shortcuts
 * - Display login/register forms for guests
 * - Display quick-access actions for authenticated users
 * - Render flash messages
 * - Provide secure CSRF-protected authentication forms
 *
 * Technologies Used:
 * ----------------------------------------------------------------------------
 * - PHP 8+
 * - HTML5
 * - CSS
 * - Session-based authentication
 *
 * Security Features:
 * ----------------------------------------------------------------------------
 * - Strict typing enabled
 * - Escaped HTML output
 * - CSRF token protection
 * - Secure password storage messaging
 *
 * Related Files:
 * ----------------------------------------------------------------------------
 * - /auth/config.php
 * - /auth/auth.php
 * - /users/login.php
 * - /users/register.php
 * - /partials/header.php
 * - /partials/footer.php
 *
 * Notes:
 * ----------------------------------------------------------------------------
 * This page acts as:
 *
 * 1. Public marketing/landing page
 * 2. Authentication portal
 * 3. User dashboard launcher
 *
 ******************************************************************************/

/**
 * Enable strict type enforcement.
 *
 * This improves reliability and catches
 * accidental type coercion bugs.
 *
 * Example:
 * ----------------------------------------------------------------------------
 * Without strict types:
 *   "5" may become integer 5 automatically
 *
 * With strict types:
 *   invalid types throw errors instead
 */
declare(strict_types=1);

/******************************************************************************
 * REQUIRED FILES
 ******************************************************************************/

/**
 * config.php
 *
 * Usually contains:
 * ----------------------------------------------------------------------------
 * - Database connection ($pdo)
 * - Session startup
 * - Helper functions
 * - Global configuration
 */
require_once (__DIR__ . "/auth/config.php");

/**
 * auth.php
 *
 * Usually contains:
 * ----------------------------------------------------------------------------
 * - Authentication helpers
 * - User session utilities
 * - Login checking functions
 */
require_once (__DIR__ . "/auth/auth.php");

/******************************************************************************
 * CURRENT USER LOOKUP
 ******************************************************************************/

/**
 * Retrieve currently authenticated user.
 *
 * Possible return values:
 * ----------------------------------------------------------------------------
 * Logged in:
 *   [
 *     'id' => 1,
 *     'username' => 'logan',
 *     ...
 *   ]
 *
 * Not logged in:
 *   null
 */
$user = current_user($pdo);

/**
 * Convert user existence into boolean.
 *
 * TRUE:
 *   user logged in
 *
 * FALSE:
 *   guest visitor
 */
$loggedIn = (bool)$user;

/******************************************************************************
 * FLASH MESSAGE SYSTEM
 *
 * Flash messages are temporary session-based notifications.
 *
 * Common examples:
 * ----------------------------------------------------------------------------
 * - "Account created successfully"
 * - "Logged in successfully"
 * - "Logged out"
 * - "Password incorrect"
 ******************************************************************************/

/**
 * Default flash value.
 */
$flash = null;

/**
 * Check if session contains flash message.
 */
if (!empty($_SESSION['flash'])) {

  /**
   * Safely cast flash to string.
   */
  $flash = (string)$_SESSION['flash'];

  /**
   * Delete flash after reading.
   *
   * Prevents duplicate display on refresh.
   */
  unset($_SESSION['flash']);
}
?>

<!--
==============================================================================
BEGIN HTML DOCUMENT
==============================================================================
-->
<!doctype html>

<!--
lang="en"
----------------------------------------------------------------------------
Improves:
- accessibility
- SEO
- screen reader support
-->
<html lang="en">

<head>

  <!--
  UTF-8 Character Encoding
  --------------------------------------------------------------------------
  Supports:
  - special symbols
  - emoji
  - foreign characters
  -->
  <meta charset="utf-8" />

  <!--
  Mobile Responsive Viewport
  --------------------------------------------------------------------------
  Makes layout scale correctly on:
  - phones
  - tablets
  - desktops
  -->
  <meta name="viewport" content="width=device-width,initial-scale=1" />

  <!--
  Browser Tab Title
  -->
  <title>MTG Collection DB</title>

  <!--
  Main homepage stylesheet
  -->
  <link rel="stylesheet" href="./css/index.css" />

  <!--
  Website favicon
  --------------------------------------------------------------------------
  Small browser tab icon.
  -->
  <link
    rel="icon"
    href="/img/mtg_collection_tracker_favicon.ico"
    type="image/x-icon">
</head>

<body>

  <!--
  Accessibility Skip Link
  --------------------------------------------------------------------------
  Allows keyboard/screen-reader users
  to skip navigation directly to main content.
  -->
  <a class="skip" href="#main">
    Skip to main content
  </a>

  <!--
  Shared site header/navigation
  -->
  <?php require_once __DIR__ . "/partials/header.php"; ?>

  <!--
  ============================================================================
  MAIN PAGE CONTENT
  ============================================================================
  -->
  <main id="main">

    <!--
    .wrap likely controls:
    - max-width
    - horizontal padding
    - centering
    -->
    <div class="wrap">

      <!--
      =========================================================================
      HERO / WELCOME SECTION
      =========================================================================
      -->
      <section
        class="card"
        aria-labelledby="welcomeTitle">

        <!--
        Main page heading
        -->
        <h1 id="welcomeTitle">
          Track your Magic: The Gathering collection
        </h1>

        <!--
        Brief application description
        -->
        <p>
          Search cards via Scryfall, then add them to your collection
          with condition, language, finish, notes, and prices.
        </p>

        <!--
        =====================================================================
        FLASH MESSAGE DISPLAY
        =====================================================================
        -->
        <?php if ($flash): ?>

          <!--
          role="status"
          --------------------------------------------------------------------
          Announces updates to assistive technologies.
          -->
          <div
            class="statusline ok"
            role="status"
            aria-live="polite">

            <!--
            h()
            --------------------------------------------------------------------
            Escapes HTML to prevent XSS attacks.
            -->
            <?= h($flash) ?>

          </div>

        <?php endif; ?>

        <!--
        =====================================================================
        LOGGED-IN USER VIEW
        =====================================================================
        -->
        <?php if ($loggedIn): ?>

          <!--
          Display current username.
          -->
          <p>
            You are signed in as

            <!--
            .pill likely styles username badge
            -->
            <span class="pill">

              <!--
              Escape username for security.
              -->
              <?= h((string)$user['username']) ?>

            </span>.
          </p>

          <!--
          Quick navigation/action buttons
          -->
          <div class="actions">

            <!--
            User collection page
            -->
            <a class="btn" href="collection.php">
              Go to My collection
            </a>

            <!--
            Browse card database
            -->
            <a class="btn secondary" href="cards.php">
              Browse cards
            </a>

            <!--
            Batch add/import page
            -->
            <a class="btn secondary" href="batch_add.php">
              Batch add
            </a>

            <!--
            Deck management page
            -->
            <a class="btn secondary" href="decks.php">
              Decks
            </a>

            <!--
            Logout endpoint
            -->
            <a class="btn secondary" href="/users/logout.php">
              Logout
            </a>

          </div>

        <!--
        =====================================================================
        GUEST / NOT LOGGED IN VIEW
        =====================================================================
        -->
        <?php else: ?>

          <!--
          Warning-style status message
          -->
          <div
            class="statusline.bad statusline"
            role="status"
            aria-live="polite">

            Not signed in.
            Register or log in below.

          </div>

        <?php endif; ?>

      </section>

      <!--
      =========================================================================
      AUTHENTICATION FORMS
      =========================================================================
      Only visible to guests.
      =========================================================================
      -->
      <?php if (!$loggedIn): ?>

        <!--
        Grid layout containing:
        - Register card
        - Login card
        -->
        <section
          class="grid"
          aria-label="Account actions">

          <!--
          =====================================================================
          REGISTER SECTION
          =====================================================================
          -->
          <section
            class="card"
            id="register"
            aria-labelledby="registerTitle">

            <h2 id="registerTitle">
              Register
            </h2>

            <!--
            Security reassurance text
            -->
            <p>
              Passwords are stored securely using
              <code>password_hash()</code>.
            </p>

            <!--
            Registration form
            -->
            <form
              action="/users/register.php"
              method="post"
              autocomplete="on">

              <!--
              CSRF TOKEN
              ----------------------------------------------------------------
              Prevents Cross-Site Request Forgery attacks.
              -->
              <input
                type="hidden"
                name="csrf"
                value="<?= h(csrf_token()) ?>">

              <!--
              ===============================================================
              USERNAME + EMAIL ROW
              ===============================================================
              -->
              <div class="row">

                <!-- USERNAME -->
                <div>

                  <label for="r_user">
                    Username
                  </label>

                  <input
                    id="r_user"
                    name="username"
                    required
                    maxlength="32"
                    autocomplete="username">

                </div>

                <!-- EMAIL -->
                <div>

                  <label for="r_email">
                    Email
                  </label>

                  <input
                    id="r_email"
                    name="email"
                    type="email"
                    required
                    maxlength="255"
                    autocomplete="email">

                </div>
              </div>

              <!--
              ===============================================================
              PASSWORD ROW
              ===============================================================
              -->
              <div class="row">

                <!-- PASSWORD -->
                <div>

                  <label for="r_pass">
                    Password
                  </label>

                  <input
                    id="r_pass"
                    name="password"
                    type="password"
                    required
                    minlength="8"
                    autocomplete="new-password">

                </div>

                <!-- CONFIRM PASSWORD -->
                <div>

                  <label for="r_pass2">
                    Confirm password
                  </label>

                  <input
                    id="r_pass2"
                    name="password2"
                    type="password"
                    required
                    minlength="8"
                    autocomplete="new-password">

                </div>
              </div>

              <!--
              Submit registration form
              -->
              <button class="btn" type="submit">
                Create account
              </button>

            </form>
          </section>

          <!--
          =====================================================================
          LOGIN SECTION
          =====================================================================
          -->
          <aside
            class="card"
            id="login"
            aria-labelledby="loginTitle">

            <h2 id="loginTitle">
              Login
            </h2>

            <p>
              Use your username (or email) and password.
            </p>

            <!--
            Login form
            -->
            <form
              action="/users/login.php"
              method="post"
              autocomplete="on">

              <!--
              CSRF protection token
              -->
              <input
                type="hidden"
                name="csrf"
                value="<?= h(csrf_token()) ?>">

              <!--
              USERNAME / EMAIL INPUT
              -->
              <label for="l_id">
                Username or email
              </label>

              <input
                id="l_id"
                name="identifier"
                required
                maxlength="255"
                autocomplete="username">

              <!--
              PASSWORD INPUT
              -->
              <label for="l_pass">
                Password
              </label>

              <input
                id="l_pass"
                name="password"
                type="password"
                required
                autocomplete="current-password">

              <!--
              Login submit button
              -->
              <button class="btn" type="submit">
                Login
              </button>

            </form>

            <!--
            Secondary guest actions
            -->
            <div class="actions" style="margin-top:10px;">

              <!--
              Allow browsing cards without account
              -->
              <a class="btn secondary" href="cards.php">
                Browse without logging in
              </a>

            </div>
          </aside>
        </section>

      <?php endif; ?>
    </div>
  </main>

  <!--
  Shared site footer
  -->
  <?php require_once __DIR__ . "/partials/footer.php"; ?>

</body>
</html>