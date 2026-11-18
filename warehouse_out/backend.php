<?php
session_start();
require_once '../config.php';
require_once '../stock_trace_helper.php';

global $conn;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'read') {
    $search = trim((string)($_GET['search'] ?? ''));
    $is_paid = trim((string)($_GET['is_paid'] ?? ''));

    $sql = "SELECT
                p.id,
                p.tanggal,
                p.supplier,
                p.total,
                p.status,
                p.is_paid,
                p.paid_at,
                p.created_at,
                u.username AS created_by_name,
                u.role AS created_by_role,
                COALESCE(i.item_count, 0) AS item_count,
                COALESCE(i.qty_total, 0) AS qty_total,
                COALESCE(i.item_detail, '') AS item_detail
            FROM purchases p
            LEFT JOIN users u ON p.created_by = u.id
            LEFT JOIN (
                SELECT
                    pi.purchase_id,
                    COUNT(*) AS item_count,
                    SUM(pi.qty) AS qty_total,
                    GROUP_CONCAT(
                        CONCAT(
                            sp.nama,
                            ' x',
                            pi.qty,
                            IF(COALESCE(sp.satuan, '') <> '', CONCAT(' ', sp.satuan), '')
                        )
                        ORDER BY sp.nama ASC
                        SEPARATOR ' | '
                    ) AS item_detail
                FROM purchase_items pi
                JOIN spareparts sp ON sp.id = pi.sparepart_id
                GROUP BY pi.purchase_id
            ) i ON i.purchase_id = p.id";

    $conditions = [];
    $conditions[] = "(u.role IN ('Admin', 'Owner') OR p.created_by IS NULL)";

    if ($search !== '') {
        $searchEsc = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(
            p.id LIKE '%$searchEsc%' 
            OR p.supplier LIKE '%$searchEsc%' 
            OR u.username LIKE '%$searchEsc%'
            OR EXISTS (
                SELECT 1 FROM purchase_items pi_search
                JOIN spareparts sp_search ON pi_search.sparepart_id = sp_search.id
                WHERE pi_search.purchase_id = p.id 
                AND sp_search.kode_sparepart LIKE '%$searchEsc%'
            )
        )";
    }

    if ($is_paid !== '') {
        $isPaidEsc = mysqli_real_escape_string($conn, $is_paid);
        $conditions[] = "p.is_paid = '$isPaidEsc'";
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY p.id DESC";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat history purchase: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// GET PURCHASE DETAIL - dengan tracking ke SPK
elseif ($action === 'get_purchase_detail') {
    $purchase_id = intval($_GET['purchase_id'] ?? 0);
    
    if ($purchase_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID Purchase tidak valid']);
        exit;
    }
    
    // Pemakaian ditelusuri FIFO per sparepart (lihat stock_trace_helper.php): stok lama & PO lebih awal habis
    // lebih dulu, SPK diurutkan dari yang paling lama, dan SPK baru tidak menggeser SPK lama.
    $items = stock_trace_purchase_items($conn, $purchase_id);
    if ($items === null) {
        echo json_encode(['success' => false, 'message' => 'Purchase tidak ditemukan']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $items]);
    exit;
}

// EXPORT DETAIL PDF - Export PDF per purchase
elseif ($action === 'export_detail_pdf') {
    require_once '../vendor/autoload.php';
    
    $purchase_id = intval($_GET['purchase_id'] ?? 0);
    
    if ($purchase_id <= 0) {
        die('ID Purchase tidak valid');
    }
    
    // Get purchase info
    $sql_purchase = "SELECT p.*, u.username as created_by_name FROM purchases p 
                     LEFT JOIN users u ON p.created_by = u.id WHERE p.id = $purchase_id LIMIT 1";
    $result_purchase = mysqli_query($conn, $sql_purchase);
    $purchase = mysqli_fetch_assoc($result_purchase);
    
    if (!$purchase) {
        die('Purchase tidak ditemukan');
    }
    
    // Items dengan pelacakan SPK (FIFO, sama dengan tampilan detail)
    $items = stock_trace_purchase_items($conn, $purchase_id) ?? [];

    // Create PDF
    $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
    
    $html = '
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; }
        h2 { text-align: center; margin-bottom: 5px; }
        .info { margin-bottom: 15px; }
        .info-row { margin: 3px 0; }
        .info-label { font-weight: bold; display: inline-block; width: 120px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #333; padding: 5px; text-align: left; vertical-align: top; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-right { text-align: right; }
        .spk-list { margin: 0; padding: 0; line-height: 1.4; }
        .spk-item { font-size: 10px; color: #333; margin: 2px 0; }
        .spk-item-line { display: block; margin: 2px 0; }
        .spk-code { font-weight: bold; color: #0066cc; }
        .spk-qty { color: #666; }
        .spk-date { color: #999; font-size: 9px; }
    </style>
    
    <h2>DETAIL WAREHOUSE OUT - PURCHASE #' . $purchase_id . '</h2>
    
    <div class="info">
        <div class="info-row"><span class="info-label">Supplier:</span> ' . htmlspecialchars($purchase['supplier'] ?? '-') . '</div>
        <div class="info-row"><span class="info-label">Tanggal:</span> ' . date('d M Y', strtotime($purchase['tanggal'])) . '</div>
        <div class="info-row"><span class="info-label">Total PO:</span> Rp ' . number_format($purchase['total'], 0, ',', '.') . '</div>
        <div class="info-row"><span class="info-label">Dibuat Oleh:</span> ' . htmlspecialchars($purchase['created_by_name'] ?? '-') . '</div>
        <div class="info-row"><span class="info-label">Status Bayar:</span> ' . htmlspecialchars($purchase['is_paid'] ?? '-') . '</div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="8%">Kode</th>
                <th width="30%">Nama Item</th>
                <th width="8%">Qty Beli</th>
                <th width="8%">Qty Pakai</th>
                <th width="8%">Qty Sisa</th>
                <th width="15%">Harga/Unit</th>
                <th width="18%">Dipakai di SPK</th>
            </tr>
        </thead>
        <tbody>
    ';
    
    $no = 1;
    foreach ($items as $item) {
        $spk_text = '';
        if (!empty($item['spk_usage'])) {
            $spk_text = '<div class="spk-list">';
            foreach ($item['spk_usage'] as $spk) {
                $spk_date = date('d M Y', strtotime($spk['tanggal']));
                $spk_text .= '<div class="spk-item-line"><span class="spk-code">' . htmlspecialchars($spk['kode_spk']) . '</span> <span class="spk-qty">- ' . $spk['qty'] . ' qty</span> <span class="spk-date">(' . $spk_date . ')</span></div>';
            }
            $spk_text .= '</div>';
        } else {
            $spk_text = '<div class="spk-list"><div class="spk-item"><i>' . (!empty($item['refunded']) ? 'Purchase di-refund' : 'Belum dipakai') . '</i></div></div>';
        }
        
        $html .= '
            <tr>
                <td class="text-right">' . $no . '</td>
                <td>' . htmlspecialchars($item['kode_sparepart'] ?? '-') . '</td>
                <td>' . htmlspecialchars($item['sparepart_name']) . '</td>
                <td class="text-right">' . $item['qty_beli'] . ' ' . htmlspecialchars($item['satuan'] ?? '') . '</td>
                <td class="text-right">' . $item['qty_pakai'] . '</td>
                <td class="text-right">' . $item['qty_sisa'] . '</td>
                <td class="text-right">Rp ' . number_format($item['harga_beli'], 0, ',', '.') . '</td>
                <td>' . $spk_text . '</td>
            </tr>
        ';
        $no++;
    }
    
    $html .= '
        </tbody>
    </table>
    
    <div style="margin-top: 20px; font-size: 10px; text-align: center;">
        <p>Dihasilkan: ' . date('d M Y H:i:s') . '</p>
    </div>
    ';
    
    $mpdf->WriteHTML($html);
    $filename = 'Purchase_Detail_' . $purchase_id . '_' . date('YmdHis') . '.pdf';
    $mpdf->Output($filename, 'D');
    exit;
}

// EXPORT ALL PDF - Export semua data warehouse out
elseif ($action === 'export_all_pdf') {
    require_once '../vendor/autoload.php';
    
    $search = trim($_GET['search'] ?? '');
    $is_paid = trim($_GET['is_paid'] ?? '');
    
    $sql = "SELECT p.*, u.username as created_by_name 
            FROM purchases p
            LEFT JOIN users u ON p.created_by = u.id";
    
    $conditions = [];
    $conditions[] = "(u.role IN ('Admin', 'Owner') OR p.created_by IS NULL)";
    
    if ($search !== '') {
        $searchEsc = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(
            p.id LIKE '%$searchEsc%' 
            OR p.supplier LIKE '%$searchEsc%' 
            OR u.username LIKE '%$searchEsc%'
            OR EXISTS (
                SELECT 1 FROM purchase_items pi_search
                JOIN spareparts sp_search ON pi_search.sparepart_id = sp_search.id
                WHERE pi_search.purchase_id = p.id 
                AND sp_search.kode_sparepart LIKE '%$searchEsc%'
            )
        )";
    }
    
    if ($is_paid !== '') {
        $isPaidEsc = mysqli_real_escape_string($conn, $is_paid);
        $conditions[] = "p.is_paid = '$isPaidEsc'";
    }
    
    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY p.id DESC";
    
    $result = mysqli_query($conn, $sql);
    $purchases = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $purchases[] = $row;
    }
    
    // Create PDF
    $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
    
    $html = '
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        h2 { text-align: center; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 4px; text-align: left; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
    
    <h2>WAREHOUSE OUT - SUMMARY SEMUA PURCHASE</h2>
    
    <table>
        <thead>
            <tr>
                <th width="6%">ID</th>
                <th width="10%">Tanggal</th>
                <th width="18%">Supplier</th>
                <th width="8%">Items</th>
                <th width="8%">Total PO</th>
                <th width="15%">Dibuat Oleh</th>
                <th width="12%">Status</th>
                <th width="12%">Status Bayar</th>
            </tr>
        </thead>
        <tbody>
    ';
    
    foreach ($purchases as $p) {
        $html .= '
            <tr>
                <td class="text-center">#' . $p['id'] . '</td>
                <td>' . date('d/m/Y', strtotime($p['tanggal'])) . '</td>
                <td>' . htmlspecialchars($p['supplier'] ?? '-') . '</td>
                <td class="text-center">-</td>
                <td class="text-right">Rp ' . number_format($p['total'], 0, ',', '.') . '</td>
                <td>' . htmlspecialchars($p['created_by_name'] ?? '-') . '</td>
                <td>' . htmlspecialchars($p['status'] ?? '-') . '</td>
                <td>' . htmlspecialchars($p['is_paid'] ?? '-') . '</td>
            </tr>
        ';
    }
    
    $html .= '
        </tbody>
    </table>
    
    <div style="margin-top: 15px; font-size: 9px; text-align: center;">
        <p>Total Records: ' . count($purchases) . ' | Dihasilkan: ' . date('d M Y H:i:s') . '</p>
    </div>
    ';
    
    $mpdf->WriteHTML($html);
    $filename = 'Warehouse_Out_All_' . date('YmdHis') . '.pdf';
    $mpdf->Output($filename, 'D');
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
?>
