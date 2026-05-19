<?php
// collection_api.php — JSON endpoint for filtered, paginated collection data
declare(strict_types=1);

require_once(__DIR__ . "/../auth/config.php");
require_once(__DIR__ . "/../auth/auth.php");

require_login();
$uid = (int)$_SESSION['uid'];

header('Content-Type: application/json; charset=utf-8');

// --- Params ---
$search    = trim((string)($_GET['search']    ?? ''));
$set       = trim((string)($_GET['set']       ?? ''));
$condition = trim((string)($_GET['condition'] ?? ''));
$finish    = trim((string)($_GET['finish']    ?? ''));
$perPage   = in_array((int)($_GET['per_page'] ?? 20), [20, 50, 100], true)
             ? (int)$_GET['per_page'] : 20;
$offset    = max(0, (int)($_GET['offset'] ?? 0));

// --- Build WHERE clauses ---
$where  = ["uc.user_id = ?"];
$params = [$uid];

if ($search !== '') {
    $where[]  = "c.name LIKE ?";
    $params[] = '%' . $search . '%';
}
if ($set !== '') {
    $where[]  = "c.set_code = ?";
    $params[] = strtolower($set);
}
if ($condition !== '') {
    $where[]  = "uc.card_condition = ?";
    $params[] = $condition;
}
if ($finish !== '') {
    $where[]  = "uc.finish = ?";
    $params[] = $finish;
}

$whereSQL = implode(' AND ', $where);

// --- Total count (for "X of Y" display) ---
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM user_collection uc
    JOIN cards c ON c.id = uc.card_id
    WHERE $whereSQL
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// --- Fetch page ---
$dataParams   = $params;
$dataParams[] = $perPage;
$dataParams[] = $offset;

$stmt = $pdo->prepare("
    SELECT
        uc.id           AS collection_id,
        uc.qty,
        uc.card_condition,
        uc.card_language,
        uc.finish,
        uc.is_signed,
        uc.is_altered,
        uc.notes,
        uc.acquired_at,
        uc.purchase_price,
        uc.updated_at,
        
        c.scryfall_id,
        c.name,
        c.type_line,
        c.set_code,
        c.set_name,
        c.collector_number,
        c.image_small,
        c.price_usd,
        c.price_usd_foil,
        c.price_usd_etched,
        c.price_updated_at
        
    FROM user_collection uc
    JOIN cards c ON c.id = uc.card_id
    WHERE $whereSQL
    ORDER BY c.name ASC, uc.card_language ASC, uc.card_condition ASC, uc.finish ASC
    LIMIT ? OFFSET ?
");
$stmt->execute($dataParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'total'    => $total,
    'offset'   => $offset,
    'per_page' => $perPage,
    'rows'     => $rows,
    'has_more' => ($offset + count($rows)) < $total,
]);
