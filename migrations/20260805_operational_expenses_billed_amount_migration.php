<?php
require_once __DIR__ . '/../config.php';

echo "=== Migration: Nominal Ditagih Customer (untuk hitung Laba OPL) ===\n";

$col = mysqli_query($conn, "SHOW COLUMNS FROM operational_expenses LIKE 'billed_amount'");
if ($col && mysqli_num_rows($col) > 0) {
    echo "✓ operational_expenses.billed_amount sudah ada\n";
} else {
    $sql = "ALTER TABLE operational_expenses ADD COLUMN billed_amount DECIMAL(14,2) NULL AFTER amount";
    if (mysqli_query($conn, $sql)) {
        echo "✓ Kolom operational_expenses.billed_amount ditambahkan\n";
    } else {
        echo "✗ FAILED: " . mysqli_error($conn) . "\n";
    }
}

echo "DONE.\n";
?>
