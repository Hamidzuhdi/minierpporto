<?php
session_start();
require_once '../config.php';
require_once '../followup_helper.php';

global $conn;

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

followup_ensure_table($conn);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CREATE - Tambah Customer Baru
if ($action === 'create') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    
    // Data kendaraan (opsional)
    $nomor_polisi = !empty($_POST['nomor_polisi']) ? strtoupper(trim($_POST['nomor_polisi'])) : '';
    $merk = trim($_POST['merk'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $tahun = !empty($_POST['tahun']) ? (int)$_POST['tahun'] : null;
    $note = trim($_POST['note'] ?? '');
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Nama customer harus diisi']);
        exit;
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Insert customer
        $sql = "INSERT INTO customers (name, phone, address) 
                VALUES ('" . mysqli_real_escape_string($conn, $name) . "',
                        '" . mysqli_real_escape_string($conn, $phone) . "',
                        '" . mysqli_real_escape_string($conn, $address) . "')";
        
        if (!mysqli_query($conn, $sql)) {
            throw new Exception('Gagal menambahkan customer: ' . mysqli_error($conn));
        }
        
        $customer_id = mysqli_insert_id($conn);
        
        // Insert vehicle jika ada nomor polisi
        if (!empty($nomor_polisi)) {
            // Cek nomor polisi sudah ada
            $check = mysqli_query($conn, "SELECT id FROM vehicles WHERE nomor_polisi = '" . mysqli_real_escape_string($conn, $nomor_polisi) . "'");
            if (mysqli_num_rows($check) > 0) {
                throw new Exception('Nomor polisi sudah terdaftar');
            }
            
            $sql_vehicle = "INSERT INTO vehicles (customer_id, nomor_polisi, merk, model, tahun, note) 
                            VALUES ($customer_id,
                                    '" . mysqli_real_escape_string($conn, $nomor_polisi) . "',
                                    '" . mysqli_real_escape_string($conn, $merk) . "',
                                    '" . mysqli_real_escape_string($conn, $model) . "',
                                    " . ($tahun ? $tahun : 'NULL') . ",
                                    '" . mysqli_real_escape_string($conn, $note) . "')";
            
            if (!mysqli_query($conn, $sql_vehicle)) {
                throw new Exception('Gagal menambahkan kendaraan: ' . mysqli_error($conn));
            }
        }
        
        mysqli_commit($conn);
        $message = 'Customer berhasil ditambahkan';
        if (!empty($nomor_polisi)) {
            $message .= ' beserta kendaraan';
        }
        echo json_encode(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// READ - Ambil Semua Customer
elseif ($action === 'read') {
    $search = $_GET['search'] ?? '';
    $reminder = $_GET['reminder'] ?? '0';
    
    $tahap = followup_tahap_from_filter($reminder);
    if ($tahap !== null) {
        // Mode reminder: jatuh tempo dihitung dari SPK terakhir, plus ringkasan follow-up tahap ini.
        $sql = "SELECT c.id, c.name, c.phone, c.address, c.created_at, ls.last_spk_id, ls.last_spk_at,
                       (SELECT COUNT(*) FROM customer_followups f WHERE f.spk_id = ls.last_spk_id AND f.tahap = '$tahap') AS fu_attempts,
                       (SELECT MAX(f.created_at) FROM customer_followups f WHERE f.spk_id = ls.last_spk_id AND f.tahap = '$tahap') AS fu_last_at
                FROM customers c
                JOIN (" . followup_last_spk_sql() . ") ls ON ls.customer_id = c.id";
    } else {
        $sql = "SELECT c.id, c.name, c.phone, c.address, c.created_at FROM customers c";
    }
    $conditions = [];
    
    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(c.name LIKE '%$search%' OR c.phone LIKE '%$search%')";
    }

    if ($tahap !== null) {
        $conditions[] = followup_due_condition_sql($tahap, 'ls');
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY c.id DESC";
    
    $result = mysqli_query($conn, $sql);
    
    $customers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Get vehicles for this customer
        $customer_id = $row['id'];
        $vehicles_sql = "SELECT id, nomor_polisi, merk, model, tahun FROM vehicles WHERE customer_id = $customer_id ORDER BY created_at DESC";
        $vehicles_result = mysqli_query($conn, $vehicles_sql);
        
        $vehicles = [];
        while ($vehicle = mysqli_fetch_assoc($vehicles_result)) {
            $vehicles[] = $vehicle;
        }
        
        $row['vehicles'] = $vehicles;

        // Total piutang aktif (invoice belum lunas) milik customer ini
        $piutang_sql = "SELECT COALESCE(SUM(i.total - COALESCE(p.total_bayar, 0)), 0) as piutang
                        FROM invoices i
                        JOIN spk s ON i.spk_id = s.id
                        LEFT JOIN (SELECT invoice_id, SUM(amount) as total_bayar FROM payments GROUP BY invoice_id) p ON i.id = p.invoice_id
                        WHERE s.customer_id = $customer_id
                          AND i.status_piutang != 'Lunas'
                          AND i.status_piutang != 'Tidak_Aktif'";
        $piutang_result = mysqli_query($conn, $piutang_sql);
        $row['total_hutang'] = $piutang_result ? (float)mysqli_fetch_assoc($piutang_result)['piutang'] : 0;

        $customers[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $customers]);
}

// READ ONE - Ambil Detail Customer
elseif ($action === 'read_one') {
    $id = (int)$_GET['id'];
    
    $sql = "SELECT id, name, phone, address, created_at FROM customers WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Customer tidak ditemukan']);
    }
}

// READ ONE WITH VEHICLES - Ambil Detail Customer + Kendaraan
elseif ($action === 'read_one_with_vehicles') {
    $id = (int)$_GET['id'];
    
    // Get customer data
    $sql = "SELECT id, name, phone, address, created_at FROM customers WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    
    if ($customer = mysqli_fetch_assoc($result)) {
        // Get vehicles
        $sql_vehicles = "SELECT id, nomor_polisi, merk, model, tahun, note FROM vehicles WHERE customer_id = $id ORDER BY created_at DESC";
        $result_vehicles = mysqli_query($conn, $sql_vehicles);
        
        $vehicles = [];
        while ($row = mysqli_fetch_assoc($result_vehicles)) {
            $vehicles[] = $row;
        }
        
        // Get SPK terakhir
        $sql_spk = "SELECT id, kode_unik_reference, tanggal, keluhan_customer, status_spk, created_at 
                    FROM spk 
                    WHERE customer_id = $id 
                      AND LOWER(COALESCE(status_spk, '')) <> 'dibatalkan'
                    ORDER BY created_at DESC 
                    LIMIT 1";
        $result_spk = mysqli_query($conn, $sql_spk);
        $last_spk = mysqli_fetch_assoc($result_spk);
        
        echo json_encode([
            'success' => true, 
            'data' => [
                'customer' => $customer,
                'vehicles' => $vehicles,
                'last_spk' => $last_spk,
                'last_invoice' => followup_last_invoice($conn, $id)
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Customer tidak ditemukan']);
    }
}

// READ VEHICLE - Ambil Detail Kendaraan by ID
elseif ($action === 'read_vehicle') {
    $vehicle_id = (int)($_GET['vehicle_id'] ?? 0);

    if ($vehicle_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Kendaraan tidak valid']);
        exit;
    }

    $sql = "SELECT id, customer_id, nomor_polisi, merk, model, tahun, note FROM vehicles WHERE id = $vehicle_id LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Kendaraan tidak ditemukan']);
    }
}

// UPDATE - Edit Customer
elseif ($action === 'update') {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Nama customer harus diisi']);
        exit;
    }
    
    $sql = "UPDATE customers SET 
            name = '" . mysqli_real_escape_string($conn, $name) . "',
            phone = '" . mysqli_real_escape_string($conn, $phone) . "',
            address = '" . mysqli_real_escape_string($conn, $address) . "'
            WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Customer berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupdate customer: ' . mysqli_error($conn)]);
    }
}

// ADD VEHICLE - Tambah Kendaraan untuk Customer Existing
elseif ($action === 'add_vehicle') {
    $customer_id = (int)$_POST['customer_id'];
    $nomor_polisi = strtoupper(trim($_POST['nomor_polisi']));
    $merk = trim($_POST['merk'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $tahun = !empty($_POST['tahun']) ? (int)$_POST['tahun'] : null;
    $note = trim($_POST['note'] ?? '');
    
    if (empty($nomor_polisi)) {
        echo json_encode(['success' => false, 'message' => 'Nomor polisi harus diisi']);
        exit;
    }
    
    // Cek nomor polisi sudah ada
    $check = mysqli_query($conn, "SELECT id FROM vehicles WHERE nomor_polisi = '" . mysqli_real_escape_string($conn, $nomor_polisi) . "'");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'Nomor polisi sudah terdaftar']);
        exit;
    }
    
    $sql = "INSERT INTO vehicles (customer_id, nomor_polisi, merk, model, tahun, note) 
            VALUES ($customer_id,
                    '" . mysqli_real_escape_string($conn, $nomor_polisi) . "',
                    '" . mysqli_real_escape_string($conn, $merk) . "',
                    '" . mysqli_real_escape_string($conn, $model) . "',
                    " . ($tahun ? $tahun : 'NULL') . ",
                    '" . mysqli_real_escape_string($conn, $note) . "')";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kendaraan berhasil ditambahkan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan kendaraan: ' . mysqli_error($conn)]);
    }
}

