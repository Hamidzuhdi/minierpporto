<?php
session_start();
require_once 'config.php';
require_once 'finance_helper.php';
require_once 'vendor/autoload.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Owner') {
    die('Unauthorized');
}

finance_ensure_default_accounts($conn);

$month = trim($_GET['month'] ?? '');
$month_esc = !empty($month) ? mysqli_real_escape_string($conn, $month) : '';
$month_filter = !empty($month_esc) ? "AND DATE_FORMAT(ft.tanggal, '%Y-%m') = '$month_esc'" : '';
$month_filter_invoice = !empty($month_esc) ? "AND DATE_FORMAT(i.tanggal, '%Y-%m') = '$month_esc'" : '';
$month_filter_spk_tanggal = !empty($month_esc) ? "AND DATE_FORMAT(s.tanggal, '%Y-%m') = '$month_esc'" : '';

$qIn = "SELECT COALESCE(SUM(ft.amount), 0) total
                FROM finance_transactions ft
                LEFT JOIN invoices i ON ft.reference_type = 'invoice' AND ft.reference_id = i.id
                WHERE ft.direction = 'in'
                    AND (ft.reference_type <> 'invoice' OR COALESCE(i.status_piutang, '') <> 'Tidak_Aktif')
                    $month_filter";
$qOut = "SELECT COALESCE(SUM(amount), 0) total FROM finance_transactions ft WHERE ft.direction = 'out' $month_filter";
$qPo = "SELECT COALESCE(SUM(amount), 0) total FROM finance_transactions ft WHERE ft.direction = 'out' AND ft.category = 'OUT-PO' $month_filter";
$qOps = "SELECT COALESCE(SUM(amount), 0) total FROM finance_transactions ft WHERE ft.direction = 'out' AND ft.reference_type = 'operational' $month_filter";
$qSpk = "SELECT COALESCE(SUM(ft.amount), 0) total
                 FROM finance_transactions ft
                 JOIN invoices i ON i.id = ft.reference_id
                 WHERE ft.direction = 'in'
                     AND ft.reference_type = 'invoice'
                     AND COALESCE(i.status_piutang, '') <> 'Tidak_Aktif'
                     $month_filter";
$qSalesDiscount = "SELECT COALESCE(SUM(ft.amount), 0) total
                                    FROM finance_transactions ft
                                    LEFT JOIN expense_categories ec ON ec.code = ft.category
                                    WHERE ft.direction = 'out'
                                        AND ft.reference_type = 'operational'
                                        AND (
                                                UPPER(ft.category) = 'SALES-DISCOUNT'
                                                OR UPPER(ft.category) = 'EXP-SALES-DISCOUNT'
                                                OR LOWER(COALESCE(ec.name, '')) = 'sales discount'
                                        )
                                        $month_filter";
$qFixedExpense = "SELECT COALESCE(SUM(ft.amount), 0) total
                                    FROM finance_transactions ft
                                    JOIN expense_categories ec ON ec.code = ft.category
                                    WHERE ft.direction = 'out'
                                        AND ft.reference_type = 'operational'
                                        AND ec.is_active = 1
                                        AND ec.status = 1
                                        $month_filter";
$qVariableExpense = "SELECT COALESCE(SUM(ft.amount), 0) total
                                         FROM finance_transactions ft
                                         JOIN expense_categories ec ON ec.code = ft.category
                                         WHERE ft.direction = 'out'
                                             AND ft.reference_type = 'operational'
                                             AND ec.is_active = 1
                                             AND ec.status = 0
                                             $month_filter";

$total_in = (float)mysqli_fetch_assoc(mysqli_query($conn, $qIn))['total'];
$total_out = (float)mysqli_fetch_assoc(mysqli_query($conn, $qOut))['total'];
$po_out = (float)mysqli_fetch_assoc(mysqli_query($conn, $qPo))['total'];
$ops_out = (float)mysqli_fetch_assoc(mysqli_query($conn, $qOps))['total'];
$spk_in = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSpk))['total'];
$net = $total_in - $total_out;
$sales_discount = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSalesDiscount))['total'];
$fixed_expense_total = (float)mysqli_fetch_assoc(mysqli_query($conn, $qFixedExpense))['total'];
$variable_expense_total = (float)mysqli_fetch_assoc(mysqli_query($conn, $qVariableExpense))['total'];

