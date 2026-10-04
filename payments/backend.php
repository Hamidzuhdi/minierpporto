<?php
session_start();
require_once '../config.php';
require_once '../finance_helper.php';

global $conn;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
finance_ensure_default_accounts($conn);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userRole = $_SESSION['role'] ?? 'Admin';

if (!in_array($userRole, ['Owner', 'Admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

if ($action === 'get_accounts') {
    $res = mysqli_query($conn, "SELECT id, code, name, opening_balance, current_balance FROM finance_accounts WHERE is_active = 1 ORDER BY id ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    echo json_encode(['success' => true, 'data' => $rows]);
}

elseif ($action === 'get_opl_detail') {
    $expenseId = (int)($_GET['expense_id'] ?? 0);
    if ($expenseId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID pengeluaran tidak valid']);
        exit;
    }

    $expRes = mysqli_query($conn, "SELECT id, tanggal, expense_name, amount, billed_amount, note FROM operational_expenses WHERE id = $expenseId LIMIT 1");
    $expense = $expRes ? mysqli_fetch_assoc($expRes) : null;
    if (!$expense) {
        echo json_encode(['success' => false, 'message' => 'Pengeluaran tidak ditemukan']);
        exit;
    }

    $billedAmount = $expense['billed_amount'] !== null ? (float)$expense['billed_amount'] : null;
    $laba = $billedAmount !== null ? ($billedAmount - (float)$expense['amount']) : null;

    echo json_encode(['success' => true, 'expense' => $expense, 'laba_opl' => $laba]);
}

elseif ($action === 'get_expense_categories') {
    $res = mysqli_query($conn, "SELECT id, code, name, description, status, is_active FROM expense_categories WHERE is_active = 1 ORDER BY name ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $r['status'] = (int)($r['status'] ?? 0);
        $rows[] = $r;
    }
    echo json_encode(['success' => true, 'data' => $rows]);
}

elseif ($action === 'get_operational_expense_categories') {
    $res = mysqli_query($conn, "
        SELECT DISTINCT oe.category_code as code,
               COALESCE(ec.name, oe.category_code) as name
        FROM operational_expenses oe
        LEFT JOIN expense_categories ec ON ec.code = oe.category_code
        WHERE oe.category_code IS NOT NULL AND oe.category_code <> ''
        ORDER BY oe.category_code ASC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    echo json_encode(['success' => true, 'data' => $rows]);
}

elseif ($action === 'create_expense_category') {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = (int)($_POST['status'] ?? 0);
    $status = ($status === 1) ? 1 : 0;
    if ($code === '' || $name === '') {
        echo json_encode(['success' => false, 'message' => 'Kode dan nama kategori wajib diisi']);
        exit;
    }
    $sql = "INSERT INTO expense_categories (code, name, description, status, is_active)
            VALUES ('" . mysqli_real_escape_string($conn, $code) . "',
                    '" . mysqli_real_escape_string($conn, $name) . "',
                    '" . mysqli_real_escape_string($conn, $description) . "',
                    $status, 1)";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kategori pengeluaran berhasil ditambahkan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menambah kategori: ' . mysqli_error($conn)]);
    }
}

elseif ($action === 'update_expense_category') {
    $id = (int)($_POST['id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = (int)($_POST['status'] ?? 0);
    $status = ($status === 1) ? 1 : 0;
    if ($id <= 0 || $code === '' || $name === '') {
        echo json_encode(['success' => false, 'message' => 'Data kategori tidak valid']);
        exit;
    }
    $sql = "UPDATE expense_categories
            SET code = '" . mysqli_real_escape_string($conn, $code) . "',
                name = '" . mysqli_real_escape_string($conn, $name) . "',
                description = '" . mysqli_real_escape_string($conn, $description) . "',
                status = $status
            WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kategori pengeluaran berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update kategori: ' . mysqli_error($conn)]);
    }
}

elseif ($action === 'delete_expense_category') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID kategori tidak valid']);
        exit;
    }
    $sql = "UPDATE expense_categories SET is_active = 0 WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kategori pengeluaran berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus kategori']);
    }
}

elseif ($action === 'toggle_expense_category_status') {
    $id = (int)($_POST['id'] ?? 0);
    $status = (int)($_POST['status'] ?? 0);
    $status = ($status === 1) ? 1 : 0;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID kategori tidak valid']);
        exit;
    }

    $sql = "UPDATE expense_categories SET status = $status WHERE id = $id AND is_active = 1";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Status kategori berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update status kategori: ' . mysqli_error($conn)]);
    }
}