// UPDATE VEHICLE - Edit Kendaraan Customer
elseif ($action === 'update_vehicle') {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $nomor_polisi = strtoupper(trim((string)($_POST['nomor_polisi'] ?? '')));
    $merk = trim((string)($_POST['merk'] ?? ''));
    $model = trim((string)($_POST['model'] ?? ''));
    $tahun = !empty($_POST['tahun']) ? (int)$_POST['tahun'] : null;
    $note = trim((string)($_POST['note'] ?? ''));

    if ($vehicle_id <= 0 || $customer_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data kendaraan tidak valid']);
        exit;
    }

    if (empty($nomor_polisi)) {
        echo json_encode(['success' => false, 'message' => 'Nomor polisi harus diisi']);
        exit;
    }

    $exists = mysqli_query($conn, "SELECT id FROM vehicles WHERE id = $vehicle_id LIMIT 1");
    if (!$exists || mysqli_num_rows($exists) === 0) {
        echo json_encode(['success' => false, 'message' => 'Kendaraan tidak ditemukan']);
        exit;
    }

    $check = mysqli_query(
        $conn,
        "SELECT id FROM vehicles WHERE nomor_polisi = '" . mysqli_real_escape_string($conn, $nomor_polisi) . "' AND id != $vehicle_id"
    );
    if ($check && mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'Nomor polisi sudah terdaftar']);
        exit;
    }

    $sql = "UPDATE vehicles SET
            customer_id = $customer_id,
            nomor_polisi = '" . mysqli_real_escape_string($conn, $nomor_polisi) . "',
            merk = '" . mysqli_real_escape_string($conn, $merk) . "',
            model = '" . mysqli_real_escape_string($conn, $model) . "',
            tahun = " . ($tahun ? $tahun : 'NULL') . ",
            note = '" . mysqli_real_escape_string($conn, $note) . "'
            WHERE id = $vehicle_id";

    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kendaraan berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupdate kendaraan: ' . mysqli_error($conn)]);
    }
}

