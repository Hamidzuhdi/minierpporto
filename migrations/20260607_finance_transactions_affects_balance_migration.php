<?php
require_once __DIR__ . '/../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE 'affects_balance'");
if ($check && mysqli_num_rows($check) > 0) {
    echo "SKIPPED: Column affects_balance already exists on finance_transactions.\n";
    exit(0);
}

$sql = "ALTER TABLE finance_transactions ADD COLUMN affects_balance TINYINT(1) NOT NULL DEFAULT 1 AFTER status";
if (mysqli_query($conn, $sql)) {
    echo "SUCCESS: Column affects_balance added to finance_transactions.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
