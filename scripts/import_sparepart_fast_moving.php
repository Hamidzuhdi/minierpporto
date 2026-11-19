<?php
// Memuat daftar barang Fast Moving beserta stok minimumnya (scripts/data/sparepart_fast_moving.csv)
// ke tabel spareparts: kategori menjadi "Fast Moving" dan min_stock mengikuti daftar.
// Barang yang tidak ada di daftar tidak disentuh (tetap Slow Moving / apa adanya).
//
// Pemakaian (dari folder proyek):
//   php scripts/import_sparepart_fast_moving.php           -> HANYA menampilkan apa yang akan berubah (tidak menulis apa pun)
//   php scripts/import_sparepart_fast_moving.php --apply   -> menerapkan perubahan
//
// Pencocokan HANYA lewat kode (kode_sparepart atau barcode), tidak menebak lewat kemiripan nama:
//   - kode sama persis (huruf besar/kecil, spasi, tanda hubung diabaikan), atau
//   - kode sama kecuali awalan huruf merek (mis. "DHT 15601-87702-008 ADM" = "15601-87702-008 ADM").
// Kode yang tidak mengandung angka (mis. "LionShield", yang hanya nama merek dan dipakai banyak barang)
// hanya dipercaya bila nama barangnya juga cocok (kata pertama nama di daftar ada di nama barang).
// Baris yang kodenya kosong, tidak ditemukan, atau cocok ke lebih dari satu barang tidak diubah dan
// dilaporkan di akhir supaya bisa diatur manual lewat halaman Spareparts.
//
// Dry-run menjalankan update yang sama persis di dalam transaksi lalu membatalkannya (ROLLBACK),
// jadi pratinjau selalu sama dengan hasil --apply.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya boleh dijalankan lewat command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sparepart_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

$apply = in_array('--apply', $argv ?? [], true);
$csvPath = __DIR__ . '/data/sparepart_fast_moving.csv';

// Kolom kategori harus sudah ada (dry-run tidak boleh mengubah struktur tabel).
$colRes = mysqli_query($conn, "SHOW COLUMNS FROM spareparts LIKE 'kategori'");
$col = $colRes ? mysqli_fetch_assoc($colRes) : null;
if (!$col || strcasecmp((string)$col['Type'], SPAREPART_KATEGORI_TYPE) !== 0) {
    fwrite(STDERR, "Kolom spareparts.kategori belum sesuai. Jalankan dulu: php migrations/20261119_sparepart_kategori_migration.php\n");
    exit(1);
}

if (!is_readable($csvPath)) {
    fwrite(STDERR, "File data tidak ditemukan: $csvPath\n");
    exit(1);
}

function code_key(string $code): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
}

// Kode dianggap sama bila identik, atau salah satunya = awalan huruf merek + yang lain.
function codes_equivalent(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
    if (strlen($short) < 6 || !preg_match('/\d/', $short) || substr($long, -strlen($short)) !== $short) {
        return false;
    }
    return (bool)preg_match('/^[A-Z]{2,}$/', substr($long, 0, strlen($long) - strlen($short)));
}

// Untuk kode berupa nama merek: kata pertama nama di daftar harus ada di nama barang (tanpa spasi/tanda baca).
function name_consistent(string $listName, string $dbName): bool
{
    if (!preg_match('/[A-Za-z]{3,}/', $listName, $m)) {
        return true;
    }
    return strpos(preg_replace('/[^A-Z]/', '', strtoupper($dbName)), strtoupper($m[0])) !== false;
}

// Data sparepart yang ada
$spareparts = [];
$res = mysqli_query($conn, "SELECT id, nama, kode_sparepart, barcode, kategori, min_stock FROM spareparts");
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $row['keys'] = array_values(array_filter([code_key((string)$row['kode_sparepart']), code_key((string)$row['barcode'])]));
    $spareparts[] = $row;
}

// Baca CSV
$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh);
$listRows = [];
while (($cols = fgetcsv($fh)) !== false) {
    if (count($cols) < 3 || trim(implode('', $cols)) === '') {
        continue;
    }
    $listRows[] = ['nama' => trim($cols[0]), 'kode' => trim($cols[1]), 'min_stock' => trim($cols[2])];
}
fclose($fh);

