<?php
// export_collection.php — Download the user's collection in various formats
declare(strict_types=1);

require_once(__DIR__ . "/../auth/config.php");
require_once(__DIR__ . "/../auth/auth.php");

require_login();
$uid = (int)$_SESSION['uid'];

$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
if (!in_array($format, ['json', 'csv', 'dek', 'txt'], true)) {
    http_response_code(400);
    exit('Invalid format.');
}

// --- Fetch full collection (no pagination) ---
$stmt = $pdo->prepare("
    SELECT
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

        c.name,
        c.set_code,
        c.set_name,
        c.collector_number,
        c.type_line,
        c.price_usd,
        c.price_usd_foil,
        c.price_usd_etched,
        c.scryfall_id
    FROM user_collection uc
    JOIN cards c ON c.id = uc.card_id
    WHERE uc.user_id = ?
    ORDER BY c.name ASC, c.set_code ASC, uc.card_condition ASC, uc.finish ASC
");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'collection_' . date('Y-m-d');

// ── JSON ─────────────────────────────────────────────────────────────────────
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');

    $out = array_map(fn($r) => [
        'name'           => $r['name'],
        'set_code'       => strtoupper($r['set_code'] ?? ''),
        'set_name'       => $r['set_name'],
        'collector_number' => $r['collector_number'],
        'qty'            => (int)$r['qty'],
        'condition'      => $r['card_condition'],
        'language'       => $r['card_language'],
        'finish'         => $r['finish'],
        'is_signed'      => (bool)$r['is_signed'],
        'is_altered'     => (bool)$r['is_altered'],
        'purchase_price' => $r['purchase_price'] !== null ? (float)$r['purchase_price'] : null,
        'acquired_at'    => $r['acquired_at'],
        'notes'          => $r['notes'],
        'scryfall_id'    => $r['scryfall_id'],
        'price_usd'      => $r['price_usd']       !== null ? (float)$r['price_usd']       : null,
        'price_usd_foil' => $r['price_usd_foil']  !== null ? (float)$r['price_usd_foil']  : null,
        'price_usd_etched' => $r['price_usd_etched'] !== null ? (float)$r['price_usd_etched'] : null,
    ], $rows);

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CSV ──────────────────────────────────────────────────────────────────────
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $out = fopen('php://output', 'w');

    // BOM for Excel UTF-8 compatibility
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Name', 'Set Code', 'Set Name', 'Collector #',
        'Qty', 'Condition', 'Language', 'Finish',
        'Signed', 'Altered', 'Purchase Price', 'Acquired',
        'Notes', 'Price (USD)', 'Price (USD Foil)', 'Price (USD Etched)',
        'Scryfall ID',
    ], ',', '"', '\\');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['name'],
            strtoupper($r['set_code'] ?? ''),
            $r['set_name'],
            $r['collector_number'],
            (int)$r['qty'],
            $r['card_condition'],
            $r['card_language'],
            $r['finish'],
            $r['is_signed']  ? 'Yes' : 'No',
            $r['is_altered'] ? 'Yes' : 'No',
            $r['purchase_price'] ?? '',
            $r['acquired_at']    ?? '',
            $r['notes']          ?? '',
            $r['price_usd']        ?? '',
            $r['price_usd_foil']   ?? '',
            $r['price_usd_etched'] ?? '',
            $r['scryfall_id'],
        ], ',', '"', '\\');
    }

    fclose($out);
    exit;
}

// ── DEK (MTGO XML) ───────────────────────────────────────────────────────────
if ($format === 'dek') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.dek"');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Deck xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
    echo '  <NetDeckID>0</NetDeckID>' . "\n";
    echo '  <PreconstructedDeckID>0</PreconstructedDeckID>' . "\n";

    foreach ($rows as $r) {
        $name = htmlspecialchars($r['name'], ENT_XML1, 'UTF-8');
        $qty  = (int)$r['qty'];
        // DEK uses CatID for multiverse ID; we don't store it so use 0
        echo "  <Cards CatID=\"0\" Quantity=\"{$qty}\" Sideboard=\"false\" Name=\"{$name}\" Annotation=\"0\" />\n";
    }

    echo '</Deck>' . "\n";
    exit;
}

// ── TXT (plain text — "Qty Name (SET) #collector" per line) ─────────────────
if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.txt"');

    echo "// My MTG Collection — exported " . date('Y-m-d') . "\n";
    echo "// Format: Qty Name (SET) #Collector | Condition | Finish | Language\n\n";

    $lastCard = null;
    foreach ($rows as $r) {
        // Print card name header when it changes
        $cardKey = $r['name'] . '||' . $r['set_code'];
        if ($cardKey !== $lastCard) {
            if ($lastCard !== null) echo "\n";
            $lastCard = $cardKey;
        }

        $set    = strtoupper($r['set_code'] ?? '');
        $num    = $r['collector_number'] ? ' #' . $r['collector_number'] : '';
        $cond   = $r['card_condition'];
        $finish = $r['finish'] !== 'nonfoil' ? ' [' . $r['finish'] . ']' : '';
        $lang   = $r['card_language'] && strtolower($r['card_language']) !== 'english'
                  ? ' [' . $r['card_language'] . ']' : '';
        $signed  = (int)$r['is_signed']  ? ' [signed]'  : '';
        $altered = (int)$r['is_altered'] ? ' [altered]' : '';

        echo "{$r['qty']} {$r['name']} ({$set}){$num} | {$cond}{$finish}{$lang}{$signed}{$altered}\n";
    }
    exit;
}