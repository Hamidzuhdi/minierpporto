<?php
session_start();
require_once '../config.php';

global $conn;

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CREATE - Tambah Kendaraan Baru
if ($action === 'create') {
    $customer_id = (int)$_POST['customer_id'];
    $nomor_polisi = strtoupper(trim($_POST['nomor_polisi']));
    $merk = trim($_POST['merk']);
    $model = trim($_POST['model']);
    $tahun = !empty($_POST['tahun']) ? (int)$_POST['tahun'] : null;
    $note = trim($_POST['note']);
    
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

// READ - Ambil Semua Kendaraan
elseif ($action === 'read') {
    $search = $_GET['search'] ?? '';
    $customer_id = $_GET['customer_id'] ?? '';
    
    $sql = "SELECT v.*, c.name as customer_name 
            FROM vehicles v
            JOIN customers c ON v.customer_id = c.id";
    
    $conditions = [];
    
    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(v.nomor_polisi LIKE '%$search%' OR v.merk LIKE '%$search%' OR c.name LIKE '%$search%')";
    }
    
    if (!empty($customer_id)) {
        $conditions[] = "v.customer_id = " . (int)$customer_id;
    }
    
    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY v.id DESC";
    
    $result = mysqli_query($conn, $sql);
    
    $vehicles = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $vehicles[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $vehicles]);
}

// READ ONE - Ambil Detail Kendaraan
elseif ($action === 'read_one') {
    $id = (int)$_GET['id'];
    
    $sql = "SELECT v.*, c.name as customer_name 
            FROM vehicles v
            JOIN customers c ON v.customer_id = c.id
            WHERE v.id = $id";
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Kendaraan tidak ditemukan']);
    }
}

// GET CUSTOMERS - Untuk dropdown
elseif ($action === 'get_customers') {
    $sql = "SELECT id, name, phone FROM customers ORDER BY name ASC";
    $result = mysqli_query($conn, $sql);
    
    $customers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $customers]);
}

// UPDATE - Edit Kendaraan
elseif ($action === 'update') {
    $id = (int)$_POST['id'];
    $customer_id = (int)$_POST['customer_id'];
    $nomor_polisi = strtoupper(trim($_POST['nomor_polisi']));
    $merk = trim($_POST['merk']);
    $model = trim($_POST['model']);
    $tahun = !empty($_POST['tahun']) ? (int)$_POST['tahun'] : null;
    $note = trim($_POST['note']);
    
    if (empty($nomor_polisi)) {
        echo json_encode(['success' => false, 'message' => 'Nomor polisi harus diisi']);
        exit;
    }
    
    // Cek nomor polisi sudah dipakai kendaraan lain
    $check = mysqli_query($conn, "SELECT id FROM vehicles WHERE nomor_polisi = '" . mysqli_real_escape_string($conn, $nomor_polisi) . "' AND id != $id");
    if (mysqli_num_rows($check) > 0) {
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
            WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kendaraan berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupdate kendaraan: ' . mysqli_error($conn)]);
    }
}

// DELETE - Hapus Kendaraan
elseif ($action === 'delete') {
    $id = (int)$_POST['id'];
    
    // Cek apakah ada SPK yang terkait
    $check = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM spk WHERE vehicle_id = $id");
    $row = mysqli_fetch_assoc($check);
    
    if ($row['cnt'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Kendaraan tidak dapat dihapus karena memiliki data SPK']);
        exit;
    }
    
    $sql = "DELETE FROM vehicles WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kendaraan berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus kendaraan: ' . mysqli_error($conn)]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
