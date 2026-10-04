<?php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'read':
        read_services();
        break;
    case 'read_one':
        read_one_service();
        break;
    case 'read_active':
        read_active_services();
        break;
    case 'create':
        create_service();
        break;
    case 'update':
        update_service();
        break;
    case 'delete':
        delete_service();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function read_services() {
    global $conn;
    
    $query = "SELECT * FROM service_prices ORDER BY kategori, nama_jasa";
    $result = mysqli_query($conn, $query);
    
    $services = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $services[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $services]);
}

function read_one_service() {
    global $conn;
    
    $id = $_GET['id'] ?? 0;
    
    $query = "SELECT * FROM service_prices WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Service not found']);
    }
}

function read_active_services() {
    global $conn;
    
    $query = "SELECT * FROM service_prices WHERE is_active = 'Y' ORDER BY kategori, nama_jasa";
    $result = mysqli_query($conn, $query);
    
    $services = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $services[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $services]);
}

function create_service() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $kode_jasa = $data['kode_jasa'] ?? '';
    $nama_jasa = $data['nama_jasa'] ?? '';
    $harga = $data['harga'] ?? 0;
    $kategori = $data['kategori'] ?? 'Ringan';
    $is_active = $data['is_active'] ?? 'Y';
    
    // Validasi
    if (empty($kode_jasa) || empty($nama_jasa) || $harga <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        return;
    }
    
    // Check duplicate kode_jasa
    $check = mysqli_query($conn, "SELECT id FROM service_prices WHERE kode_jasa = '$kode_jasa'");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'Kode jasa sudah digunakan']);
        return;
    }
    
    $query = "INSERT INTO service_prices (kode_jasa, nama_jasa, harga, kategori, is_active) 
              VALUES (?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'ssdss', $kode_jasa, $nama_jasa, $harga, $kategori, $is_active);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Service berhasil ditambahkan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan service: ' . mysqli_error($conn)]);
    }
}

function update_service() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = $data['id'] ?? 0;
    $kode_jasa = $data['kode_jasa'] ?? '';
    $nama_jasa = $data['nama_jasa'] ?? '';
    $harga = $data['harga'] ?? 0;
    $kategori = $data['kategori'] ?? 'Ringan';
    $is_active = $data['is_active'] ?? 'Y';
    
    // Validasi
    if (empty($id) || empty($kode_jasa) || empty($nama_jasa) || $harga <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        return;
    }
    
    // Check duplicate kode_jasa (exclude current id)
    $check = mysqli_query($conn, "SELECT id FROM service_prices WHERE kode_jasa = '$kode_jasa' AND id != $id");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'Kode jasa sudah digunakan']);
        return;
    }
    
    $query = "UPDATE service_prices SET kode_jasa = ?, nama_jasa = ?, harga = ?, kategori = ?, is_active = ? 
              WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'ssdssi', $kode_jasa, $nama_jasa, $harga, $kategori, $is_active, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Service berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update service: ' . mysqli_error($conn)]);
    }
}

function delete_service() {
    global $conn;
    
    $id = $_GET['id'] ?? 0;
    
    // Check if used in SPK
    $check = mysqli_query($conn, "SELECT id FROM spk_services WHERE service_price_id = $id LIMIT 1");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'Service tidak dapat dihapus karena sudah digunakan di SPK']);
        return;
    }
    
    $query = "DELETE FROM service_prices WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Service berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus service: ' . mysqli_error($conn)]);
    }
}
?>
