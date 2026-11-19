<?php
// Membetulkan data LAMA: SPK yang diskonnya sudah di-ACC Owner SETELAH invoice dibuat.
//
// Latar belakang
//   Dulu, saat diskon di-ACC hanya tabel spk yang berubah, sehingga invoice tetap menampilkan harga penuh.
//   Akibatnya admin terpaksa menginput pembayaran sebesar harga penuh (sistem menolak nominal yang lebih kecil
//   dari sisa tagihan), padahal uang yang masuk sebenarnya sudah dipotong diskon. Dampaknya ke tiga tempat:
//   total invoice, nominal pembayaran, dan saldo akun (Cash/Bank/QRIS) yang jadi lebih besar dari uang asli.
//
// Aturan yang dijalankan (per invoice aktif terbaru milik SPK yang diskonnya approved tapi belum tercatat di invoice)
//   A. Invoice SUDAH LUNAS
//        - invoice dibetulkan: subtotal, diskon, total (diskon tampil di layar Invoice)
//        - pembayaran non-DP TERAKHIR diturunkan sampai total pembayaran = total setelah diskon;
//          transaksi ledger lamanya dibalik dan diganti yang baru, sehingga saldo akun ikut benar
//   B. Invoice BELUM LUNAS dan belum ada cicilan   -> invoice dibetulkan saja
//   C. Invoice BELUM LUNAS dan sudah ada cicilan   -> invoice dibetulkan, cicilan TIDAK diubah, sisa hutang dihitung
//        ulang: masih kurang (Sudah Dicicil) / pas (jadi Lunas) / kelebihan bayar (ditandai untuk dicek manual)
//   D. Yang tidak cocok aturan di atas (data janggal) TIDAK disentuh dan masuk daftar MANUAL beserta alasannya.
//
// Pemakaian (dari folder proyek):
//   php scripts/sync_invoice_discount.php                     -> DRY-RUN: tampilkan apa yang akan berubah, tidak menulis apa pun
//   php scripts/sync_invoice_discount.php --apply             -> terapkan (menulis backup JSON ke scripts/backup/ dulu)
//   php scripts/sync_invoice_discount.php --invoice=12,15     -> batasi ke invoice tertentu (mis. uji coba satu invoice dulu)
//   php scripts/sync_invoice_discount.php --csv=laporan.csv   -> tulis juga laporan CSV (untuk dicek di Excel/Sheets)
//   php scripts/sync_invoice_discount.php --user=1            -> id user yang dicatat di audit log / ledger (opsional)
//
// Dry-run menjalankan kode yang sama persis di dalam satu transaksi lalu membatalkannya (ROLLBACK), jadi pratinjau
// (termasuk saldo akun sesudah koreksi) selalu sama dengan hasil --apply. Tiap invoice punya SAVEPOINT sendiri:
// kalau satu invoice gagal (mis. saldo kas tidak cukup untuk membalik transaksi), hanya invoice itu yang dibatalkan.
// Jalankan sebaiknya saat aplikasi tidak sedang dipakai, dan backup database dulu.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya boleh dijalankan lewat command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../invoice_helper.php';
require_once __DIR__ . '/../finance_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

// ---------------------------------------------------------------------------------------------------------------
// Opsi
// ---------------------------------------------------------------------------------------------------------------
$opts = getopt('', ['apply', 'invoice:', 'csv:', 'user:', 'help']);
if (isset($opts['help'])) {
    echo "Lihat komentar di bagian atas file ini untuk penjelasan lengkap.\n"
        . "  (tanpa opsi)        dry-run, tidak menulis apa pun\n"
        . "  --apply             terapkan perubahan\n"
        . "  --invoice=ID[,ID]   batasi ke invoice tertentu\n"
        . "  --csv=PATH          tulis laporan CSV\n"
        . "  --user=ID           id user untuk audit log / ledger\n";
    exit(0);
}

$apply = isset($opts['apply']);

