<?php
// Memperbaiki invoice aktif yang diskonnya tidak sama dengan diskon SPK-nya.
//
// Penyebab umum: diskon di-ACC Owner SETELAH invoice dibuat (mis. SPK revisi, yang invoicenya langsung
// dibuat dengan diskon 0). Sebelumnya approval hanya mengubah tabel spk, sehingga invoice tetap
// menampilkan total penuh tanpa diskon, padahal PDF invoice (yang membaca SPK) sudah memotongnya.
//
// Pemakaian (dari folder proyek):
//   php scripts/sync_invoice_discount.php           -> HANYA menampilkan apa yang akan berubah (tidak menulis apa pun)
//   php scripts/sync_invoice_discount.php --apply   -> menerapkan perubahan
//
// Dry-run menjalankan sinkronisasi yang sama persis di dalam transaksi lalu membatalkannya (ROLLBACK),
// jadi pratinjau selalu sama dengan hasil --apply.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya boleh dijalankan lewat command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../invoice_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

$apply = in_array('--apply', $argv ?? [], true);

$col = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'discount_amount'");
if (!$col || mysqli_num_rows($col) === 0) {
    echo "SKIPPED: kolom invoices.discount_amount belum ada, tidak ada yang perlu disinkronkan.\n";
    exit(0);
}

function rp_fmt($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

// Hanya pembayaran yang approved dihitung (sama dengan spk_sync_active_invoice_totals).
$approvalCol = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'approval_status'");
$approvedCond = ($approvalCol && mysqli_num_rows($approvalCol) > 0)
    ? " AND (p.approval_status = 'approved' OR p.approval_status IS NULL)"
    : '';

function invoice_snapshot(mysqli $conn, int $invoiceId): array
{
    global $approvedCond;
    $res = mysqli_query(
        $conn,
        "SELECT i.discount_amount, i.total, i.status_piutang,
                COALESCE((SELECT SUM(p.amount) FROM payments p
                          WHERE p.invoice_id = i.id$approvedCond), 0) AS total_paid
         FROM invoices i WHERE i.id = $invoiceId"
    );
    return $res ? (mysqli_fetch_assoc($res) ?: []) : [];
}

// Invoice aktif yang diskonnya beda dengan yang seharusnya (diskon SPK yang sudah approved, maksimal sebesar subtotal).
$res = mysqli_query(
    $conn,
    "SELECT i.id AS invoice_id, i.no_invoice, i.spk_id, s.kode_unik_reference AS spk_code
     FROM invoices i
     JOIN spk s ON s.id = i.spk_id
     WHERE i.status_piutang <> 'Tidak_Aktif'
       AND ROUND(COALESCE(i.discount_amount, 0), 2) <> ROUND(
            CASE WHEN LOWER(s.discount_status) = 'approved'
                 THEN LEAST(GREATEST(s.discount_amount_approved, 0), i.biaya_jasa + i.biaya_sparepart)
                 ELSE 0 END, 2)
     ORDER BY i.id"
);
if (!$res) {
    fwrite(STDERR, 'Query gagal: ' . mysqli_error($conn) . "\n");
    exit(2);
}

$candidates = [];
while ($row = mysqli_fetch_assoc($res)) {
    $candidates[] = $row;
}

if (count($candidates) === 0) {
    echo "OK: semua invoice aktif sudah sesuai dengan diskon SPK-nya. Tidak ada yang perlu diubah.\n";
    exit(0);
}

echo ($apply ? "MODE APPLY" : "MODE DRY-RUN (tidak ada yang ditulis)") . ': ' . count($candidates) . " invoice akan disesuaikan\n\n";

mysqli_begin_transaction($conn);
$warnings = 0;
foreach ($candidates as $c) {
    $invoiceId = (int)$c['invoice_id'];
    $before = invoice_snapshot($conn, $invoiceId);
    spk_sync_active_invoice_totals($conn, (int)$c['spk_id']);
    $after = invoice_snapshot($conn, $invoiceId);

    echo "- {$c['spk_code']}  (invoice {$c['no_invoice']}, id $invoiceId)\n";
    echo '    Diskon : ' . rp_fmt($before['discount_amount'] ?? 0) . '  ->  ' . rp_fmt($after['discount_amount'] ?? 0) . "\n";
    echo '    Total  : ' . rp_fmt($before['total'] ?? 0) . '  ->  ' . rp_fmt($after['total'] ?? 0) . "\n";
    echo '    Status : ' . ($before['status_piutang'] ?? '-') . '  ->  ' . ($after['status_piutang'] ?? '-')
        . '   (terbayar ' . rp_fmt($after['total_paid'] ?? 0) . ")\n";

    if ((float)($after['total_paid'] ?? 0) > (float)($after['total'] ?? 0)) {
        $warnings++;
        echo '    PERHATIAN: pembayaran (' . rp_fmt($after['total_paid']) . ') melebihi total baru. '
            . "Customer sudah membayar lebih dari total setelah diskon, cek apakah perlu dikembalikan.\n";
    }
    echo "\n";
}

if ($apply) {
    mysqli_commit($conn);
    echo "SELESAI: perubahan disimpan.\n";
} else {
    mysqli_rollback($conn);
    echo "Dry-run selesai, tidak ada yang diubah. Jalankan lagi dengan --apply untuk menyimpan.\n";
}
if ($warnings > 0) {
    echo "$warnings invoice punya pembayaran yang melebihi total baru (lihat tanda PERHATIAN di atas).\n";
}
