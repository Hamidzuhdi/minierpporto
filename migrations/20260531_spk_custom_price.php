<?php
require_once __DIR__ . '/../config.php';

echo "=== Migration: Add Custom Price Support to SPK ===\n";

// Add use_custom_price column to spk_items
$sql1 = "ALTER TABLE spk_items ADD COLUMN use_custom_price TINYINT(1) DEFAULT 0 AFTER subtotal";
if (@mysqli_query($conn, $sql1)) {
    echo "✓ Added spk_items.use_custom_price column\n";
} else {
    if (strpos(mysqli_error($conn), "Duplicate column") === false) {
        echo "✗ FAILED add use_custom_price: " . mysqli_error($conn) . "\n";
    } else {
        echo "✓ spk_items.use_custom_price already exists\n";
    }
}

// Add harga_custom column to spk_items
$sql2 = "ALTER TABLE spk_items ADD COLUMN harga_custom DECIMAL(14,2) DEFAULT NULL AFTER use_custom_price";
if (@mysqli_query($conn, $sql2)) {
    echo "✓ Added spk_items.harga_custom column\n";
} else {
    if (strpos(mysqli_error($conn), "Duplicate column") === false) {
        echo "✗ FAILED add harga_custom: " . mysqli_error($conn) . "\n";
    } else {
        echo "✓ spk_items.harga_custom already exists\n";
    }
}

// Add use_custom_price column to spk table for toggle
$sql3 = "ALTER TABLE spk ADD COLUMN use_custom_price TINYINT(1) DEFAULT 0 AFTER status_spk";
if (@mysqli_query($conn, $sql3)) {
    echo "✓ Added spk.use_custom_price column\n";
} else {
    if (strpos(mysqli_error($conn), "Duplicate column") === false) {
        echo "✗ FAILED add spk.use_custom_price: " . mysqli_error($conn) . "\n";
    } else {
        echo "✓ spk.use_custom_price already exists\n";
    }
}

echo "DONE: SPK custom price columns added.\n";
?>
