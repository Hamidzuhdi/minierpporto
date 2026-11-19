<?php
session_start();
require_once '../config.php';
require_once '../vendor/autoload.php'; // mPDF autoload
require_once '../estimasi_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

$estimasiId = (int)($_GET['id'] ?? 0);
if ($estimasiId <= 0) {
    http_response_code(400);
    die('Estimasi tidak valid');
}

try {
    $e = estimasi_load($conn, $estimasiId);
} catch (Throwable $ex) {
    http_response_code(500);
    die('Gagal memuat estimasi: ' . $ex->getMessage());
}

if (!$e) {
    http_response_code(404);
    die('Estimasi tidak ditemukan');
}

$services = $e['services'];
$items = $e['items'];
$totals = $e['totals'];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rp($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

// Teks multi-baris (keluhan, analisa, saran) tampil dengan baris barunya.
function multiline($value): string
{
    return nl2br(h(trim((string)$value)));
}

$kendaraan = trim(($e['nomor_polisi'] ?? '') . ' - ' . ($e['merk'] ?? '') . ' ' . ($e['model'] ?? ''), ' -');
if (!empty($e['tahun']) && (int)$e['tahun'] > 0) {
    $kendaraan .= ' (' . (int)$e['tahun'] . ')';
}
$kilometer = is_numeric($e['kilometer'] ?? null)
    ? number_format((float)$e['kilometer'], 0, ',', '.') . ' KM'
    : '-';

$customerClean = preg_replace('/[^a-zA-Z0-9]/', '_', (string)($e['customer_name'] ?? 'Customer'));
$filename = 'Estimasi_' . $e['kode_estimasi'] . '_' . $customerClean . '.pdf';

// Logo dikonversi ke base64 agar terbaca mPDF
$logo_path = __DIR__ . '/../caricon.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_top' => 10,
    'margin_bottom' => 14,
    'margin_left' => 12,
    'margin_right' => 12,
    'margin_header' => 4,
    'margin_footer' => 7,
]);
$mpdf->SetTitle('Estimasi ' . $e['kode_estimasi']);
$mpdf->SetAuthor('Bengkel Mini ERP');
$mpdf->SetFooter('Halaman {PAGENO} dari {nbpg}');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Estimasi <?php echo h($e['kode_estimasi']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; color: #000; }
        .header { border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 10px; }
        .header td { vertical-align: middle; }
        .header .addr p { margin: 0 0 1px 0; font-size: 8.5pt; line-height: 1.2; text-align: right; }
        .title { font-size: 15pt; font-weight: bold; margin: 0 0 6px 0; letter-spacing: 1px; }
        .info { width: 100%; margin-bottom: 8px; }
        .info td { padding: 2px 4px 2px 0; font-size: 9.5pt; vertical-align: top; }
        .info .label { font-weight: bold; width: 17%; }
        .box { border: 1px solid #000; padding: 6px 8px; margin: 6px 0; background-color: #f9f9f9; }
        .box h4 { margin: 0 0 3px 0; font-size: 9.5pt; }
        .box p { margin: 0; font-size: 9.5pt; }
        h3.section { font-size: 10.5pt; margin: 10px 0 4px 0; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { border: 1px solid #000; padding: 4px 5px; font-size: 9.5pt; }
        table.items th { background-color: #f0f0f0; }
        table.items tr.subtotal td { background-color: #f0f0f0; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        table.totals { width: 100%; border-collapse: collapse; }
        table.totals td { padding: 5px; border-bottom: 1px solid #ddd; font-size: 10pt; }
        table.totals tr.grand td { font-weight: bold; font-size: 12pt; border-top: 2px solid #000; border-bottom: 2px solid #000; }
        .note { margin-top: 14px; padding: 6px 8px; border: 1px solid #000; background-color: #fff3cd; font-size: 9pt; }
        .printed { margin-top: 10px; text-align: center; font-size: 8.5pt; color: #666; }
    </style>
</head>
<body>
    <table class="header" width="100%">
        <tr>
            <td width="22%">
                <?php if ($logo_base64 !== ''): ?>
                <img src="<?php echo $logo_base64; ?>" alt="Logo" style="width: 60px;">
                <?php endif; ?>
            </td>
            <td class="addr" width="78%">
                <p><strong>Jl. Gayungsari Bar. I No.12, Kebonsari, Kec. Gayungan</strong></p>
                <p><strong>Surabaya, Jawa Timur 60233</strong></p>
                <p>Admin Operasional: 0822-4484-1932</p>
                <p>Admin Finance: +62 822-2854-8800 / +62 819-4974-8881</p>
            </td>
        </tr>
    </table>

    <div class="title">ESTIMASI BIAYA</div>

    <table class="info">
        <tr>
            <td class="label">No. Estimasi</td>
            <td width="33%">: <?php echo h($e['kode_estimasi']); ?></td>
            <td class="label">Customer</td>
            <td>: <?php echo h($e['customer_name'] ?? '-'); ?></td>
        </tr>
        <tr>
            <td class="label">Tanggal</td>
            <td>: <?php echo h(date('d/m/Y', strtotime($e['tanggal']))); ?></td>
            <td class="label">Telepon</td>
            <td>: <?php echo h(($e['customer_phone'] ?? '') !== '' ? $e['customer_phone'] : '-'); ?></td>
        </tr>
        <tr>
            <td class="label">Kendaraan</td>
            <td>: <?php echo h($kendaraan !== '' ? $kendaraan : '-'); ?></td>
            <td class="label">Alamat</td>
            <td>: <?php echo h(trim((string)($e['customer_address'] ?? '')) !== '' ? $e['customer_address'] : '-'); ?></td>
        </tr>
        <tr>
            <td class="label">Kilometer</td>
            <td>: <?php echo h($kilometer); ?></td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <?php if (trim((string)$e['keluhan_customer']) !== ''): ?>
    <div class="box">
        <h4>Keluhan Customer</h4>
        <p><?php echo multiline($e['keluhan_customer']); ?></p>
    </div>
    <?php endif; ?>

    <?php if (trim((string)$e['analisa_mekanik']) !== ''): ?>
    <div class="box">
        <h4>Hasil Pemeriksaan</h4>
        <p><?php echo multiline($e['analisa_mekanik']); ?></p>
    </div>
    <?php endif; ?>

    <?php if (trim((string)$e['service_description']) !== ''): ?>
    <div class="box">
        <h4>Pekerjaan yang Akan Dilakukan</h4>
        <p><?php echo multiline($e['service_description']); ?></p>
    </div>
    <?php endif; ?>

    <?php if (count($services) > 0): ?>
    <h3 class="section">Rincian Jasa Service</h3>
    <table class="items">
        <thead>
            <tr>
                <th class="text-center" width="6%">No</th>
                <th>Nama Jasa</th>
                <th class="text-center" width="9%">Qty</th>
                <th class="text-right" width="20%">Harga</th>
                <th class="text-right" width="20%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($services as $i => $svc): ?>
            <tr>
                <td class="text-center"><?php echo $i + 1; ?></td>
                <td><?php echo h($svc['nama_jasa']); ?></td>
                <td class="text-center"><?php echo (int)$svc['qty']; ?></td>
                <td class="text-right"><?php echo rp($svc['harga_eff']); ?></td>
                <td class="text-right"><?php echo rp($svc['subtotal']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="4" class="text-right">Total Jasa Service</td>
                <td class="text-right"><?php echo rp($totals['total_jasa']); ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (count($items) > 0): ?>
    <h3 class="section">Rincian Sparepart</h3>
    <table class="items">
        <thead>
            <tr>
                <th class="text-center" width="6%">No</th>
                <th>Nama Sparepart</th>
                <th class="text-center" width="9%">Qty</th>
                <th class="text-center" width="10%">Satuan</th>
                <th class="text-right" width="17%">Harga</th>
                <th class="text-right" width="20%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item): ?>
            <tr>
                <td class="text-center"><?php echo $i + 1; ?></td>
                <td><?php echo h($item['sparepart_name']); ?></td>
                <td class="text-center"><?php echo (int)$item['qty']; ?></td>
                <td class="text-center"><?php echo h($item['satuan']); ?></td>
                <td class="text-right"><?php echo rp($item['harga_eff']); ?></td>
                <td class="text-right"><?php echo rp($item['subtotal']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="5" class="text-right">Total Sparepart</td>
                <td class="text-right"><?php echo rp($totals['total_sparepart']); ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (count($services) === 0 && count($items) === 0): ?>
    <div class="box"><p>Rincian biaya belum diisi.</p></div>
    <?php endif; ?>

    <table width="100%" style="margin-top: 12px;">
        <tr>
            <td width="52%"></td>
            <td width="48%">
                <table class="totals">
                    <tr>
                        <td>Total Jasa Service</td>
                        <td class="text-right"><?php echo rp($totals['total_jasa']); ?></td>
                    </tr>
                    <tr>
                        <td>Total Sparepart</td>
                        <td class="text-right"><?php echo rp($totals['total_sparepart']); ?></td>
                    </tr>
                    <?php if ($totals['discount_amount'] > 0): ?>
                    <tr>
                        <td>Diskon</td>
                        <td class="text-right">- <?php echo rp($totals['discount_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="grand">
                        <td>TOTAL ESTIMASI</td>
                        <td class="text-right"><?php echo rp($totals['total_estimasi']); ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <?php if (trim((string)$e['saran_service']) !== ''): ?>
    <div class="box" style="margin-top: 12px;">
        <h4>Saran Service</h4>
        <p><?php echo multiline($e['saran_service']); ?></p>
    </div>
    <?php endif; ?>

    <div class="note">
        Estimasi ini merupakan perkiraan biaya berdasarkan pemeriksaan awal dan dapat berubah
        sesuai kondisi aktual kendaraan. Dokumen ini bukan invoice dan bukan bukti pembayaran.
    </div>

    <div class="printed">Dicetak: <?php echo date('d/m/Y H:i'); ?></div>
</body>
</html>
<?php
$html = ob_get_clean();

$mpdf->WriteHTML($html);
$mpdf->Output($filename, 'I');
