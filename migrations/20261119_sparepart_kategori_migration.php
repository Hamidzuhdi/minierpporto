<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sparepart_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

function sparepart_kategori_type(mysqli $conn): ?string
{
    $res = mysqli_query($conn, "SHOW COLUMNS FROM spareparts LIKE 'kategori'");
    $col = $res ? mysqli_fetch_assoc($res) : null;
    return $col ? (string)$col['Type'] : null;
}

if (strcasecmp((string)sparepart_kategori_type($conn), SPAREPART_KATEGORI_TYPE) === 0) {
    echo "SKIPPED: spareparts.kategori sudah bertipe " . SPAREPART_KATEGORI_TYPE . ".\n";
    exit(0);
}

$before = sparepart_kategori_type($conn);
if (!sparepart_ensure_kategori_column($conn)) {
    echo "FAILED: " . mysqli_error($conn) . "\n";
    exit(2);
}

echo "SUCCESS: spareparts.kategori ENUM('Fast Moving','Slow Moving') "
    . ($before === null ? "ditambahkan (semua sparepart lama = Slow Moving).\n" : "dirapikan dari tipe $before.\n");
exit(0);
