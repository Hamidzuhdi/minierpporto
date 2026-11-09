<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../followup_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$check = mysqli_query($conn, "SHOW TABLES LIKE 'customer_followups'");
if ($check && mysqli_num_rows($check) > 0) {
    echo "SKIPPED: Table customer_followups already exists.\n";
    exit(0);
}

followup_ensure_table($conn);

$check = mysqli_query($conn, "SHOW TABLES LIKE 'customer_followups'");
if ($check && mysqli_num_rows($check) > 0) {
    echo "SUCCESS: Table customer_followups created.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
