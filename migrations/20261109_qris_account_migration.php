<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../finance_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

function qris_migration_is_ready(mysqli $conn): bool
{
    $acc = mysqli_query($conn, "SELECT 1 FROM finance_accounts WHERE code = 'qris' LIMIT 1");
    if (!$acc || mysqli_num_rows($acc) === 0) {
        return false;
    }

    foreach (['method', 'pending_method'] as $column) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE '$column'");
        $col = $res ? mysqli_fetch_assoc($res) : null;
        if (!$col || stripos((string)($col['Type'] ?? ''), "'qris'") === false) {
            return false;
        }
    }

    return true;
}

if (qris_migration_is_ready($conn)) {
    echo "SKIPPED: QRIS account and payment method already exist.\n";
    exit(0);
}

finance_ensure_default_accounts($conn);

if (qris_migration_is_ready($conn)) {
    echo "SUCCESS: Account 'qris' created and payments.method / pending_method now accept 'qris'.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
