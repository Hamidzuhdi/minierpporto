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
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserRole = (string)($_SESSION['role'] ?? 'Admin');

finance_ensure_default_accounts($conn);

function spk_insert_discount_history(
    mysqli $conn,
    int $spkId,
    string $actionType,
    float $requestedAmount,
    float $approvedAmount,
    ?string $note,
    ?int $actedBy,
    ?string $actedRole
): bool {
    $actionEsc = mysqli_real_escape_string($conn, $actionType);
    $noteEsc = mysqli_real_escape_string($conn, (string)$note);
    $roleEsc = mysqli_real_escape_string($conn, (string)$actedRole);
    $actedBySql = $actedBy ? (string)$actedBy : 'NULL';

    $sql = "INSERT INTO spk_discount_histories
            (spk_id, action_type, requested_amount, approved_amount, note, acted_by, acted_role)
            VALUES
            ($spkId, '$actionEsc', $requestedAmount, $approvedAmount, '$noteEsc', $actedBySql, '$roleEsc')";

    return mysqli_query($conn, $sql) !== false;
}

function spk_sync_active_invoice_totals(mysqli $conn, int $spkId): void
{
    if ($spkId <= 0) {
        return;
    }

    $invRes = mysqli_query(
        $conn,
        "SELECT id FROM invoices
         WHERE spk_id = $spkId
           AND status_piutang <> 'Tidak_Aktif'
         ORDER BY id DESC
         LIMIT 1"
    );
    $inv = $invRes ? mysqli_fetch_assoc($invRes) : null;
    if (!$inv) {
        return;
    }

    $invoiceId = (int)$inv['id'];

    // Use GENERATED subtotal column which respects custom_price
    $qItems = "SELECT COALESCE(SUM(si.subtotal), 0) AS biaya_sparepart
               FROM spk_items si
               WHERE si.spk_id = $spkId";
    
    $itemsRes = mysqli_query($conn, $qItems);
    $itemsRow = $itemsRes ? mysqli_fetch_assoc($itemsRes) : [];
    $biayaSparepart = (float)($itemsRow['biaya_sparepart'] ?? 0);

    $svcRes = mysqli_query($conn, "SELECT COALESCE(SUM(subtotal), 0) AS biaya_jasa FROM spk_services WHERE spk_id = $spkId");
    $svcRow = $svcRes ? mysqli_fetch_assoc($svcRes) : [];
    $biayaJasa = (float)($svcRow['biaya_jasa'] ?? 0);

    $discountAmount = 0.0;
    $discColRes = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'discount_amount'");
    $hasDiscountCol = $discColRes && mysqli_num_rows($discColRes) > 0;
    if ($hasDiscountCol) {
        $discRes = mysqli_query($conn, "SELECT COALESCE(discount_amount, 0) AS discount_amount FROM invoices WHERE id = $invoiceId LIMIT 1");
        $discRow = $discRes ? mysqli_fetch_assoc($discRes) : [];
        $discountAmount = max(0.0, (float)($discRow['discount_amount'] ?? 0));
    }

    $subtotal = $biayaSparepart + $biayaJasa;
    $total = max(0.0, $subtotal - $discountAmount);

    $approvalColRes = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'approval_status'");
    $hasApprovalCol = $approvalColRes && mysqli_num_rows($approvalColRes) > 0;
    $approvedCond = $hasApprovalCol ? " AND (approval_status = 'approved' OR approval_status IS NULL)" : '';
    $paidRes = mysqli_query(
        $conn,
        "SELECT COALESCE(SUM(amount), 0) AS total_paid, MAX(tanggal) AS last_paid
         FROM payments
         WHERE invoice_id = $invoiceId$approvedCond"
    );
    $paidRow = $paidRes ? mysqli_fetch_assoc($paidRes) : [];
    $totalPaid = (float)($paidRow['total_paid'] ?? 0);
    $lastPaid = $paidRow['last_paid'] ?? null;

    $status = 'Belum Bayar';
    $paidAtSql = 'NULL';
    if ($total > 0 && $totalPaid >= $total) {
        $status = 'Lunas';
        if (!empty($lastPaid)) {
            $paidAtSql = "'" . mysqli_real_escape_string($conn, $lastPaid) . "'";
        }
    } elseif ($totalPaid > 0) {
        $status = 'Sudah Dicicil';
    }

    if ($hasDiscountCol) {
        mysqli_query(
            $conn,
            "UPDATE invoices
             SET biaya_jasa = $biayaJasa,
                 biaya_sparepart = $biayaSparepart,
                 total = $total,
                 status_piutang = '$status',
                 paid_at = $paidAtSql
             WHERE id = $invoiceId"
        );
    } else {
        mysqli_query(
            $conn,
            "UPDATE invoices
             SET biaya_jasa = $biayaJasa,
                 biaya_sparepart = $biayaSparepart,
                 total = $total,
                 status_piutang = '$status',
                 paid_at = $paidAtSql
             WHERE id = $invoiceId"
        );
    }
}

// Support both legacy `stok` and new `current_stock` column names.
$stock_col_check = mysqli_query($conn, "SHOW COLUMNS FROM spareparts LIKE 'current_stock'");
$sparepart_stock_col = (mysqli_num_rows($stock_col_check) > 0) ? 'current_stock' : 'stok';

// Ensure status_spk enum includes Dibatalkan.
$status_col_res = mysqli_query($conn, "SHOW COLUMNS FROM spk LIKE 'status_spk'");
if ($status_col_res && ($status_col = mysqli_fetch_assoc($status_col_res))) {
    $status_type = (string)($status_col['Type'] ?? '');
    if (stripos($status_type, "'Dibatalkan'") === false) {
        @mysqli_query(
            $conn,
            "ALTER TABLE spk MODIFY COLUMN status_spk ENUM('Menunggu Konfirmasi','Disetujui','Dalam Pengerjaan','Selesai','Dikirim ke Owner','Buat Invoice','Sudah Cetak Invoice','Dibatalkan') DEFAULT 'Menunggu Konfirmasi'"
        );
    }
}

// Ensure kilometer column exists for SPK odometer tracking.
$kilometer_col_res = mysqli_query($conn, "SHOW COLUMNS FROM spk LIKE 'kilometer'");
if (!$kilometer_col_res || mysqli_num_rows($kilometer_col_res) === 0) {
    @mysqli_query($conn, "ALTER TABLE spk ADD COLUMN kilometer INT UNSIGNED NULL AFTER vehicle_id");
}

