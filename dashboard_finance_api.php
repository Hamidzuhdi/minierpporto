<?php
session_start();
require_once 'config.php';
require_once 'finance_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Owner') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
finance_ensure_default_accounts($conn);

$month = trim($_GET['month'] ?? '');
$month_esc = !empty($month) ? mysqli_real_escape_string($conn, $month) : '';
$month_filter = !empty($month_esc) ? "AND DATE_FORMAT(ft.tanggal, '%Y-%m') = '$month_esc'" : '';

// Summary from ledger (exclude internal transfer from net cashflow)
$qIn = "SELECT COALESCE(SUM(ft.amount), 0) total
                FROM finance_transactions ft
                LEFT JOIN invoices i ON ft.reference_type = 'invoice' AND ft.reference_id = i.id
                WHERE ft.direction = 'in'
                    AND (ft.reference_type <> 'invoice' OR COALESCE(i.status_piutang, '') <> 'Tidak_Aktif')
                    $month_filter";
$qOut = "SELECT COALESCE(SUM(amount), 0) total FROM finance_transactions ft WHERE ft.direction = 'out' $month_filter";
$qPo = "SELECT COALESCE(SUM(amount), 0) total FROM finance_transactions ft WHERE ft.direction = 'out' AND ft.category = 'OUT-PO' $month_filter";
$qOps = "SELECT COALESCE(SUM(amount), 0) total FROM finance_transactions ft WHERE ft.direction = 'out' AND ft.reference_type = 'operational' $month_filter";
$qSpkIn = "SELECT COALESCE(SUM(ft.amount), 0) total
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
$spk_in = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSpkIn))['total'];
$sales_discount = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSalesDiscount))['total'];
$fixed_expense_total = (float)mysqli_fetch_assoc(mysqli_query($conn, $qFixedExpense))['total'];
$variable_expense_total = (float)mysqli_fetch_assoc(mysqli_query($conn, $qVariableExpense))['total'];

// Sparepart profit: accrual begitu item dipakai di SPK, TIDAK menunggu invoice Lunas.
// Supaya SPK dibuat Januari tetap terhitung di Januari meski customer baru lunas bulan berikutnya.
$has_hpp_col_res = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'hpp_satuan'");
$has_hpp_col = $has_hpp_col_res && mysqli_num_rows($has_hpp_col_res) > 0;

$month_filter_spk_tanggal = !empty($month_esc) ? "AND DATE_FORMAT(s.tanggal, '%Y-%m') = '$month_esc'" : '';

// Use si.subtotal (GENERATED column) supaya harga khusus (harga_custom) ikut terhitung.
$qSpareRevenue = "SELECT COALESCE(SUM(si.subtotal), 0) total
                  FROM spk_items si
                  JOIN spk s ON s.id = si.spk_id
                  WHERE s.status_spk <> 'Dibatalkan' $month_filter_spk_tanggal";

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
$spare_hpp = (float)mysqli_fetch_assoc(mysqli_query($conn, $qSpareHpp))['total'];
$spare_profit = $spare_revenue - $spare_hpp;

// Total jasa mekanik (service) - untuk history/tracking saja
$spk_month_filter = !empty($month_esc) ? "AND DATE_FORMAT(s.created_at, '%Y-%m') = '$month_esc'" : '';
// Use ss.subtotal (GENERATED column) supaya harga khusus (harga_custom) ikut terhitung.
$qTotalJasa = "SELECT COALESCE(SUM(ss.subtotal), 0) total
               FROM spk_services ss
               JOIN spk s ON s.id = ss.spk_id
               WHERE s.status_spk IN ('Selesai', 'Dikirim ke Owner', 'Buat Invoice', 'Sudah Cetak Invoice')
               $spk_month_filter";
$total_jasa_mekanik = (float)mysqli_fetch_assoc(mysqli_query($conn, $qTotalJasa))['total'];

// Laba OPL (pekerjaan pihak ketiga): nominal ditagih ke customer - nominal dibayar ke pihak ketiga.
// Tidak dikaitkan ke SPK manapun, murni dari operational_expenses. "OPL" ditandai lewat
// category_code (mis. "OPL JASA", "OPL PART") - kategori aktif yang dipakai menandai lewat code.
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

$laba_kotor_formula = $total_in - ($sales_discount + $spare_hpp);
$total_beban_operasional = $fixed_expense_total + $variable_expense_total;
// Zakat dihitung dari Laba Bersih (setelah beban operasional), bukan dari Laba Kotor.
$laba_bersih_sebelum_zakat = $laba_kotor_formula - $total_beban_operasional;
$zakat = $laba_bersih_sebelum_zakat > 0 ? ($laba_bersih_sebelum_zakat * 0.025) : 0;
$net_profit = $laba_bersih_sebelum_zakat - $zakat;

$cashAcc = finance_get_account_by_code($conn, 'cash');
$bankAcc = finance_get_account_by_code($conn, 'bank');
$saldo_akhir = (float)($cashAcc['current_balance'] ?? 0) + (float)($bankAcc['current_balance'] ?? 0);

