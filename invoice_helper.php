<?php

// Menyelaraskan invoice aktif milik sebuah SPK dengan data SPK saat ini: biaya jasa, biaya sparepart,
// diskon, total, dan status piutang (dihitung ulang dari pembayaran yang sudah approved).
//
// Diskon SELALU mengikuti SPK dan hanya berlaku jika sudah di-ACC Owner (discount_status = 'approved'),
// sama seperti saat invoice dibuat dan seperti di PDF invoice. Dengan begitu diskon yang di-ACC SETELAH
// invoice dibuat (mis. SPK revisi, yang invoicenya langsung dibuat dengan diskon 0) ikut masuk ke invoice.
function spk_sync_active_invoice_totals(mysqli $conn, int $spkId): void
{
    if ($spkId <= 0) {
        return;
    }

    $invRes = mysqli_query(
        $conn,
        "SELECT id FROM invoices
         WHERE spk_id = $spkId
           AND status_piutang <> 'Tidak_Aktif'
         ORDER BY id DESC
         LIMIT 1"
    );
    $inv = $invRes ? mysqli_fetch_assoc($invRes) : null;
    if (!$inv) {
        return;
    }

    $invoiceId = (int)$inv['id'];

    // Use GENERATED subtotal column which respects custom_price
    $qItems = "SELECT COALESCE(SUM(si.subtotal), 0) AS biaya_sparepart
               FROM spk_items si
               WHERE si.spk_id = $spkId";

    $itemsRes = mysqli_query($conn, $qItems);
    $itemsRow = $itemsRes ? mysqli_fetch_assoc($itemsRes) : [];
    $biayaSparepart = (float)($itemsRow['biaya_sparepart'] ?? 0);

    $svcRes = mysqli_query($conn, "SELECT COALESCE(SUM(subtotal), 0) AS biaya_jasa FROM spk_services WHERE spk_id = $spkId");
    $svcRow = $svcRes ? mysqli_fetch_assoc($svcRes) : [];
    $biayaJasa = (float)($svcRow['biaya_jasa'] ?? 0);

    $subtotal = $biayaSparepart + $biayaJasa;

    $spkDiscRes = mysqli_query($conn, "SELECT discount_status, discount_amount_approved FROM spk WHERE id = $spkId LIMIT 1");
    $spkDisc = $spkDiscRes ? mysqli_fetch_assoc($spkDiscRes) : [];
    $discountAmount = (strtolower((string)($spkDisc['discount_status'] ?? '')) === 'approved')
        ? max(0.0, (float)($spkDisc['discount_amount_approved'] ?? 0))
        : 0.0;
    $discountAmount = min($discountAmount, $subtotal);

    $total = max(0.0, $subtotal - $discountAmount);

    $approvalColRes = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'approval_status'");
    $hasApprovalCol = $approvalColRes && mysqli_num_rows($approvalColRes) > 0;
    $approvedCond = $hasApprovalCol ? " AND (approval_status = 'approved' OR approval_status IS NULL)" : '';
    $paidRes = mysqli_query(
        $conn,
        "SELECT COALESCE(SUM(amount), 0) AS total_paid, MAX(tanggal) AS last_paid
         FROM payments
         WHERE invoice_id = $invoiceId$approvedCond"
    );
    $paidRow = $paidRes ? mysqli_fetch_assoc($paidRes) : [];
    $totalPaid = (float)($paidRow['total_paid'] ?? 0);
    $lastPaid = $paidRow['last_paid'] ?? null;

    $status = 'Belum Bayar';
    $paidAtSql = 'NULL';
    if ($total > 0 && $totalPaid >= $total) {
        $status = 'Lunas';
        if (!empty($lastPaid)) {
            $paidAtSql = "'" . mysqli_real_escape_string($conn, $lastPaid) . "'";
        }
    } elseif ($totalPaid > 0) {
        $status = 'Sudah Dicicil';
    }

    // Kolom discount_amount hanya ada setelah migrasi diskon; lewati jika belum ada.
    $discColRes = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'discount_amount'");
    $hasDiscountCol = $discColRes && mysqli_num_rows($discColRes) > 0;
    $discountSet = $hasDiscountCol ? "discount_amount = $discountAmount," : '';

    mysqli_query(
        $conn,
        "UPDATE invoices
         SET biaya_jasa = $biayaJasa,
             biaya_sparepart = $biayaSparepart,
             $discountSet
             total = $total,
             status_piutang = '$status',
             paid_at = $paidAtSql
         WHERE id = $invoiceId"
    );
}