// Ensure nama_mekanik column exists for SPK mechanic info.
$mekanik_col_res = mysqli_query($conn, "SHOW COLUMNS FROM spk LIKE 'nama_mekanik'");
if (!$mekanik_col_res || mysqli_num_rows($mekanik_col_res) === 0) {
    @mysqli_query($conn, "ALTER TABLE spk ADD COLUMN nama_mekanik VARCHAR(100) NULL AFTER analisa_mekanik");
}

// Ensure invoices status supports Tidak_Aktif for revision flow.
$invoice_status_col_res = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'status_piutang'");
if ($invoice_status_col_res && ($invoice_status_col = mysqli_fetch_assoc($invoice_status_col_res))) {
    $invoice_status_type = (string)($invoice_status_col['Type'] ?? '');
    if (stripos($invoice_status_type, "'Tidak_Aktif'") === false) {
        @mysqli_query(
            $conn,
            "ALTER TABLE invoices MODIFY COLUMN status_piutang ENUM('Belum Bayar','Sudah Dicicil','Lunas','Tidak_Aktif') NOT NULL DEFAULT 'Belum Bayar'"
        );
    }
}

// CREATE - Buat SPK Baru
if ($action === 'create') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $tanggal = trim((string)($_POST['tanggal'] ?? ''));
    $keluhan_customer = trim((string)($_POST['keluhan_customer'] ?? ''));

    if ($vehicle_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Kendaraan wajib dipilih']);
        exit;
    }

    if ($tanggal === '') {
        echo json_encode(['success' => false, 'message' => 'Tanggal wajib diisi']);
        exit;
    }

    if ($keluhan_customer === '') {
        echo json_encode(['success' => false, 'message' => 'Keluhan customer wajib diisi']);
        exit;
    }

    // Always bind customer to the selected vehicle owner to avoid mismatches.
    $vehicleCheck = mysqli_query($conn, "SELECT customer_id FROM vehicles WHERE id = $vehicle_id LIMIT 1");
    $vehicleRow = $vehicleCheck ? mysqli_fetch_assoc($vehicleCheck) : null;
    if (!$vehicleRow) {
        echo json_encode(['success' => false, 'message' => 'Kendaraan tidak ditemukan']);
        exit;
    }
    $customer_id = (int)($vehicleRow['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Customer untuk kendaraan tidak valid']);
        exit;
    }
    
    // Generate kode unik reference based on selected tanggal and existing max suffix.
    $tanggalTs = strtotime($tanggal);
    if ($tanggalTs === false) {
        echo json_encode(['success' => false, 'message' => 'Format tanggal tidak valid']);
        exit;
    }

    $prefix = 'SPK';
    $date_code = date('Ymd', $tanggalTs);
    $kodePrefix = $prefix . '-' . $date_code . '-';
    $kodePrefixEsc = mysqli_real_escape_string($conn, $kodePrefix);
    $tanggalEsc = mysqli_real_escape_string($conn, $tanggal);
    $keluhanEsc = mysqli_real_escape_string($conn, $keluhan_customer);

    $kode_unik_reference = '';
    $spk_id = 0;
    $created = false;
    $lastError = '';

    // Retry a few times to handle simultaneous inserts safely.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $seqSql = "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(kode_unik_reference, '-', -1) AS UNSIGNED)), 0) AS max_seq
                   FROM spk
                   WHERE kode_unik_reference LIKE '{$kodePrefixEsc}%'";
        $seqRes = mysqli_query($conn, $seqSql);
        if (!$seqRes) {
            $lastError = mysqli_error($conn);
            break;
        }

        $seqRow = mysqli_fetch_assoc($seqRes);
        $urutan = ((int)($seqRow['max_seq'] ?? 0)) + 1;
        $kode_unik_reference = $kodePrefix . str_pad((string)$urutan, 4, '0', STR_PAD_LEFT);
        $kodeEsc = mysqli_real_escape_string($conn, $kode_unik_reference);

        $sql = "INSERT INTO spk (kode_unik_reference, customer_id, vehicle_id, tanggal, keluhan_customer, status_spk)
                VALUES ('$kodeEsc',
                        $customer_id,
                        $vehicle_id,
                        '$tanggalEsc',
                        '$keluhanEsc',
                        'Menunggu Konfirmasi')";

        if (mysqli_query($conn, $sql)) {
            $spk_id = mysqli_insert_id($conn);
            $created = true;
            break;
        }

        if (mysqli_errno($conn) !== 1062) {
            $lastError = mysqli_error($conn);
            break;
        }
    }

    if ($created) {
        echo json_encode([
            'success' => true,
            'message' => 'SPK berhasil dibuat',
            'kode_unik' => $kode_unik_reference,
            'spk_id' => $spk_id
        ]);
    } else {
        $errorMsg = $lastError !== '' ? $lastError : 'Kode SPK bentrok, silakan coba lagi';
        echo json_encode(['success' => false, 'message' => 'Gagal membuat SPK: ' . $errorMsg]);
    }
}

// READ - Ambil Semua SPK
elseif ($action === 'read') {
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $vehicle_id = $_GET['vehicle_id'] ?? '';
    $discount_flow = $_GET['discount_flow'] ?? '';
    $revision_filter = $_GET['revision_filter'] ?? '';
    
    $sql = "SELECT s.*, 
            c.name as customer_name, c.phone as customer_phone,
            v.nomor_polisi, v.merk, v.model
            FROM spk s
            JOIN customers c ON s.customer_id = c.id
            JOIN vehicles v ON s.vehicle_id = v.id";
    
    $conditions = [];
    
    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(s.kode_unik_reference LIKE '%$search%'
            OR (CASE
                    WHEN s.kode_unik_reference REGEXP '-REV[0-9]+$' THEN SUBSTRING_INDEX(s.kode_unik_reference, '-REV', 1)
                    ELSE s.kode_unik_reference
                END) LIKE '%$search%'
            OR c.name LIKE '%$search%'
            OR v.nomor_polisi LIKE '%$search%')";
    }
    
    if (!empty($status)) {
        $status = mysqli_real_escape_string($conn, $status);
        $conditions[] = "s.status_spk = '$status'";
    }
    
    if (!empty($vehicle_id)) {
        $vehicle_id = (int)$vehicle_id;
        $conditions[] = "s.vehicle_id = $vehicle_id";
    }

    if (!empty($discount_flow)) {
        $discount_flow = mysqli_real_escape_string($conn, $discount_flow);
        if ($discount_flow === 'attention') {
            $conditions[] = "LOWER(COALESCE(s.discount_status, 'none')) = 'pending'";
        } elseif ($discount_flow === 'has_request') {
            $conditions[] = "COALESCE(s.discount_amount_requested, 0) > 0";
        }
    }
    
    // Filter untuk revision
    if (!empty($revision_filter)) {
        if ($revision_filter === 'revisions') {
            // Show only SPK yang memiliki revision_of_spk_id (adalah revisi)
            $conditions[] = "s.revision_of_spk_id IS NOT NULL";
        } elseif ($revision_filter === 'has_revisions') {
            // Show only SPK original yang sudah memiliki revisions
            $conditions[] = "s.id IN (SELECT DISTINCT revision_of_spk_id FROM spk WHERE revision_of_spk_id IS NOT NULL)";
        }
    }
    
    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY s.id DESC";
    
    $result = mysqli_query($conn, $sql);
    
    $spks = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $spks[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $spks]);
}

