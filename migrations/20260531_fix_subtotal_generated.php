<?php
require_once __DIR__ . '/../config.php';

echo "=== Migration: Fix Subtotal Generated Column for Custom Price ===\n";

// Modify subtotal generated column to use harga_custom when applicable
$sql = "ALTER TABLE spk_items MODIFY COLUMN subtotal DECIMAL(14,2) GENERATED ALWAYS AS (
    CASE 
        WHEN use_custom_price = 1 AND harga_custom IS NOT NULL THEN qty * harga_custom
        ELSE qty * harga_satuan
    END
) STORED";

if (mysqli_query($conn, $sql)) {
    echo "✓ Modified spk_items.subtotal GENERATED column to support custom price\n";
    echo "DONE: Subtotal now calculates using harga_custom when use_custom_price = 1\n";
} else {
    echo "✗ FAILED to modify subtotal: " . mysqli_error($conn) . "\n";
}
?>
