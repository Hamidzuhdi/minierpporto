<?php

// DP (uang muka) SPK: dicatat dari awal SPK (total belum perlu ada) dan langsung masuk ledger keuangan
// bertanggal sesuai tanggal DP. Saat invoice dibuat, DP otomatis dijadikan pembayaran invoice
// (baris payments yang menunjuk ke transaksi ledger yang sama), sehingga sisa piutang dan status
// invoice sinkron tanpa mencatat uang masuk dua kali.

require_once __DIR__ . '/finance_helper.php';

const DP_LEDGER_CATEGORY = 'IN-CUST-DP';

function dp_ensure_schema(mysqli $conn): void
{
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS spk_down_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            spk_id INT NOT NULL,
            tanggal DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            method ENUM('cash','transfer','qris') NOT NULL DEFAULT 'transfer',
            finance_account_id INT NULL,
            finance_tx_id INT NULL,
            note TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            refunded_at DATETIME NULL,
            INDEX idx_spk_dp_spk (spk_id),
            CONSTRAINT fk_spk_dp_spk FOREIGN KEY (spk_id) REFERENCES spk(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Penanda baris pembayaran invoice yang berasal dari DP (dikunci dari edit/hapus di halaman invoice).
    $col = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'spk_dp_id'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, "ALTER TABLE payments ADD COLUMN spk_dp_id INT NULL, ADD INDEX idx_payments_spk_dp (spk_dp_id)");
    }
}

function dp_is_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function dp_audit(mysqli $conn, int $userId, string $action, int $targetId, string $description): void
{
    $userSql = $userId > 0 ? (string)$userId : 'NULL';
    $descEsc = mysqli_real_escape_string($conn, $description);
    mysqli_query(
        $conn,
        "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
         VALUES ($userSql, '$action', 'spk_down_payments', $targetId, '$descEsc', NOW())"
    );
}

