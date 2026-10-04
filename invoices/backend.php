<?php
session_start();
require_once '../config.php';
require_once '../finance_helper.php';

global $conn;

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_role = $_SESSION['role'] ?? 'Admin';

finance_ensure_default_accounts($conn);

$spkItemPriceColRes = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'harga_satuan'");
$hasSpkItemPriceCol = $spkItemPriceColRes && mysqli_num_rows($spkItemPriceColRes) > 0;
$invoiceDiscountColRes = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'discount_amount'");
$hasInvoiceDiscountCol = $invoiceDiscountColRes && mysqli_num_rows($invoiceDiscountColRes) > 0;

function ensure_invoice_user_column(mysqli $conn): void {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'user_id'");
    if ($res && mysqli_num_rows($res) === 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN user_id INT NULL AFTER note");
    }

    $idxRes = mysqli_query($conn, "SHOW INDEX FROM invoices WHERE Key_name = 'idx_invoices_user_id'");
    if (!$idxRes || mysqli_num_rows($idxRes) === 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD INDEX idx_invoices_user_id (user_id)");
    }

    $fkRes = mysqli_query($conn, "
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'invoices'
          AND CONSTRAINT_NAME = 'fk_invoices_user'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        LIMIT 1
    ");
    if (!$fkRes || mysqli_num_rows($fkRes) === 0) {
        mysqli_query($conn, "
            ALTER TABLE invoices
            ADD CONSTRAINT fk_invoices_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON UPDATE CASCADE
            ON DELETE SET NULL
        ");
    }
}

function ensure_invoice_status_enum(mysqli $conn): void {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'status_piutang'");
    if ($res && ($col = mysqli_fetch_assoc($res))) {
        $type = (string)($col['Type'] ?? '');
        if (stripos($type, "'Tidak_Aktif'") === false) {
            @mysqli_query(
                $conn,
                "ALTER TABLE invoices MODIFY COLUMN status_piutang ENUM('Belum Bayar','Sudah Dicicil','Lunas','Tidak_Aktif') NOT NULL DEFAULT 'Belum Bayar'"
            );
        }
    }
}

function ensure_payment_approval_columns(mysqli $conn): void {
    $checks = [
        'approval_status' => "ALTER TABLE payments ADD COLUMN approval_status ENUM('approved','pending_create','pending_edit','pending_delete','rejected') NOT NULL DEFAULT 'approved' AFTER finance_amount",
        'approval_note' => "ALTER TABLE payments ADD COLUMN approval_note TEXT NULL AFTER approval_status",
        'requested_by' => "ALTER TABLE payments ADD COLUMN requested_by INT NULL AFTER approval_note",
        'requested_at' => "ALTER TABLE payments ADD COLUMN requested_at DATETIME NULL AFTER requested_by",
        'approved_by' => "ALTER TABLE payments ADD COLUMN approved_by INT NULL AFTER requested_at",
        'approved_at' => "ALTER TABLE payments ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
        'pending_amount' => "ALTER TABLE payments ADD COLUMN pending_amount DECIMAL(14,2) NULL AFTER approved_at",
        'pending_tanggal' => "ALTER TABLE payments ADD COLUMN pending_tanggal DATE NULL AFTER pending_amount",
        'pending_method' => "ALTER TABLE payments ADD COLUMN pending_method ENUM('cash','transfer') NULL AFTER pending_tanggal",
        'pending_note' => "ALTER TABLE payments ADD COLUMN pending_note TEXT NULL AFTER pending_method",
    ];

    foreach ($checks as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            mysqli_query($conn, $ddl);
        }
    }
}

function ensure_payment_attachments_table(mysqli $conn): void {
    $res = mysqli_query($conn, "SHOW TABLES LIKE 'payment_attachments'");
    if ($res && mysqli_num_rows($res) > 0) {
        return;
    }
    mysqli_query($conn, "
        CREATE TABLE payment_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment_attachments_payment (payment_id),
            CONSTRAINT fk_payment_attachments_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
}

// Folder fisik penyimpanan bukti pembayaran untuk 1 invoice (dibuat otomatis kalau belum ada)
function payment_attachments_dir(int $invoiceId): string {
    $dir = __DIR__ . '/../uploads/payments/' . $invoiceId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

// Baca file upload mentah, validasi ulang sebagai gambar asli, resize + kompres, simpan sebagai JPEG.
// Hasil re-encode ini juga jadi lapis keamanan: payload apa pun yang diselipkan di file asli ikut hilang
// karena yang disimpan adalah gambar baru dari data piksel, bukan salinan byte file upload.
function payment_attachment_process_upload(string $tmpPath, string $destAbsPath): array {
    $info = @getimagesize($tmpPath);
    if ($info === false) {
        return ['success' => false, 'message' => 'File bukan gambar yang valid'];
    }

    switch ($info['mime']) {
        case 'image/jpeg':
            $src = @imagecreatefromjpeg($tmpPath);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($tmpPath);
            break;
        case 'image/webp':
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false;
            break;
        default:
            return ['success' => false, 'message' => 'Format gambar tidak didukung (hanya JPG/PNG/WEBP)'];
    }
    if (!$src) {
        return ['success' => false, 'message' => 'Gagal membaca gambar'];
    }

    $width = imagesx($src);
    $height = imagesy($src);
    $maxDim = 1280;
    if ($width > $maxDim || $height > $maxDim) {
        $ratio = min($maxDim / $width, $maxDim / $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);
        $src = $resized;
    }

    // Flatten transparansi (PNG/WEBP) ke background putih sebelum di-encode jadi JPEG
    if (in_array($info['mime'], ['image/png', 'image/webp'], true)) {
        $w = imagesx($src);
        $h = imagesy($src);
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);
        $src = $flat;
    }

    $ok = imagejpeg($src, $destAbsPath, 80);
    imagedestroy($src);

    if (!$ok) {
        return ['success' => false, 'message' => 'Gagal menyimpan gambar'];
    }
    return ['success' => true, 'size' => filesize($destAbsPath)];
}

// Normalisasi struktur $_FILES['proof'] (multiple file input) jadi list flat per file
function collect_uploaded_proof_files(): array {
    $files = [];
    if (!isset($_FILES['proof']) || !is_array($_FILES['proof']['name'] ?? null)) {
        return $files;
    }
    foreach ($_FILES['proof']['name'] as $idx => $name) {
        if (($_FILES['proof']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => $name,
            'tmp_name' => $_FILES['proof']['tmp_name'][$idx],
            'error' => $_FILES['proof']['error'][$idx],
            'size' => $_FILES['proof']['size'][$idx],
        ];
    }
    return $files;
}

function approved_payment_where_clause(bool $hasApprovalCols, string $alias = 'p'): string {
    if (!$hasApprovalCols) {
        return '';
    }
    return " AND ($alias.approval_status = 'approved' OR $alias.approval_status IS NULL)";
}

function recalc_invoice_status(mysqli $conn, int $invoiceId, bool $hasApprovalCols): array {
    $approvedCond = approved_payment_where_clause($hasApprovalCols, 'p');
    $qInv = mysqli_query($conn, "SELECT total, status_piutang FROM invoices WHERE id = $invoiceId LIMIT 1");
    $inv = $qInv ? mysqli_fetch_assoc($qInv) : null;
    if (!$inv) {
        return ['success' => false, 'message' => 'Invoice tidak ditemukan'];
    }

    $qPaid = mysqli_query($conn, "SELECT COALESCE(SUM(p.amount), 0) total_paid FROM payments p WHERE p.invoice_id = $invoiceId $approvedCond");
    $paid = (float)(mysqli_fetch_assoc($qPaid)['total_paid'] ?? 0);
    $total = (float)$inv['total'];
    $sisa = $total - $paid;

    if ((string)($inv['status_piutang'] ?? '') === 'Tidak_Aktif') {
        return [
            'success' => true,
            'status' => 'Tidak_Aktif',
            'total_paid' => $paid,
            'sisa' => $sisa,
        ];
    }

    $newStatus = 'Belum Bayar';
    $paidAtSql = 'NULL';
    if ($sisa <= 0 && $total > 0) {
        $newStatus = 'Lunas';
        $qLastPaid = mysqli_query($conn, "SELECT MAX(p.tanggal) last_paid_date FROM payments p WHERE p.invoice_id = $invoiceId $approvedCond");
        $lastPaidDate = $qLastPaid ? (mysqli_fetch_assoc($qLastPaid)['last_paid_date'] ?? null) : null;
        if ($lastPaidDate) {
            $paidAtSql = "'" . mysqli_real_escape_string($conn, $lastPaidDate) . "'";
        }
    } elseif ($paid > 0) {
        $newStatus = 'Sudah Dicicil';
    }

    $ok = mysqli_query($conn, "UPDATE invoices SET status_piutang = '$newStatus', paid_at = $paidAtSql WHERE id = $invoiceId");
    if (!$ok) {
        return ['success' => false, 'message' => 'Gagal update status invoice: ' . mysqli_error($conn)];
    }

    return [
        'success' => true,
        'status' => $newStatus,
        'total_paid' => $paid,
        'sisa' => $sisa,
    ];
}

ensure_payment_approval_columns($conn);
ensure_invoice_user_column($conn);
ensure_invoice_status_enum($conn);
ensure_payment_attachments_table($conn);
$paymentApprovalColRes = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'approval_status'");
$hasPaymentApprovalCols = $paymentApprovalColRes && mysqli_num_rows($paymentApprovalColRes) > 0;

function has_payment_finance_columns(mysqli $conn): bool {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'finance_tx_id'");
    return $res && mysqli_num_rows($res) > 0;
}

// CREATE INVOICE - Owner/Admin bisa buat invoice
if ($action === 'create_invoice') {
    if (!in_array($user_role, ['Owner', 'Admin'], true)) {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner/Admin yang bisa membuat invoice']);
        exit;
    }
    
    $spk_id = (int)$_POST['spk_id'];
    
    // Validasi SPK status harus "Sudah Cetak Invoice"
    $sql_check = "SELECT s.*, s.status_spk, c.name as customer_name, v.nomor_polisi
                  FROM spk s
                  JOIN customers c ON s.customer_id = c.id
                  JOIN vehicles v ON s.vehicle_id = v.id
                  WHERE s.id = $spk_id";
    $result = mysqli_query($conn, $sql_check);
    $spk = mysqli_fetch_assoc($result);
    
    if (!$spk) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak ditemukan']);
        exit;
    }
    
    if ($spk['status_spk'] !== 'Sudah Cetak Invoice') {
        echo json_encode(['success' => false, 'message' => 'Invoice hanya bisa dibuat untuk SPK dengan status "Sudah Cetak Invoice"']);
        exit;
    }
    
    // Cek apakah SPK sudah punya invoice
    $check_invoice = mysqli_query($conn, "SELECT id FROM invoices WHERE spk_id = $spk_id");
    if (mysqli_num_rows($check_invoice) > 0) {
        echo json_encode(['success' => false, 'message' => 'SPK ini sudah memiliki invoice']);
        exit;
    }
    
    // Hitung total sparepart dari spk_items - pakai si.subtotal (GENERATED column yang sudah respect harga_custom)
    if ($hasSpkItemPriceCol) {
        $sql_items = "SELECT SUM(si.subtotal) as biaya_sparepart
                      FROM spk_items si
                      WHERE si.spk_id = $spk_id";
    } else {
        $sql_items = "SELECT SUM(si.qty * sp.harga_jual_default) as biaya_sparepart
                      FROM spk_items si
                      JOIN spareparts sp ON si.sparepart_id = sp.id
                      WHERE si.spk_id = $spk_id";
    }
    $result_items = mysqli_query($conn, $sql_items);
    $items_data = mysqli_fetch_assoc($result_items);
    $biaya_sparepart = (float)($items_data['biaya_sparepart'] ?? 0);
    
    // Hitung total jasa dari spk_services
    $sql_services = "SELECT SUM(subtotal) as biaya_jasa
                     FROM spk_services
                     WHERE spk_id = $spk_id";
    $result_services = mysqli_query($conn, $sql_services);
    $services_data = mysqli_fetch_assoc($result_services);
    $biaya_jasa = (float)($services_data['biaya_jasa'] ?? 0);
    
    $subtotal = $biaya_sparepart + $biaya_jasa;
    $discount_amount = (strtolower((string)($spk['discount_status'] ?? '')) === 'approved')
        ? (float)($spk['discount_amount_approved'] ?? 0)
        : 0;
    if ($discount_amount < 0) {
        $discount_amount = 0;
    }
    if ($discount_amount > $subtotal) {
        $discount_amount = $subtotal;
    }
    $total = $subtotal - $discount_amount;
    
    // Generate no_invoice unik
    $prefix = 'INV';
    $date_code = date('Ymd');
    $check = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM invoices WHERE DATE(tanggal) = CURDATE()");
    $row_count = mysqli_fetch_assoc($check);
    $urutan = $row_count['cnt'] + 1;
    $no_invoice = $prefix . '-' . $date_code . '-' . str_pad($urutan, 4, '0', STR_PAD_LEFT);
    
    // Insert invoice
    if ($hasInvoiceDiscountCol) {
        $sql = "INSERT INTO invoices (spk_id, no_invoice, tanggal, biaya_jasa, biaya_sparepart, discount_amount, total, metode_pembayaran, status_piutang, note, user_id, created_at)
                VALUES ($spk_id, '$no_invoice', CURDATE(), $biaya_jasa, $biaya_sparepart, $discount_amount, $total, 'cash', 'Belum Bayar', NULL, " . (int)$_SESSION['user_id'] . ", NOW())";
    } else {
        $sql = "INSERT INTO invoices (spk_id, no_invoice, tanggal, biaya_jasa, biaya_sparepart, total, metode_pembayaran, status_piutang, note, user_id, created_at)
                VALUES ($spk_id, '$no_invoice', CURDATE(), $biaya_jasa, $biaya_sparepart, $total, 'cash', 'Belum Bayar', NULL, " . (int)$_SESSION['user_id'] . ", NOW())";
    }
    
    if (mysqli_query($conn, $sql)) {
        $invoice_id = mysqli_insert_id($conn);
        
        // Audit log
        $log_msg = "Invoice #{$no_invoice} dibuat untuk SPK #{$spk_id} - {$spk['customer_name']} ({$spk['nomor_polisi']})";
        $sql_log = "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
                    VALUES ({$_SESSION['user_id']}, 'CREATE', 'invoices', $invoice_id, '" . mysqli_real_escape_string($conn, $log_msg) . "', NOW())";
        mysqli_query($conn, $sql_log);
        
        echo json_encode(['success' => true, 'message' => 'Invoice berhasil dibuat', 'invoice_id' => $invoice_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal membuat invoice: ' . mysqli_error($conn)]);
    }
}

// READ - Ambil semua invoice
elseif ($action === 'read') {
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $month = $_GET['month'] ?? '';

        $sql = "SELECT i.*, 
            s.id as spk_id, s.kode_unik_reference as spk_code, s.revision_number, s.status_spk,
            c.name as customer_name, c.phone as customer_phone,
            v.nomor_polisi, v.merk, v.model,
            u.username as invoice_created_by_name,
            u.role as invoice_created_by_role
            FROM invoices i
            JOIN spk s ON i.spk_id = s.id
            JOIN customers c ON s.customer_id = c.id
            JOIN vehicles v ON s.vehicle_id = v.id
            LEFT JOIN users u ON i.user_id = u.id";
    
    $conditions = [];
    
    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(i.id LIKE '%$search%'
            OR s.kode_unik_reference LIKE '%$search%'
            OR (CASE
                    WHEN s.kode_unik_reference REGEXP '-REV[0-9]+$' THEN SUBSTRING_INDEX(s.kode_unik_reference, '-REV', 1)
                    ELSE s.kode_unik_reference
                END) LIKE '%$search%'
            OR c.name LIKE '%$search%'
            OR v.nomor_polisi LIKE '%$search%')";
    }
    
    if (!empty($status)) {
        $status = mysqli_real_escape_string($conn, $status);
        $conditions[] = "i.status_piutang = '$status'";
    }

    if (!empty($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = mysqli_real_escape_string($conn, $month);
        $conditions[] = "DATE_FORMAT(i.tanggal, '%Y-%m') = '$month'";
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY i.id DESC";
    
    $result = mysqli_query($conn, $sql);
    $invoices = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Hitung total pembayaran
        $approvedCond = approved_payment_where_clause($hasPaymentApprovalCols, 'p');
        $sql_payments = "SELECT COALESCE(SUM(p.amount), 0) as total_paid FROM payments p WHERE p.invoice_id = {$row['id']} $approvedCond";
        $result_payments = mysqli_query($conn, $sql_payments);
        $payments_data = mysqli_fetch_assoc($result_payments);
        $row['total_paid'] = (float)$payments_data['total_paid'];
        $row['sisa_piutang'] = $row['total'] - $row['total_paid'];
        
        $invoices[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $invoices]);
}

// READ ONE - Detail invoice dengan items dan payments
elseif ($action === 'read_one') {
    $id = (int)$_GET['id'];
    
    // Get invoice header
        $sql = "SELECT i.*, 
            s.kode_unik_reference as spk_code, s.revision_number, s.tanggal as spk_tanggal, s.keluhan_customer,
            c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
            v.nomor_polisi, v.merk, v.model, v.tahun,
            u.username as invoice_created_by_name,
            u.role as invoice_created_by_role
            FROM invoices i
            JOIN spk s ON i.spk_id = s.id
            JOIN customers c ON s.customer_id = c.id
            JOIN vehicles v ON s.vehicle_id = v.id
            LEFT JOIN users u ON i.user_id = u.id
            WHERE i.id = $id";
    
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        // Get services
        $sql_services = "SELECT ss.*, sp.kode_jasa, sp.nama_jasa, sp.kategori
                         FROM spk_services ss
                         JOIN service_prices sp ON ss.service_price_id = sp.id
                         WHERE ss.spk_id = {$row['spk_id']}";
        $result_services = mysqli_query($conn, $sql_services);
        
        $services = [];
        while ($service = mysqli_fetch_assoc($result_services)) {
            $services[] = $service;
        }
        $row['services'] = $services;
        
        // Get sparepart items
        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'harga_satuan'");
        $has_price_cols = $col_check && mysqli_num_rows($col_check) > 0;
        if ($has_price_cols) {
            $sql_items = "SELECT si.*, sp.nama as sparepart_name, sp.satuan, sp.harga_jual_default,
                          COALESCE(NULLIF(si.harga_satuan, 0), sp.harga_jual_default) as harga_satuan_eff,
                          (si.qty * COALESCE(NULLIF(si.harga_satuan, 0), sp.harga_jual_default)) as subtotal
                          FROM spk_items si
                          JOIN spareparts sp ON si.sparepart_id = sp.id
                          WHERE si.spk_id = {$row['spk_id']}";
        } else {
            $sql_items = "SELECT si.*, sp.nama as sparepart_name, sp.satuan, sp.harga_jual_default,
                          sp.harga_jual_default as harga_satuan_eff,
                          (si.qty * sp.harga_jual_default) as subtotal
                          FROM spk_items si
                          JOIN spareparts sp ON si.sparepart_id = sp.id
                          WHERE si.spk_id = {$row['spk_id']}";
        }
        $result_items = mysqli_query($conn, $sql_items);
        
        $items = [];
        while ($item = mysqli_fetch_assoc($result_items)) {
            $items[] = $item;
        }
        $row['items'] = $items;
        
        // Get payments history
        $sql_payments = "SELECT p.*, 
                    ur.username as requested_by_name,
                    ua.username as approved_by_name
                 FROM payments p
                 LEFT JOIN users ur ON p.requested_by = ur.id
                 LEFT JOIN users ua ON p.approved_by = ua.id
                 WHERE p.invoice_id = $id
                 ORDER BY p.tanggal DESC, p.id DESC";
        $result_payments = mysqli_query($conn, $sql_payments);
        
        $payments = [];
        $total_paid = 0;
        while ($payment = mysqli_fetch_assoc($result_payments)) {
            $isApproved = !$hasPaymentApprovalCols || ($payment['approval_status'] === null || $payment['approval_status'] === 'approved');
            if ($isApproved) {
                $total_paid += (float)$payment['amount'];
            }
            $payments[] = $payment;
        }

        // Ambil bukti pembayaran (bisa lebih dari 1 foto per payment) dalam 1 query
        if (!empty($payments)) {
            $paymentIds = [];
            foreach ($payments as $p) {
                $paymentIds[] = (int)$p['id'];
            }
            $sql_att = "SELECT id, payment_id, file_path FROM payment_attachments WHERE payment_id IN (" . implode(',', $paymentIds) . ") ORDER BY id";
            $result_att = mysqli_query($conn, $sql_att);
            $attByPayment = [];
            while ($att = mysqli_fetch_assoc($result_att)) {
                $attByPayment[$att['payment_id']][] = [
                    'id' => (int)$att['id'],
                    'url' => '../uploads/' . $att['file_path'],
                ];
            }
            foreach ($payments as &$p) {
                $p['attachments'] = $attByPayment[$p['id']] ?? [];
            }
            unset($p);
        }

        $row['payments'] = $payments;
        $row['total_paid'] = $total_paid;
        $row['sisa_piutang'] = $row['total'] - $total_paid;
        
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan']);
    }
}

// CREATE PAYMENT - Input cicilan baru
elseif ($action === 'create_payment') {
    // Admin dan Owner bisa input pembayaran
    if ($user_role !== 'Owner' && $user_role !== 'Admin') {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses']);
        exit;
    }
    
    $invoice_id = (int)$_POST['invoice_id'];
    $amount = (float)$_POST['amount'];
    $tanggal = $_POST['payment_date'];
    $method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $note = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

    $accountCode = ($method === 'cash') ? 'cash' : 'bank';
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah pembayaran harus lebih dari 0']);
        exit;
    }
    
    // Get invoice data
    $sql_invoice = "SELECT total, status_piutang FROM invoices WHERE id = $invoice_id";
    $result_invoice = mysqli_query($conn, $sql_invoice);
    $invoice = mysqli_fetch_assoc($result_invoice);
    
    if (!$invoice) {
        echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan']);
        exit;
    }

    if (($invoice['status_piutang'] ?? '') === 'Tidak_Aktif') {
        echo json_encode(['success' => false, 'message' => 'Invoice tidak aktif, pembayaran baru tidak diizinkan']);
        exit;
    }
    
    // Hitung total pembayaran approved sebelumnya
    $approvedCond = approved_payment_where_clause($hasPaymentApprovalCols, 'p');
    $sql_paid = "SELECT COALESCE(SUM(p.amount), 0) as total_paid FROM payments p WHERE p.invoice_id = $invoice_id $approvedCond";
    $result_paid = mysqli_query($conn, $sql_paid);
    $paid_data = mysqli_fetch_assoc($result_paid);
    $total_paid = (float)$paid_data['total_paid'];
    
    $sisa = $invoice['total'] - $total_paid;
    
    if ($amount > $sisa) {
        echo json_encode(['success' => false, 'message' => "Jumlah pembayaran melebihi sisa piutang (Rp " . number_format($sisa, 0, ',', '.') . ")"]);
        exit;
    }

    // Bukti pembayaran opsional (boleh 0 foto), tapi kalau upload tetap dibatasi jumlah/ukuran/tipe
    $proofFiles = collect_uploaded_proof_files();
    $maxPhotos = 5;
    $maxFileSize = 5 * 1024 * 1024;

    if (count($proofFiles) > $maxPhotos) {
        echo json_encode(['success' => false, 'message' => "Maksimal $maxPhotos foto per pembayaran"]);
        exit;
    }
    foreach ($proofFiles as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload gagal untuk file: ' . $f['name']]);
            exit;
        }
        if ($f['size'] > $maxFileSize) {
            echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 5MB: ' . $f['name']]);
            exit;
        }
        $imgInfo = @getimagesize($f['tmp_name']);
        if ($imgInfo === false || !in_array($imgInfo['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            echo json_encode(['success' => false, 'message' => 'File harus berupa gambar JPG/PNG/WEBP: ' . $f['name']]);
            exit;
        }
    }

    $savedAttachmentPaths = [];

    mysqli_begin_transaction($conn);
    try {
        // Admin/Owner: langsung apply ke keuangan tanpa approval.
        $account = finance_get_account_by_code($conn, $accountCode);
        if (!$account) {
            throw new Exception('Akun keuangan tidak ditemukan');
        }

        $financeAmount = $amount;
        $txId = null;
        if ($financeAmount > 0) {
            $tx = finance_add_transaction(
                $conn,
                $tanggal,
                (int)$account['id'],
                'in',
                'IN-CUST-PAYMENT',
                $financeAmount,
                'invoice',
                $invoice_id,
                $note !== '' ? $note : ('Pembayaran invoice #' . $invoice_id),
                (int)$_SESSION['user_id']
            );
            if (!$tx['success']) {
                throw new Exception($tx['message']);
            }
            $txId = (int)$tx['transaction_id'];
        }

        if (has_payment_finance_columns($conn)) {
                $sql = "INSERT INTO payments (invoice_id, amount, tanggal, method, finance_account_id, finance_tx_id, finance_amount, note, approval_status, requested_by, requested_at, approved_by, approved_at, created_at)
                    VALUES ($invoice_id, $amount, '$tanggal', '$method', {$account['id']}, " . ($txId ?: 'NULL') . ", $financeAmount, '$note', 'approved', {$_SESSION['user_id']}, NOW(), {$_SESSION['user_id']}, NOW(), NOW())";
        } else {
            $sql = "INSERT INTO payments (invoice_id, amount, tanggal, method, note, created_at)
                    VALUES ($invoice_id, $amount, '$tanggal', '$method', '$note', NOW())";
        }

        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal mencatat pembayaran: ' . mysqli_error($conn));
        }

        $payment_id = mysqli_insert_id($conn);

        // Resize + kompres tiap foto, simpan ke disk, catat path-nya di DB
        $attachmentUrls = [];
        $uploadDir = payment_attachments_dir($invoice_id);
        foreach ($proofFiles as $idx => $f) {
            $filename = 'pay' . $payment_id . '_' . ($idx + 1) . '_' . bin2hex(random_bytes(4)) . '.jpg';
            $destAbsPath = $uploadDir . '/' . $filename;

            $proc = payment_attachment_process_upload($f['tmp_name'], $destAbsPath);
            if (!$proc['success']) {
                throw new Exception($proc['message']);
            }
            $savedAttachmentPaths[] = $destAbsPath;

            $relPath = "payments/$invoice_id/$filename";
            $escRelPath = mysqli_real_escape_string($conn, $relPath);
            $fileSize = (int)$proc['size'];
            if (!mysqli_query($conn, "INSERT INTO payment_attachments (payment_id, file_path, file_size, created_at) VALUES ($payment_id, '$escRelPath', $fileSize, NOW())")) {
                throw new Exception('Gagal menyimpan data bukti pembayaran: ' . mysqli_error($conn));
            }
            $attachmentUrls[] = '../uploads/' . $relPath;
        }

        $recalc = recalc_invoice_status($conn, $invoice_id, $hasPaymentApprovalCols);
        if (!$recalc['success']) {
            throw new Exception($recalc['message']);
        }

        $log_msg = "Pembayaran Rp " . number_format($amount, 0, ',', '.') . " untuk Invoice #$invoice_id via $method (langsung tercatat, " . count($proofFiles) . " foto bukti)";
        mysqli_query($conn, "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
                            VALUES ({$_SESSION['user_id']}, 'CREATE', 'payments', $payment_id, '" . mysqli_real_escape_string($conn, $log_msg) . "', NOW())");

        mysqli_commit($conn);

        echo json_encode([
            'success' => true,
            'message' => 'Pembayaran berhasil dicatat',
            'new_status' => $recalc['status'],
            'new_total_paid' => $recalc['total_paid'],
            'new_sisa' => $recalc['sisa'],
            'attachments' => $attachmentUrls
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        foreach ($savedAttachmentPaths as $p) {
            @unlink($p);
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// EDIT PAYMENT - Update cicilan
elseif ($action === 'edit_payment') {
    // Admin dan Owner bisa edit pembayaran
    if ($user_role !== 'Owner' && $user_role !== 'Admin') {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses']);
        exit;
    }
    
    $payment_id = (int)$_POST['payment_id'];
    $invoice_id = (int)$_POST['invoice_id'];
    $new_amount = (float)$_POST['amount'];
    $tanggal = $_POST['payment_date'];
    $method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $note = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    
    if ($new_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah pembayaran harus lebih dari 0']);
        exit;
    }
    
    // Get payment data lama
    $sql_payment = "SELECT amount FROM payments WHERE id = $payment_id";
    $result = mysqli_query($conn, $sql_payment);
    $payment = mysqli_fetch_assoc($result);
    
    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Pembayaran tidak ditemukan']);
        exit;
    }
    
    $old_amount = (float)$payment['amount'];
    
    // Get invoice data
    $sql_invoice = "SELECT total, status_piutang FROM invoices WHERE id = $invoice_id";
    $result_invoice = mysqli_query($conn, $sql_invoice);
    $invoice = mysqli_fetch_assoc($result_invoice);
    
    if (!$invoice) {
        echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan']);
        exit;
    }

    if (($invoice['status_piutang'] ?? '') === 'Tidak_Aktif') {
        echo json_encode(['success' => false, 'message' => 'Invoice tidak aktif, pembayaran tidak bisa diubah']);
        exit;
    }
    
    // Hitung total pembayaran approved (exclude yang sedang diedit)
    $approvedCond = approved_payment_where_clause($hasPaymentApprovalCols, 'p');
    $sql_paid = "SELECT COALESCE(SUM(p.amount), 0) as total_paid FROM payments p WHERE p.invoice_id = $invoice_id AND p.id != $payment_id $approvedCond";
    $result_paid = mysqli_query($conn, $sql_paid);
    $paid_data = mysqli_fetch_assoc($result_paid);
    $total_paid_others = (float)$paid_data['total_paid'];
    
    // Total jika payment ini diupdate
    $new_total_paid = $total_paid_others + $new_amount;
    $sisa = $invoice['total'] - $new_total_paid;
    
    if ($sisa < 0) {
        echo json_encode(['success' => false, 'message' => "Total pembayaran melebihi tagihan (Sisa akan menjadi negatif: Rp " . number_format(abs($sisa), 0, ',', '.') . ")"]);
        exit;
    }
    
    mysqli_begin_transaction($conn);
    try {
        $qPayMeta = mysqli_query($conn, "SELECT finance_tx_id, finance_amount FROM payments WHERE id = $payment_id LIMIT 1");
        $payMeta = $qPayMeta ? mysqli_fetch_assoc($qPayMeta) : null;
        if ($payMeta && !empty($payMeta['finance_tx_id'])) {
            $reverse = finance_reverse_transaction($conn, (int)$payMeta['finance_tx_id']);
            if (!$reverse['success']) {
                throw new Exception($reverse['message']);
            }
        } elseif ($payMeta && (float)($payMeta['finance_amount'] ?? 0) > 0) {
            // Legacy rows may not store finance_tx_id. Try to reverse matching old invoice-in row.
            $legacyAmount = (float)$payMeta['finance_amount'];
            $qLegacy = mysqli_query($conn, "SELECT id FROM finance_transactions
                                            WHERE reference_type = 'invoice'
                                              AND reference_id = $invoice_id
                                              AND direction = 'in'
                                              AND category = 'IN-CUST-SPAREPART'
                                              AND amount = $legacyAmount
                                            ORDER BY id DESC
                                            LIMIT 1");
            $legacyTx = $qLegacy ? mysqli_fetch_assoc($qLegacy) : null;
            if ($legacyTx && !empty($legacyTx['id'])) {
                $reverse = finance_reverse_transaction($conn, (int)$legacyTx['id']);
                if (!$reverse['success']) {
                    throw new Exception($reverse['message']);
                }
            }
        }

        $accountCode = ($method === 'cash') ? 'cash' : 'bank';
        $account = finance_get_account_by_code($conn, $accountCode);
        if (!$account) {
            throw new Exception('Akun keuangan tidak ditemukan');
        }

        // Cashflow masuk harus mengikuti nominal pembayaran aktual invoice.
        $financeAmount = $new_amount;
        $newTxId = null;
        if ($financeAmount > 0) {
            $tx = finance_add_transaction(
                $conn,
                $tanggal,
                (int)$account['id'],
                'in',
            'IN-CUST-PAYMENT',
                $financeAmount,
                'invoice',
                $invoice_id,
                $note !== '' ? $note : ('Edit pembayaran invoice #' . $invoice_id),
                (int)$_SESSION['user_id']
            );
            if (!$tx['success']) {
                throw new Exception($tx['message']);
            }
            $newTxId = (int)$tx['transaction_id'];
        }

        // Update payment
        if (has_payment_finance_columns($conn)) {
            $sql = "UPDATE payments
                    SET amount = $new_amount,
                        tanggal = '$tanggal',
                        method = '$method',
                        finance_account_id = {$account['id']},
                        finance_tx_id = " . ($newTxId ?: 'NULL') . ",
                        finance_amount = $financeAmount,
                        note = '$note',
                        updated_at = NOW()
                    WHERE id = $payment_id";
        } else {
            $sql = "UPDATE payments SET amount = $new_amount, tanggal = '$tanggal', method = '$method', note = '$note', updated_at = NOW() WHERE id = $payment_id";
        }

        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal mengupdate pembayaran: ' . mysqli_error($conn));
        }

        $recalc = recalc_invoice_status($conn, $invoice_id, $hasPaymentApprovalCols);
        if (!$recalc['success']) {
            throw new Exception($recalc['message']);
        }
        
        // Audit log
        $log_msg = "Pembayaran #$payment_id diupdate dari Rp " . number_format($old_amount, 0, ',', '.') . " menjadi Rp " . number_format($new_amount, 0, ',', '.') . " untuk Invoice #$invoice_id";
        $sql_log = "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
                    VALUES ({$_SESSION['user_id']}, 'UPDATE', 'payments', $payment_id, '" . mysqli_real_escape_string($conn, $log_msg) . "', NOW())";
        mysqli_query($conn, $sql_log);

        mysqli_commit($conn);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Pembayaran berhasil diupdate',
            'new_status' => $recalc['status'],
            'new_total_paid' => $recalc['total_paid'],
            'new_sisa' => $recalc['sisa']
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// DELETE PAYMENT - Hapus cicilan (hanya yang terakhir)
elseif ($action === 'delete_payment') {
    $payment_id = (int)$_POST['payment_id'];
    
    // Get payment data
    $sql_payment = "SELECT invoice_id, amount FROM payments WHERE id = $payment_id";
    $result = mysqli_query($conn, $sql_payment);
    $payment = mysqli_fetch_assoc($result);
    
    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Pembayaran tidak ditemukan']);
        exit;
    }
    
    $invoice_id = $payment['invoice_id'];
    $amount = (float)$payment['amount'];

    $invCheckRes = mysqli_query($conn, "SELECT status_piutang FROM invoices WHERE id = $invoice_id LIMIT 1");
    $invCheck = $invCheckRes ? mysqli_fetch_assoc($invCheckRes) : null;
    if (!$invCheck) {
        echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan']);
        exit;
    }
    if (($invCheck['status_piutang'] ?? '') === 'Tidak_Aktif') {
        echo json_encode(['success' => false, 'message' => 'Invoice tidak aktif, pembayaran tidak bisa dihapus']);
        exit;
    }

    // Ambil path file bukti pembayaran dulu (row-nya ikut terhapus otomatis lewat FK CASCADE saat payment dihapus)
    $attachmentsToDelete = [];
    $qAtt = mysqli_query($conn, "SELECT file_path FROM payment_attachments WHERE payment_id = $payment_id");
    if ($qAtt) {
        while ($attRow = mysqli_fetch_assoc($qAtt)) {
            $attachmentsToDelete[] = __DIR__ . '/../uploads/' . $attRow['file_path'];
        }
    }

    mysqli_begin_transaction($conn);
    try {
        $qPayMeta = mysqli_query($conn, "SELECT finance_tx_id, finance_amount FROM payments WHERE id = $payment_id LIMIT 1");
        $payMeta = $qPayMeta ? mysqli_fetch_assoc($qPayMeta) : null;
        if ($payMeta && !empty($payMeta['finance_tx_id'])) {
            $reverse = finance_reverse_transaction($conn, (int)$payMeta['finance_tx_id']);
            if (!$reverse['success']) {
                throw new Exception($reverse['message']);
            }
        } elseif ($payMeta && (float)($payMeta['finance_amount'] ?? 0) > 0) {
            // Legacy rows may not store finance_tx_id. Try to reverse matching old invoice-in row.
            $legacyAmount = (float)$payMeta['finance_amount'];
            $qLegacy = mysqli_query($conn, "SELECT id FROM finance_transactions
                                            WHERE reference_type = 'invoice'
                                              AND reference_id = $invoice_id
                                              AND direction = 'in'
                                              AND category = 'IN-CUST-SPAREPART'
                                              AND amount = $legacyAmount
                                            ORDER BY id DESC
                                            LIMIT 1");
            $legacyTx = $qLegacy ? mysqli_fetch_assoc($qLegacy) : null;
            if ($legacyTx && !empty($legacyTx['id'])) {
                $reverse = finance_reverse_transaction($conn, (int)$legacyTx['id']);
                if (!$reverse['success']) {
                    throw new Exception($reverse['message']);
                }
            }
        }

        // Delete payment
        $sql = "DELETE FROM payments WHERE id = $payment_id";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal menghapus pembayaran: ' . mysqli_error($conn));
        }

        $recalc = recalc_invoice_status($conn, $invoice_id, $hasPaymentApprovalCols);
        if (!$recalc['success']) {
            throw new Exception($recalc['message']);
        }
        
        // Audit log
        $log_msg = "Pembayaran Rp " . number_format($amount, 0, ',', '.') . " dihapus dari Invoice #$invoice_id";
        $sql_log = "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
                    VALUES ({$_SESSION['user_id']}, 'DELETE', 'payments', $payment_id, '" . mysqli_real_escape_string($conn, $log_msg) . "', NOW())";
        mysqli_query($conn, $sql_log);

        mysqli_commit($conn);

        // Hapus file fisik setelah DB commit sukses (kalau rollback, file lama tetap ada karena baris DB-nya juga tidak jadi terhapus)
        foreach ($attachmentsToDelete as $path) {
            @unlink($path);
        }

        echo json_encode(['success' => true, 'message' => 'Pembayaran berhasil dihapus']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

elseif ($action === 'approve_payment') {
    echo json_encode(['success' => false, 'message' => 'Flow approval pembayaran invoice dinonaktifkan. Simpan/Edit/Hapus langsung diproses.']);
}

elseif ($action === 'reject_payment') {
    echo json_encode(['success' => false, 'message' => 'Flow approval pembayaran invoice dinonaktifkan.']);
}

// AUTO CREATE INVOICES - Create invoices for all SPK ready
elseif ($action === 'auto_create_invoices') {
    if (!in_array($user_role, ['Owner', 'Admin'], true)) {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner/Admin yang bisa membuat invoice']);
        exit;
    }
    
    // Mulai transaction agar aman
    mysqli_begin_transaction($conn);
    
    // Get all SPK with status "Sudah Cetak Invoice" yang belum punya invoice
    $sql = "SELECT s.id, s.discount_status, s.discount_amount_approved FROM spk s
            WHERE s.status_spk = 'Sudah Cetak Invoice'
            AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.spk_id = s.id)";
    $result = mysqli_query($conn, $sql);
    
    $created_count = 0;
    $errors = [];
    $prefix = 'INV';
    $date_code = date('Ymd');
    
    while ($row = mysqli_fetch_assoc($result)) {
        $spk_id = $row['id'];
        
        // Hitung total sparepart - pakai si.subtotal (GENERATED column yang sudah respect harga_custom)
        if ($hasSpkItemPriceCol) {
            $sql_items = "SELECT SUM(si.subtotal) as biaya_sparepart
                          FROM spk_items si
                          WHERE si.spk_id = $spk_id";
        } else {
            $sql_items = "SELECT SUM(si.qty * sp.harga_jual_default) as biaya_sparepart
                          FROM spk_items si
                          JOIN spareparts sp ON si.sparepart_id = sp.id
                          WHERE si.spk_id = $spk_id";
        }
        $result_items = mysqli_query($conn, $sql_items);
        $items_data = mysqli_fetch_assoc($result_items);
        $biaya_sparepart = (float)($items_data['biaya_sparepart'] ?? 0);
        
        // Hitung total jasa
        $sql_services = "SELECT SUM(subtotal) as biaya_jasa
                         FROM spk_services
                         WHERE spk_id = $spk_id";
        $result_services = mysqli_query($conn, $sql_services);
        $services_data = mysqli_fetch_assoc($result_services);
        $biaya_jasa = (float)($services_data['biaya_jasa'] ?? 0);
        
        $subtotal = $biaya_sparepart + $biaya_jasa;
        $discount_amount = (strtolower((string)($row['discount_status'] ?? '')) === 'approved')
            ? (float)($row['discount_amount_approved'] ?? 0)
            : 0;
        if ($discount_amount < 0) {
            $discount_amount = 0;
        }
        if ($discount_amount > $subtotal) {
            $discount_amount = $subtotal;
        }
        $total = $subtotal - $discount_amount;
        
        // Generate no_invoice unik dengan cara yang AMAN
        // Ambil nomor terakhir untuk hari ini
        $sql_last = "SELECT no_invoice FROM invoices WHERE no_invoice LIKE '$prefix-$date_code-%' ORDER BY id DESC LIMIT 1 FOR UPDATE";
        $result_last = mysqli_query($conn, $sql_last);
        $last_row = mysqli_fetch_assoc($result_last);
        
        $last_number = 0;
        if ($last_row) {
            $parts = explode('-', $last_row['no_invoice']);
            $last_number = (int)end($parts);
        }
        
        $urutan = $last_number + 1;
        $no_invoice = $prefix . '-' . $date_code . '-' . str_pad($urutan, 4, '0', STR_PAD_LEFT);
        
        // Insert invoice
        if ($hasInvoiceDiscountCol) {
            $sql_insert = "INSERT INTO invoices (spk_id, no_invoice, tanggal, biaya_jasa, biaya_sparepart, discount_amount, total, metode_pembayaran, status_piutang, note, user_id, created_at)
                           VALUES ($spk_id, '$no_invoice', CURDATE(), $biaya_jasa, $biaya_sparepart, $discount_amount, $total, 'cash', 'Belum Bayar', NULL, " . (int)$_SESSION['user_id'] . ", NOW())";
        } else {
            $sql_insert = "INSERT INTO invoices (spk_id, no_invoice, tanggal, biaya_jasa, biaya_sparepart, total, metode_pembayaran, status_piutang, note, user_id, created_at)
                           VALUES ($spk_id, '$no_invoice', CURDATE(), $biaya_jasa, $biaya_sparepart, $total, 'cash', 'Belum Bayar', NULL, " . (int)$_SESSION['user_id'] . ", NOW())";
        }
        
        if (mysqli_query($conn, $sql_insert)) {
            $created_count++;
        } else {
            $errors[] = "SPK ID $spk_id: " . mysqli_error($conn);
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'message' => 'Gagal membuat invoice: ' . mysqli_error($conn)]);
            exit;
        }
    }
    
    mysqli_commit($conn);
    
    if ($created_count > 0) {
        $msg = "Berhasil membuat $created_count invoice otomatis";
        if (count($errors) > 0) {
            $msg .= " (dengan " . count($errors) . " error)";
        }
        echo json_encode(['success' => true, 'message' => $msg, 'created' => $created_count, 'errors' => $errors]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Tidak ada SPK yang siap dibuatkan invoice atau semua sudah punya invoice']);
    }
}

// GET SPK READY FOR INVOICE - SPK yang bisa dibuatkan invoice
elseif ($action === 'get_spk_ready') {
    // SPK dengan status "Sudah Cetak Invoice" yang belum punya invoice
    $sql = "SELECT s.id, s.kode_unik_reference, s.tanggal,
            c.name as customer_name, v.nomor_polisi
            FROM spk s
            JOIN customers c ON s.customer_id = c.id
            JOIN vehicles v ON s.vehicle_id = v.id
            WHERE s.status_spk = 'Sudah Cetak Invoice'
            AND s.id NOT IN (SELECT spk_id FROM invoices)
            ORDER BY s.id DESC";
    
    $result = mysqli_query($conn, $sql);
    $spks = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $spks[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $spks]);
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
