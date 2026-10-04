<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../finance_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

finance_ensure_default_accounts($conn);

$requiredColumns = [
    'status',
    'approved_by',
    'approved_at',
    'rejected_by',
    'rejected_at',
];

$missing = [];
foreach ($requiredColumns as $column) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    if (!$res || mysqli_num_rows($res) === 0) {
        $missing[] = $column;
    }
}

$statusInfo = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE 'status'");
$statusRow = $statusInfo ? mysqli_fetch_assoc($statusInfo) : null;
$indexInfo = mysqli_query($conn, "SHOW INDEX FROM finance_transactions WHERE Key_name = 'idx_finance_tx_status'");
$hasStatusIndex = $indexInfo && mysqli_num_rows($indexInfo) > 0;

if (count($missing) > 0) {
    echo "Migration FAILED\n";
    echo "Missing columns: " . implode(', ', $missing) . "\n";
    exit(2);
}

echo "Migration SUCCESS\n";
echo "status column type: " . ($statusRow['Type'] ?? '-') . "\n";
echo "status default: " . ($statusRow['Default'] ?? '-') . "\n";
echo "status index exists: " . ($hasStatusIndex ? 'yes' : 'no') . "\n";
