<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../followup_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

function followup_hasil_column_type(mysqli $conn): string
{
    $res = mysqli_query($conn, "SHOW COLUMNS FROM customer_followups LIKE 'hasil'");
    $col = $res ? mysqli_fetch_assoc($res) : null;
    return (string)($col['Type'] ?? '');
}

$type = followup_hasil_column_type($conn);
if ($type === '') {
    echo "SKIPPED: Table customer_followups does not exist yet (it will be created with the new values).\n";
    exit(0);
}
if (stripos($type, "'tidak_jawab'") === false) {
    echo "SKIPPED: customer_followups.hasil already uses no_respon / respon / prospek.\n";
    exit(0);
}

followup_ensure_hasil_values($conn);

$type = followup_hasil_column_type($conn);
if (stripos($type, "'tidak_jawab'") === false && stripos($type, "'prospek'") !== false) {
    echo "SUCCESS: customer_followups.hasil renamed (tidak_jawab->no_respon, jawab->respon, jawab_lanjut->prospek).\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