// READ ONE - Detail SPK dengan Items
elseif ($action === 'read_one') {
    $id = (int)$_GET['id'];
    
    $sql = "SELECT s.*,
            c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
            v.nomor_polisi, v.merk, v.model, v.tahun
            FROM spk s
            JOIN customers c ON s.customer_id = c.id
            JOIN vehicles v ON s.vehicle_id = v.id
            WHERE s.id = $id";
    
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        // Get SPK items
        // Check if harga_satuan column exists after migration
        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'harga_satuan'");
        $has_price_cols = mysqli_num_rows($col_check) > 0;
        
        if ($has_price_cols) {
            // Use GENERATED column for subtotal - respects custom_price if set
            $sql_items = "SELECT si.*, sp.nama as sparepart_name, sp.satuan, sp.harga_jual_default,
                          COALESCE(NULLIF(si.harga_satuan, 0), sp.harga_jual_default) as harga_satuan_eff
                          FROM spk_items si
                          JOIN spareparts sp ON si.sparepart_id = sp.id
                          WHERE si.spk_id = $id";
        } else {
            $sql_items = "SELECT si.*, sp.nama as sparepart_name, sp.satuan, sp.harga_jual_default,
                          sp.harga_jual_default as harga_satuan_eff,
                          (si.qty * sp.harga_jual_default) as subtotal
                          FROM spk_items si
                          JOIN spareparts sp ON si.sparepart_id = sp.id
                          WHERE si.spk_id = $id";
        }
        $result_items = mysqli_query($conn, $sql_items);
        
        $items = [];
        while ($item = mysqli_fetch_assoc($result_items)) {
            $items[] = $item;
        }
        
        $row['items'] = $items;
        
        // Get SPK services
        $sql_services = "SELECT ss.*, sp.kode_jasa, sp.nama_jasa, sp.kategori
                         FROM spk_services ss
                         JOIN service_prices sp ON ss.service_price_id = sp.id
                         WHERE ss.spk_id = $id";
        $result_services = mysqli_query($conn, $sql_services);
        
        $services = [];
        while ($service = mysqli_fetch_assoc($result_services)) {
            $services[] = $service;
        }
        
        $row['services'] = $services;
        
        // Hitung total biaya jasa dari services
        $total_biaya_jasa = 0;
        foreach ($services as $svc) {
            $total_biaya_jasa += (float)($svc['subtotal'] ?? 0);
        }
        $row['biaya_jasa'] = $total_biaya_jasa;
        
        // Get warehouse requests
        $sql_warehouse = "SELECT wo.*, sp.nama as sparepart_name, u.username as requested_by_name
                         FROM warehouse_out wo
                         JOIN spareparts sp ON wo.sparepart_id = sp.id
                         LEFT JOIN users u ON wo.requested_by = u.id
                         WHERE wo.spk_id = $id
                         ORDER BY wo.id DESC";
        $result_warehouse = mysqli_query($conn, $sql_warehouse);
        
        $warehouse_requests = [];
        while ($wr = mysqli_fetch_assoc($result_warehouse)) {
            $warehouse_requests[] = $wr;
        }
        
        $row['warehouse_requests'] = $warehouse_requests;

        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'SPK tidak ditemukan']);
    }
}

// GET CUSTOMERS - Untuk dropdown
elseif ($action === 'get_customers') {
    $sql = "SELECT
                MIN(c.id) as id,
                MIN(c.name) as name,
                MIN(c.phone) as phone,
                GROUP_CONCAT(c.id ORDER BY c.id ASC) as customer_ids
            FROM customers c
            GROUP BY LOWER(TRIM(c.name)), COALESCE(TRIM(c.phone), '')
            ORDER BY MIN(c.name) ASC";
    $result = mysqli_query($conn, $sql);
    
    $customers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $customers]);
}

// GET VEHICLES BY CUSTOMER
elseif ($action === 'get_vehicles') {
    $customer_ids_raw = trim((string)($_GET['customer_ids'] ?? $_GET['customer_id'] ?? ''));
    $idParts = array_filter(array_map('trim', explode(',', $customer_ids_raw)), function ($v) {
        return $v !== '';
    });

    $ids = [];
    foreach ($idParts as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));

    if (count($ids) === 0) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $idsSql = implode(',', $ids);
    $sql = "SELECT id, customer_id, nomor_polisi, merk, model, tahun
            FROM vehicles
            WHERE customer_id IN ($idsSql)
            ORDER BY nomor_polisi ASC";
    $result = mysqli_query($conn, $sql);
    
    $vehicles = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $vehicles[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $vehicles]);
}

// GET ALL VEHICLES FOR FILTER (with customer name)
elseif ($action === 'get_all_vehicles') {
    $sql = "SELECT v.id, v.nomor_polisi, v.merk, v.model, c.name as customer_name 
            FROM vehicles v
            JOIN customers c ON v.customer_id = c.id
            ORDER BY c.name ASC, v.nomor_polisi ASC";
    $result = mysqli_query($conn, $sql);
    
    $vehicles = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $vehicles[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $vehicles]);
}

