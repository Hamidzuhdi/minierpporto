<?php
require_once __DIR__ . '/../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM expense_categories LIKE 'status'");
if ($check && mysqli_num_rows($check) > 0) {
    echo "SKIPPED: Column status already exists on expense_categories.\n";
    exit(0);
}

$sql = "ALTER TABLE expense_categories ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 0 AFTER description";
if (mysqli_query($conn, $sql)) {
    echo "SUCCESS: Column status added to expense_categories with default 0.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
