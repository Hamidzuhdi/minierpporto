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
$user_role = $_SESSION['role'] ?? 'Admin';
$is_owner = ($user_role === 'Owner');

// CREATE - Tambah Sparepart Baru
if ($action === 'create') {
    $nama = trim($_POST['nama']);
    $kode_sparepart = trim($_POST['kode_sparepart']);
    $barcode = trim($_POST['barcode']);
    $satuan = trim($_POST['satuan']);
    $harga_beli_default = (float)($_POST['harga_beli_default'] ?? 0);
    $harga_jual_default = (float)$_POST['harga_jual_default'];
    $min_stock = (int)$_POST['min_stock'];
    $current_stock = (int)$_POST['current_stock'];
    
    if (empty($nama)) {
        echo json_encode(['success' => false, 'message' => 'Nama sparepart harus diisi']);
        exit;
    }

    // Hanya Owner boleh menetapkan harga beli manual saat setup awal.
    if (!$is_owner) {
        $harga_beli_default = 0;
    }
    
    // Cek kode_sparepart sudah ada (jika diisi)
    if (!empty($kode_sparepart)) {
        $check = mysqli_query($conn, "SELECT id FROM spareparts WHERE kode_sparepart = '" . mysqli_real_escape_string($conn, $kode_sparepart) . "'");
        if (mysqli_num_rows($check) > 0) {
            echo json_encode(['success' => false, 'message' => 'Kode sparepart sudah terdaftar']);
            exit;
        }
    }
    
    // Cek barcode sudah ada (jika diisi)
    if (!empty($barcode)) {
        $check = mysqli_query($conn, "SELECT id FROM spareparts WHERE barcode = '" . mysqli_real_escape_string($conn, $barcode) . "'");
        if (mysqli_num_rows($check) > 0) {
            echo json_encode(['success' => false, 'message' => 'Barcode sudah terdaftar']);
            exit;
        }
    }
    
    $sql = "INSERT INTO spareparts (nama, kode_sparepart, barcode, satuan, harga_beli_default, harga_jual_default, min_stock, current_stock) 
            VALUES ('" . mysqli_real_escape_string($conn, $nama) . "',
                    " . (!empty($kode_sparepart) ? "'" . mysqli_real_escape_string($conn, $kode_sparepart) . "'" : "NULL") . ",
                    " . (!empty($barcode) ? "'" . mysqli_real_escape_string($conn, $barcode) . "'" : "NULL") . ",
                    '" . mysqli_real_escape_string($conn, $satuan) . "',
                    $harga_beli_default,
                    $harga_jual_default,
                    $min_stock,
                    $current_stock)";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Sparepart berhasil ditambahkan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan sparepart: ' . mysqli_error($conn)]);
    }
}

// READ - Ambil Semua Sparepart
elseif ($action === 'read') {
    $search = $_GET['search'] ?? '';
    $low_stock = $_GET['low_stock'] ?? '';
    
    $sql = "SELECT id, nama, kode_sparepart, barcode, satuan, harga_beli_default, harga_jual_default, 
            min_stock, current_stock, created_at FROM spareparts";
    
    $conditions = [];
    
    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(nama LIKE '%$search%' OR barcode LIKE '%$search%' OR kode_sparepart LIKE '%$search%')";
    }
    
    if ($low_stock == '1') {
        $conditions[] = "current_stock <= min_stock";
    }
    
    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY nama ASC";
    
    $result = mysqli_query($conn, $sql);
    
    $spareparts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $spareparts[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $spareparts]);
}

// READ ONE - Ambil Detail Sparepart
elseif ($action === 'read_one') {
    $id = (int)$_GET['id'];
    
    $sql = "SELECT * FROM spareparts WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Sparepart tidak ditemukan']);
    }
}