echo ($apply ? "MODE APPLY" : "MODE DRY-RUN (tidak ada yang ditulis)") . ': ' . count($listRows) . " baris di daftar Fast Moving\n\n";

$changes = [];
$unchanged = 0;
$problems = [];
$usedIds = [];

mysqli_begin_transaction($conn);
foreach ($listRows as $item) {
    $label = $item['nama'] . ($item['kode'] !== '' ? " [{$item['kode']}]" : '');
    $itemKey = code_key($item['kode']);

    if ($itemKey === '') {
        $problems[] = ['Tanpa kode, atur manual', $label];
        continue;
    }

    $matches = [];
    foreach ($spareparts as $sp) {
        foreach ($sp['keys'] as $k) {
            if (codes_equivalent($itemKey, $k)) {
                $matches[$sp['id']] = $sp;
                break;
            }
        }
    }

    // Kode tanpa angka = nama merek, bisa dipakai banyak barang: wajib sejalan dengan nama barangnya.
    if (!preg_match('/\d/', $item['kode'])) {
        $matches = array_filter($matches, function ($m) use ($item) {
            return name_consistent($item['nama'], (string)$m['nama']);
        });
        if (count($matches) === 0) {
            $problems[] = ['Kode hanya nama merek dan nama barang tidak cocok, atur manual', $label];
            continue;
        }
    }

    if (count($matches) === 0) {
        $problems[] = ['Kode tidak ditemukan di data sparepart', $label];
        continue;
    }
    if (count($matches) > 1) {
        $names = array_map(function ($m) { return "#{$m['id']} {$m['nama']}"; }, $matches);
        $problems[] = ['Cocok ke lebih dari satu barang (' . implode('; ', $names) . ')', $label];
        continue;
    }

    $sp = reset($matches);
    $id = (int)$sp['id'];
    if (isset($usedIds[$id])) {
        $problems[] = ["Duplikat: barang #$id sudah diisi oleh baris '{$usedIds[$id]}'", $label];
        continue;
    }
    $usedIds[$id] = $label;

    $newMin = $item['min_stock'] === '' ? null : (int)$item['min_stock'];
    $setParts = [];
    $diffs = [];
    if ($sp['kategori'] !== SPAREPART_KATEGORI_FAST) {
        $setParts[] = "kategori = '" . SPAREPART_KATEGORI_FAST . "'";
        $diffs[] = "kategori {$sp['kategori']} -> " . SPAREPART_KATEGORI_FAST;
    }
    if ($newMin !== null && (int)$sp['min_stock'] !== $newMin) {
        $setParts[] = "min_stock = $newMin";
        $diffs[] = "min stok {$sp['min_stock']} -> $newMin";
    }

    if (!$setParts) {
        $unchanged++;
        continue;
    }

    if (!mysqli_query($conn, 'UPDATE spareparts SET ' . implode(', ', $setParts) . " WHERE id = $id")) {
        mysqli_rollback($conn);
        fwrite(STDERR, "Gagal update barang #$id: " . mysqli_error($conn) . "\n");
        exit(2);
    }
    $changes[] = "#$id {$sp['nama']} ({$sp['kode_sparepart']})  <-  daftar: {$item['nama']}
        " . implode(', ', $diffs);
}

echo 'Akan berubah (' . count($changes) . "):\n";
foreach ($changes as $c) {
    echo "  - $c\n";
}
echo "\nSudah sesuai, tidak berubah: $unchanged\n";

echo "\nTidak diproses (" . count($problems) . ") -> atur manual lewat halaman Spareparts:\n";
$byReason = [];
foreach ($problems as [$reason, $label]) {
    $byReason[$reason][] = $label;
}
foreach ($byReason as $reason => $labels) {
    echo "  * $reason: " . count($labels) . "\n";
    foreach ($labels as $l) {
        echo "      - $l\n";
    }
}

echo "\n";
if ($apply) {
    mysqli_commit($conn);
    echo "SELESAI: perubahan disimpan.\n";
} else {
    mysqli_rollback($conn);
    echo "Dry-run selesai, tidak ada yang diubah. Jalankan lagi dengan --apply untuk menyimpan.\n";
}
