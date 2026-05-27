<?php
/******************************************************************************
 * File: collection.php
 *
 * Purpose:
 * ----------------------------------------------------------------------------
 * This page is responsible for displaying the currently logged-in user's
 * Magic: The Gathering card collection.
 *
 * Features Included:
 * ----------------------------------------------------------------------------
 * - User authentication protection
 * - Collection summary statistics
 * - Estimated collection value calculations
 * - Paid value calculations
 * - Dynamic filtering system
 * - AJAX-powered lazy loading / pagination
 * - Editable collection entries
 * - Variant grouping (foil, etched, signed, etc.)
 * - Export functionality
 * - Hover image previews
 * - Accessibility enhancements
 * - CSRF protection
 *
 * Main Technologies Used:
 * ----------------------------------------------------------------------------
 * - PHP 8+
 * - PDO (database access)
 * - Vanilla JavaScript
 * - HTML5
 * - CSS
 *
 * Database Tables Used:
 * ----------------------------------------------------------------------------
 * - user_collection
 * - cards
 *
 * Security Features:
 * ----------------------------------------------------------------------------
 * - Strict typing enabled
 * - Session-based authentication
 * - CSRF token validation
 * - Escaped HTML output
 * - Prepared SQL statements
 *
 * Author:
 * ----------------------------------------------------------------------------
 * Logan Napier
 *
 * Notes:
 * ----------------------------------------------------------------------------
 * This file acts as both:
 *   1. A server-rendered PHP page
 *   2. A frontend controller for dynamic JS interactions
 *
 ******************************************************************************/

/**
 * Enables PHP strict typing.
 *
 * This forces PHP to respect declared parameter and return types more strictly.
 *
 * Example:
 *   function test(int $x)
 *
 * Without strict_types:
 *   test("5") would silently convert string -> int
 *
 * With strict_types enabled:
 *   test("5") throws a TypeError
 */
declare(strict_types=1);

/******************************************************************************
 * REQUIRED FILES
 ******************************************************************************/

/**
 * config.php
 *
 * Usually contains:
 * - Database connection ($pdo)
 * - Utility helper functions
 * - Session initialization
 * - Environment configuration
 */
require_once (__DIR__ . "/auth/config.php");

/**
 * auth.php
 *
 * Usually contains:
 * - Login checking
 * - User helper functions
 * - Authentication utilities
 */
require_once (__DIR__ . "/auth/auth.php");

/******************************************************************************
 * AUTHENTICATION
 ******************************************************************************/

/**
 * require_login()
 *
 * Prevents guests from accessing this page.
 *
 * Typical behavior:
 * - If user is NOT logged in:
 *     redirect to login page
 *
 * - If logged in:
 *     continue execution
 */
require_login();

/**
 * Pull current logged-in user ID from session.
 *
 * Casting to int provides:
 * - type safety
 * - protection against accidental string usage
 */
$uid = (int)$_SESSION['uid'];

/**
 * Retrieve full current user record.
 *
 * Example returned data:
 * [
 *   'id' => 5,
 *   'username' => 'logan',
 *   'email' => '...'
 * ]
 */
$user = current_user($pdo);

/**
 * Used by header.php
 * to know whether user is logged in.
 */
$loggedIn = true;

/******************************************************************************
 * FLASH MESSAGE SYSTEM
 ******************************************************************************/

/**
 * Flash messages are temporary session messages.
 *
 * Common examples:
 * - "Card updated successfully"
 * - "Collection item deleted"
 * - "Import complete"
 *
 * Flash messages are usually:
 *   1. Stored in session
 *   2. Read once
 *   3. Immediately deleted
 */
$flash = null;

/**
 * Check if flash message exists.
 */
if (!empty($_SESSION['flash'])) {

  /**
   * Convert flash message to string safely.
   */
  $flash = (string)$_SESSION['flash'];

  /**
   * Remove flash after reading.
   *
   * Prevents it from appearing repeatedly.
   */
  unset($_SESSION['flash']);
}

/******************************************************************************
 * COLLECTION SUMMARY QUERY
 *
 * IMPORTANT:
 * ----------------------------------------------------------------------------
 * This query intentionally ignores filters.
 *
 * These totals always represent:
 *   ENTIRE USER COLLECTION
 *
 * NOT:
 *   currently filtered results
 ******************************************************************************/

/**
 * Prepare SQL query.
 *
 * We join:
 * - user_collection
 * - cards
 *
 * Why?
 * ----------------------------------------------------------------------------
 * user_collection contains:
 *   user-owned data
 *
 * cards contains:
 *   official card pricing/info
 */
