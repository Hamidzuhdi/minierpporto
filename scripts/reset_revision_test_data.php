<?php
require_once __DIR__ . '/../config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

try {
    mysqli_begin_transaction($conn);

    $stockColRes = mysqli_query($conn, "SHOW COLUMNS FROM spareparts LIKE 'current_stock'");
    $stockCol = (mysqli_num_rows($stockColRes) > 0) ? 'current_stock' : 'stok';
    $paymentApprovalColRes = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'approval_status'");
    $hasPaymentApprovalCols = $paymentApprovalColRes && mysqli_num_rows($paymentApprovalColRes) > 0;

    $revisionRows = [];
    $revisionSql = "SELECT id, kode_unik_reference, revision_of_spk_id, revision_number, status_spk
                    FROM spk
                    WHERE revision_number > 0
                       OR revision_of_spk_id IS NOT NULL
                       OR kode_unik_reference REGEXP '-REV[0-9]+$'";
    $revisionRes = mysqli_query($conn, $revisionSql);
    while ($row = mysqli_fetch_assoc($revisionRes)) {
        $revisionRows[] = $row;
    }

    if (count($revisionRows) === 0) {
        mysqli_commit($conn);
        echo "Tidak ada data revisi yang perlu dibersihkan." . PHP_EOL;
        exit(0);
    }

    $revisionIds = [];
    $rootIds = [];

    foreach ($revisionRows as $row) {
        $id = (int)$row['id'];
        $revNum = (int)($row['revision_number'] ?? 0);
        $rootId = (int)($row['revision_of_spk_id'] ?? 0);
        $isRevision = ($revNum > 0) || ($rootId > 0) || preg_match('/-REV\d+$/', (string)$row['kode_unik_reference']);

        if ($isRevision) {
            $revisionIds[] = $id;
            if ($rootId > 0) {
                $rootIds[] = $rootId;
            }
        }
    }

    $revisionIds = array_values(array_unique($revisionIds));
    $rootIds = array_values(array_unique($rootIds));

    if (count($revisionIds) === 0) {
        mysqli_commit($conn);
        echo "Tidak ada row revisi valid untuk dihapus." . PHP_EOL;
        exit(0);
    }

    $idsSql = implode(',', array_map('intval', $revisionIds));

    // Hapus data turunan dulu agar aman dari FK.
    mysqli_query($conn, "DELETE FROM invoices WHERE spk_id IN ($idsSql)");
    mysqli_query($conn, "DELETE FROM warehouse_out WHERE spk_id IN ($idsSql)");
    mysqli_query($conn, "DELETE FROM spk_discount_histories WHERE spk_id IN ($idsSql)");
    mysqli_query($conn, "DELETE FROM spk_services WHERE spk_id IN ($idsSql)");
    mysqli_query($conn, "DELETE FROM spk_items WHERE spk_id IN ($idsSql)");

    // Hapus SPK revisi.
    mysqli_query($conn, "DELETE FROM spk WHERE id IN ($idsSql)");

    // Pulihkan SPK root agar bisa dites ulang.
    foreach ($rootIds as $rootId) {
        $rootId = (int)$rootId;
        if ($rootId <= 0) {
            continue;
        }

        $rootRes = mysqli_query($conn, "SELECT id, status_spk, saran_service FROM spk WHERE id = $rootId LIMIT 1");
        $root = mysqli_fetch_assoc($rootRes);
        if (!$root) {
            continue;
        }

        $wasCancelled = ((string)$root['status_spk'] === 'Dibatalkan');

        // Bersihkan tag catatan revisi otomatis.
        $saran = (string)($root['saran_service'] ?? '');
        $saran = preg_replace('/\n?\[REVISI:.*$/s', '', $saran);
        $saranEsc = mysqli_real_escape_string($conn, $saran);

        // Kembalikan status ke Sudah Cetak Invoice untuk test ulang revisi.
        mysqli_query(
            $conn,
            "UPDATE spk
             SET status_spk = 'Sudah Cetak Invoice',
                 saran_service = '$saranEsc',
                 revision_of_spk_id = NULL,
                 revision_number = 0
             WHERE id = $rootId"
        );

        // Saat create_revision lama, stok root sudah dikembalikan saat dibatalkan.
        // Jika root dipulihkan jadi aktif lagi, konsumsi stoknya perlu diterapkan lagi.
        if ($wasCancelled) {
            $itemRes = mysqli_query(
                $conn,
                "SELECT sparepart_id, SUM(qty) as qty_total
                 FROM spk_items
                 WHERE spk_id = $rootId
                 GROUP BY sparepart_id"
            );

            while ($item = mysqli_fetch_assoc($itemRes)) {
                $sparepartId = (int)$item['sparepart_id'];
                $qty = (int)$item['qty_total'];
                if ($sparepartId > 0 && $qty > 0) {
                    mysqli_query($conn, "UPDATE spareparts SET $stockCol = $stockCol - $qty WHERE id = $sparepartId");
                }
            }
        }

        // Pulihkan status invoice root (jika sempat jadi Tidak_Aktif saat revisi).
        $invRes = mysqli_query($conn, "SELECT id, total FROM invoices WHERE spk_id = $rootId ORDER BY id ASC");
        while ($inv = mysqli_fetch_assoc($invRes)) {
            $invId = (int)$inv['id'];
            $invTotal = (float)$inv['total'];

            $approvedCond = $hasPaymentApprovalCols ? " AND (approval_status = 'approved' OR approval_status IS NULL)" : '';
            $paidRes = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) AS total_paid, MAX(tanggal) AS last_paid FROM payments WHERE invoice_id = $invId $approvedCond");
            $paidRow = mysqli_fetch_assoc($paidRes);
            $totalPaid = (float)($paidRow['total_paid'] ?? 0);
            $lastPaid = $paidRow['last_paid'] ?? null;

            $status = 'Belum Bayar';
            $paidAtSql = 'NULL';
            if ($invTotal > 0 && $totalPaid >= $invTotal) {
                $status = 'Lunas';
                if (!empty($lastPaid)) {
                    $paidAtSql = "'" . mysqli_real_escape_string($conn, $lastPaid) . "'";
                }
            } elseif ($totalPaid > 0) {
                $status = 'Sudah Dicicil';
            }

            mysqli_query($conn, "UPDATE invoices SET status_piutang = '$status', paid_at = $paidAtSql WHERE id = $invId");
        }
    }

    mysqli_commit($conn);

    echo "Cleanup revisi selesai." . PHP_EOL;
    echo "SPK revisi dihapus: " . count($revisionIds) . PHP_EOL;
    echo "SPK root dipulihkan: " . count($rootIds) . PHP_EOL;

} catch (Throwable $e) {
    mysqli_rollback($conn);
    fwrite(STDERR, "Gagal cleanup revisi: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