// ADD FOLLOWUP - Catat hasil follow-up customer untuk SPK terakhirnya
elseif ($action === 'add_followup') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $tahap = trim((string)($_POST['tahap'] ?? ''));
    $hasil = trim((string)($_POST['hasil'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($customer_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Customer tidak valid']);
        exit;
    }
    if (!isset(FOLLOWUP_TAHAP_INTERVAL[$tahap])) {
        echo json_encode(['success' => false, 'message' => 'Tahap follow-up tidak valid']);
        exit;
    }
    if (!in_array($hasil, FOLLOWUP_HASIL, true)) {
        echo json_encode(['success' => false, 'message' => 'Hasil follow-up wajib dipilih']);
        exit;
    }

    $lastRes = mysqli_query(
        $conn,
        "SELECT ls.last_spk_id
         FROM customers c
         JOIN (" . followup_last_spk_sql() . ") ls ON ls.customer_id = c.id
         WHERE c.id = $customer_id
         LIMIT 1"
    );
    $last = $lastRes ? mysqli_fetch_assoc($lastRes) : null;
    if (!$last) {
        echo json_encode(['success' => false, 'message' => 'Customer belum punya SPK yang bisa di-follow-up']);
        exit;
    }

    $sql = "INSERT INTO customer_followups (customer_id, spk_id, tahap, hasil, note, created_by)
            VALUES ($customer_id,
                    " . (int)$last['last_spk_id'] . ",
                    '" . mysqli_real_escape_string($conn, $tahap) . "',
                    '" . mysqli_real_escape_string($conn, $hasil) . "',
                    '" . mysqli_real_escape_string($conn, $note) . "',
                    " . (int)$_SESSION['user_id'] . ")";

    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Hasil follow-up berhasil dicatat']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mencatat follow-up: ' . mysqli_error($conn)]);
    }
}

// GET FOLLOWUPS - Riwayat follow-up satu customer
elseif ($action === 'get_followups') {
    $customer_id = (int)($_GET['customer_id'] ?? 0);

    $sql = "SELECT f.id, f.tahap, f.hasil, f.note, f.created_at, u.username AS created_by_name, s.kode_unik_reference
            FROM customer_followups f
            LEFT JOIN users u ON u.id = f.created_by
            LEFT JOIN spk s ON s.id = f.spk_id
            WHERE f.customer_id = $customer_id
            ORDER BY f.id DESC
            LIMIT 50";
    $result = mysqli_query($conn, $sql);

    $followups = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $followups[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => $followups,
        'last_invoice' => followup_last_invoice($conn, $customer_id)
    ]);
}

// DELETE - Hapus Customer
elseif ($action === 'delete') {
    $id = (int)$_POST['id'];
    
    // Cek apakah ada kendaraan yang terkait
    $check = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM vehicles WHERE customer_id = $id");
    $row = mysqli_fetch_assoc($check);
    
    if ($row['cnt'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Customer tidak dapat dihapus karena memiliki data kendaraan']);
        exit;
    }
    
    $sql = "DELETE FROM customers WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Customer berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus customer: ' . mysqli_error($conn)]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
