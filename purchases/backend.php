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

finance_ensure_default_accounts($conn);

// Ensure purchase tax and item discount columns exist for revised purchase flow.
$tax_col_res = mysqli_query($conn, "SHOW COLUMNS FROM purchases LIKE 'tax_amount'");
if (!$tax_col_res || mysqli_num_rows($tax_col_res) === 0) {
    @mysqli_query($conn, "ALTER TABLE purchases ADD COLUMN tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total");
}

$discount_col_res = mysqli_query($conn, "SHOW COLUMNS FROM purchase_items LIKE 'discount_amount'");
if (!$discount_col_res || mysqli_num_rows($discount_col_res) === 0) {
    @mysqli_query($conn, "ALTER TABLE purchase_items ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER harga_beli");
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CREATE - Tambah Purchase Baru
if ($action === 'create') {
    $supplier = trim($_POST['supplier'] ?? '');
    $tanggal = $_POST['tanggal'];
    $items_json = $_POST['items'];
    $items = json_decode($items_json, true);
    $tax_amount = (float)($_POST['tax_amount'] ?? 0);
    if ($tax_amount < 0) {
        $tax_amount = 0;
    }
    
    // Supplier wajib diisi oleh semua role.
    if (empty($supplier)) {
        echo json_encode(['success' => false, 'message' => 'Supplier wajib diisi']);
        exit;
    }
    
    if (empty($tanggal) || !is_array($items) || count($items) === 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        exit;
    }
    
    // Hitung total item setelah diskon per item.
    $total_item_after_discount = 0;
    foreach ($items as &$item) {
        $sparepart_id = (int)($item['sparepart_id'] ?? 0);
        $qty = max(0, (int)($item['qty'] ?? 0));
        if ($sparepart_id <= 0 || $qty <= 0) {
            echo json_encode(['success' => false, 'message' => 'Sparepart dan qty harus valid']);
            exit;
        }

        $item['sparepart_id'] = $sparepart_id;
        $item['qty'] = $qty;

        $harga_beli = max(0, (float)($item['harga_beli'] ?? 0));
        $line_base = $qty * $harga_beli;
        $discount_amount = max(0, (float)($item['discount_amount'] ?? 0));
        if ($discount_amount > $line_base) {
            $discount_amount = $line_base;
        }
        $line_subtotal = $line_base - $discount_amount;

        $item['harga_beli'] = $harga_beli;
        $item['discount_amount'] = $discount_amount;
        $item['subtotal'] = $line_subtotal;
        $total_item_after_discount += $line_subtotal;
    }
    unset($item); // CRITICAL: Break the reference to avoid bugs in next foreach

    $total = $total_item_after_discount + $tax_amount;

    mysqli_begin_transaction($conn);
    try {
        // Determine status based on user role
        $user_role = $_SESSION['role'] ?? 'Admin';
        $status = ($user_role === 'Owner') ? 'Approved' : 'Pending';
        
        // Purchase dibuat dengan status sesuai role: Admin=Pending, Owner=Approved
        // Stock tetap berkurang meskipun pending
        $sql = "INSERT INTO purchases (supplier, tanggal, total, tax_amount, status, is_paid, created_by) 
                VALUES ('" . mysqli_real_escape_string($conn, $supplier) . "',
                        '" . mysqli_real_escape_string($conn, $tanggal) . "',
                        $total,
                        $tax_amount,
                        '$status',
                        'Belum Bayar',
                        " . (int)$_SESSION['user_id'] . ")";

        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal menambahkan purchase: ' . mysqli_error($conn));
        }

        $purchase_id = mysqli_insert_id($conn);

        foreach ($items as $item) {
            $sparepart_id = (int)$item['sparepart_id'];
            $qty_baru = (int)$item['qty'];
            $harga_beli_baru = (float)$item['harga_beli'];
            $discount_amount = (float)($item['discount_amount'] ?? 0);
            $barcode = isset($item['barcode']) ? "'" . mysqli_real_escape_string($conn, $item['barcode']) . "'" : 'NULL';

            $sql_item = "INSERT INTO purchase_items (purchase_id, sparepart_id, qty, harga_beli, discount_amount, barcode) 
                        VALUES ($purchase_id, $sparepart_id, $qty_baru, $harga_beli_baru, $discount_amount, $barcode)";
            if (!mysqli_query($conn, $sql_item)) {
                throw new Exception('Gagal menambahkan item purchase: ' . mysqli_error($conn));
            }

            $sql_get_current = "SELECT current_stock, harga_beli_default FROM spareparts WHERE id = $sparepart_id LIMIT 1";
            $result_current = mysqli_query($conn, $sql_get_current);
            $current_data = $result_current ? mysqli_fetch_assoc($result_current) : null;
            if (!$current_data) {
                throw new Exception('Sparepart tidak ditemukan saat update stock');
            }

            $qty_lama = (int)($current_data['current_stock'] ?? 0);
            $harga_lama = (float)($current_data['harga_beli_default'] ?? 0);
            if (($qty_lama + $qty_baru) > 0) {
                $moving_average = (($qty_lama * $harga_lama) + ($qty_baru * $harga_beli_baru)) / ($qty_lama + $qty_baru);
            } else {
                $moving_average = $harga_beli_baru;
            }

            $sql_stock = "UPDATE spareparts
                          SET current_stock = current_stock + $qty_baru,
                              harga_beli_default = $moving_average
                          WHERE id = $sparepart_id";
            if (!mysqli_query($conn, $sql_stock)) {
                throw new Exception('Gagal update stock sparepart: ' . mysqli_error($conn));
            }
        }

        mysqli_commit($conn);
        echo json_encode([
            'success' => true,
            'message' => 'Purchase berhasil ditambahkan, stock langsung bertambah',
            'purchase_id' => $purchase_id
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// READ - Ambil Semua Purchase
elseif ($action === 'read') {
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    
    $sql = "SELECT DISTINCT p.*, u.username as created_by_name, fa.name as payment_account_name, fa.code as payment_account_code
            FROM purchases p
            LEFT JOIN users u ON p.created_by = u.id
            LEFT JOIN finance_accounts fa ON p.payment_account_id = fa.id
            LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
            LEFT JOIN spareparts s ON pi.sparepart_id = s.id";
    
    $conditions = [];
    
    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(p.supplier LIKE '%$search%' OR p.id LIKE '%$search%' OR s.kode_sparepart LIKE '%$search%')";
    }
    
    if (!empty($status)) {
        $status = mysqli_real_escape_string($conn, $status);
        if ($status === 'Sudah Bayar' || $status === 'Belum Bayar') {
            $conditions[] = "p.is_paid = '$status'";
        } else {
            $conditions[] = "p.status = '$status'";
        }
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
    
    echo json_encode(['success' => true, 'data' => $purchases]);
}

// READ ONE - Ambil Detail Purchase dengan Items
elseif ($action === 'read_one') {
    $id = (int)$_GET['id'];
    
    // Get purchase header
    $sql = "SELECT p.*, u.username as created_by_name 
            FROM purchases p
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.id = $id";
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        // Get purchase items
        $sql_items = "SELECT pi.*, s.nama as sparepart_name, s.satuan, s.kode_sparepart,
                  GREATEST((pi.qty * pi.harga_beli) - COALESCE(pi.discount_amount, 0), 0) as subtotal_calc
                      FROM purchase_items pi
                      JOIN spareparts s ON pi.sparepart_id = s.id
                      WHERE pi.purchase_id = $id";
        $result_items = mysqli_query($conn, $sql_items);
        
        $items = [];
        while ($item = mysqli_fetch_assoc($result_items)) {
            $item['subtotal'] = $item['subtotal_calc'];
            unset($item['subtotal_calc']);
            $items[] = $item;
        }
        
        $row['items'] = $items;
        
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Purchase tidak ditemukan']);
    }
}

// GET SUPPLIER HISTORY - Untuk datalist supplier pada create purchase
elseif ($action === 'get_supplier_history') {
    $sql = "SELECT supplier
            FROM purchases
            WHERE TRIM(COALESCE(supplier, '')) <> ''
              AND supplier <> 'Pending - Akan diisi Owner'
            GROUP BY supplier
            ORDER BY MAX(id) DESC
            LIMIT 100";
    $result = mysqli_query($conn, $sql);

    $suppliers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $suppliers[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $suppliers]);
}

// GET SPAREPARTS - Untuk dropdown
elseif ($action === 'get_spareparts') {
    $sql = "SELECT id, kode_sparepart, nama, satuan, harga_beli_default, current_stock FROM spareparts ORDER BY nama ASC";
    $result = mysqli_query($conn, $sql);
    
    $spareparts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $spareparts[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $spareparts]);
}

// GET FINANCE ACCOUNTS - Untuk pilihan pembayaran PO
elseif ($action === 'get_finance_accounts') {
    $result = mysqli_query($conn, "SELECT id, code, name, current_balance FROM finance_accounts WHERE is_active = 1 ORDER BY id ASC");
    $accounts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $accounts[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $accounts]);
}