// UPDATE - Mekanik Input Analisa & Estimasi
elseif ($action === 'update_analisa') {
    $id = (int)$_POST['id'];
    $analisa_mekanik = trim($_POST['analisa_mekanik']);
    $nama_mekanik = trim((string)($_POST['nama_mekanik'] ?? ''));
    $service_description = trim($_POST['service_description']);
    $saran_service = trim($_POST['saran_service']);
    $kilometer_raw = trim((string)($_POST['kilometer'] ?? ''));
    $kilometer_normalized = str_replace(['.', ',', ' '], '', $kilometer_raw);

    if ($nama_mekanik === '') {
        echo json_encode(['success' => false, 'message' => 'Nama mekanik wajib diisi']);
        exit;
    }

    if ($kilometer_normalized === '' || !ctype_digit($kilometer_normalized)) {
        echo json_encode(['success' => false, 'message' => 'Kilometer wajib diisi angka']);
        exit;
    }

    $kilometer = (int)$kilometer_normalized;
    if ($kilometer < 0) {
        echo json_encode(['success' => false, 'message' => 'Kilometer tidak boleh negatif']);
        exit;
    }
    
    $sql = "UPDATE spk SET 
            analisa_mekanik = '" . mysqli_real_escape_string($conn, $analisa_mekanik) . "',
            nama_mekanik = '" . mysqli_real_escape_string($conn, $nama_mekanik) . "',
            service_description = '" . mysqli_real_escape_string($conn, $service_description) . "',
            saran_service = '" . mysqli_real_escape_string($conn, $saran_service) . "',
            kilometer = $kilometer
            WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Analisa & estimasi berhasil disimpan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . mysqli_error($conn)]);
    }
}

// SUBMIT DISCOUNT REQUEST (Admin)
elseif ($action === 'submit_discount') {
    if ($currentUserRole !== 'Admin') {
        echo json_encode(['success' => false, 'message' => 'Role Anda tidak memiliki akses flow diskon']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $discountAmount = (float)($_POST['discount_amount_requested'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak valid']);
        exit;
    }

    if ($discountAmount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Nominal diskon harus lebih dari 0']);
        exit;
    }

    $spkRes = mysqli_query($conn, "SELECT id, kode_unik_reference, status_spk, discount_status, discount_finance_tx_id FROM spk WHERE id = $id LIMIT 1");
    $spk = $spkRes ? mysqli_fetch_assoc($spkRes) : null;
    if (!$spk) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak ditemukan']);
        exit;
    }

    if (($spk['status_spk'] ?? '') === 'Dibatalkan') {
        echo json_encode(['success' => false, 'message' => 'SPK dibatalkan, diskon tidak dapat diajukan']);
        exit;
    }

    if (($spk['discount_status'] ?? '') === 'approved' && (int)($spk['discount_finance_tx_id'] ?? 0) > 0) {
        echo json_encode(['success' => false, 'message' => 'Diskon sudah di-ACC owner dan tercatat ke keuangan']);
        exit;
    }

    mysqli_begin_transaction($conn);
    try {
        $sqlUpdate = "UPDATE spk
                      SET discount_amount_requested = $discountAmount,
                          discount_amount_approved = 0,
                          discount_status = 'pending',
                          discount_reason = NULL,
                          discount_owner_note = NULL,
                          discount_requested_by = $currentUserId,
                          discount_requested_at = NOW(),
                          discount_reviewed_by = NULL,
                          discount_reviewed_at = NULL
                      WHERE id = $id";
        if (!mysqli_query($conn, $sqlUpdate)) {
            throw new Exception('Gagal menyimpan pengajuan diskon: ' . mysqli_error($conn));
        }

        $historyAction = (($spk['discount_status'] ?? 'none') === 'none') ? 'submit' : 'resubmit';
        if (!spk_insert_discount_history($conn, $id, $historyAction, $discountAmount, 0, null, $currentUserId, $currentUserRole)) {
            throw new Exception('Gagal menyimpan histori diskon: ' . mysqli_error($conn));
        }

        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Pengajuan diskon berhasil dikirim ke Owner']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// REVIEW DISCOUNT REQUEST (Owner)
elseif ($action === 'review_discount') {
    if ($currentUserRole !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang dapat review diskon']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak valid']);
        exit;
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        echo json_encode(['success' => false, 'message' => 'Keputusan review tidak valid']);
        exit;
    }

    $spkRes = mysqli_query($conn, "SELECT id, kode_unik_reference, discount_status, discount_amount_requested, discount_finance_tx_id FROM spk WHERE id = $id LIMIT 1");
    $spk = $spkRes ? mysqli_fetch_assoc($spkRes) : null;
    if (!$spk) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak ditemukan']);
        exit;
    }

    $discountStatus = (string)($spk['discount_status'] ?? 'none');
    $requestedAmount = (float)($spk['discount_amount_requested'] ?? 0);

    if (!in_array($discountStatus, ['pending', 'revision'], true)) {
        echo json_encode(['success' => false, 'message' => 'SPK ini tidak memiliki pengajuan diskon aktif']);
        exit;
    }

    if ($requestedAmount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Nominal pengajuan diskon tidak valid']);
        exit;
    }
    $approvedAmount = $requestedAmount;

    mysqli_begin_transaction($conn);
    try {
        if ($decision === 'approve') {
            if ((int)($spk['discount_finance_tx_id'] ?? 0) > 0) {
                throw new Exception('Diskon SPK ini sudah pernah diposting ke keuangan');
            }

            $account = finance_get_account_by_code($conn, 'cash');
            if (!$account) {
                throw new Exception('Akun kas tidak ditemukan');
            }

            $tx = finance_add_transaction(
                $conn,
                date('Y-m-d'),
                (int)$account['id'],
                'out',
                'EXP-SALES-DISCOUNT',
                $approvedAmount,
                'operational',
                $id,
                'Sales discount SPK #' . ($spk['kode_unik_reference'] ?? $id),
                $currentUserId,
                'approved',
                false // Diskon penjualan tetap dicatat untuk histori, tapi tidak memotong saldo kas (sudah tercermin di total invoice yang lebih kecil)
            );
            if (!$tx['success']) {
                throw new Exception($tx['message']);
            }

            $txId = (int)($tx['transaction_id'] ?? 0);
            if ($txId <= 0) {
                throw new Exception('ID transaksi keuangan tidak valid saat approval diskon');
            }
            $sqlUpdate = "UPDATE spk
                          SET discount_status = 'approved',
                              discount_amount_requested = $requestedAmount,
                              discount_amount_approved = $approvedAmount,
                              discount_owner_note = NULL,
                              discount_reviewed_by = $currentUserId,
                              discount_reviewed_at = NOW(),
                              discount_finance_tx_id = $txId
                          WHERE id = $id";
            if (!mysqli_query($conn, $sqlUpdate)) {
                throw new Exception('Gagal menyimpan approval diskon: ' . mysqli_error($conn));
            }

            if (!spk_insert_discount_history($conn, $id, 'approve', $requestedAmount, $approvedAmount, null, $currentUserId, $currentUserRole)) {
                throw new Exception('Gagal menyimpan histori approval: ' . mysqli_error($conn));
            }

            mysqli_commit($conn);
            echo json_encode(['success' => true, 'message' => 'Diskon berhasil di-ACC dan tercatat di keuangan']);
            exit;
        }

        $sqlUpdate = "UPDATE spk
                      SET discount_status = 'rejected',
                          discount_amount_approved = 0,
                          discount_owner_note = NULL,
                          discount_reviewed_by = $currentUserId,
                          discount_reviewed_at = NOW()
                      WHERE id = $id";
        if (!mysqli_query($conn, $sqlUpdate)) {
            throw new Exception('Gagal menolak diskon: ' . mysqli_error($conn));
        }

        if (!spk_insert_discount_history($conn, $id, 'reject', $requestedAmount, 0, null, $currentUserId, $currentUserRole)) {
            throw new Exception('Gagal menyimpan histori penolakan: ' . mysqli_error($conn));
        }

        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Pengajuan diskon ditolak']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// GET DISCOUNT HISTORY
elseif ($action === 'get_discount_history') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak valid']);
        exit;
    }

    $sql = "SELECT h.*, u.username as acted_by_name
            FROM spk_discount_histories h
            LEFT JOIN users u ON u.id = h.acted_by
            WHERE h.spk_id = $id
            ORDER BY h.id DESC";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat histori diskon: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $rows]);
}

// UPDATE STATUS SPK
elseif ($action === 'update_status') {
    $id = (int)$_POST['id'];
    $status = $_POST['status'];
    
    // Validasi status
    $valid_statuses = ['Menunggu Konfirmasi', 'Disetujui', 'Dalam Pengerjaan', 'Selesai', 'Dikirim ke Owner', 'Buat Invoice', 'Sudah Cetak Invoice', 'Sudah Reminder WA', 'Sudah FU', 'Dibatalkan'];
    if (!in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
        exit;
    }

    $spk_res = mysqli_query($conn, "SELECT status_spk FROM spk WHERE id = $id LIMIT 1");
    $spk_row = $spk_res ? mysqli_fetch_assoc($spk_res) : null;
    if (!$spk_row) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak ditemukan']);
        exit;
    }

    $old_status = (string)($spk_row['status_spk'] ?? '');
    $target_status = mysqli_real_escape_string($conn, $status);

    // Validasi: Sudah Reminder WA dan Sudah FU hanya bisa diakses dari "Sudah Cetak Invoice", "Sudah Reminder WA", atau "Sudah FU"
    $follow_up_statuses = ['Sudah Cetak Invoice', 'Sudah Reminder WA', 'Sudah FU'];
    if (in_array($status, ['Sudah Reminder WA', 'Sudah FU'])) {
        if (!in_array($old_status, $follow_up_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Status ' . $status . ' hanya bisa diubah dari status "Sudah Cetak Invoice", "Sudah Reminder WA", atau "Sudah FU"']);
            exit;
        }
    }

    // Jika dibatalkan, kembalikan stok sekali (idempotent) saat transisi ke Dibatalkan.
    if ($old_status !== 'Dibatalkan' && $status === 'Dibatalkan') {
        $restore_res = mysqli_query($conn, "SELECT sparepart_id, SUM(qty) as qty_total FROM spk_items WHERE spk_id = $id GROUP BY sparepart_id");
        if (!$restore_res) {
            echo json_encode(['success' => false, 'message' => 'Gagal membaca item SPK untuk pembatalan: ' . mysqli_error($conn)]);
            exit;
        }

        while ($it = mysqli_fetch_assoc($restore_res)) {
            $sparepart_id = (int)$it['sparepart_id'];
            $qty_total = (int)$it['qty_total'];
            mysqli_query($conn, "UPDATE spareparts SET {$sparepart_stock_col} = {$sparepart_stock_col} + $qty_total WHERE id = $sparepart_id");
        }
    }

    $sql = "UPDATE spk SET status_spk = '$target_status' WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Status SPK berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update status: ' . mysqli_error($conn)]);
    }
}