// SEARCH BY BARCODE - Untuk scan barcode
elseif ($action === 'search_barcode') {
    $barcode = trim($_GET['barcode']);
    
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'Barcode tidak boleh kosong']);
        exit;
    }
    
    $sql = "SELECT * FROM spareparts WHERE barcode = '" . mysqli_real_escape_string($conn, $barcode) . "'";
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Sparepart dengan barcode tersebut tidak ditemukan']);
    }
}

// UPDATE - Edit Sparepart
elseif ($action === 'update') {
    $id = (int)$_POST['id'];
    $nama = trim($_POST['nama']);
    $kode_sparepart = trim($_POST['kode_sparepart']);
    $barcode = trim($_POST['barcode']);
    $satuan = trim($_POST['satuan']);
    $harga_beli_default = (float)($_POST['harga_beli_default'] ?? 0);
    $harga_jual_default = (float)$_POST['harga_jual_default'];
    $min_stock = (int)$_POST['min_stock'];
    $current_stock = (int)$_POST['current_stock'];
    
    if (empty($nama)) {
        echo json_encode(['success' => false, 'message' => 'Nama sparepart harus diisi']);
        exit;
    }

    // Jika bukan Owner, pertahankan harga beli lama (abaikan input dari client).
    if (!$is_owner) {
        $existingRes = mysqli_query($conn, "SELECT harga_beli_default FROM spareparts WHERE id = $id LIMIT 1");
        if ($existingRes && ($existingRow = mysqli_fetch_assoc($existingRes))) {
            $harga_beli_default = (float)$existingRow['harga_beli_default'];
        } else {
            echo json_encode(['success' => false, 'message' => 'Sparepart tidak ditemukan']);
            exit;
        }
    }
    
    // Cek kode_sparepart sudah dipakai sparepart lain (jika diisi)
    if (!empty($kode_sparepart)) {
        $check = mysqli_query($conn, "SELECT id FROM spareparts WHERE kode_sparepart = '" . mysqli_real_escape_string($conn, $kode_sparepart) . "' AND id != $id");
        if (mysqli_num_rows($check) > 0) {
            echo json_encode(['success' => false, 'message' => 'Kode sparepart sudah terdaftar']);
            exit;
        }
    }
    
    // Cek barcode sudah dipakai sparepart lain (jika diisi)
    if (!empty($barcode)) {
        $check = mysqli_query($conn, "SELECT id FROM spareparts WHERE barcode = '" . mysqli_real_escape_string($conn, $barcode) . "' AND id != $id");
        if (mysqli_num_rows($check) > 0) {
            echo json_encode(['success' => false, 'message' => 'Barcode sudah terdaftar']);
            exit;
        }
    }
    
    $sql = "UPDATE spareparts SET 
            nama = '" . mysqli_real_escape_string($conn, $nama) . "',
            kode_sparepart = " . (!empty($kode_sparepart) ? "'" . mysqli_real_escape_string($conn, $kode_sparepart) . "'" : "NULL") . ",
            barcode = " . (!empty($barcode) ? "'" . mysqli_real_escape_string($conn, $barcode) . "'" : "NULL") . ",
            satuan = '" . mysqli_real_escape_string($conn, $satuan) . "',
            harga_beli_default = $harga_beli_default,
            harga_jual_default = $harga_jual_default,
            min_stock = $min_stock,
            current_stock = $current_stock
            WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Sparepart berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupdate sparepart: ' . mysqli_error($conn)]);
    }
}

// DELETE - Hapus Sparepart
elseif ($action === 'delete') {
    $id = (int)$_POST['id'];
    
    // Cek apakah ada transaksi yang terkait
    $check = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM purchase_items WHERE sparepart_id = $id");
    $row = mysqli_fetch_assoc($check);
    
    if ($row['cnt'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Sparepart tidak dapat dihapus karena memiliki data transaksi']);
        exit;
    }
    
    $sql = "DELETE FROM spareparts WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Sparepart berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus sparepart: ' . mysqli_error($conn)]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
