<?php
require_once __DIR__ . '/../config.php';

$sqlSpk = "SELECT COUNT(1) AS c
           FROM spk
           WHERE revision_number > 0
              OR revision_of_spk_id IS NOT NULL
              OR kode_unik_reference REGEXP '-REV[0-9]+$'";
$resSpk = mysqli_query($conn, $sqlSpk);
$rowSpk = mysqli_fetch_assoc($resSpk);

echo 'Sisa SPK revisi: ' . (int)($rowSpk['c'] ?? 0) . PHP_EOL;

$sqlInv = "SELECT COUNT(1) AS c
           FROM invoices i
           JOIN spk s ON i.spk_id = s.id
           WHERE s.revision_number > 0
              OR s.revision_of_spk_id IS NOT NULL
              OR s.kode_unik_reference REGEXP '-REV[0-9]+$'";
$resInv = mysqli_query($conn, $sqlInv);
$rowInv = mysqli_fetch_assoc($resInv);

echo 'Sisa invoice revisi: ' . (int)($rowInv['c'] ?? 0) . PHP_EOL;