elseif ($action === 'summary') {
    $cash = finance_get_account_by_code($conn, 'cash');
    $bank = finance_get_account_by_code($conn, 'bank');

    $today = date('Y-m-d');
    $month = date('Y-m');

    $qTodayIn = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) as total FROM finance_transactions WHERE status = 'approved' AND direction IN ('in','transfer_in') AND tanggal = '$today'");
    $qTodayOut = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) as total FROM finance_transactions WHERE status = 'approved' AND direction IN ('out','transfer_out') AND tanggal = '$today'");
    $qMonthIn = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) as total FROM finance_transactions WHERE status = 'approved' AND direction IN ('in','transfer_in') AND DATE_FORMAT(tanggal, '%Y-%m') = '$month'");
    $qMonthOut = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) as total FROM finance_transactions WHERE status = 'approved' AND direction IN ('out','transfer_out') AND DATE_FORMAT(tanggal, '%Y-%m') = '$month'");

    echo json_encode([
        'success' => true,
        'data' => [
            'cash_balance' => (float)($cash['current_balance'] ?? 0),
            'bank_balance' => (float)($bank['current_balance'] ?? 0),
            'total_balance' => (float)($cash['current_balance'] ?? 0) + (float)($bank['current_balance'] ?? 0),
            'today_in' => (float)mysqli_fetch_assoc($qTodayIn)['total'],
            'today_out' => (float)mysqli_fetch_assoc($qTodayOut)['total'],
            'month_in' => (float)mysqli_fetch_assoc($qMonthIn)['total'],
            'month_out' => (float)mysqli_fetch_assoc($qMonthOut)['total'],
        ]
    ]);
}

elseif ($action === 'read_transactions') {
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $account = $_GET['account'] ?? '';
    $direction = $_GET['direction'] ?? '';
    $category = $_GET['category'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');
    $page = (int)($_GET['page'] ?? 1);
    $perPage = (int)($_GET['per_page'] ?? 20);
    if ($page < 1) {
        $page = 1;
    }
    if ($perPage < 1) {
        $perPage = 20;
    }
    if ($perPage > 100) {
        $perPage = 100;
    }
    $offset = ($page - 1) * $perPage;

          $sql = "SELECT ft.*, fa.code as account_code, fa.name as account_name, u.username as created_by_name,
                    uo.username as approved_by_name, ur.username as rejected_by_name,
               ec.name as expense_category_name
            FROM finance_transactions ft
            JOIN finance_accounts fa ON ft.account_id = fa.id
            LEFT JOIN users u ON ft.created_by = u.id
                LEFT JOIN users uo ON ft.approved_by = uo.id
                LEFT JOIN users ur ON ft.rejected_by = ur.id
            LEFT JOIN expense_categories ec ON ft.category = ec.code";

    $conds = [];
    if ($from !== '') {
        $conds[] = "ft.tanggal >= '" . mysqli_real_escape_string($conn, $from) . "'";
    }
    if ($to !== '') {
        $conds[] = "ft.tanggal <= '" . mysqli_real_escape_string($conn, $to) . "'";
    }
    if ($account !== '') {
        $accountEsc = mysqli_real_escape_string($conn, $account);
        $conds[] = "fa.code = '$accountEsc'";
    }
    if ($direction !== '') {
        $dirEsc = mysqli_real_escape_string($conn, $direction);
        $conds[] = "ft.direction = '$dirEsc'";
    }
    if ($category !== '') {
        $catEsc = mysqli_real_escape_string($conn, $category);
        $conds[] = "ft.category = '$catEsc'";
    }
    if ($keyword !== '') {
        $kwEsc = mysqli_real_escape_string($conn, $keyword);
        $conds[] = "(ft.note LIKE '%$kwEsc%' OR ft.reference_type LIKE '%$kwEsc%' OR ft.reference_id LIKE '%$kwEsc%')";
    }

    $whereSql = '';
    if (count($conds) > 0) {
        $whereSql = " WHERE " . implode(' AND ', $conds);
        $sql .= $whereSql;
    }

    $countSql = "SELECT COUNT(*) as total
                 FROM finance_transactions ft
                 JOIN finance_accounts fa ON ft.account_id = fa.id" . $whereSql;
    $countRes = mysqli_query($conn, $countSql);
    $total = 0;
    if ($countRes) {
        $countRow = mysqli_fetch_assoc($countRes);
        $total = (int)($countRow['total'] ?? 0);
    }

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $sql .= " ORDER BY ft.tanggal DESC, ft.id DESC LIMIT $perPage OFFSET $offset";

    $res = mysqli_query($conn, $sql);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages
        ]
    ]);
}