$has_hpp_col_res = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'hpp_satuan'");
$has_hpp_col = $has_hpp_col_res && mysqli_num_rows($has_hpp_col_res) > 0;

// Accrual: dihitung begitu item dipakai di SPK, tidak menunggu invoice Lunas.
$qSpareRevenue = "SELECT COALESCE(SUM(si.subtotal), 0) total
                  FROM spk_items si
                  JOIN spk s ON s.id = si.spk_id
                  WHERE s.status_spk <> 'Dibatalkan' $month_filter_spk_tanggal";

$qServiceRevenue = "SELECT COALESCE(SUM(i.biaya_jasa), 0) total
                    FROM invoices i
                    WHERE i.status_piutang = 'Lunas' $month_filter_invoice";

if ($has_hpp_col) {
    $qSpareHpp = "SELECT COALESCE(SUM(si.qty * si.hpp_satuan), 0) total
                  FROM spk_items si
                  JOIN spk s ON s.id = si.spk_id
                  WHERE s.status_spk <> 'Dibatalkan' $month_filter_spk_tanggal";
} else {
    $qSpareHpp = "SELECT COALESCE(SUM(si.qty * sp.harga_beli_default), 0) total
                  FROM spk_items si
                  JOIN spareparts sp ON sp.id = si.sparepart_id
                  JOIN spk s ON s.id = si.spk_id
                  WHERE s.status_spk <> 'Dibatalkan' $month_filter_spk_tanggal";
}

$spare_revenue = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSpareRevenue))['total'];
$service_revenue = (float)mysqli_fetch_assoc(mysqli_query($conn, $qServiceRevenue))['total'];
$spare_hpp = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSpareHpp))['total'];
$spare_profit = $spare_revenue - $spare_hpp;
$laba_kotor_formula = $total_in - ($sales_discount + $spare_hpp);
$total_beban_operasional = $fixed_expense_total + $variable_expense_total;
// Zakat dihitung dari Laba Bersih (setelah beban operasional), bukan dari Laba Kotor.
$laba_bersih_sebelum_zakat = $laba_kotor_formula - $total_beban_operasional;
$zakat = $laba_bersih_sebelum_zakat > 0 ? ($laba_bersih_sebelum_zakat * 0.025) : 0;
$net_profit = $laba_bersih_sebelum_zakat - $zakat;

// Laba OPL (pekerjaan pihak ketiga): nominal ditagih ke customer - nominal dibayar ke pihak ketiga.
// "OPL" ditandai lewat category_code (mis. "OPL JASA", "OPL PART") - kategori aktif yang
// dipakai menandai lewat code.
$month_filter_oe = !empty($month_esc) ? "AND DATE_FORMAT(oe.tanggal, '%Y-%m') = '$month_esc'" : '';

$qOplLabaJasa = "SELECT COALESCE(SUM(oe.billed_amount - oe.amount), 0) total
             FROM operational_expenses oe
             WHERE oe.category_code LIKE '%OPL%' AND oe.category_code LIKE '%JASA%'
               AND oe.billed_amount IS NOT NULL $month_filter_oe";
$opl_laba_jasa = (float)mysqli_fetch_assoc(mysqli_query($conn, $qOplLabaJasa))['total'];

$qOplLabaPart = "SELECT COALESCE(SUM(oe.billed_amount - oe.amount), 0) total
             FROM operational_expenses oe
             WHERE oe.category_code LIKE '%OPL%' AND oe.category_code LIKE '%PART%'
               AND oe.billed_amount IS NOT NULL $month_filter_oe";
$opl_laba_part = (float)mysqli_fetch_assoc(mysqli_query($conn, $qOplLabaPart))['total'];