// ADD SERVICE TO SPK
elseif ($action === 'add_service') {
    $spk_id = (int)$_POST['spk_id'];
    $service_price_id = (int)$_POST['service_price_id'];
    $qty = (int)$_POST['qty'];
    $harga = (float)$_POST['harga'];
    
    $sql = "INSERT INTO spk_services (spk_id, service_price_id, qty, harga) 
            VALUES ($spk_id, $service_price_id, $qty, $harga)";
    
    if (mysqli_query($conn, $sql)) {
        spk_sync_active_invoice_totals($conn, $spk_id);
        echo json_encode(['success' => true, 'message' => 'Jasa service berhasil ditambahkan ke SPK']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan service: ' . mysqli_error($conn)]);
    }
}

// DELETE SERVICE FROM SPK
elseif ($action === 'delete_service') {
    $id = (int)$_POST['id'];
    $spk_id = 0;
    $svcRes = mysqli_query($conn, "SELECT spk_id FROM spk_services WHERE id = $id LIMIT 1");
    if ($svcRes && ($svcRow = mysqli_fetch_assoc($svcRes))) {
        $spk_id = (int)($svcRow['spk_id'] ?? 0);
    }
    
    $sql = "DELETE FROM spk_services WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        if ($spk_id > 0) {
            spk_sync_active_invoice_totals($conn, $spk_id);
        }
        echo json_encode(['success' => true, 'message' => 'Service berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus service: ' . mysqli_error($conn)]);
    }
}

// GET ALL SPAREPARTS FOR DROPDOWN
elseif ($action === 'get_all_spareparts') {
    $sql = "SELECT id, kode_sparepart, nama, satuan, {$sparepart_stock_col} as stok, harga_jual_default 
            FROM spareparts 
            WHERE {$sparepart_stock_col} > 0
            ORDER BY nama ASC";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Gagal memuat sparepart: ' . mysqli_error($conn)]);
        exit;
    }
    
    $spareparts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $spareparts[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $spareparts]);
}