elseif ($action === 'read_account_expenses') {
    $accountCode = trim($_GET['account_code'] ?? '');
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $direction = trim($_GET['direction'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $keyword = trim($_GET['keyword'] ?? '');
    if ($accountCode === '') {
        echo json_encode(['success' => false, 'message' => 'Kode akun wajib diisi']);
        exit;
    }

    $accountCodeEsc = mysqli_real_escape_string($conn, $accountCode);
    $conds = [
        "fa.code = '$accountCodeEsc'",
        // Transaksi memo (mis. EXP-SALES-DISCOUNT) sengaja tidak memengaruhi saldo akun ini,
        // jadi disembunyikan dari mutasi akun supaya tidak terlihat seolah memotong saldo.
        // Riwayatnya tetap bisa dilihat lewat kartu "Riwayat Diskon Penjualan".
        "ft.affects_balance = 1"
    ];

    // Add direction filter if specified
    if ($direction !== '') {
        $directionEsc = mysqli_real_escape_string($conn, $direction);
        $conds[] = "ft.direction = '$directionEsc'";
    }

    if ($from !== '') {
        $conds[] = "ft.tanggal >= '" . mysqli_real_escape_string($conn, $from) . "'";
    }
    if ($to !== '') {
        $conds[] = "ft.tanggal <= '" . mysqli_real_escape_string($conn, $to) . "'";
    }
    if ($status !== '') {
        $statusEsc = mysqli_real_escape_string($conn, $status);
        $conds[] = "ft.status = '$statusEsc'";
    }
    if ($keyword !== '') {
        $keywordEsc = mysqli_real_escape_string($conn, $keyword);
        $conds[] = "(ft.note LIKE '%$keywordEsc%' OR ft.reference_type LIKE '%$keywordEsc%' OR ft.category LIKE '%$keywordEsc%')";
    }

    $sql = "SELECT ft.id, ft.tanggal, ft.direction, ft.status, ft.category, ft.reference_type, ft.reference_id,
                   ft.note, ft.amount, ft.created_by, u.username as created_by_name,
                   fa.code as account_code, fa.name as account_name
            FROM finance_transactions ft
            JOIN finance_accounts fa ON ft.account_id = fa.id
            LEFT JOIN users u ON ft.created_by = u.id
            WHERE " . implode(' AND ', $conds) . "
            ORDER BY ft.tanggal DESC, ft.id DESC
            LIMIT 300";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat detail pengeluaran: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }

    echo json_encode(['success' => true, 'data' => $rows]);
}

// EXPORT EXCEL - Mutasi akun (cash/bank) sesuai filter yang sedang aktif di modal
elseif ($action === 'export_account_expenses_excel') {
    $accountCode = trim($_GET['account_code'] ?? '');
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $direction = trim($_GET['direction'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $keyword = trim($_GET['keyword'] ?? '');
    if ($accountCode === '') {
        echo json_encode(['success' => false, 'message' => 'Kode akun wajib diisi']);
        exit;
    }

    $accountCodeEsc = mysqli_real_escape_string($conn, $accountCode);
    $conds = [
        "fa.code = '$accountCodeEsc'",
        // Selaras dengan read_account_expenses: transaksi memo (EXP-SALES-DISCOUNT dkk) yang
        // tidak memengaruhi saldo akun ini tidak ikut diexport sebagai mutasi akun.
        "ft.affects_balance = 1"
    ];

    if ($direction !== '') {
        $directionEsc = mysqli_real_escape_string($conn, $direction);
        $conds[] = "ft.direction = '$directionEsc'";
    }
    if ($from !== '') {
        $conds[] = "ft.tanggal >= '" . mysqli_real_escape_string($conn, $from) . "'";
    }
    if ($to !== '') {
        $conds[] = "ft.tanggal <= '" . mysqli_real_escape_string($conn, $to) . "'";
    }
    if ($status !== '') {
        $statusEsc = mysqli_real_escape_string($conn, $status);
        $conds[] = "ft.status = '$statusEsc'";
    }
    if ($keyword !== '') {
        $keywordEsc = mysqli_real_escape_string($conn, $keyword);
        $conds[] = "(ft.note LIKE '%$keywordEsc%' OR ft.reference_type LIKE '%$keywordEsc%' OR ft.category LIKE '%$keywordEsc%')";
    }

    $sql = "SELECT ft.tanggal, fa.name as account_name, ft.direction, ft.status, ft.category,
                   ft.reference_type, ft.reference_id, u.username as created_by_name, ft.note, ft.amount
            FROM finance_transactions ft
            JOIN finance_accounts fa ON ft.account_id = fa.id
            LEFT JOIN users u ON ft.created_by = u.id
            WHERE " . implode(' AND ', $conds) . "
            ORDER BY ft.tanggal DESC, ft.id DESC
            LIMIT 5000";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Gagal export data: ' . mysqli_error($conn)]);
        exit;
    }

    $dirLabelMap = [
        'in' => 'Masuk',
        'out' => 'Keluar',
        'transfer_in' => 'Transfer Masuk',
        'transfer_out' => 'Transfer Keluar'
    ];

    $escapeCsvLine = function (array $fields): string {
        $escaped = array_map(function ($field) {
            $field = (string)$field;
            $field = str_replace('"', '""', $field);
            if (preg_match('/[",\n]/', $field)) {
                $field = '"' . $field . '"';
            }
            return $field;
        }, $fields);
        return implode(',', $escaped);
    };

    $lines = [];
    $lines[] = $escapeCsvLine(['Tanggal', 'Akun', 'Arah', 'Status', 'Kategori', 'Referensi', 'Dibuat Oleh', 'Catatan', 'Nominal']);

    while ($r = mysqli_fetch_assoc($res)) {
        $reference = ($r['reference_type'] ?: '-') . ($r['reference_id'] ? (' #' . $r['reference_id']) : '');
        $isOut = in_array($r['direction'], ['out', 'transfer_out'], true);
        $sign = $isOut ? '-' : '+';
        $lines[] = $escapeCsvLine([
            $r['tanggal'],
            $r['account_name'],
            $dirLabelMap[$r['direction']] ?? $r['direction'],
            $r['status'],
            $r['category'] ?: '-',
            $reference,
            $r['created_by_name'] ?: '-',
            $r['note'] ?: '-',
            $sign . 'Rp ' . number_format((float)$r['amount'], 0, ',', '.'),
        ]);
    }

    $csvContent = "\xEF\xBB\xBF" . implode("\r\n", $lines);
    $filename = 'mutasi_' . strtolower($accountCodeEsc) . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . strlen($csvContent));
    echo $csvContent;
    exit;
}

// RIWAYAT TRANSAKSI MEMO - dicatat untuk histori/audit, tapi sengaja TIDAK memengaruhi saldo akun
// (mis. EXP-SALES-DISCOUNT: diskon sudah tercermin di total invoice yang lebih kecil)
elseif ($action === 'read_balance_memo_transactions') {
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');

    $conds = ["ft.affects_balance = 0"];
    if ($from !== '') {
        $conds[] = "ft.tanggal >= '" . mysqli_real_escape_string($conn, $from) . "'";
    }
    if ($to !== '') {
        $conds[] = "ft.tanggal <= '" . mysqli_real_escape_string($conn, $to) . "'";
    }
    if ($keyword !== '') {
        $keywordEsc = mysqli_real_escape_string($conn, $keyword);
        $conds[] = "(ft.note LIKE '%$keywordEsc%' OR ft.reference_type LIKE '%$keywordEsc%' OR ft.category LIKE '%$keywordEsc%')";
    }

    $sql = "SELECT ft.id, ft.tanggal, ft.direction, ft.status, ft.category, ft.reference_type, ft.reference_id,
                   ft.note, ft.amount, u.username as created_by_name
            FROM finance_transactions ft
            LEFT JOIN users u ON ft.created_by = u.id
            WHERE " . implode(' AND ', $conds) . "
            ORDER BY ft.tanggal DESC, ft.id DESC
            LIMIT 300";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat riwayat: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    $total = 0.0;
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
        $total += (float)$r['amount'];
    }

    echo json_encode(['success' => true, 'data' => $rows, 'total' => $total]);
}

elseif ($action === 'read_pending_transactions') {
    if ($userRole !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang bisa melihat approval']);
        exit;
    }

    $sql = "SELECT ft.*, fa.name as account_name, u.username as created_by_name
            FROM finance_transactions ft
            JOIN finance_accounts fa ON ft.account_id = fa.id
            LEFT JOIN users u ON ft.created_by = u.id
            WHERE ft.status = 'pending'
            ORDER BY ft.created_at DESC, ft.id DESC
            LIMIT 300";
    $res = mysqli_query($conn, $sql);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }

    echo json_encode(['success' => true, 'data' => $rows]);
}

