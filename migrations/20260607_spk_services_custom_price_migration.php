<?php
require_once __DIR__ . '/../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$check1 = mysqli_query($conn, "SHOW COLUMNS FROM spk_services LIKE 'use_custom_price'");
$check2 = mysqli_query($conn, "SHOW COLUMNS FROM spk_services LIKE 'harga_custom'");

if ($check1 && mysqli_num_rows($check1) > 0 && $check2 && mysqli_num_rows($check2) > 0) {
    echo "SKIPPED: Columns use_custom_price and harga_custom already exist on spk_services.\n";
    exit(0);
}

$sql = "ALTER TABLE spk_services
        ADD COLUMN use_custom_price TINYINT(1) NOT NULL DEFAULT 0 AFTER harga,
        ADD COLUMN harga_custom DECIMAL(14,2) AFTER use_custom_price";

if (mysqli_query($conn, $sql)) {
    echo "SUCCESS: Columns use_custom_price and harga_custom added to spk_services.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