$onlyInvoices = [];
if (isset($opts['invoice'])) {
    $raw = is_array($opts['invoice']) ? implode(',', $opts['invoice']) : (string)$opts['invoice'];
    foreach (explode(',', $raw) as $v) {
        $n = (int)trim($v);
        if ($n > 0) {
            $onlyInvoices[$n] = $n;
        }
    }
    if (count($onlyInvoices) === 0) {
        fwrite(STDERR, "--invoice harus berisi id invoice berupa angka, mis. --invoice=12,15\n");
        exit(1);
    }
}

$csvPath = isset($opts['csv']) ? (string)(is_array($opts['csv']) ? end($opts['csv']) : $opts['csv']) : null;
$userId = isset($opts['user']) ? (int)(is_array($opts['user']) ? end($opts['user']) : $opts['user']) : 0;

// ---------------------------------------------------------------------------------------------------------------
// Helper kecil
// ---------------------------------------------------------------------------------------------------------------
function rp($value): string
{
    $n = (float)$value;
    return ($n < 0 ? '-' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.');
}

function cents($value): int
{
    return (int)round(((float)$value) * 100);
}

function db_all(mysqli $conn, string $sql): array
{
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        fwrite(STDERR, 'Query gagal: ' . mysqli_error($conn) . "\n  " . $sql . "\n");
        exit(2);
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    return $rows;
}

function db_one(mysqli $conn, string $sql): ?array
{
    $rows = db_all($conn, $sql);
    return $rows[0] ?? null;
}

function db_has_col(mysqli $conn, string $table, string $col): bool
{
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

function audit_log(mysqli $conn, int $userId, string $table, int $targetId, string $description): void
{
    $userSql = $userId > 0 ? (string)$userId : 'NULL';
    $descEsc = mysqli_real_escape_string($conn, '[Koreksi diskon data lama] ' . $description);
    mysqli_query(
        $conn,
        "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
         VALUES ($userSql, 'UPDATE', '$table', $targetId, '$descEsc', NOW())"
    );
}

function account_balances(mysqli $conn): array
{
    $out = [];
    foreach (db_all($conn, "SELECT id, code, name, current_balance FROM finance_accounts ORDER BY id") as $r) {
        $out[(int)$r['id']] = ['name' => $r['name'], 'balance' => (float)$r['current_balance']];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------
// Pemeriksaan skema
// ---------------------------------------------------------------------------------------------------------------
if (!db_has_col($conn, 'invoices', 'discount_amount')) {
    echo "SKIPPED: kolom invoices.discount_amount belum ada, tidak ada yang perlu disinkronkan.\n";
    exit(0);
}

$ctx = [
    'has_appr' => db_has_col($conn, 'payments', 'approval_status'),
    'has_fin'  => db_has_col($conn, 'payments', 'finance_tx_id'),
    'has_dp'   => db_has_col($conn, 'payments', 'spk_dp_id'),
];

if ($userId > 0 && !db_one($conn, "SELECT id FROM users WHERE id = $userId")) {
    fwrite(STDERR, "--user=$userId tidak ditemukan di tabel users.\n");
    exit(1);
}

// ---------------------------------------------------------------------------------------------------------------
// Kandidat: invoice aktif TERBARU milik SPK, diskon SPK sudah approved, tetapi diskon di invoice belum sama.
// (SPK yang belum punya invoice tidak ikut: invoicenya nanti dibuat dengan diskon yang benar.)
// ---------------------------------------------------------------------------------------------------------------
$onlyCond = $onlyInvoices ? ' AND i.id IN (' . implode(',', $onlyInvoices) . ')' : '';
$candidates = db_all(
    $conn,
    "SELECT i.id AS invoice_id, i.no_invoice, i.spk_id, i.status_piutang,
            i.discount_amount AS inv_discount, i.total AS inv_total, i.biaya_jasa, i.biaya_sparepart,
            s.kode_unik_reference AS spk_code, s.status_spk, s.discount_amount_approved AS spk_discount,
            c.name AS customer_name, v.nomor_polisi
     FROM invoices i
     JOIN spk s ON s.id = i.spk_id
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN vehicles v ON v.id = s.vehicle_id
     WHERE i.status_piutang <> 'Tidak_Aktif'
       AND i.id = (SELECT MAX(i2.id) FROM invoices i2 WHERE i2.spk_id = i.spk_id AND i2.status_piutang <> 'Tidak_Aktif')
       AND LOWER(s.discount_status) = 'approved'
       AND s.discount_amount_approved > 0
       AND ROUND(COALESCE(i.discount_amount, 0), 2) <> ROUND(
            LEAST(GREATEST(s.discount_amount_approved, 0), i.biaya_jasa + i.biaya_sparepart), 2)
       $onlyCond
     ORDER BY i.id"
);

if (count($candidates) === 0) {
    echo "OK: semua invoice aktif sudah sesuai dengan diskon SPK-nya. Tidak ada yang perlu diubah.\n";
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------
// Perencanaan (hanya membaca): tentukan kategori dan perubahan tiap invoice
// ---------------------------------------------------------------------------------------------------------------
function plan_candidate(mysqli $conn, array $ctx, array $c): array
{
    $invoiceId = (int)$c['invoice_id'];
    $spkId = (int)$c['spk_id'];

    $subtotal = (float)$c['biaya_jasa'] + (float)$c['biaya_sparepart'];
    $discount = min(max(0.0, (float)$c['spk_discount']), $subtotal);
    $oldTotal = (float)$c['inv_total'];
    $newTotal = $subtotal - $discount;

    $plan = [
        'category'   => 'MANUAL',
        'reason'     => '',
        'subtotal'   => $subtotal,
        'discount'   => $discount,
        'old_total'  => $oldTotal,
        'new_total'  => $newTotal,
        'paid'       => 0.0,
        'sisa_baru'  => $newTotal,
        'fix'        => null,
        'flag'       => '',
    ];

    // Biaya di invoice harus sama dengan item/jasa SPK saat ini. Kalau beda, ada perubahan lain selain diskon
    // dan skrip ini tidak boleh ikut menimpanya.
    $items = db_one($conn, "SELECT COALESCE(SUM(subtotal), 0) AS v FROM spk_items WHERE spk_id = $spkId");
    $svc = db_one($conn, "SELECT COALESCE(SUM(subtotal), 0) AS v FROM spk_services WHERE spk_id = $spkId");
    if (cents($items['v'] ?? 0) !== cents($c['biaya_sparepart']) || cents($svc['v'] ?? 0) !== cents($c['biaya_jasa'])) {
        $plan['reason'] = 'Biaya di invoice (jasa ' . rp($c['biaya_jasa']) . ', sparepart ' . rp($c['biaya_sparepart'])
            . ') tidak sama dengan item SPK saat ini (jasa ' . rp($svc['v'] ?? 0) . ', sparepart ' . rp($items['v'] ?? 0) . '). Cek dulu.';
        return $plan;
    }

    if (cents($newTotal) <= 0) {
        $plan['reason'] = 'Diskon sama dengan atau melebihi total tagihan (total setelah diskon ' . rp($newTotal) . ').';
        return $plan;
    }

    // Pembayaran invoice
    $cols = 'p.id, p.amount, p.tanggal, p.method';
    $cols .= $ctx['has_fin'] ? ', p.finance_tx_id, p.finance_account_id' : '';
    $cols .= $ctx['has_dp'] ? ', p.spk_dp_id' : '';
    $cols .= $ctx['has_appr'] ? ', p.approval_status' : '';
    $rows = db_all($conn, "SELECT $cols FROM payments p WHERE p.invoice_id = $invoiceId ORDER BY p.id");

    $approved = [];
    $pending = 0;
    foreach ($rows as $r) {
        $st = $ctx['has_appr'] ? ($r['approval_status'] ?? 'approved') : 'approved';
        if ($st === null || $st === 'approved') {
            $approved[] = $r;
        } else {
            $pending++;
        }
    }
    $paid = 0.0;
    foreach ($approved as $r) {
        $paid += (float)$r['amount'];
    }
    $plan['paid'] = $paid;
    $plan['sisa_baru'] = $newTotal - $paid;

    if ($pending > 0) {
        $plan['reason'] = "Ada $pending pembayaran yang masih menunggu/ditolak approval. Selesaikan dulu di halaman Invoice.";
        return $plan;
    }

    // ----- A. Sudah Lunas -----
    if ($c['status_piutang'] === 'Lunas') {
        if (cents($paid) !== cents($oldTotal)) {
            $plan['reason'] = 'Status Lunas, tetapi total pembayaran (' . rp($paid) . ') tidak sama dengan total invoice lama (' . rp($oldTotal) . ').';
            return $plan;
        }
        $overpay = $paid - $newTotal;
        if (cents($overpay) < 0) {
            $plan['reason'] = 'Total baru (' . rp($newTotal) . ') lebih besar dari total lama (' . rp($oldTotal) . '), tidak wajar untuk diskon.';
            return $plan;
        }
        $plan['category'] = 'LUNAS';
        if (cents($overpay) === 0) {
            return $plan; // hanya invoice yang perlu dibetulkan
        }

        // Pembayaran non-DP terakhir. DP adalah uang asli dan terkunci dari edit di halaman Invoice.
        $last = null;
        for ($k = count($approved) - 1; $k >= 0; $k--) {
            if (empty($approved[$k]['spk_dp_id'] ?? null)) {
                $last = $approved[$k];
                break;
            }
        }
        if ($last === null) {
            $plan['category'] = 'MANUAL';
            $plan['reason'] = 'Lunas hanya dari DP, tidak ada pembayaran biasa yang bisa dikurangi. Kelebihan bayar ' . rp($overpay) . ' perlu diputuskan manual.';
            return $plan;
        }
        $newAmount = (float)$last['amount'] - $overpay;
        if (cents($newAmount) <= 0) {
            $plan['category'] = 'MANUAL';
            $plan['reason'] = 'Pembayaran terakhir (' . rp($last['amount']) . ') tidak cukup untuk dikurangi ' . rp($overpay)
                . ' tanpa menjadi nol/negatif. Perlu dibagi ke pembayaran sebelumnya secara manual.';
            return $plan;
        }
        if (!$ctx['has_fin'] || (int)($last['finance_tx_id'] ?? 0) <= 0) {
            $plan['category'] = 'MANUAL';
            $plan['reason'] = 'Pembayaran #' . $last['id'] . ' tidak punya referensi transaksi ledger (finance_tx_id), tidak aman dikoreksi otomatis.';
            return $plan;
        }
        $tx = db_one(
            $conn,
            "SELECT id, account_id, direction, category, amount, status, affects_balance
             FROM finance_transactions WHERE id = " . (int)$last['finance_tx_id']
        );
        if (!$tx || $tx['direction'] !== 'in' || $tx['status'] !== 'approved' || (int)$tx['affects_balance'] !== 1
            || cents($tx['amount']) !== cents($last['amount'])) {
            $plan['category'] = 'MANUAL';
            $plan['reason'] = 'Transaksi ledger pembayaran #' . $last['id'] . ' tidak ditemukan atau tidak cocok (nominal/arah/status). Cek manual.';
            return $plan;
        }
        $plan['fix'] = [
            'payment_id' => (int)$last['id'],
            'tanggal'    => $last['tanggal'],
            'method'     => $last['method'],
            'old_amount' => (float)$last['amount'],
            'new_amount' => $newAmount,
            'old_tx_id'  => (int)$tx['id'],
            'account_id' => (int)$tx['account_id'],
            'category'   => (string)$tx['category'],
        ];
        return $plan;
    }

    // ----- B & C. Belum Lunas -----
    if (cents($paid) === 0) {
        $plan['category'] = 'BELUM_TANPA_CICILAN';
        return $plan;
    }

    $plan['category'] = 'BELUM_CICILAN';
    if (cents($plan['sisa_baru']) < 0) {
        $plan['flag'] = 'KELEBIHAN BAYAR ' . rp(-$plan['sisa_baru']) . ' (cicilan lebih besar dari total setelah diskon). Cicilan tidak diubah, cek manual.';
    }
    return $plan;
}

$items = [];
foreach ($candidates as $c) {
    $items[] = ['c' => $c, 'plan' => plan_candidate($conn, $ctx, $c), 'after' => null, 'error' => ''];
}

// ---------------------------------------------------------------------------------------------------------------
// Backup sebelum menulis (hanya --apply)
// ---------------------------------------------------------------------------------------------------------------
$backupPath = null;
if ($apply) {
    $invoiceIds = [];
    $txIds = [];
    foreach ($items as $it) {
        if ($it['plan']['category'] === 'MANUAL') {
            continue;
        }
        $invoiceIds[] = (int)$it['c']['invoice_id'];
        if ($it['plan']['fix']) {
            $txIds[] = (int)$it['plan']['fix']['old_tx_id'];
        }
    }
    if ($invoiceIds) {
        $backup = [
            'dibuat'        => date('c'),
            'catatan'       => 'Kondisi SEBELUM skrip sync_invoice_discount.php --apply dijalankan.',
            'invoices'      => db_all($conn, 'SELECT * FROM invoices WHERE id IN (' . implode(',', $invoiceIds) . ')'),
            'payments'      => db_all($conn, 'SELECT * FROM payments WHERE invoice_id IN (' . implode(',', $invoiceIds) . ')'),
            'finance_transactions' => $txIds ? db_all($conn, 'SELECT * FROM finance_transactions WHERE id IN (' . implode(',', $txIds) . ')') : [],
            'finance_accounts'     => db_all($conn, 'SELECT * FROM finance_accounts ORDER BY id'),
        ];
        $dir = __DIR__ . '/backup';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fwrite(STDERR, "Gagal membuat folder backup $dir. Dibatalkan, tidak ada yang diubah.\n");
            exit(3);
        }
        $backupPath = $dir . '/sync_invoice_discount_' . date('Ymd_His') . '.json';
        if (@file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            fwrite(STDERR, "Gagal menulis backup ke $backupPath. Dibatalkan, tidak ada yang diubah.\n");
            exit(3);
        }
        $backupPath = realpath($backupPath) ?: $backupPath;
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Eksekusi: satu transaksi, SAVEPOINT per invoice. Dry-run = ROLLBACK di akhir.
// ---------------------------------------------------------------------------------------------------------------
function snapshot_invoice(mysqli $conn, int $invoiceId, bool $hasAppr): array
{
    $appr = $hasAppr ? " AND (p.approval_status = 'approved' OR p.approval_status IS NULL)" : '';
    return db_one(
        $conn,
        "SELECT i.discount_amount, i.total, i.status_piutang,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id$appr), 0) AS total_paid
         FROM invoices i WHERE i.id = $invoiceId"
    ) ?: [];
}

function apply_plan(mysqli $conn, array $ctx, int $userId, array $c, array $plan): void
{
    $invoiceId = (int)$c['invoice_id'];
    $spkId = (int)$c['spk_id'];

    // 1. Invoice mengikuti diskon SPK (helper yang sama dengan alur normal aplikasi).
    spk_sync_active_invoice_totals($conn, $spkId);

    $inv = snapshot_invoice($conn, $invoiceId, $ctx['has_appr']);
    if (cents($inv['discount_amount'] ?? -1) !== cents($plan['discount']) || cents($inv['total'] ?? -1) !== cents($plan['new_total'])) {
        throw new Exception('Invoice tidak berubah sesuai rencana (diskon/total). Dibatalkan.');
    }

    // 2. Pembayaran terakhir disesuaikan dengan uang yang benar-benar masuk (sama dengan alur edit_payment).
    if ($plan['fix']) {
        $f = $plan['fix'];

        $cur = db_one($conn, "SELECT amount, finance_tx_id FROM payments WHERE id = {$f['payment_id']} AND invoice_id = $invoiceId FOR UPDATE");
        if (!$cur || cents($cur['amount']) !== cents($f['old_amount']) || (int)$cur['finance_tx_id'] !== $f['old_tx_id']) {
            throw new Exception('Pembayaran #' . $f['payment_id'] . ' berubah sejak pengecekan. Dibatalkan.');
        }

        $reverse = finance_reverse_transaction($conn, $f['old_tx_id']);
        if (!$reverse['success']) {
            throw new Exception($reverse['message']);
        }

        $note = 'Koreksi diskon SPK ' . $c['spk_code'] . ': pembayaran ' . rp($f['old_amount']) . ' menjadi ' . rp($f['new_amount']);
        $tx = finance_add_transaction(
            $conn,
            (string)$f['tanggal'],
            $f['account_id'],
            'in',
            $f['category'],
            $f['new_amount'],
            'invoice',
            $invoiceId,
            $note,
            $userId > 0 ? $userId : null
        );
        if (!$tx['success']) {
            throw new Exception($tx['message']);
        }
        $newTxId = (int)$tx['transaction_id'];

        $upd = mysqli_query(
            $conn,
            "UPDATE payments
             SET amount = {$f['new_amount']}, finance_tx_id = $newTxId, finance_amount = {$f['new_amount']}, updated_at = NOW()
             WHERE id = {$f['payment_id']}"
        );
        if (!$upd) {
            throw new Exception('Gagal mengupdate pembayaran: ' . mysqli_error($conn));
        }

        audit_log(
            $conn,
            $userId,
            'payments',
            $f['payment_id'],
            "Pembayaran #{$f['payment_id']} invoice {$c['no_invoice']} (SPK {$c['spk_code']}) dari " . rp($f['old_amount'])
            . ' menjadi ' . rp($f['new_amount']) . "; ledger #{$f['old_tx_id']} diganti #$newTxId."
        );

        $recalc = recalc_invoice_status($conn, $invoiceId, $ctx['has_appr']);
        if (!$recalc['success']) {
            throw new Exception($recalc['message']);
        }
        $inv = snapshot_invoice($conn, $invoiceId, $ctx['has_appr']);
        if ($inv['status_piutang'] !== 'Lunas' || cents($inv['total_paid']) !== cents($plan['new_total'])) {
            throw new Exception('Setelah koreksi, invoice tidak Lunas / total bayar tidak sama dengan total baru. Dibatalkan.');
        }
    }

    audit_log(
        $conn,
        $userId,
        'invoices',
        $invoiceId,
        "Invoice {$c['no_invoice']} (SPK {$c['spk_code']}): diskon " . rp($c['inv_discount']) . ' -> ' . rp($plan['discount'])
        . ', total ' . rp($c['inv_total']) . ' -> ' . rp($plan['new_total']) . ', status ' . $c['status_piutang'] . ' -> ' . $inv['status_piutang'] . '.'
    );
}

$balancesBefore = account_balances($conn);

mysqli_begin_transaction($conn);
foreach ($items as $idx => $it) {
    if ($it['plan']['category'] === 'MANUAL') {
        continue;
    }
    $invoiceId = (int)$it['c']['invoice_id'];
    $sp = 'sp_inv_' . $invoiceId;
    mysqli_query($conn, "SAVEPOINT $sp");
    try {
        apply_plan($conn, $ctx, $userId, $it['c'], $it['plan']);
        $items[$idx]['after'] = snapshot_invoice($conn, $invoiceId, $ctx['has_appr']);
        mysqli_query($conn, "RELEASE SAVEPOINT $sp");
    } catch (Exception $e) {
        mysqli_query($conn, "ROLLBACK TO SAVEPOINT $sp");
        $items[$idx]['plan']['category'] = 'MANUAL';
        $items[$idx]['plan']['fix'] = null;
        $items[$idx]['plan']['reason'] = 'Gagal diterapkan dan dibatalkan: ' . $e->getMessage();
        $items[$idx]['error'] = $e->getMessage();
    }
}
$balancesAfter = account_balances($conn);

if ($apply) {
    mysqli_commit($conn);
} else {
    mysqli_rollback($conn);
}

// ---------------------------------------------------------------------------------------------------------------
// Laporan
// ---------------------------------------------------------------------------------------------------------------
$groups = [
    'LUNAS'               => 'A. SUDAH LUNAS: invoice dibetulkan + pembayaran terakhir disesuaikan dengan nominal setelah diskon',
    'BELUM_TANPA_CICILAN' => 'B. BELUM LUNAS, belum ada cicilan: invoice dibetulkan saja',
    'BELUM_CICILAN'       => 'C. BELUM LUNAS, sudah ada cicilan: invoice dibetulkan, sisa hutang dihitung ulang (cicilan tidak diubah)',
    'MANUAL'              => 'D. PERLU DICEK MANUAL: TIDAK diubah',
];

echo ($apply ? 'MODE APPLY' : 'MODE DRY-RUN (tidak ada yang ditulis)') . ': ' . count($items) . " invoice ditemukan dengan diskon SPK yang belum tercatat di invoice\n";

$counts = array_fill_keys(array_keys($groups), 0);
$flagged = 0;

foreach ($groups as $key => $title) {
    $list = array_filter($items, fn($it) => $it['plan']['category'] === $key);
    $counts[$key] = count($list);
    echo "\n=== $title (" . count($list) . ") ===\n";
    if (!$list) {
        echo "  (tidak ada)\n";
        continue;
    }
    foreach ($list as $it) {
        $c = $it['c'];
        $p = $it['plan'];
        $who = trim(($c['customer_name'] ?? '') . ' ' . ($c['nomor_polisi'] ?? ''));
        echo "\n- {$c['spk_code']}  (invoice {$c['no_invoice']}, id {$c['invoice_id']})  [{$c['status_piutang']}]  SPK: {$c['status_spk']}"
            . ($who !== '' ? "  | $who" : '') . "\n";

        if ($key === 'MANUAL') {
            echo "    Alasan  : {$p['reason']}\n";
            echo '    Data    : total invoice ' . rp($c['inv_total']) . ', diskon SPK ' . rp($c['spk_discount']) . ', terbayar ' . rp($p['paid']) . "\n";
            continue;
        }

        $after = $it['after'] ?? [];
        echo '    Subtotal: ' . rp($p['subtotal']) . "\n";
        echo '    Diskon  : ' . rp($c['inv_discount']) . '  ->  ' . rp($after['discount_amount'] ?? $p['discount']) . "\n";
        echo '    Total   : ' . rp($c['inv_total']) . '  ->  ' . rp($after['total'] ?? $p['new_total']) . "\n";
        if ($p['fix']) {
            $f = $p['fix'];
            echo "    Bayar #{$f['payment_id']} ({$f['method']}, {$f['tanggal']}): " . rp($f['old_amount']) . '  ->  ' . rp($f['new_amount'])
                . "   (ledger #{$f['old_tx_id']} dibalik dan diganti baru)\n";
        }
        $paidAfter = (float)($after['total_paid'] ?? $p['paid']);
        $sisa = $p['new_total'] - $paidAfter;
        echo '    Terbayar: ' . rp($paidAfter) . '   Sisa hutang: ' . rp(max(0, $sisa))
            . '   Status: ' . $c['status_piutang'] . '  ->  ' . ($after['status_piutang'] ?? '?') . "\n";
        if ($key === 'BELUM_CICILAN') {
            if (cents($sisa) > 0) {
                echo '    Hasil   : masih kurang ' . rp($sisa) . " dari total setelah diskon\n";
            } elseif (cents($sisa) === 0) {
                echo "    Hasil   : hutang sudah 0, invoice jadi Lunas\n";
            }
        }
        if ($p['flag'] !== '') {
            $flagged++;
            echo "    PERHATIAN: {$p['flag']}\n";
        }
    }
}

// Perubahan saldo akun
echo "\n=== SALDO AKUN (" . ($apply ? 'setelah apply' : 'simulasi, belum ditulis') . ") ===\n";
$anyBalanceChange = false;
foreach ($balancesBefore as $id => $b) {
    $after = $balancesAfter[$id]['balance'] ?? $b['balance'];
    $diff = $after - $b['balance'];
    if (cents($diff) !== 0) {
        $anyBalanceChange = true;
        echo '  ' . str_pad($b['name'], 18) . rp($b['balance']) . '  ->  ' . rp($after) . '   (selisih ' . rp($diff) . ")\n";
    }
}
if (!$anyBalanceChange) {
    echo "  Tidak ada perubahan saldo.\n";
} else {
    echo "  Bandingkan dengan hitungan fisik Cash / mutasi rekening: sesudah koreksi harusnya sama.\n";
}

echo "\n=== RINGKASAN ===\n";
$summaryLabels = [
    'LUNAS'               => 'A. Sudah lunas (invoice + pembayaran dikoreksi)',
    'BELUM_TANPA_CICILAN' => 'B. Belum lunas, belum ada cicilan (invoice dibetulkan)',
    'BELUM_CICILAN'       => 'C. Belum lunas, sudah ada cicilan (invoice dibetulkan)',
    'MANUAL'              => 'D. Perlu dicek manual (tidak diubah)',
];
foreach ($summaryLabels as $key => $label) {
    echo '  ' . str_pad($label, 58) . str_pad((string)$counts[$key], 4, ' ', STR_PAD_LEFT) . "\n";
}
if ($flagged > 0) {
    echo "  $flagged invoice punya kelebihan bayar (lihat PERHATIAN di atas).\n";
}
if ($counts['MANUAL'] > 0) {
    echo "  {$counts['MANUAL']} invoice TIDAK diubah dan perlu dicek manual (bagian D).\n";
}

if ($csvPath !== null) {
    $fh = @fopen($csvPath, 'w');
    if (!$fh) {
        fwrite(STDERR, "\nGagal menulis CSV ke $csvPath\n");
    } else {
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['kategori', 'invoice_id', 'no_invoice', 'spk', 'status_spk', 'customer', 'nopol', 'status_lama', 'status_baru',
            'subtotal', 'diskon_lama', 'diskon_baru', 'total_lama', 'total_baru', 'terbayar_sebelum', 'terbayar_sesudah',
            'sisa_hutang', 'pembayaran_dikoreksi', 'nominal_lama', 'nominal_baru', 'catatan'], ';');
        foreach ($items as $it) {
            $c = $it['c'];
            $p = $it['plan'];
            $a = $it['after'] ?? [];
            $paidAfter = isset($a['total_paid']) ? (float)$a['total_paid'] : (float)$p['paid'];
            fputcsv($fh, [
                $p['category'], $c['invoice_id'], $c['no_invoice'], $c['spk_code'], $c['status_spk'],
                $c['customer_name'] ?? '', $c['nomor_polisi'] ?? '', $c['status_piutang'], $a['status_piutang'] ?? '',
                $p['subtotal'], $c['inv_discount'], $p['discount'], $c['inv_total'], $p['new_total'], $p['paid'], $paidAfter,
                max(0, $p['new_total'] - $paidAfter),
                $p['fix']['payment_id'] ?? '', $p['fix']['old_amount'] ?? '', $p['fix']['new_amount'] ?? '',
                $p['category'] === 'MANUAL' ? $p['reason'] : $p['flag'],
            ], ';');
        }
        fclose($fh);
        echo "\nLaporan CSV: $csvPath\n";
    }
}

if ($apply) {
    echo "\nSELESAI: perubahan disimpan.\n";
    if ($backupPath) {
        echo "Backup kondisi sebelum perubahan: $backupPath\n";
    }
} else {
    echo "\nDry-run selesai, tidak ada yang diubah. Cek daftarnya, lalu coba satu invoice dulu: --apply --invoice=ID\n";
}
