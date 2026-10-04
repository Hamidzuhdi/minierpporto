<?php
session_start();
require_once '../config.php';
require_once '../vendor/autoload.php'; // mPDF autoload

// Pastikan user sudah login dan role Owner
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$user_role = $_SESSION['role'] ?? 'Admin';
$spk_id = (int)$_GET['spk_id'];

if ($user_role !== 'Owner') {
    // Admin can access only if SPK status is 'Sudah Cetak Invoice'
    $check_status = mysqli_query($conn, "SELECT status_spk FROM spk WHERE id = $spk_id");
    $check_row = mysqli_fetch_assoc($check_status);
    if (!$check_row || $check_row['status_spk'] !== 'Sudah Cetak Invoice') {
        die('Hanya Owner yang dapat mengakses invoice PDF');
    }
}

// Get SPK data
$sql = "SELECT s.*,
        c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
        v.nomor_polisi, v.merk, v.model, v.tahun,
        i.status_piutang as invoice_status_piutang
        FROM spk s
        JOIN customers c ON s.customer_id = c.id
        JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN invoices i ON i.spk_id = s.id
        WHERE s.id = $spk_id";

$result = mysqli_query($conn, $sql);

if (!$row = mysqli_fetch_assoc($result)) {
    die('SPK tidak ditemukan');
}

$spk = $row;

// Get SPK items (sparepart) dengan harga jual
// NOTE: Use si.subtotal GENERATED column yang sudah handle custom price logic
$sql_items = "SELECT si.*, sp.nama as sparepart_name, sp.satuan, sp.harga_jual_default
              FROM spk_items si
              JOIN spareparts sp ON si.sparepart_id = sp.id
              WHERE si.spk_id = $spk_id";
$result_items = mysqli_query($conn, $sql_items);

$items = [];
$total_sparepart = 0;
while ($item = mysqli_fetch_assoc($result_items)) {
    $items[] = $item;
    // Use GENERATED subtotal yang sudah include custom price
    $total_sparepart += $item['subtotal'];
}

// Get SPK services (jasa service)
$sql_services = "SELECT ss.*, sp.nama_jasa, sp.kategori
                 FROM spk_services ss
                 JOIN service_prices sp ON ss.service_price_id = sp.id
                 WHERE ss.spk_id = $spk_id";
$result_services = mysqli_query($conn, $sql_services);

$services = [];
$total_jasa = 0;
while ($svc = mysqli_fetch_assoc($result_services)) {
    $services[] = $svc;
    $total_jasa += $svc['subtotal'];
}

$subtotal = $total_sparepart + $total_jasa;
$discount_amount = (strtolower((string)($spk['discount_status'] ?? '')) === 'approved')
    ? (float)($spk['discount_amount_approved'] ?? 0)
    : 0;
if ($discount_amount < 0) {
    $discount_amount = 0;
}
if ($discount_amount > $subtotal) {
    $discount_amount = $subtotal;
}
$grand_total = $subtotal - $discount_amount;

// Generate filename: namacust_nopol_mobil_tglsekarang
$customer_name_clean = preg_replace('/[^a-zA-Z0-9]/', '_', $spk['customer_name']);
$nopol_clean = preg_replace('/[^a-zA-Z0-9]/', '_', $spk['nomor_polisi']);
$jenis_mobil_clean = preg_replace('/[^a-zA-Z0-9]/', '_', $spk['merk'] . '_' . $spk['model']);
$tanggal_clean = date('Ymd'); // Tanggal sekarang
$filename = $customer_name_clean . '_' . $nopol_clean . '_' . $jenis_mobil_clean . '_' . $tanggal_clean . '.pdf';

// Logo path - convert to base64 for mPDF
$logo_path = __DIR__ . '/../caricon.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
}

// Initialize mPDF - Optimized margins for 1 page fit
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_top' => 6,
    'margin_bottom' => 12,
    'margin_left' => 9,
    'margin_right' => 9,
    'margin_header' => 3,
    'margin_footer' => 8
]);

// Set document properties
$mpdf->SetTitle('Invoice ' . $spk['kode_unik_reference']);
$mpdf->SetAuthor('Bengkel Mini ERP');

// Tampilkan watermark "LUNAS" samar-samar di tengah invoice jika sudah dibayar penuh
if (($spk['invoice_status_piutang'] ?? '') === 'Lunas') {
    $mpdf->SetWatermarkText('LUNAS', 0.08);
    $mpdf->showWatermarkText = true;
}

// Set footer with page numbering - center aligned
$mpdf->SetFooter('{PAGENO}');

// Start output buffering to capture HTML
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice <?php echo $spk['kode_unik_reference']; ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            margin: 0;
        }
        .header {
    margin: 0 0 6px 0;
    border-bottom: 2px solid #000;
    padding-bottom: 4px;
    width: 100%;
    overflow: hidden;
}

