<?php
require __DIR__ . '/../config.php';

global $conn;

$qIn = "SELECT COALESCE(SUM(amount),0) total FROM finance_transactions WHERE direction='in'";
$qOut = "SELECT COALESCE(SUM(amount),0) total FROM finance_transactions WHERE direction='out'";
$qSp = "SELECT COALESCE(SUM(si.qty * COALESCE(NULLIF(si.harga_satuan,0), sp.harga_jual_default)),0) total
        FROM spk_items si
        JOIN spareparts sp ON sp.id=si.sparepart_id
        JOIN invoices i ON i.spk_id=si.spk_id
        WHERE i.status_piutang='Lunas'";
$qHpp = "SELECT COALESCE(SUM(si.qty * si.hpp_satuan),0) total
         FROM spk_items si
         JOIN invoices i ON i.spk_id=si.spk_id
         WHERE i.status_piutang='Lunas'";
$qLunasInv = "SELECT COALESCE(SUM(i.biaya_jasa),0) jasa_lunas,
                     COALESCE(SUM(i.biaya_sparepart),0) spare_lunas,
                     COALESCE(SUM(i.total),0) total_lunas
              FROM invoices i
              WHERE i.status_piutang='Lunas'";

$totalIn = (float)mysqli_fetch_assoc(mysqli_query($conn, $qIn))['total'];
$totalOut = (float)mysqli_fetch_assoc(mysqli_query($conn, $qOut))['total'];
$spareRevenue = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSp))['total'];
$spareHpp = (float)mysqli_fetch_assoc(mysqli_query($conn, $qHpp))['total'];
$lunas = mysqli_fetch_assoc(mysqli_query($conn, $qLunasInv));

printf("total_in=%.2f\n", $totalIn);
printf("total_out=%.2f\n", $totalOut);
printf("net=%.2f\n", $totalIn - $totalOut);
printf("spare_revenue=%.2f\n", $spareRevenue);
printf("spare_hpp=%.2f\n", $spareHpp);
printf("spare_profit=%.2f\n", $spareRevenue - $spareHpp);
printf("jasa_lunas=%.2f\n", (float)$lunas['jasa_lunas']);
printf("spare_lunas_invoice=%.2f\n", (float)$lunas['spare_lunas']);
printf("total_invoice_lunas=%.2f\n", (float)$lunas['total_lunas']);