elseif ($action === 'approve_transaction') {
    if ($userRole !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang bisa approve']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID transaksi tidak valid']);
        exit;
    }

    $result = finance_approve_transaction($conn, $id, (int)$_SESSION['user_id']);
    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Transaksi berhasil di-approve']);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Gagal approve transaksi']);
    }
}

elseif ($action === 'reject_transaction') {
    if ($userRole !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang bisa reject']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID transaksi tidak valid']);
        exit;
    }

    $result = finance_reject_transaction($conn, $id, (int)$_SESSION['user_id']);
    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Transaksi berhasil di-reject']);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Gagal reject transaksi']);
    }
}

elseif ($action === 'create_operational_expense') {
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $expenseName = trim($_POST['expense_name'] ?? '');
    $categoryCode = trim($_POST['category_code'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $accountCode = $_POST['account_code'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if ($expenseName === '' || $categoryCode === '' || $amount <= 0 || $accountCode === '') {
        echo json_encode(['success' => false, 'message' => 'Data pengeluaran operasional tidak lengkap']);
        exit;
    }

    $qCat = mysqli_query($conn, "SELECT code, name FROM expense_categories WHERE code = '" . mysqli_real_escape_string($conn, $categoryCode) . "' AND is_active = 1 LIMIT 1");
    $cat = mysqli_fetch_assoc($qCat);
    if (!$cat) {
        echo json_encode(['success' => false, 'message' => 'Kategori pengeluaran tidak valid']);
        exit;
    }

    $account = finance_get_account_by_code($conn, $accountCode);
    if (!$account) {
        echo json_encode(['success' => false, 'message' => 'Akun sumber dana tidak valid']);
        exit;
    }

    // Khusus kategori OPL (jasa pihak ketiga): wajib isi nominal yang ditagih ke customer,
    // supaya Laba OPL (ditagih - dibayar ke pihak ketiga) bisa dihitung. Tidak dikaitkan ke SPK manapun.
    // "OPL" ditandai lewat CODE kategori (mis. "OPL JASA", "OPL PART") - kategori aktif yang
    // sebenarnya dipakai menandai lewat code, bukan name.
    $billedAmount = null;
    if (stripos($cat['code'], 'OPL') !== false) {
        $billedAmount = (float)($_POST['billed_amount'] ?? 0);
        if ($billedAmount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Pengeluaran kategori OPL wajib diisi nominal yang ditagih ke customer']);
            exit;
        }
    }

    $txStatus = ($userRole === 'Owner') ? 'approved' : 'pending';

    mysqli_begin_transaction($conn);
    try {
        $billedAmountSql = $billedAmount !== null ? (string)$billedAmount : 'NULL';
        $sql = "INSERT INTO operational_expenses (tanggal, expense_name, category_code, amount, billed_amount, account_id, note, created_by)
                VALUES ('" . mysqli_real_escape_string($conn, $tanggal) . "',
                        '" . mysqli_real_escape_string($conn, $expenseName) . "',
                        '" . mysqli_real_escape_string($conn, $cat['code']) . "',
                        $amount,
                        $billedAmountSql,
                        {$account['id']},
                        '" . mysqli_real_escape_string($conn, $note) . "',
                        " . (int)$_SESSION['user_id'] . ")";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal simpan operational expense: ' . mysqli_error($conn));
        }

        $expenseId = mysqli_insert_id($conn);

        $tx = finance_add_transaction(
            $conn,
            $tanggal,
            (int)$account['id'],
            'out',
            $cat['code'],
            $amount,
            'operational',
            $expenseId,
            $note !== '' ? $note : ('Pengeluaran ' . $cat['name'] . ': ' . $expenseName),
            (int)$_SESSION['user_id'],
            $txStatus
        );

        if (!$tx['success']) {
            throw new Exception($tx['message']);
        }

        mysqli_commit($conn);
        if ($txStatus === 'pending') {
            echo json_encode(['success' => true, 'message' => 'Pengeluaran operasional berhasil diajukan, menunggu approval Owner']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Pengeluaran operasional berhasil dicatat']);
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

elseif ($action === 'create_income') {
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $incomeName = trim($_POST['income_name'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $accountCode = $_POST['account_code'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if ($incomeName === '' || $amount <= 0 || $accountCode === '') {
        echo json_encode(['success' => false, 'message' => 'Data pemasukan tidak lengkap']);
        exit;
    }

    $account = finance_get_account_by_code($conn, $accountCode);
    if (!$account) {
        echo json_encode(['success' => false, 'message' => 'Akun tujuan tidak valid']);
        exit;
    }

    $tx = finance_add_transaction(
        $conn,
        $tanggal,
        (int)$account['id'],
        'in',
        'IN-MANUAL',
        $amount,
        'manual',
        null,
        $note !== '' ? $note : ('Pemasukan: ' . $incomeName),
        (int)$_SESSION['user_id'],
        'approved'
    );

    if ($tx['success']) {
        echo json_encode(['success' => true, 'message' => 'Pemasukan berhasil dicatat']);
    } else {
        echo json_encode(['success' => false, 'message' => $tx['message']]);
    }
}

elseif ($action === 'create_transfer') {
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $fromCode = $_POST['from_account_code'] ?? '';
    $toCode = $_POST['to_account_code'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($fromCode === '' || $toCode === '' || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data transfer tidak lengkap']);
        exit;
    }
    if ($fromCode === $toCode) {
        echo json_encode(['success' => false, 'message' => 'Akun asal dan tujuan tidak boleh sama']);
        exit;
    }

    $fromAcc = finance_get_account_by_code($conn, $fromCode);
    $toAcc = finance_get_account_by_code($conn, $toCode);
    if (!$fromAcc || !$toAcc) {
        echo json_encode(['success' => false, 'message' => 'Akun transfer tidak valid']);
        exit;
    }

    $txStatus = ($userRole === 'Owner') ? 'approved' : 'pending';

    mysqli_begin_transaction($conn);
    try {
        $out = finance_add_transaction(
            $conn,
            $tanggal,
            (int)$fromAcc['id'],
            'transfer_out',
            'TRF-OUT',
            $amount,
            'transfer',
            null,
            $note !== '' ? $note : ('Transfer ke ' . $toAcc['name']),
            (int)$_SESSION['user_id'],
            $txStatus
        );
        if (!$out['success']) {
            throw new Exception($out['message']);
        }

        $in = finance_add_transaction(
            $conn,
            $tanggal,
            (int)$toAcc['id'],
            'transfer_in',
            'TRF-IN',
            $amount,
            'transfer',
            null,
            $note !== '' ? $note : ('Transfer dari ' . $fromAcc['name']),
            (int)$_SESSION['user_id'],
            $txStatus
        );
        if (!$in['success']) {
            throw new Exception($in['message']);
        }

        mysqli_query($conn, "UPDATE finance_transactions SET reference_id = {$in['transaction_id']} WHERE id = {$out['transaction_id']}");
        mysqli_query($conn, "UPDATE finance_transactions SET reference_id = {$out['transaction_id']} WHERE id = {$in['transaction_id']}");

        mysqli_commit($conn);
        if ($txStatus === 'pending') {
            echo json_encode(['success' => true, 'message' => 'Transfer berhasil diajukan, menunggu approval Owner']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Transfer antar akun berhasil']);
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

elseif ($action === 'export_transactions_excel') {
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $account = $_GET['account'] ?? '';
    $direction = $_GET['direction'] ?? '';
    $category = $_GET['category'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');

    $sql = "SELECT ft.tanggal, fa.name as account_name, ft.direction, ft.status, ft.category,
                   ft.reference_type, ft.reference_id, u.username as created_by_name, ft.note, ft.amount,
                   ft.affects_balance
            FROM finance_transactions ft
            JOIN finance_accounts fa ON ft.account_id = fa.id
            LEFT JOIN users u ON ft.created_by = u.id";

    $conds = [];
    if ($from !== '') $conds[] = "ft.tanggal >= '" . mysqli_real_escape_string($conn, $from) . "'";
    if ($to !== '') $conds[] = "ft.tanggal <= '" . mysqli_real_escape_string($conn, $to) . "'";
    if ($account !== '') $conds[] = "fa.code = '" . mysqli_real_escape_string($conn, $account) . "'";
    if ($direction !== '') $conds[] = "ft.direction = '" . mysqli_real_escape_string($conn, $direction) . "'";
    if ($category !== '') $conds[] = "ft.category = '" . mysqli_real_escape_string($conn, $category) . "'";
    if ($keyword !== '') {
        $kwEsc = mysqli_real_escape_string($conn, $keyword);
        $conds[] = "(ft.note LIKE '%$kwEsc%' OR ft.reference_type LIKE '%$kwEsc%' OR ft.reference_id LIKE '%$kwEsc%')";
    }
    if (!empty($conds)) $sql .= " WHERE " . implode(' AND ', $conds);
    $sql .= " ORDER BY ft.tanggal DESC, ft.id DESC LIMIT 5000";

    $res = mysqli_query($conn, $sql);

    $dirLabelMap = ['in' => 'Masuk', 'out' => 'Keluar', 'transfer_in' => 'Transfer Masuk', 'transfer_out' => 'Transfer Keluar'];

    $escapeCsvLine = function(array $fields): string {
        $escaped = array_map(function($f) {
            $f = str_replace('"', '""', (string)$f);
            if (preg_match('/[",\n]/', $f)) $f = '"' . $f . '"';
            return $f;
        }, $fields);
        return implode(',', $escaped);
    };

    $lines = [];
    $lines[] = $escapeCsvLine(['Tanggal', 'Akun', 'Arah', 'Status', 'Kategori', 'Referensi', 'Dibuat Oleh', 'Catatan', 'Nominal']);
    while ($r = mysqli_fetch_assoc($res)) {
        $isMemo = ((int)($r['affects_balance'] ?? 1)) === 0;
        $isOut = in_array($r['direction'], ['out', 'transfer_out'], true);
        $reference = ($r['reference_type'] ?: '-') . ($r['reference_id'] ? (' #' . $r['reference_id']) : '');
        $sign = (!$isMemo && $isOut) ? '-' : '';
        $nominal = $sign . 'Rp ' . number_format((float)$r['amount'], 0, ',', '.');
        $lines[] = $escapeCsvLine([
            $r['tanggal'],
            $isMemo ? '—' : $r['account_name'],
            $isMemo ? 'Catat' : ($dirLabelMap[$r['direction']] ?? $r['direction']),
            $r['status'],
            $r['category'] ?: '-',
            $reference,
            $r['created_by_name'] ?: '-',
            $r['note'] ?: '-',
            $nominal,
        ]);
    }

    $csvContent = "\xEF\xBB\xBF" . implode("\r\n", $lines);
    $filename = 'histori_mutasi_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . strlen($csvContent));
    echo $csvContent;
    exit;
}

elseif ($action === 'export_transactions_pdf') {
    require_once '../vendor/autoload.php';
    
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $account = $_GET['account'] ?? '';
    $direction = $_GET['direction'] ?? '';
    $category = $_GET['category'] ?? '';
    $keyword = trim($_GET['keyword'] ?? '');

    $sql = "SELECT ft.*, fa.code as account_code, fa.name as account_name, u.username as created_by_name
            FROM finance_transactions ft
            JOIN finance_accounts fa ON ft.account_id = fa.id
            LEFT JOIN users u ON ft.created_by = u.id";

    $conds = [];
    if ($from !== '') {
        $conds[] = "ft.tanggal >= '" . mysqli_real_escape_string($conn, $from) . "'";
    }
    if ($to !== '') {
        $conds[] = "ft.tanggal <= '" . mysqli_real_escape_string($conn, $to) . "'";
    }
    if ($account !== '') {
        $accountEsc = mysqli_real_escape_string($conn, $account);
        $conds[] = "fa.code = '$accountEsc'";
    }
    if ($direction !== '') {
        $dirEsc = mysqli_real_escape_string($conn, $direction);
        $conds[] = "ft.direction = '$dirEsc'";
    }
    if ($category !== '') {
        $catEsc = mysqli_real_escape_string($conn, $category);
        $conds[] = "ft.category = '$catEsc'";
    }
    if ($keyword !== '') {
        $kwEsc = mysqli_real_escape_string($conn, $keyword);
        $conds[] = "(ft.note LIKE '%$kwEsc%' OR ft.reference_type LIKE '%$kwEsc%' OR ft.reference_id LIKE '%$kwEsc%')";
    }

    $whereSql = '';
    if (count($conds) > 0) {
        $whereSql = " WHERE " . implode(' AND ', $conds);
        $sql .= $whereSql;
    }

    $sql .= " ORDER BY ft.tanggal DESC, ft.id DESC LIMIT 1000";

    $res = mysqli_query($conn, $sql);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }

    $dirLabelMap = [
        'in' => 'Masuk',
        'out' => 'Keluar',
        'transfer_in' => 'Transfer Masuk',
        'transfer_out' => 'Transfer Keluar'
    ];

    $html = '<style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>';

    $html .= '<h3>Laporan Histori Mutasi</h3>';
    $html .= '<p>Tanggal Export: ' . date('d/m/Y H:i:s') . '</p>';
    
    $filterInfo = [];
    if ($from) $filterInfo[] = 'Dari: ' . $from;
    if ($to) $filterInfo[] = 'Sampai: ' . $to;
    if ($account) $filterInfo[] = 'Akun: ' . $account;
    if ($direction) $filterInfo[] = 'Arah: ' . ($dirLabelMap[$direction] ?? $direction);
    if ($category) $filterInfo[] = 'Kategori: ' . $category;
    if ($keyword) $filterInfo[] = 'Keyword: ' . $keyword;
    if (!empty($filterInfo)) {
        $html .= '<p><strong>Filter:</strong> ' . implode(' | ', $filterInfo) . '</p>';
    }

    $html .= '<table>';
    $html .= '<thead><tr>
        <th>Tanggal</th>
        <th>Akun</th>
        <th>Arah</th>
        <th>Kategori</th>
        <th>Referensi</th>
        <th>Catatan</th>
        <th class="text-right">Jumlah</th>
    </tr></thead>';
    $html .= '<tbody>';

    $totalIn = 0;
    $totalOut = 0;
    foreach ($rows as $row) {
        $isMemo = ((int)($row['affects_balance'] ?? 1)) === 0;
        $dirLabel = $isMemo ? 'Catat' : ($dirLabelMap[$row['direction']] ?? $row['direction']);
        $accountLabel = $isMemo ? '—' : ($row['account_name'] ?? '-');
        $amount = floatval($row['amount'] ?? 0);
        $reference = ($row['reference_type'] ?? '-') . ($row['reference_id'] ? (' #' . $row['reference_id']) : '');

        // Sum by direction only for balance-affecting transactions
        if (!$isMemo) {
            if (in_array($row['direction'], ['in', 'transfer_in'])) {
                $totalIn += $amount;
            } else {
                $totalOut += $amount;
            }
        }

        $html .= '<tr>
            <td>' . ($row['tanggal'] ?? '-') . '</td>
            <td>' . $accountLabel . '</td>
            <td>' . $dirLabel . '</td>
            <td>' . ($row['category'] ?? '-') . '</td>
            <td>' . $reference . '</td>
            <td>' . ($row['note'] ?? '-') . '</td>
            <td class="text-right">Rp ' . number_format($amount, 0, ',', '.') . '</td>
        </tr>';
    }

    $netBalance = $totalIn - $totalOut;
    $html .= '<tr style="background-color: #e8f5e9;">
        <td colspan="6" class="text-right"><strong>Total Masuk:</strong></td>
        <td class="text-right"><strong>Rp ' . number_format($totalIn, 0, ',', '.') . '</strong></td>
    </tr>';
    $html .= '<tr style="background-color: #ffebee;">
        <td colspan="6" class="text-right"><strong>Total Keluar:</strong></td>
        <td class="text-right"><strong>Rp ' . number_format($totalOut, 0, ',', '.') . '</strong></td>
    </tr>';
    $html .= '<tr style="background-color: #f0f0f0; font-weight: bold;">
        <td colspan="6" class="text-right">Saldo Akhir (Masuk - Keluar):</td>
        <td class="text-right">Rp ' . number_format($netBalance, 0, ',', '.') . '</td>
    </tr>';
    $html .= '</tbody></table>';

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 10,
        'margin_bottom' => 10,
    ]);
    
    $mpdf->WriteHTML($html);
    $filename = 'Histori_Mutasi_' . date('Y-m-d_His') . '.pdf';
    $mpdf->Output($filename, 'D');
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