// UPDATE PURCHASE - Owner isi supplier dan harga
elseif ($action === 'update_purchase') {
    $purchase_id = (int)$_POST['purchase_id'];
    $supplier = trim($_POST['supplier']);
    
    // Cek role harus owner
    $user_role = $_SESSION['role'] ?? 'Admin';
    if ($user_role !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang bisa update purchase ini']);
        exit;
    }
    
    if (empty($supplier)) {
        echo json_encode(['success' => false, 'message' => 'Supplier wajib diisi']);
        exit;
    }
    
    // Get items from POST
    $items = $_POST['items'] ?? [];
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Items tidak boleh kosong']);
        exit;
    }
    
    // Hitung total baru
    $total = 0;
    foreach ($items as $item) {
        $total += (int)$item['qty'] * (float)$item['harga_beli'];
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update purchase header
        $sql_update = "UPDATE purchases SET 
                      supplier = '" . mysqli_real_escape_string($conn, $supplier) . "',
                      total = $total
                      WHERE id = $purchase_id";
        mysqli_query($conn, $sql_update);
        
        // Update purchase items harga
        foreach ($items as $item) {
            $sql_item = "UPDATE purchase_items SET 
                        harga_beli = " . (float)$item['harga_beli'] . "
                        WHERE purchase_id = $purchase_id 
                        AND sparepart_id = " . (int)$item['sparepart_id'];
            mysqli_query($conn, $sql_item);
        }
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Purchase berhasil diupdate']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Gagal update purchase: ' . $e->getMessage()]);
    }
}

