<?php
require_once __DIR__ . '/../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$changed = [];

$taxCol = mysqli_query($conn, "SHOW COLUMNS FROM purchases LIKE 'tax_amount'");
if (!$taxCol || mysqli_num_rows($taxCol) === 0) {
    if (!mysqli_query($conn, "ALTER TABLE purchases ADD COLUMN tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total")) {
        fwrite(STDERR, "FAILED add purchases.tax_amount: " . mysqli_error($conn) . "\n");
        exit(2);
    }
    $changed[] = 'purchases.tax_amount';
}

$discountCol = mysqli_query($conn, "SHOW COLUMNS FROM purchase_items LIKE 'discount_amount'");
if (!$discountCol || mysqli_num_rows($discountCol) === 0) {
    if (!mysqli_query($conn, "ALTER TABLE purchase_items ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER harga_beli")) {
        fwrite(STDERR, "FAILED add purchase_items.discount_amount: " . mysqli_error($conn) . "\n");
        exit(3);
    }
    $changed[] = 'purchase_items.discount_amount';
}

// Ensure subtotal follows formula: (qty * harga_beli) - discount_amount.
$subtotalCol = mysqli_query($conn, "SHOW COLUMNS FROM purchase_items LIKE 'subtotal'");
$subtotalMeta = $subtotalCol ? mysqli_fetch_assoc($subtotalCol) : null;
$isGeneratedSubtotal = $subtotalMeta && stripos((string)($subtotalMeta['Extra'] ?? ''), 'generated') !== false;

if ($isGeneratedSubtotal) {
    if (!mysqli_query(
        $conn,
        "ALTER TABLE purchase_items
         MODIFY COLUMN subtotal DECIMAL(14,2)
         GENERATED ALWAYS AS (GREATEST((qty * harga_beli) - COALESCE(discount_amount, 0), 0)) STORED"
    )) {
        fwrite(STDERR, "FAILED alter generated subtotal formula: " . mysqli_error($conn) . "\n");
        exit(4);
    }
} else {
    mysqli_query(
        $conn,
        "UPDATE purchase_items
         SET subtotal = GREATEST((qty * harga_beli) - COALESCE(discount_amount, 0), 0)"
    );
}

mysqli_query(
    $conn,
    "UPDATE purchases p
     LEFT JOIN (
        SELECT purchase_id, SUM(GREATEST((qty * harga_beli) - COALESCE(discount_amount, 0), 0)) AS item_total
        FROM purchase_items
        GROUP BY purchase_id
     ) x ON x.purchase_id = p.id
     SET p.total = COALESCE(x.item_total, 0) + COALESCE(p.tax_amount, 0)"
);

if (count($changed) === 0) {
    echo "SKIPPED: required columns already exist.\n";
} else {
    echo "SUCCESS: added columns => " . implode(', ', $changed) . "\n";
}

echo "DONE: aligned subtotal formula and recalculated purchases.total.\n";
exit(0);
