<?php
require_once __DIR__ . '/../config.php';

echo "=== Migration: Fix Purchase Status Column ===\n";

// Expand status column to accommodate all values
$sql = "ALTER TABLE purchases MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Pending'";

if (mysqli_query($conn, $sql)) {
    echo "✓ purchases.status column expanded to VARCHAR(50)\n";
} else {
    echo "✗ FAILED: " . mysqli_error($conn) . "\n";
    exit(1);
}

echo "DONE: Purchase status column fixed.\n";
?>
