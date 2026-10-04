<?php
session_start();
require_once '../config.php';
require_once '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$spk_id = (int)($_GET['spk_id'] ?? 0);
if ($spk_id <= 0) {
    die('SPK tidak valid');
}

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS spk_estimate_pdf_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spk_id INT NOT NULL,
        batch_no INT NOT NULL,
        generated_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_spk_batch (spk_id, batch_no),
        INDEX idx_spk_batch_spk (spk_id)
    ) ENGINE=InnoDB
");

$sqlSpk = "SELECT s.*, c.name as customer_name, c.phone as customer_phone,
                  v.nomor_polisi, v.merk, v.model, v.tahun
           FROM spk s
           JOIN customers c ON c.id = s.customer_id
           JOIN vehicles v ON v.id = s.vehicle_id
           WHERE s.id = $spk_id
           LIMIT 1";
$resSpk = mysqli_query($conn, $sqlSpk);
$spk = $resSpk ? mysqli_fetch_assoc($resSpk) : null;

if (!$spk) {
    die('SPK tidak ditemukan');
}

if ($spk['status_spk'] !== 'Menunggu Konfirmasi') {
    die('PDF estimasi hanya tersedia untuk SPK status Menunggu Konfirmasi');
}

$colHarga = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'harga_satuan'");
$hasHargaCol = $colHarga && mysqli_num_rows($colHarga) > 0;

$sqlServices = "SELECT ss.qty, ss.harga, ss.subtotal, sp.nama_jasa
                FROM spk_services ss
                JOIN service_prices sp ON sp.id = ss.service_price_id
                WHERE ss.spk_id = $spk_id
                ORDER BY ss.id ASC";
$resServices = mysqli_query($conn, $sqlServices);
$services = [];
$total_jasa = 0;
while ($row = mysqli_fetch_assoc($resServices)) {
    $services[] = $row;
    $total_jasa += (float)($row['subtotal'] ?? 0);
}

// Get sparepart items - gunakan GENERATED subtotal column yang handle custom price
$sqlItems = "SELECT si.*, sp.nama as sparepart_name, sp.satuan, sp.harga_jual_default
             FROM spk_items si
             JOIN spareparts sp ON sp.id = si.sparepart_id
             WHERE si.spk_id = $spk_id
             ORDER BY si.id ASC";
$resItems = mysqli_query($conn, $sqlItems);
$items = [];
$total_sparepart = 0;
while ($row = mysqli_fetch_assoc($resItems)) {
    $items[] = $row;
    // Use GENERATED subtotal yang sudah handle custom price
    $total_sparepart += (float)($row['subtotal'] ?? 0);
}

$subtotal_estimate = $total_jasa + $total_sparepart;
$discount_status = strtolower((string)($spk['discount_status'] ?? 'none'));
$discount_requested = (float)($spk['discount_amount_requested'] ?? 0);
$discount_approved_raw = (float)($spk['discount_amount_approved'] ?? 0);
$discount_approved = $discount_approved_raw > 0 ? $discount_approved_raw : $discount_requested;
$discount_to_apply = 0;

if ($discount_status === 'approved' && $discount_approved > 0) {
    $discount_to_apply = $discount_approved;
}

if ($discount_to_apply > $subtotal_estimate) {
    $discount_to_apply = $subtotal_estimate;
}

$grand_total = $subtotal_estimate - $discount_to_apply;

$qBatch = mysqli_query($conn, "SELECT COALESCE(MAX(batch_no), 0) AS max_batch FROM spk_estimate_pdf_batches WHERE spk_id = $spk_id");
$batchNo = ((int)(mysqli_fetch_assoc($qBatch)['max_batch'] ?? 0)) + 1;
mysqli_query($conn, "INSERT INTO spk_estimate_pdf_batches (spk_id, batch_no, generated_by) VALUES ($spk_id, $batchNo, " . (int)$_SESSION['user_id'] . ")");

