<?php
require_once __DIR__ . '/../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM spk LIKE 'kilometer'");
if ($check && mysqli_num_rows($check) > 0) {
    echo "SKIPPED: Column kilometer already exists on spk.\n";
    exit(0);
}

$sql = "ALTER TABLE spk ADD COLUMN kilometer INT UNSIGNED NULL AFTER vehicle_id";
if (mysqli_query($conn, $sql)) {
    echo "SUCCESS: Column kilometer added to spk.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