// UPDATE STATUS - Approve/Refund dengan update stock
elseif ($action === 'update_status') {
    $id = (int)$_POST['id'];
    $new_status = $_POST['status'];
    $user_role = $_SESSION['role'] ?? 'Admin';

    // Admin dan Owner bisa refund untuk purchase belum dibayar.
    // Untuk purchase yang sudah dibayar, hanya Owner yang bisa refund.
    if (!in_array($user_role, ['Admin', 'Owner'])) {
        echo json_encode(['success' => false, 'message' => 'Hanya Admin/Owner yang dapat refund purchase']);
        exit;
    }
    
    // Flow baru: hanya butuh aksi refund bila diperlukan.
    if ($new_status !== 'Refund') {
        echo json_encode(['success' => false, 'message' => 'Aksi status yang diizinkan hanya Refund']);
        exit;
    }
    
    // Get current purchase data
    $sql = "SELECT status, is_paid, total, payment_account_id FROM purchases WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    $purchase = mysqli_fetch_assoc($result);
    if (!$purchase) {
        echo json_encode(['success' => false, 'message' => 'Purchase tidak ditemukan']);
        exit;
    }
    $old_status = $purchase['status'];
    $was_paid = (($purchase['is_paid'] ?? '') === 'Sudah Bayar');

    if ($old_status === 'Refund') {
        echo json_encode(['success' => false, 'message' => 'Purchase sudah berstatus Refund']);
        exit;
    }

    if ($was_paid && $user_role !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang dapat refund purchase yang sudah dibayar']);
        exit;
    }
    
    // Get purchase items
    $sql_items = "SELECT sparepart_id, qty FROM purchase_items WHERE purchase_id = $id";
    $result_items = mysqli_query($conn, $sql_items);
    $items = [];
    while ($item = mysqli_fetch_assoc($result_items)) {
        $items[] = $item;
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update purchase status
        $sql_update = "UPDATE purchases SET status = 'Refund', is_paid = 'Belum Bayar', payment_account_id = NULL, paid_at = NULL, payment_note = NULL WHERE id = $id";
        if (!mysqli_query($conn, $sql_update)) {
            throw new Exception('Gagal update status purchase');
        }

        foreach ($items as $item) {
            $sql_stock = "UPDATE spareparts 
                         SET current_stock = GREATEST(current_stock - " . (int)$item['qty'] . ", 0)
                         WHERE id = " . (int)$item['sparepart_id'];
            if (!mysqli_query($conn, $sql_stock)) {
                throw new Exception('Gagal update stock saat refund');
            }
        }

        $message = 'Purchase berhasil di-refund dan stock telah dikurangi';
        if ($was_paid) {
            $accountId = (int)($purchase['payment_account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new Exception('Akun pembayaran purchase tidak ditemukan');
            }

            $account = finance_get_account_by_id($conn, $accountId);
            if (!$account) {
                throw new Exception('Akun pembayaran purchase tidak valid');
            }

            $tx = finance_add_transaction(
                $conn,
                date('Y-m-d'),
                $accountId,
                'in',
                'IN-REFUND-PO',
                (float)$purchase['total'],
                'purchase',
                $id,
                'Refund dari purchase #' . $id,
                (int)$_SESSION['user_id'],
                'approved'
            );

            if (!$tx['success']) {
                throw new Exception($tx['message']);
            }

            $message = 'Purchase berhasil di-refund, stock telah dikurangi, dan saldo akun telah ditambahkan';
        }
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Gagal update status: ' . $e->getMessage()]);
    }
}

// UPDATE PAYMENT STATUS
elseif ($action === 'update_payment') {
    $id = (int)$_POST['id'];
    $is_paid = $_POST['is_paid'];
    $user_role = $_SESSION['role'] ?? 'Admin';

    if ($user_role !== 'Owner') {
        echo json_encode(['success' => false, 'message' => 'Hanya Owner yang dapat memproses pembayaran purchase']);
        exit;
    }

    $payment_account_code = $_POST['payment_account_code'] ?? '';
    $payment_note = trim($_POST['payment_note'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');

    $qPurchase = mysqli_query($conn, "SELECT id, total, is_paid, status FROM purchases WHERE id = $id LIMIT 1");
    $purchase = mysqli_fetch_assoc($qPurchase);
    if (!$purchase) {
        echo json_encode(['success' => false, 'message' => 'Purchase tidak ditemukan']);
        exit;
    }

    mysqli_begin_transaction($conn);
    try {
        // Mark as paid: create finance out transaction
        if ($is_paid === 'Sudah Bayar') {
            if (($purchase['status'] ?? '') === 'Refund') {
                throw new Exception('Purchase berstatus Refund tidak bisa dibayar');
            }
            if ($purchase['is_paid'] === 'Sudah Bayar') {
                throw new Exception('Purchase ini sudah ditandai Sudah Bayar');
            }
            if (empty($payment_account_code)) {
                throw new Exception('Pilih sumber dana (Cash/Rekening)');
            }

            $account = finance_get_account_by_code($conn, $payment_account_code);
            if (!$account) {
                throw new Exception('Akun pembayaran tidak valid');
            }

            $tx = finance_add_transaction(
                $conn,
                $payment_date,
                (int)$account['id'],
                'out',
                'OUT-PO',
                (float)$purchase['total'],
                'purchase',
                $id,
                $payment_note !== '' ? $payment_note : 'Pengeluaran PO #' . $id,
                (int)$_SESSION['user_id'],
                'approved'
            );

            if (!$tx['success']) {
                throw new Exception($tx['message']);
            }

            $sql = "UPDATE purchases
                    SET is_paid = 'Sudah Bayar',
                        status = 'Approved',
                        payment_account_id = {$account['id']},
                        paid_at = NOW(),
                        payment_note = '" . mysqli_real_escape_string($conn, $payment_note) . "'
                    WHERE id = $id";
            if (!mysqli_query($conn, $sql)) {
                throw new Exception('Gagal update status pembayaran purchase');
            }
        }

        // Mark back to unpaid: reverse latest finance transaction for this purchase
        else {
            if ($purchase['is_paid'] !== 'Sudah Bayar') {
                throw new Exception('Purchase ini belum berstatus Sudah Bayar');
            }

            $qTx = mysqli_query($conn, "SELECT id FROM finance_transactions WHERE reference_type = 'purchase' AND reference_id = $id AND category = 'OUT-PO' ORDER BY id DESC LIMIT 1");
            $txRow = mysqli_fetch_assoc($qTx);
            if ($txRow) {
                $reverse = finance_reverse_transaction($conn, (int)$txRow['id']);
                if (!$reverse['success']) {
                    throw new Exception($reverse['message']);
                }
            }

            $sql = "UPDATE purchases
                    SET is_paid = 'Belum Bayar',
                        payment_account_id = NULL,
                        paid_at = NULL,
                        payment_note = NULL
                    WHERE id = $id";
            if (!mysqli_query($conn, $sql)) {
                throw new Exception('Gagal rollback status pembayaran purchase');
            }
        }

        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Status pembayaran berhasil diupdate']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// DELETE - Hapus Purchase (hanya jika Pending)
elseif ($action === 'delete') {
    $id = (int)$_POST['id'];
    
    // Cek status
    $check = mysqli_query($conn, "SELECT status FROM purchases WHERE id = $id");
    $purchase = mysqli_fetch_assoc($check);
    
    if ($purchase['status'] === 'Approved') {
        echo json_encode(['success' => false, 'message' => 'Purchase yang sudah di-approve tidak dapat dihapus. Gunakan Refund.']);
        exit;
    }
    
    // Delete purchase items first
    mysqli_query($conn, "DELETE FROM purchase_items WHERE purchase_id = $id");
    
    // Delete purchase
    $sql = "DELETE FROM purchases WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Purchase berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus purchase: ' . mysqli_error($conn)]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