// ADD SPAREPART TO SPK
elseif ($action === 'add_sparepart') {
    $spk_id = (int)$_POST['spk_id'];
    $sparepart_id = (int)$_POST['sparepart_id'];
    $qty = (int)$_POST['qty'];

    if ($spk_id <= 0 || $sparepart_id <= 0 || $qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Parameter tambah sparepart tidak valid']);
        exit;
    }

    $spk_exists = mysqli_query($conn, "SELECT id FROM spk WHERE id = $spk_id LIMIT 1");
    if (!$spk_exists || mysqli_num_rows($spk_exists) === 0) {
        echo json_encode(['success' => false, 'message' => 'SPK tidak ditemukan']);
        exit;
    }
    
    // Check stock availability + get price snapshot
    $check_stock = mysqli_query($conn, "SELECT {$sparepart_stock_col} as stok, harga_jual_default, harga_beli_default FROM spareparts WHERE id = $sparepart_id");
    $stock = mysqli_fetch_assoc($check_stock);

    if (!$stock) {
        echo json_encode(['success' => false, 'message' => 'Sparepart tidak ditemukan']);
        exit;
    }
    
    if ($stock['stok'] < $qty) {
        echo json_encode(['success' => false, 'message' => 'Stok tidak mencukupi. Stok tersedia: ' . $stock['stok']]);
        exit;
    }
    
    $harga_satuan = (float)$stock['harga_jual_default'];
    $hpp_satuan = (float)$stock['harga_beli_default'];
    
    // Check migration columns in spk_items and adapt insert query.
    $col_harga = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'harga_satuan'");
    $has_harga_col = $col_harga && mysqli_num_rows($col_harga) > 0;

    $col_hpp = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'hpp_satuan'");
    $has_hpp_col = $col_hpp && mysqli_num_rows($col_hpp) > 0;
    
    if ($has_harga_col && $has_hpp_col) {
        $sql = "INSERT INTO spk_items (spk_id, sparepart_id, qty, harga_satuan, hpp_satuan) 
                VALUES ($spk_id, $sparepart_id, $qty, $harga_satuan, $hpp_satuan)";
    } elseif ($has_harga_col) {
        $sql = "INSERT INTO spk_items (spk_id, sparepart_id, qty, harga_satuan) 
                VALUES ($spk_id, $sparepart_id, $qty, $harga_satuan)";
    } else {
        $sql = "INSERT INTO spk_items (spk_id, sparepart_id, qty) 
                VALUES ($spk_id, $sparepart_id, $qty)";
    }
    
    if (mysqli_query($conn, $sql)) {
        // Decrease stock immediately after item is added.
        mysqli_query($conn, "UPDATE spareparts SET {$sparepart_stock_col} = {$sparepart_stock_col} - $qty WHERE id = $sparepart_id");
        spk_sync_active_invoice_totals($conn, $spk_id);
        echo json_encode(['success' => true, 'message' => 'Sparepart berhasil ditambahkan ke SPK']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan sparepart: ' . mysqli_error($conn)]);
    }
}

// DELETE SPAREPART FROM SPK
elseif ($action === 'delete_sparepart') {
    $id = (int)$_POST['id'];
    
    // Get sparepart info to restore stock.
    $get_item = mysqli_query($conn, "SELECT spk_id, sparepart_id, qty FROM spk_items WHERE id = $id");
    $item = mysqli_fetch_assoc($get_item);
    
    if ($item) {
        $spk_id = (int)($item['spk_id'] ?? 0);
        // Restore stock.
        mysqli_query($conn, "UPDATE spareparts SET {$sparepart_stock_col} = {$sparepart_stock_col} + {$item['qty']} WHERE id = {$item['sparepart_id']}");
        
        // Delete from spk_items
        $sql = "DELETE FROM spk_items WHERE id = $id";
        
        if (mysqli_query($conn, $sql)) {
            if ($spk_id > 0) {
                spk_sync_active_invoice_totals($conn, $spk_id);
            }
            echo json_encode(['success' => true, 'message' => 'Sparepart berhasil dihapus']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus sparepart: ' . mysqli_error($conn)]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    }
}

// CREATE REVISION - Buat SPK revisi dari invoice yang sudah Sudah Cetak Invoice
elseif ($action === 'create_revision') {
    // Only Owner can create revisions
    if ($currentUserRole !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang bisa membuat revisi SPK']);
        exit;
    }

    $original_spk_id = (int)($_POST['original_spk_id'] ?? 0);
    
    if ($original_spk_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID SPK original tidak valid']);
        exit;
    }
    
    // Check migration columns untuk pricing di spk_items
    $col_harga_spk = mysqli_query($conn, "SHOW COLUMNS FROM spk_items LIKE 'harga_satuan'");
    $hasSpkItemPriceCol = $col_harga_spk && mysqli_num_rows($col_harga_spk) > 0;
    
    // Check migration columns untuk discount di invoices
    $col_discount_inv = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'discount_amount'");
    $hasInvoiceDiscountCol = $col_discount_inv && mysqli_num_rows($col_discount_inv) > 0;
    
    // Ambil data SPK original
    $original_spk_sql = "SELECT s.*, c.name as customer_name, v.nomor_polisi
                         FROM spk s
                         JOIN customers c ON s.customer_id = c.id
                         JOIN vehicles v ON s.vehicle_id = v.id
                         WHERE s.id = $original_spk_id LIMIT 1";
    $original_spk_result = mysqli_query($conn, $original_spk_sql);
    $original_spk = mysqli_fetch_assoc($original_spk_result);
    
    if (!$original_spk) {
        echo json_encode(['success' => false, 'message' => 'SPK original tidak ditemukan']);
        exit;
    }
    
    if ($original_spk['status_spk'] !== 'Sudah Cetak Invoice') {
        echo json_encode(['success' => false, 'message' => 'Hanya SPK dengan status "Sudah Cetak Invoice" yang bisa di-revisi']);
        exit;
    }
    
    // Gunakan root SPK agar urutan revisi konsisten (REV1, REV2, dst) walau source dari SPK revisi.
    $root_spk_id = (int)($original_spk['revision_of_spk_id'] ?? 0);
    if ($root_spk_id <= 0) {
        $root_spk_id = $original_spk_id;
    }

    // Tentukan revision_number berdasarkan seluruh chain revisi dari root.
    $max_rev_sql = "SELECT COALESCE(MAX(revision_number), 0) as max_rev
                    FROM spk
                    WHERE id = $root_spk_id OR revision_of_spk_id = $root_spk_id";
    $max_rev_result = mysqli_query($conn, $max_rev_sql);
    $max_rev_row = mysqli_fetch_assoc($max_rev_result);
    $next_revision_number = ((int)($max_rev_row['max_rev'] ?? 0)) + 1;
    
    // Generate kode SPK revisi: SPK-20260417-0006-REV1 atau SPK-20260417-0006-REV2
    // Extract base kode tanpa -REV suffix jika sudah ada
    $original_kode = $original_spk['kode_unik_reference'];
    if (preg_match('/^(.+)-REV\d+$/', $original_kode, $matches)) {
        // Sudah ada -REV suffix, ambil base kode
        $base_kode = $matches[1];
    } else {
        // Belum ada -REV suffix, ini adalah original SPK
        $base_kode = $original_kode;
    }
    $new_kode = $base_kode . '-REV' . $next_revision_number;
    $new_kode_esc = mysqli_real_escape_string($conn, $new_kode);
    $customer_id = (int)$original_spk['customer_id'];
    $vehicle_id = (int)$original_spk['vehicle_id'];
    
    // Buat SPK revisi baru dengan status langsung "Sudah Cetak Invoice"
    $insert_spk_sql = "INSERT INTO spk (kode_unik_reference, customer_id, vehicle_id, tanggal, keluhan_customer, 
                                       analisa_mekanik, nama_mekanik, service_description, saran_service,
                                       status_spk, revision_of_spk_id, revision_number, created_at)
                       SELECT '$new_kode_esc', customer_id, vehicle_id, CURDATE(), keluhan_customer,
                              analisa_mekanik, nama_mekanik, service_description, saran_service,
                      'Sudah Cetak Invoice', $root_spk_id, $next_revision_number, NOW()
                       FROM spk WHERE id = $original_spk_id";
    
    if (!mysqli_query($conn, $insert_spk_sql)) {
        echo json_encode(['success' => false, 'message' => 'Gagal membuat SPK revisi: ' . mysqli_error($conn)]);
        exit;
    }
    
    $new_spk_id = mysqli_insert_id($conn);
    
    // Copy semua items dari original SPK
    $items_sql = "INSERT INTO spk_items (spk_id, sparepart_id, qty, harga_satuan, hpp_satuan)
                  SELECT $new_spk_id, sparepart_id, qty, harga_satuan, hpp_satuan FROM spk_items 
                  WHERE spk_id = $original_spk_id";
    $items_result = mysqli_query($conn, $items_sql);
    if (!$items_result) {
        echo json_encode(['success' => false, 'message' => 'Gagal copy items: ' . mysqli_error($conn)]);
        exit;
    }
    
    // Copy semua services dari original SPK
    $services_sql = "INSERT INTO spk_services (spk_id, service_price_id, qty, harga)
                     SELECT $new_spk_id, service_price_id, qty, harga FROM spk_services 
                     WHERE spk_id = $original_spk_id";
    $services_result = mysqli_query($conn, $services_sql);
    if (!$services_result) {
        echo json_encode(['success' => false, 'message' => 'Gagal copy services: ' . mysqli_error($conn)]);
        exit;
    }
    
    // Auto-generate invoice baru untuk SPK revisi
    // Hitung total sparepart dari spk_items menggunakan GENERATED subtotal
    $sql_items = "SELECT SUM(si.subtotal) as biaya_sparepart
                  FROM spk_items si
                  WHERE si.spk_id = $new_spk_id";
    $result_items = mysqli_query($conn, $sql_items);
    $items_data = mysqli_fetch_assoc($result_items);
    $biaya_sparepart = (float)($items_data['biaya_sparepart'] ?? 0);
    
    // Hitung total jasa dari spk_services
    $sql_services = "SELECT SUM(subtotal) as biaya_jasa FROM spk_services WHERE spk_id = $new_spk_id";
    $result_services = mysqli_query($conn, $sql_services);
    $services_data = mysqli_fetch_assoc($result_services);
    $biaya_jasa = (float)($services_data['biaya_jasa'] ?? 0);
    
    $subtotal = $biaya_sparepart + $biaya_jasa;
    $discount_amount = 0; // Revisi tidak inherit discount dari original
    $total = $subtotal - $discount_amount;
    
    // Generate no_invoice unik
    $prefix = 'INV';
    $date_code = date('Ymd');
    $check = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM invoices WHERE DATE(tanggal) = CURDATE()");
    $row_count = mysqli_fetch_assoc($check);
    $urutan = $row_count['cnt'] + 1;
    $no_invoice = $prefix . '-' . $date_code . '-' . str_pad($urutan, 4, '0', STR_PAD_LEFT);
    
    // Insert invoice untuk SPK revisi
    if ($hasInvoiceDiscountCol) {
        $sql_invoice = "INSERT INTO invoices (spk_id, no_invoice, tanggal, biaya_jasa, biaya_sparepart, discount_amount, total, metode_pembayaran, status_piutang, note, user_id, created_at)
                        VALUES ($new_spk_id, '$no_invoice', CURDATE(), $biaya_jasa, $biaya_sparepart, $discount_amount, $total, 'cash', 'Belum Bayar', NULL, " . $currentUserId . ", NOW())";
    } else {
        $sql_invoice = "INSERT INTO invoices (spk_id, no_invoice, tanggal, biaya_jasa, biaya_sparepart, total, metode_pembayaran, status_piutang, note, user_id, created_at)
                        VALUES ($new_spk_id, '$no_invoice', CURDATE(), $biaya_jasa, $biaya_sparepart, $total, 'cash', 'Belum Bayar', NULL, " . $currentUserId . ", NOW())";
    }
    
    if (!mysqli_query($conn, $sql_invoice)) {
        echo json_encode(['success' => false, 'message' => 'Gagal membuat invoice revisi: ' . mysqli_error($conn)]);
        exit;
    }
    
    $invoice_id = mysqli_insert_id($conn);

    // Nonaktifkan invoice lama dari SPK original agar tidak dihitung di dashboard/laba.
    $inactive_note = mysqli_real_escape_string($conn, 'Dinonaktifkan otomatis karena revisi SPK ' . $new_kode);
    $sql_inactive_old_invoice = "UPDATE invoices
                                SET status_piutang = 'Tidak_Aktif',
                                    note = CONCAT(COALESCE(note, ''), '\n[INACTIVE] {$inactive_note}')
                                WHERE spk_id = $original_spk_id
                                  AND id <> $invoice_id
                                  AND status_piutang <> 'Tidak_Aktif'";
    mysqli_query($conn, $sql_inactive_old_invoice);
    
    // Auto-cleanup: Delete finance_transactions and payments for the old invoices
    $old_invoices_res = mysqli_query($conn, "SELECT id FROM invoices 
                                             WHERE spk_id = $original_spk_id 
                                             AND id <> $invoice_id 
                                             AND status_piutang = 'Tidak_Aktif'");
    if ($old_invoices_res) {
        while ($old_inv = mysqli_fetch_assoc($old_invoices_res)) {
            $old_inv_id = (int)$old_inv['id'];
            // Delete payments first (foreign key constraint)
            mysqli_query($conn, "DELETE FROM payments WHERE invoice_id = $old_inv_id");
            // Delete finance transactions
            mysqli_query($conn, "DELETE FROM finance_transactions WHERE reference_type='invoice' AND reference_id=$old_inv_id");
        }
    }
    
    // Auto-cancel original SPK (set status to Dibatalkan)
    $restore_res = mysqli_query($conn, "SELECT sparepart_id, SUM(qty) as qty_total FROM spk_items WHERE spk_id = $original_spk_id GROUP BY sparepart_id");
    if ($restore_res) {
        while ($it = mysqli_fetch_assoc($restore_res)) {
            $sparepart_id = (int)$it['sparepart_id'];
            $qty_total = (int)$it['qty_total'];
            mysqli_query($conn, "UPDATE spareparts SET {$sparepart_stock_col} = {$sparepart_stock_col} + $qty_total WHERE id = $sparepart_id");
        }
    }
    
    // Set original SPK status to Dibatalkan dan tambah note
    $cancel_msg = "Auto-dibatalkan karena ada SPK Revisi #{$new_kode}";
    $cancel_msg_esc = mysqli_real_escape_string($conn, $cancel_msg);
    $sql_cancel = "UPDATE spk SET status_spk = 'Dibatalkan', saran_service = CONCAT(COALESCE(saran_service, ''), '\n[REVISI: {$cancel_msg_esc}]') WHERE id = $original_spk_id";
    mysqli_query($conn, $sql_cancel);
    
    // Audit log
    $log_msg = "SPK Revisi #{$new_kode} dibuat untuk #{$original_kode} - {$original_spk['customer_name']} ({$original_spk['nomor_polisi']})";
    $sql_log = "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
                VALUES ({$currentUserId}, 'CREATE_REVISION', 'spk', $new_spk_id, '" . mysqli_real_escape_string($conn, $log_msg) . "', NOW())";
    mysqli_query($conn, $sql_log);
    
    echo json_encode([
        'success' => true,
        'message' => 'SPK revisi berhasil dibuat dan invoice otomatis digenerate',
        'spk_id' => $new_spk_id,
        'invoice_id' => $invoice_id,
        'no_invoice' => $no_invoice,
        'kode_spk' => $new_kode
    ]);
}

// DELETE SPK (hanya jika belum ada invoice)
elseif ($action === 'delete') {
    echo json_encode(['success' => false, 'message' => 'Hapus SPK dinonaktifkan. Gunakan status Dibatalkan.']);
}

// TOGGLE CUSTOM PRICE MODE
elseif ($action === 'toggle_custom_price') {
    $id = (int)$_POST['id'];
    $use_custom = (int)($_POST['use_custom'] ?? 0);
    
    // Update SPK flag
    $sql = "UPDATE spk SET use_custom_price = $use_custom WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        // When toggling OFF, also turn off custom pricing for all items & services in this SPK
        if ($use_custom == 0) {
            mysqli_query($conn, "UPDATE spk_items SET use_custom_price = 0 WHERE spk_id = $id");
            mysqli_query($conn, "UPDATE spk_services SET use_custom_price = 0 WHERE spk_id = $id");
        }
        // Sync invoice totals when toggling custom pricing
        spk_sync_active_invoice_totals($conn, $id);
        echo json_encode(['success' => true, 'message' => 'Mode harga khusus ' . ($use_custom ? 'diaktifkan' : 'dinonaktifkan')]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update mode harga: ' . mysqli_error($conn)]);
    }
}

// UPDATE CUSTOM PRICE FOR SPAREPART ITEM
elseif ($action === 'update_item_custom_price') {
    $item_id = (int)$_POST['item_id'];
    $harga_custom = (float)($_POST['harga_custom'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 0);
    
    if ($item_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Item ID tidak valid']);
        exit;
    }
    
    // Get item data
    $itemRes = mysqli_query($conn, "SELECT spk_id FROM spk_items WHERE id = $item_id LIMIT 1");
    $itemRow = $itemRes ? mysqli_fetch_assoc($itemRes) : null;
    if (!$itemRow) {
        echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan']);
        exit;
    }
    
    $spk_id = (int)$itemRow['spk_id'];
    
    // Update harga_custom ONLY - subtotal is GENERATED COLUMN, let DB calculate it
    $sql = "UPDATE spk_items SET harga_custom = $harga_custom, use_custom_price = 1 WHERE id = $item_id";
    if (mysqli_query($conn, $sql)) {
        // Sync invoice totals if exists
        spk_sync_active_invoice_totals($conn, $spk_id);
        echo json_encode(['success' => true, 'message' => 'Harga khusus berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update harga: ' . mysqli_error($conn)]);
    }
}

// UPDATE CUSTOM PRICE FOR SERVICE
elseif ($action === 'update_service_custom_price') {
    $service_id = (int)$_POST['service_id'];
    $harga_custom = (float)($_POST['harga_custom'] ?? 0);

    if ($service_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Service ID tidak valid']);
        exit;
    }

    // Get service data
    $svcRes = mysqli_query($conn, "SELECT spk_id FROM spk_services WHERE id = $service_id LIMIT 1");
    $svcRow = $svcRes ? mysqli_fetch_assoc($svcRes) : null;
    if (!$svcRow) {
        echo json_encode(['success' => false, 'message' => 'Service tidak ditemukan']);
        exit;
    }

    $spk_id = (int)$svcRow['spk_id'];

    // Update harga_custom ONLY - subtotal is GENERATED COLUMN, let DB calculate it
    $sql = "UPDATE spk_services SET harga_custom = $harga_custom, use_custom_price = 1 WHERE id = $service_id";
    if (mysqli_query($conn, $sql)) {
        // Sync invoice totals if exists
        spk_sync_active_invoice_totals($conn, $spk_id);
        echo json_encode(['success' => true, 'message' => 'Harga khusus jasa berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update harga: ' . mysqli_error($conn)]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
