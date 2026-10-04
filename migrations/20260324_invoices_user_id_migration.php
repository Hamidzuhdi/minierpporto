<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../finance_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$res = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'user_id'");
if (!$res || mysqli_num_rows($res) === 0) {
    if (!mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN user_id INT NULL AFTER note")) {
        fwrite(STDERR, "FAILED add user_id column: " . mysqli_error($conn) . "\n");
        exit(2);
    }
}

$idx = mysqli_query($conn, "SHOW INDEX FROM invoices WHERE Key_name = 'idx_invoices_user_id'");
if (!$idx || mysqli_num_rows($idx) === 0) {
    if (!mysqli_query($conn, "ALTER TABLE invoices ADD INDEX idx_invoices_user_id (user_id)")) {
        fwrite(STDERR, "FAILED add index: " . mysqli_error($conn) . "\n");
        exit(3);
    }
}

$fk = mysqli_query($conn, "
    SELECT 1
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'invoices'
      AND CONSTRAINT_NAME = 'fk_invoices_user'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    LIMIT 1
");
if (!$fk || mysqli_num_rows($fk) === 0) {
    if (!mysqli_query($conn, "
        ALTER TABLE invoices
        ADD CONSTRAINT fk_invoices_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
    ")) {
        fwrite(STDERR, "FAILED add FK: " . mysqli_error($conn) . "\n");
        exit(4);
    }
}

$colInfo = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'user_id'");
$row = $colInfo ? mysqli_fetch_assoc($colInfo) : null;
echo "Migration SUCCESS\n";
echo "Column user_id type: " . ($row['Type'] ?? '-') . "\n";