$cashAcc = finance_get_account_by_code($conn, 'cash');
$bankAcc = finance_get_account_by_code($conn, 'bank');
$saldo_akhir = (float)($cashAcc['current_balance'] ?? 0) + (float)($bankAcc['current_balance'] ?? 0);

$qTopOps = "SELECT ft.category, COALESCE(ec.name, ft.category) as category_name, SUM(ft.amount) total_out
            FROM finance_transactions ft
            LEFT JOIN expense_categories ec ON ft.category = ec.code
            WHERE ft.direction = 'out' AND ft.reference_type = 'operational' $month_filter
            GROUP BY ft.category, ec.name
            ORDER BY total_out DESC
            LIMIT 10";
$top_categories = [];
$resTopOps = mysqli_query($conn, $qTopOps);
while ($row = mysqli_fetch_assoc($resTopOps)) {
    $top_categories[] = $row;
}

function rupiah($value)
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function category_label($code, $expenseName)
{
    if (!empty($expenseName)) return $expenseName;
    $labels = [
        'OUT-PO' => 'Pengeluaran PO',
        'IN-CUST-SPAREPART' => 'Pemasukan SPK Sparepart',
        'IN-CUST-PAYMENT' => 'Pembayaran Invoice Customer',
        'TRF-IN' => 'Transfer Masuk',
        'TRF-OUT' => 'Transfer Keluar',
    ];
    return $labels[$code] ?? $code;
}

$period_label = !empty($month_esc)
    ? date('F Y', strtotime($month_esc . '-01'))
    : 'Semua Waktu';
$generated_at = date('d-m-Y H:i:s');