// Monthly trend (last 12 months)
$chart_months = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chart_months[$m] = [
        'bulan' => $m,
        'label' => date('M Y', strtotime($m . '-01')),
        'total_in' => 0,
        'total_out' => 0,
        'net' => 0,
    ];
}

$oldest_month = date('Y-m', strtotime('-11 months'));
$qMonthly = "SELECT DATE_FORMAT(ft.tanggal, '%Y-%m') bulan,
                SUM(CASE
                       WHEN ft.direction = 'in'
                           AND (ft.reference_type <> 'invoice' OR COALESCE(i.status_piutang, '') <> 'Tidak_Aktif')
                       THEN ft.amount ELSE 0 END) as total_in,
                SUM(CASE WHEN ft.direction = 'out' THEN ft.amount ELSE 0 END) as total_out
           FROM finance_transactions ft
           LEFT JOIN invoices i ON ft.reference_type = 'invoice' AND ft.reference_id = i.id
             WHERE DATE_FORMAT(ft.tanggal, '%Y-%m') >= '$oldest_month'
             GROUP BY DATE_FORMAT(ft.tanggal, '%Y-%m')";
$resMonthly = mysqli_query($conn, $qMonthly);
while ($row = mysqli_fetch_assoc($resMonthly)) {
    if (isset($chart_months[$row['bulan']])) {
        $chart_months[$row['bulan']]['total_in'] = (float)$row['total_in'];
        $chart_months[$row['bulan']]['total_out'] = (float)$row['total_out'];
        $chart_months[$row['bulan']]['net'] = (float)$row['total_in'] - (float)$row['total_out'];
    }
}

// Top expense categories (operational)
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

$qFixedBreakdown = "SELECT ec.code as category, ec.name as category_name, SUM(ft.amount) total_out
                                        FROM finance_transactions ft
                                        JOIN expense_categories ec ON ec.code = ft.category
                                        WHERE ft.direction = 'out'
                                            AND ft.reference_type = 'operational'
                                            AND ec.is_active = 1
                                            AND ec.status = 1
                                            $month_filter
                                        GROUP BY ec.code, ec.name
                                        ORDER BY total_out DESC";
$fixed_categories = [];
$resFixedBreakdown = mysqli_query($conn, $qFixedBreakdown);
while ($row = mysqli_fetch_assoc($resFixedBreakdown)) {
        $fixed_categories[] = $row;
}

$qVariableBreakdown = "SELECT ec.code as category, ec.name as category_name, SUM(ft.amount) total_out
                                             FROM finance_transactions ft
                                             JOIN expense_categories ec ON ec.code = ft.category
                                             WHERE ft.direction = 'out'
                                                 AND ft.reference_type = 'operational'
                                                 AND ec.is_active = 1
                                                 AND ec.status = 0
                                                 $month_filter
                                             GROUP BY ec.code, ec.name
                                             ORDER BY total_out DESC";
$variable_categories = [];
$resVariableBreakdown = mysqli_query($conn, $qVariableBreakdown);
while ($row = mysqli_fetch_assoc($resVariableBreakdown)) {
        $variable_categories[] = $row;
}

// Recent ledger transactions
$qRecent = "SELECT ft.tanggal, ft.direction, ft.category, ft.reference_type, ft.reference_id, ft.note, ft.amount,
                   fa.name as account_name, COALESCE(ec.name, '') as expense_category_name
            FROM finance_transactions ft
            JOIN finance_accounts fa ON ft.account_id = fa.id
            LEFT JOIN expense_categories ec ON ft.category = ec.code
            WHERE 1=1 $month_filter
            ORDER BY ft.id DESC
            LIMIT 12";
$recent = [];
$resRecent = mysqli_query($conn, $qRecent);
while ($row = mysqli_fetch_assoc($resRecent)) {
    $recent[] = $row;
}

echo json_encode([
    'success' => true,
    'summary' => [
        'total_in' => $total_in,
        'total_out' => $total_out,
        'po_out' => $po_out,
        'ops_out' => $ops_out,
        'spk_in' => $spk_in,
        'spare_revenue' => $spare_revenue,
        'spare_hpp' => $spare_hpp,
        'spare_profit' => $spare_profit,
        'sales_discount' => $sales_discount,
        'laba_kotor_formula' => $laba_kotor_formula,
        'fixed_expense_total' => $fixed_expense_total,
        'variable_expense_total' => $variable_expense_total,
        'total_beban_operasional' => $total_beban_operasional,
        'zakat' => $zakat,
        'net_profit' => $net_profit,
        'total_jasa_mekanik' => $total_jasa_mekanik,
        'opl_laba_jasa' => $opl_laba_jasa,
        'opl_laba_part' => $opl_laba_part,
        'net_cashflow' => $total_in - $total_out,
        'saldo_akhir' => $saldo_akhir,
    ],
    'monthly' => array_values($chart_months),
    'top_categories' => $top_categories,
    'fixed_categories' => $fixed_categories,
    'variable_categories' => $variable_categories,
    'recent' => $recent,
]);
?>