// Invoice aktif (bukan Tidak_Aktif) milik SPK, 0 jika belum ada.
function dp_active_invoice_id(mysqli $conn, int $spkId): int
{
    $res = mysqli_query(
        $conn,
        "SELECT id FROM invoices WHERE spk_id = $spkId AND status_piutang <> 'Tidak_Aktif' ORDER BY id DESC LIMIT 1"
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return (int)($row['id'] ?? 0);
}

// Total DP yang masih berlaku (belum dikembalikan).
function dp_total(mysqli $conn, int $spkId): float
{
    $res = mysqli_query(
        $conn,
        "SELECT COALESCE(SUM(amount), 0) AS total FROM spk_down_payments WHERE spk_id = $spkId AND refunded_at IS NULL"
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return (float)($row['total'] ?? 0);
}

function dp_list(mysqli $conn, int $spkId): array
{
    $res = mysqli_query(
        $conn,
        "SELECT d.id, d.tanggal, d.amount, d.method, d.note, d.created_at, d.refunded_at, u.username AS created_by_name
         FROM spk_down_payments d
         LEFT JOIN users u ON u.id = d.created_by
         WHERE d.spk_id = $spkId
         ORDER BY d.tanggal ASC, d.id ASC"
    );

    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    return $rows;
}

// Catat DP: transaksi ledger masuk (bertanggal sesuai tanggal DP) + baris DP.
// Jika SPK sudah punya invoice aktif, DP langsung ikut dihitung sebagai pembayarannya.
function dp_add(mysqli $conn, int $spkId, string $tanggal, float $amount, string $method, string $note, int $userId): array
{
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Nominal DP harus lebih dari 0'];
    }
    if (!in_array($method, PAYMENT_METHODS, true)) {
        return ['success' => false, 'message' => 'Metode pembayaran DP tidak valid'];
    }
    if (!dp_is_valid_date($tanggal)) {
        return ['success' => false, 'message' => 'Tanggal DP tidak valid'];
    }

    $spkRes = mysqli_query($conn, "SELECT id, kode_unik_reference, status_spk FROM spk WHERE id = $spkId LIMIT 1");
    $spk = $spkRes ? mysqli_fetch_assoc($spkRes) : null;
    if (!$spk) {
        return ['success' => false, 'message' => 'SPK tidak ditemukan'];
    }
    if (($spk['status_spk'] ?? '') === 'Dibatalkan') {
        return ['success' => false, 'message' => 'SPK dibatalkan, DP tidak dapat dicatat'];
    }

    $account = finance_get_account_by_code($conn, finance_account_code_for_method($method));
    if (!$account) {
        return ['success' => false, 'message' => 'Akun keuangan untuk metode ini tidak ditemukan'];
    }

    mysqli_begin_transaction($conn);
    try {
        $txNote = 'DP SPK ' . $spk['kode_unik_reference'] . ($note !== '' ? ' - ' . $note : '');
        $tx = finance_add_transaction(
            $conn,
            $tanggal,
            (int)$account['id'],
            'in',
            DP_LEDGER_CATEGORY,
            $amount,
            'spk',
            $spkId,
            $txNote,
            $userId > 0 ? $userId : null
        );
        if (!$tx['success']) {
            throw new Exception($tx['message']);
        }

        $sql = "INSERT INTO spk_down_payments (spk_id, tanggal, amount, method, finance_account_id, finance_tx_id, note, created_by)
                VALUES ($spkId,
                        '" . mysqli_real_escape_string($conn, $tanggal) . "',
                        $amount,
                        '" . mysqli_real_escape_string($conn, $method) . "',
                        " . (int)$account['id'] . ",
                        " . (int)$tx['transaction_id'] . ",
                        '" . mysqli_real_escape_string($conn, $note) . "',
                        " . ($userId > 0 ? $userId : 'NULL') . ")";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal menyimpan DP: ' . mysqli_error($conn));
        }
        $dpId = (int)mysqli_insert_id($conn);

        $invoiceId = dp_active_invoice_id($conn, $spkId);
        if ($invoiceId > 0) {
            dp_attach_to_invoice($conn, $spkId, $invoiceId);
            $recalc = recalc_invoice_status($conn, $invoiceId, finance_payments_have_approval_cols($conn));
            if (!$recalc['success']) {
                throw new Exception($recalc['message']);
            }
        }

        dp_audit($conn, $userId, 'CREATE', $dpId, 'DP Rp ' . number_format($amount, 0, ',', '.') . ' (' . $method . ') untuk SPK ' . $spk['kode_unik_reference']);

        mysqli_commit($conn);
        return ['success' => true, 'dp_id' => $dpId];
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Jadikan DP yang belum tercatat di invoice ini sebagai baris pembayaran invoice.
// Tidak membuat transaksi ledger baru: baris pembayaran menunjuk ke transaksi DP yang sama.
// Pemanggil wajib menghitung ulang status invoice (recalc_invoice_status) sesudahnya.
function dp_attach_to_invoice(mysqli $conn, int $spkId, int $invoiceId): int
{
    $res = mysqli_query(
        $conn,
        "SELECT d.*
         FROM spk_down_payments d
         WHERE d.spk_id = $spkId
           AND d.refunded_at IS NULL
           AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.spk_dp_id = d.id AND p.invoice_id = $invoiceId)
         ORDER BY d.tanggal ASC, d.id ASC"
    );

    $count = 0;
    while ($res && ($dp = mysqli_fetch_assoc($res))) {
        $noteEsc = mysqli_real_escape_string($conn, 'DP' . (($dp['note'] ?? '') !== '' ? ': ' . $dp['note'] : ''));
        $accountSql = $dp['finance_account_id'] ? (int)$dp['finance_account_id'] : 'NULL';
        $txSql = $dp['finance_tx_id'] ? (int)$dp['finance_tx_id'] : 'NULL';
        $bySql = $dp['created_by'] ? (int)$dp['created_by'] : 'NULL';

        $sql = "INSERT INTO payments (invoice_id, amount, tanggal, method, finance_account_id, finance_tx_id, finance_amount, note,
                                      approval_status, requested_by, requested_at, approved_by, approved_at, created_at, spk_dp_id)
                VALUES ($invoiceId, {$dp['amount']}, '{$dp['tanggal']}', '{$dp['method']}', $accountSql, $txSql, {$dp['amount']}, '$noteEsc',
                        'approved', $bySql, NOW(), $bySql, NOW(), NOW(), {$dp['id']})";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal menyertakan DP ke invoice: ' . mysqli_error($conn));
        }
        $count++;
    }

    return $count;
}

