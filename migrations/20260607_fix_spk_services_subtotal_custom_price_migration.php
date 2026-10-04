<?php
require_once __DIR__ . '/../config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

// spk_services.subtotal was a GENERATED column using only `qty * harga`,
// ignoring harga_custom/use_custom_price entirely. This made custom price edits
// for jasa silently fail to reflect in subtotal (and any manual UPDATE of
// subtotal errors out since it's a STORED generated column).
// Align formula with spk_items.subtotal so custom price is respected.
$sql = "ALTER TABLE spk_services
        MODIFY COLUMN subtotal DECIMAL(14,2)
        GENERATED ALWAYS AS (
            CASE WHEN (use_custom_price = 1 AND harga_custom IS NOT NULL)
                 THEN (qty * harga_custom)
                 ELSE (qty * harga)
            END
        ) STORED";

if (mysqli_query($conn, $sql)) {
    echo "SUCCESS: spk_services.subtotal formula updated to respect harga_custom.\n";
    exit(0);
}

echo "FAILED: " . mysqli_error($conn) . "\n";
exit(2);