.header-left {
    float: right;
    width: 74%;
    text-align: right;
}

.header-right {
    float: left;
    width: 26%;
}

.header-right img {
    width: 58px;
    height: auto;
}
        .header-left p {
            margin: 0 0 1px 0;
            font-size: 8px;
            line-height: 1.15;
        }
        .info-section {
            margin-bottom: 8px;
        }
        .info-section table {
            width: 100%;
        }
        .info-section td {
            padding: 2px 0;
            font-size: 8.5px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #000;
            padding: 4px;
            text-align: left;
            font-size: 9px;
        }
        .items-table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .items-table .grand-total {
            background-color: #f0f0f0;
            font-weight: bold;
            border-top: 2px solid #000;
        }
        .items-table .text-right {
            text-align: right;
        }
        .items-table .text-center {
            text-align: center;
        }
        .total-section {
            margin-top: 20px;
            float: right;
            width: 300px;
        }
        .total-section table {
            width: 100%;
            border-collapse: collapse;
        }
        .total-section td {
            padding: 5px;
            border-bottom: 1px solid #ddd;
        }
        .total-section .grand-total {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #000;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }
    </style>
</head>
<body>
    <div class="header">
    <div class="header-left">
        <p><strong>Jl. Gayungsari Bar. I No.12, Kebonsari, Kec. Gayungan</strong></p>
        <p><strong>Surabaya, Jawa Timur 60233</strong></p>
        <p>Admin Operasional: 0822-4484-1932</p>
        <p>Admin Finance: +62 822-2854-8800 / +62 819-4974-8881</p>
    </div>

    <div class="header-right">
        <?php if (!empty($logo_base64)): ?>
        <img src="<?php echo $logo_base64; ?>" alt="Logo">
        <?php endif; ?>
    </div>
</div>

    <div class="info-section">
        <table>
            <tr>
                <td width="15%"><strong>Invoice No:</strong></td>
                <td width="35%"><?php echo $spk['kode_unik_reference']; ?></td>
                <td width="15%"><strong>Tanggal:</strong></td>
                <td width="35%"><?php echo date('d/m/Y', strtotime($spk['tanggal'])); ?></td>
            </tr>
            <tr>
                <td><strong>Customer:</strong></td>
                <td><?php echo $spk['customer_name']; ?></td>
                <td><strong>Telepon:</strong></td>
                <td><?php echo $spk['customer_phone']; ?></td>
            </tr>
            <tr>
                <td><strong>Alamat:</strong></td>
                <td><?php echo $spk['customer_address'] ?? '-'; ?></td>
                <td><strong>Kendaraan:</strong></td>
                <td><?php echo $spk['nomor_polisi'] . ' - ' . $spk['merk'] . ' ' . $spk['model'] . ' (' . $spk['tahun'] . ')'; ?></td>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td><strong>Kilometer:</strong></td>
                <td>
                    <?php
                        $invoice_kilometer = $spk['kilometer'] ?? null;
                        echo is_numeric($invoice_kilometer)
                            ? number_format((float)$invoice_kilometer, 0, ',', '.') . ' KM'
                            : '-';
                    ?>
                </td>
            </tr>
        </table>
    </div>

    <h3 style="font-size: 11px; margin: 8px 0 5px 0;">Detail Pekerjaan Jasa Service</h3>
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" width="5%">No</th>
                <th width="50%">Nama Jasa</th>
                <th class="text-center" width="10%">Qty</th>
                <th class="text-right" width="15%">Harga</th>
                <th class="text-right" width="20%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            if (count($services) > 0) {
                foreach ($services as $svc): 
            ?>
            <?php
                $svcCustomEnabled = (int)($svc['use_custom_price'] ?? 0) === 1;
                $svcCustomPrice = (float)($svc['harga_custom'] ?? 0);
                $svcDisplayPrice = ($svcCustomEnabled && $svcCustomPrice > 0) ? $svcCustomPrice : (float)$svc['harga'];
            ?>
            <tr>
                <td class="text-center"><?php echo $no++; ?></td>
                <td><?php echo $svc['nama_jasa']; ?></td>
                <td class="text-center"><?php echo $svc['qty']; ?></td>
                <td class="text-right">Rp <?php echo number_format($svcDisplayPrice, 0, ',', '.'); ?></td>
                <td class="text-right">Rp <?php echo number_format($svc['subtotal'], 0, ',', '.'); ?></td>
            </tr>
            <?php 
                endforeach;
            } else {
            ?>
            <tr>
                <td colspan="5" class="text-center">Tidak ada jasa service</td>
            </tr>
            <?php } ?>
            <tr class="grand-total">
                <td colspan="4" class="text-right"><strong>Total Jasa Service:</strong></td>
                <td class="text-right"><strong>Rp <?php echo number_format($total_jasa, 0, ',', '.'); ?></strong></td>
            </tr>
        </tbody>
    </table>

    <h3 style="font-size: 11px; margin: 8px 0 5px 0;">Detail Sparepart yang Digunakan</h3>
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" width="5%">No</th>
                <th width="40%">Nama Sparepart</th>
                <th class="text-center" width="10%">Qty</th>
                <th class="text-center" width="10%">Satuan</th>
                <th class="text-right" width="15%">Harga</th>
                <th class="text-right" width="20%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            if (count($items) > 0) {
                foreach ($items as $item): 
            ?>
            <tr>
                <td class="text-center"><?php echo $no++; ?></td>
                <td><?php echo $item['sparepart_name']; ?></td>
                <td class="text-center"><?php echo $item['qty']; ?></td>
                <td class="text-center"><?php echo $item['satuan']; ?></td>
                <?php
                    // Display correct price: custom if enabled, else default
                    $itemCustomEnabled = (int)($item['use_custom_price'] ?? 0) === 1;
                    $customPrice = (float)($item['harga_custom'] ?? 0);
                    $displayPrice = ($itemCustomEnabled && $customPrice > 0) ? $customPrice : (float)$item['harga_jual_default'];
                ?>
                <td class="text-right">Rp <?php echo number_format($displayPrice, 0, ',', '.'); ?></td>
                <td class="text-right">Rp <?php echo number_format($item['subtotal'], 0, ',', '.'); ?></td>
            </tr>
            <?php 
                endforeach;
            } else {
            ?>
            <tr>
                <td colspan="6" class="text-center">Tidak ada sparepart</td>
            </tr>
            <?php } ?>
            <tr class="grand-total">
                <td colspan="5" class="text-right"><strong>Total Sparepart:</strong></td>
                <td class="text-right"><strong>Rp <?php echo number_format($total_sparepart, 0, ',', '.'); ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="clearfix">
        <div class="total-section">
            <table>
                <tr>
                    <td><strong>Total Jasa Service:</strong></td>
                    <td class="text-right">Rp <?php echo number_format($total_jasa, 0, ',', '.'); ?></td>
                </tr>
                <tr>
                    <td><strong>Total Sparepart:</strong></td>
                    <td class="text-right">Rp <?php echo number_format($total_sparepart, 0, ',', '.'); ?></td>
                </tr>
                <tr>
                    <td><strong>Discount:</strong></td>
                    <td class="text-right">Rp <?php echo number_format($discount_amount, 0, ',', '.'); ?></td>
                </tr>
                <tr class="grand-total">
                    <td><strong>GRAND TOTAL:</strong></td>
                    <td class="text-right"><strong>Rp <?php echo number_format($grand_total, 0, ',', '.'); ?></strong></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Saran Service -->
    <div style="clear: both; margin-top: 10px; border: 1px solid #000; padding: 8px; background-color: #f9f9f9;">
        <h4 style="margin: 0 0 5px 0; font-size: 10px;">Saran Service:</h4>
        <p style="margin: 0; font-size: 9px;"><?php echo !empty($spk['saran_service']) ? nl2br(htmlspecialchars($spk['saran_service'])) : '-'; ?></p>
    </div>

    <!-- Bank Account Info -->
    <div style="margin-top: 8px; padding: 8px; border: 1px solid #000; background-color: #f9f9f9;">
        <h4 style="margin: 0 0 5px 0; font-size: 10px;">Informasi Pembayaran:</h4>
        <p style="margin: 0; font-size: 9px;"><strong>Bank BCA:</strong> 4680408799 a.n. Moh. Ambali</p>
    </div>

    <!-- Warranty Terms -->
    <div style="margin-top: 8px; padding: 6px; border: 1px solid #000; background-color: #fff3cd; text-align: center;">
        <p style="margin: 0; font-size: 9px; font-weight: bold;">⚠️ Semua service bergaransi selama 2 minggu</p>
    </div>

    <div style="clear: both; margin-top: 30px;">
        <table width="100%">
            <tr>
                <td width="50%" style="text-align: center; font-size: 9px;">
                    <p style="margin: 0;">Mengetahui,</p>
                    <br><br>
                    <p style="margin: 0;">____________________</p>
                    <p style="margin: 0;"><?php echo $spk['customer_name']; ?></p>
                </td>
                <td width="50%" style="text-align: center; font-size: 9px;">
                    <p style="margin: 0;">Hormat Kami,</p>
                    <br><br>
                    <p style="margin: 0;">____________________</p>
                    <p style="margin: 0;">Bengkel</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <p>Terima kasih atas kepercayaan Anda | Printed: <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>

</body>
</html>
<?php
// Get the buffered HTML content
$html = ob_get_clean();

// Write HTML to mPDF
$mpdf->WriteHTML($html);

// Output PDF to browser
$mpdf->Output($filename, 'I');
?>