function rupiah($value) {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$spkDate = date('Ymd', strtotime($spk['tanggal']));
$cust = preg_replace('/[^a-zA-Z0-9]/', '_', $spk['customer_name']);
$filename = $cust . '_' . $spkDate . '_batch' . $batchNo . '.pdf';

$html = '
<style>
body { font-family: sans-serif; font-size: 10pt; color: #222; }
h2 { margin: 0 0 4px 0; }
.small { color: #666; font-size: 9pt; }
.section-title { margin-top: 14px; font-weight: bold; }
table { width: 100%; border-collapse: collapse; margin-top: 6px; }
th, td { border: 1px solid #ddd; padding: 6px; }
th { background: #f5f5f5; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.footer-total { margin-top: 10px; padding: 8px; border: 1px solid #ddd; background: #f8f9fa; }
</style>

<h2>Estimasi Biaya SPK</h2>
<div class="small">Customer: ' . htmlspecialchars($spk['customer_name']) . '</div>
<div class="small">Tanggal: ' . htmlspecialchars($spk['tanggal']) . ' | Batch: #' . $batchNo . '</div>
<div class="small">SPK: ' . htmlspecialchars($spk['kode_unik_reference']) . ' | Kendaraan: ' . htmlspecialchars($spk['nomor_polisi'] . ' - ' . ($spk['merk'] ?? '') . ' ' . ($spk['model'] ?? '')) . '</div>
<div class="small">Keluhan: ' . htmlspecialchars((string)$spk['keluhan_customer']) . '</div>
<div class="small">Status Diskon: ' . htmlspecialchars(strtoupper($discount_status)) . ' | Request: ' . rupiah($discount_requested) . ' | Approved: ' . rupiah($discount_approved) . '</div>

<div class="section-title">Detail Jasa</div>
<table>
    <thead>
        <tr>
            <th>Nama Jasa</th>
            <th style="width:10%" class="text-center">Qty</th>
            <th style="width:20%" class="text-right">Harga</th>
            <th style="width:20%" class="text-right">Subtotal</th>
        </tr>
    </thead>
    <tbody>';

if (count($services) === 0) {
    $html .= '<tr><td colspan="4" class="text-center">Tidak ada jasa</td></tr>';
} else {
    foreach ($services as $svc) {
        $html .= '<tr>
            <td>' . htmlspecialchars($svc['nama_jasa']) . '</td>
            <td class="text-center">' . (int)$svc['qty'] . '</td>
            <td class="text-right">' . rupiah($svc['harga']) . '</td>
            <td class="text-right">' . rupiah($svc['subtotal']) . '</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<div class="section-title">Detail Sparepart</div>
<table>
    <thead>
        <tr>
            <th>Nama Sparepart</th>
            <th style="width:10%" class="text-center">Qty</th>
            <th style="width:20%" class="text-right">Harga</th>
            <th style="width:20%" class="text-right">Subtotal</th>
        </tr>
    </thead>
    <tbody>';

if (count($items) === 0) {
    $html .= '<tr><td colspan="4" class="text-center">Tidak ada sparepart</td></tr>';
} else {
    foreach ($items as $it) {
        // Display correct price: custom if enabled, else default
        $itemCustomEnabled = (int)($it['use_custom_price'] ?? 0) === 1;
        $customPrice = (float)($it['harga_custom'] ?? 0);
        $displayPrice = ($itemCustomEnabled && $customPrice > 0) ? $customPrice : (float)$it['harga_jual_default'];
        
        $html .= '<tr>
            <td>' . htmlspecialchars($it['sparepart_name']) . '</td>
            <td class="text-center">' . (int)$it['qty'] . ' ' . htmlspecialchars($it['satuan']) . '</td>
            <td class="text-right">' . rupiah($displayPrice) . '</td>
            <td class="text-right">' . rupiah($it['subtotal']) . '</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<div class="footer-total">
    <div>Total Jasa: <strong>' . rupiah($total_jasa) . '</strong></div>
    <div>Total Sparepart: <strong>' . rupiah($total_sparepart) . '</strong></div>
    <div>Subtotal Estimasi: <strong>' . rupiah($subtotal_estimate) . '</strong></div>
    <div>Diskon Diterapkan: <strong>' . rupiah($discount_to_apply) . '</strong></div>
    <div>Grand Total Estimasi: <strong>' . rupiah($grand_total) . '</strong></div>
</div>
';

$mpdf = new \Mpdf\Mpdf([
    'format' => 'A4',
    'orientation' => 'P',
    'margin_top' => 12,
    'margin_bottom' => 12,
    'margin_left' => 10,
    'margin_right' => 10,
]);
$mpdf->WriteHTML($html);
$mpdf->Output($filename, 'I');
exit;