// Hapus satu DP: balikkan transaksi ledger, lepas dari invoice (jika sudah ikut), hitung ulang status invoice.
function dp_delete(mysqli $conn, int $dpId, int $userId): array
{
    $res = mysqli_query($conn, "SELECT * FROM spk_down_payments WHERE id = $dpId LIMIT 1");
    $dp = $res ? mysqli_fetch_assoc($res) : null;
    if (!$dp) {
        return ['success' => false, 'message' => 'DP tidak ditemukan'];
    }
    if ($dp['refunded_at'] !== null) {
        return ['success' => false, 'message' => 'DP ini sudah dikembalikan'];
    }

    mysqli_begin_transaction($conn);
    try {
        if (!empty($dp['finance_tx_id'])) {
            $reverse = finance_reverse_transaction($conn, (int)$dp['finance_tx_id']);
            if (!$reverse['success']) {
                throw new Exception($reverse['message']);
            }
        }

        $invoiceIds = [];
        $invRes = mysqli_query($conn, "SELECT DISTINCT invoice_id FROM payments WHERE spk_dp_id = $dpId");
        while ($invRes && ($inv = mysqli_fetch_assoc($invRes))) {
            $invoiceIds[] = (int)$inv['invoice_id'];
        }

        if (!mysqli_query($conn, "DELETE FROM payments WHERE spk_dp_id = $dpId")) {
            throw new Exception('Gagal melepas DP dari invoice: ' . mysqli_error($conn));
        }
        if (!mysqli_query($conn, "DELETE FROM spk_down_payments WHERE id = $dpId")) {
            throw new Exception('Gagal menghapus DP: ' . mysqli_error($conn));
        }

        $hasApprovalCols = finance_payments_have_approval_cols($conn);
        foreach ($invoiceIds as $invoiceId) {
            $recalc = recalc_invoice_status($conn, $invoiceId, $hasApprovalCols);
            if (!$recalc['success']) {
                throw new Exception($recalc['message']);
            }
        }

        dp_audit($conn, $userId, 'DELETE', $dpId, 'DP Rp ' . number_format((float)$dp['amount'], 0, ',', '.') . ' dihapus dari SPK #' . (int)$dp['spk_id']);

        mysqli_commit($conn);
        return ['success' => true];
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Pembatalan SPK: seluruh DP dikembalikan, ledger dibalik sehingga pendapatan dan saldo ikut berkurang.
// Tidak mengelola transaksi database; pemanggil membungkusnya bersama perubahan status SPK.
function dp_refund_all(mysqli $conn, int $spkId, int $userId): array
{
    $res = mysqli_query($conn, "SELECT * FROM spk_down_payments WHERE spk_id = $spkId AND refunded_at IS NULL ORDER BY id ASC");
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    if (count($rows) === 0) {
        return ['success' => true, 'count' => 0, 'total' => 0.0];
    }

    if (dp_active_invoice_id($conn, $spkId) > 0) {
        return [
            'success' => false,
            'message' => 'SPK sudah punya invoice aktif dengan DP, tidak bisa dibatalkan. Hapus DP lebih dulu atau gunakan Revisi SPK.',
        ];
    }

    $total = 0.0;
    foreach ($rows as $dp) {
        if (!empty($dp['finance_tx_id'])) {
            $reverse = finance_reverse_transaction($conn, (int)$dp['finance_tx_id']);
            if (!$reverse['success']) {
                return ['success' => false, 'message' => 'DP gagal dikembalikan: ' . $reverse['message']];
            }
        }

        if (!mysqli_query($conn, "UPDATE spk_down_payments SET refunded_at = NOW(), finance_tx_id = NULL WHERE id = " . (int)$dp['id'])) {
            return ['success' => false, 'message' => 'Gagal menandai DP dikembalikan: ' . mysqli_error($conn)];
        }

        $total += (float)$dp['amount'];
        dp_audit($conn, $userId, 'REFUND', (int)$dp['id'], 'DP Rp ' . number_format((float)$dp['amount'], 0, ',', '.') . ' dikembalikan karena SPK #' . $spkId . ' dibatalkan');
    }

    return ['success' => true, 'count' => count($rows), 'total' => $total];
}

// Revisi SPK: DP ikut berpindah ke SPK revisi (beserta referensi transaksi ledger-nya).
function dp_move_to_spk(mysqli $conn, int $fromSpkId, int $toSpkId): void
{
    $txIds = [];
    $res = mysqli_query($conn, "SELECT finance_tx_id FROM spk_down_payments WHERE spk_id = $fromSpkId AND refunded_at IS NULL AND finance_tx_id IS NOT NULL");
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $txIds[] = (int)$row['finance_tx_id'];
    }

    mysqli_query($conn, "UPDATE spk_down_payments SET spk_id = $toSpkId WHERE spk_id = $fromSpkId AND refunded_at IS NULL");

    if (count($txIds) > 0) {
        mysqli_query(
            $conn,
            "UPDATE finance_transactions SET reference_id = $toSpkId
             WHERE reference_type = 'spk' AND category = '" . DP_LEDGER_CATEGORY . "' AND id IN (" . implode(',', $txIds) . ")"
        );
    }
}