$html = '
<style>
body { font-family: sans-serif; font-size: 10pt; color: #222; }
h2 { margin: 0 0 6px 0; color: #0b5ed7; }
.small { color: #666; font-size: 9pt; }
.summary-list { border: 1px solid #ddd; margin-top: 12px; }
.summary-row { border-bottom: 1px solid #eee; padding: 7px 10px; }
.summary-row:last-child { border-bottom: 0; }
.summary-title { color: #666; font-size: 9pt; }
.summary-value { font-size: 11pt; font-weight: bold; float: right; }
.summary-detail { color: #555; font-size: 8.5pt; font-style: italic; }
.section-title { margin-top: 14px; font-size: 11pt; font-weight: bold; color: #333; }
table { width: 100%; border-collapse: collapse; margin-top: 6px; }
th, td { border: 1px solid #ddd; padding: 6px; }
th { background: #f5f5f5; text-align: left; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.in { color: #198754; font-weight: bold; }
.out { color: #dc3545; font-weight: bold; }
</style>

<h2>Laporan Keuangan</h2>
<div class="small">Periode: ' . htmlspecialchars($period_label) . '</div>
<div class="small">Dicetak oleh: ' . htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Owner') . '</div>
<div class="small">Generated at: ' . $generated_at . '</div>

<div class="summary-list">
    <div class="summary-row"><span class="summary-title">1. Cashflow Masuk</span><span class="summary-value">' . rupiah($total_in) . '</span></div>
    <div class="summary-row"><span class="summary-title">2. Kategori Pengeluaran (Sales Discount)</span><span class="summary-value">' . rupiah($sales_discount) . '</span></div>
    <div class="summary-row"><span class="summary-title">3. Biaya HPP Sparepart</span><span class="summary-value">' . rupiah($spare_hpp) . '</span></div>
    <div class="summary-row"><span class="summary-title">4. Laba Kotor [1 - (2 + 3)]</span><span class="summary-value">' . rupiah($laba_kotor_formula) . '</span></div>
    <div class="summary-row"><span class="summary-detail">Detail #4: ' . rupiah($total_in) . ' - (' . rupiah($sales_discount) . ' + ' . rupiah($spare_hpp) . ') = ' . rupiah($laba_kotor_formula) . '</span></div>
    <div class="summary-row"><span class="summary-title">5. Judul (Static): Beban Operasional</span><span class="summary-value">-</span></div>
    <div class="summary-row"><span class="summary-title">6. Judul (Static): Beban Tetap</span><span class="summary-value">-</span></div>
    <div class="summary-row"><span class="summary-title">7. Semua expense_categories status = 1</span><span class="summary-value">' . rupiah($fixed_expense_total) . '</span></div>
    <div class="summary-row"><span class="summary-title">8. Judul (Static): Beban Tidak Tetap</span><span class="summary-value">-</span></div>
    <div class="summary-row"><span class="summary-title">9. Semua expense_categories status = 0</span><span class="summary-value">' . rupiah($variable_expense_total) . '</span></div>
    <div class="summary-row"><span class="summary-title">10. Total Beban Operasional [7 + 9]</span><span class="summary-value">' . rupiah($total_beban_operasional) . '</span></div>
    <div class="summary-row"><span class="summary-detail">Detail #10: ' . rupiah($fixed_expense_total) . ' + ' . rupiah($variable_expense_total) . ' = ' . rupiah($total_beban_operasional) . '</span></div>
    <div class="summary-row"><span class="summary-title">11. Laba Bersih Sebelum Zakat [4 - 10]</span><span class="summary-value">' . rupiah($laba_bersih_sebelum_zakat) . '</span></div>
    <div class="summary-row"><span class="summary-detail">Detail #11: ' . rupiah($laba_kotor_formula) . ' - ' . rupiah($total_beban_operasional) . ' = ' . rupiah($laba_bersih_sebelum_zakat) . '</span></div>
    <div class="summary-row"><span class="summary-title">12. Zakat 2.5%</span><span class="summary-value">' . rupiah($zakat) . '</span></div>
    <div class="summary-row"><span class="summary-detail">Detail #12: 2.5% x ' . rupiah($laba_bersih_sebelum_zakat) . ' = ' . rupiah($zakat) . '</span></div>
    <div class="summary-row"><span class="summary-title">13. Net Profit / Laba Bersih [11 - 12]</span><span class="summary-value">' . rupiah($net_profit) . '</span></div>
    <div class="summary-row"><span class="summary-detail">Detail #13: ' . rupiah($laba_bersih_sebelum_zakat) . ' - ' . rupiah($zakat) . ' = ' . rupiah($net_profit) . '</span></div>
    <div class="summary-row"><span class="summary-title">Laba OPL Jasa</span><span class="summary-value">' . rupiah($opl_laba_jasa) . '</span></div>
    <div class="summary-row"><span class="summary-title">Laba OPL Part</span><span class="summary-value">' . rupiah($opl_laba_part) . '</span></div>
    <div class="summary-row"><span class="summary-detail">Ditagih ke customer dikurangi dibayar ke pihak ketiga (kategori OPL JASA/OPL PART), di luar Net Profit di atas</span></div>
    <div class="summary-row"><span class="summary-title">Saldo Akhir (Cash + Bank)</span><span class="summary-value">' . rupiah($saldo_akhir) . '</span></div>
</div>

<div class="section-title">Top Kategori Pengeluaran</div>
<table>
    <thead>
        <tr>
            <th style="width:10%">No</th>
            <th>Kategori</th>
            <th style="width:25%" class="text-right">Total</th>
        </tr>
    </thead>
    <tbody>';

if (count($top_categories) === 0) {
    $html .= '<tr><td colspan="3" class="text-center">Belum ada data</td></tr>';
} else {
    $no = 1;
    foreach ($top_categories as $row) {
        $html .= '<tr>
            <td class="text-center">' . $no++ . '</td>
            <td>' . htmlspecialchars(category_label($row['category'], $row['category_name'])) . '</td>
            <td class="text-right">' . rupiah($row['total_out']) . '</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>';

$filename = 'laporan_keuangan_' . (!empty($month_esc) ? str_replace('-', '', $month_esc) : 'all') . '_' . date('Ymd_His') . '.pdf';

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