$stmt = $pdo->prepare("
  SELECT
    uc.qty,
    uc.purchase_price,
    uc.finish,

    c.price_usd,
    c.price_usd_foil,
    c.price_usd_etched

  FROM user_collection uc

  JOIN cards c
    ON c.id = uc.card_id

  WHERE uc.user_id = ?
");

/**
 * Execute prepared statement.
 *
 * Using prepared statements protects against SQL injection.
 */
$stmt->execute([$uid]);

/**
 * Fetch ALL matching rows into array.
 *
 * Example:
 * [
 *   [
 *     'qty' => 2,
 *     'finish' => 'foil',
 *     ...
 *   ],
 *   ...
 * ]
 */
$allRows = $stmt->fetchAll();

/******************************************************************************
 * HELPER FUNCTIONS
 ******************************************************************************/

/**
 * money_val()
 *
 * Purpose:
 * ----------------------------------------------------------------------------
 * Formats int into USD currency string.
 *
 * Example:
 * ----------------------------------------------------------------------------
 * Input:
 *   12.5
 *
 * Output:
 *   "$12.50"
 *
 * Null handling:
 * ----------------------------------------------------------------------------
 * Returns empty string if value missing.
 */
function money_val($v): string {
  /**
   * Handle empty values.
   */
  if ($v === null || $v === '') {
    return '';
  }
  return '$' . number_format((float)$v, 2);
}

/**
 * finish_price()
 *
 * Purpose:
 * ----------------------------------------------------------------------------
 * Determines correct Scryfall price
 * based on card finish.
 *
 * Possible finishes:
 * ----------------------------------------------------------------------------
 * - nonfoil
 * - foil
 * - etched
 *
 * Why needed:
 * ----------------------------------------------------------------------------
 * Scryfall stores separate prices for each finish.
 */
function finish_price(array $r): ?float {

  /**
   * Default finish fallback.
   */
  $finish = (string)($r['finish'] ?? 'nonfoil');

  /**************************************************************************
   * FOIL PRICE
   **************************************************************************/
  if ($finish === 'foil') {

    /**
     * Ensure foil price actually exists.
     */
    return (
      $r['price_usd_foil'] !== null &&
      $r['price_usd_foil'] !== ''
    )
      ? (float)$r['price_usd_foil']
      : null;
  }

  /**************************************************************************
   * ETCHED PRICE
   **************************************************************************/
  if ($finish === 'etched') {

    return (
      $r['price_usd_etched'] !== null &&
      $r['price_usd_etched'] !== ''
    )
      ? (float)$r['price_usd_etched']
      : null;
  }

  /**************************************************************************
   * DEFAULT NONFOIL PRICE
   **************************************************************************/
  return (
    $r['price_usd'] !== null &&
    $r['price_usd'] !== ''
  )
    ? (float)$r['price_usd']
    : null;
}

/******************************************************************************
 * SUMMARY TOTAL VARIABLES
 ******************************************************************************/

/**
 * Total quantity of cards.
 *
 * Example:
 * If user owns:
 * - 4 Lightning Bolt
 * - 2 Sol Ring
 *
 * totalQty = 6
 */
$totalQty = 0;

/**
 * Total amount user personally paid.
 */
$totalPaid = 0.0;

/**
 * Number of copies with known purchase prices.
 */
$totalPaidKnown = 0;

/**
 * Estimated Scryfall market value.
 */
$totalEst = 0.0;

/**
 * Number of copies with known market prices.
 */
$totalEstKnown = 0;

/******************************************************************************
 * CALCULATE COLLECTION TOTALS
 ******************************************************************************/

/**
 * Loop through every collection row.
 */
foreach ($allRows as $r) {

  /**
   * Convert quantity safely to integer.
   */
  $qty = (int)$r['qty'];

  /**
   * Add to total quantity.
   */
  $totalQty += $qty;

  /**************************************************************************
   * USER PAID VALUE CALCULATION
   **************************************************************************/

  /**
   * Only calculate if purchase price exists.
   */
  if ($r['purchase_price'] !== null && $r['purchase_price'] !== '') {

    /**
     * Multiply:
     *   purchase price * quantity
     */
    $totalPaid += ((float)$r['purchase_price']) * $qty;

    /**
     * Track known-price copies.
     */
    $totalPaidKnown += $qty;
  }

  /**************************************************************************
   * SCRYFALL ESTIMATED VALUE CALCULATION
   **************************************************************************/

  /**
   * Determine correct finish-specific price.
   */
  $p = finish_price($r);

  /**
   * Only use valid prices.
   */
  if ($p !== null && $p >= 0) {

    /**
     * Add estimated market value.
     */
    $totalEst += $p * $qty;

    /**
     * Track known-price copies.
     */
    $totalEstKnown += $qty;
  }
}

/******************************************************************************
 * FILTER DROPDOWN DATA
 *
 * Purpose:
 * ----------------------------------------------------------------------------
 * Populate "Set" dropdown dynamically.
 ******************************************************************************/

/**
 * Get distinct sets owned by user.
 */
$setsStmt = $pdo->prepare("
  SELECT DISTINCT
    c.set_code,
    c.set_name

  FROM user_collection uc

  JOIN cards c
    ON c.id = uc.card_id

  WHERE uc.user_id = ?

  ORDER BY c.set_name ASC
");

/**
 * Execute query.
 */
$setsStmt->execute([$uid]);

/**
 * Fetch all distinct sets.
 */
$sets = $setsStmt->fetchAll();

/******************************************************************************
 * DETERMINE IF USER HAS ANY CARDS
 ******************************************************************************/

/**
 * Boolean shortcut for easier template rendering.
 */
$hasCards = $totalQty > 0;
?>