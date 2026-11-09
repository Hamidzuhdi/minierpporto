<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dp_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

function dp_migration_is_ready(mysqli $conn): bool
{
    $table = mysqli_query($conn, "SHOW TABLES LIKE 'spk_down_payments'");
    $column = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'spk_dp_id'");

    return $table && mysqli_num_rows($table) > 0 && $column && mysqli_num_rows($column) > 0;
}

if (dp_migration_is_ready($conn)) {
    echo "SKIPPED: spk_down_payments table and payments.spk_dp_id already exist.\n";
    exit(0);
}

// Metode pembayaran 'qris' dibutuhkan oleh kolom DP; pastikan sudah tersedia.
finance_ensure_default_accounts($conn);
dp_ensure_schema($conn);

if (dp_migration_is_ready($conn)) {
    echo "SUCCESS: Table spk_down_payments created and payments.spk_dp_id added.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
