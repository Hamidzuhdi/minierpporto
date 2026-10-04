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

// CREATE - Tambah User Baru
if ($action === 'create') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username dan password harus diisi']);
        exit;
    }
    
    // Cek username sudah ada
    $check = mysqli_query($conn, "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "'");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'Username sudah digunakan']);
        exit;
    }
    
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    $sql = "INSERT INTO users (username, password_hash, full_name, role) 
            VALUES ('" . mysqli_real_escape_string($conn, $username) . "',
                    '" . mysqli_real_escape_string($conn, $password_hash) . "',
                    '" . mysqli_real_escape_string($conn, $full_name) . "',
                    '" . mysqli_real_escape_string($conn, $role) . "')";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'User berhasil ditambahkan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menambahkan user: ' . mysqli_error($conn)]);
    }
}

// READ - Ambil Semua User
elseif ($action === 'read') {
    $sql = "SELECT id, username, full_name, role, created_at FROM users ORDER BY id DESC";
    $result = mysqli_query($conn, $sql);
    
    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $users]);
}

// READ ONE - Ambil Detail User
elseif ($action === 'read_one') {
    $id = (int)$_GET['id'];
    
    $sql = "SELECT id, username, full_name, role, created_at FROM users WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
    }
}

// UPDATE - Edit User
elseif ($action === 'update') {
    $id = (int)$_POST['id'];
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    $password = $_POST['password'] ?? '';
    
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username harus diisi']);
        exit;
    }
    
    // Cek username sudah dipakai user lain
    $check = mysqli_query($conn, "SELECT id FROM users WHERE username = '" . mysqli_real_escape_string($conn, $username) . "' AND id != $id");
    if (mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'Username sudah digunakan']);
        exit;
    }
    
    $sql = "UPDATE users SET 
            username = '" . mysqli_real_escape_string($conn, $username) . "',
            full_name = '" . mysqli_real_escape_string($conn, $full_name) . "',
            role = '" . mysqli_real_escape_string($conn, $role) . "'";
    
    // Update password jika diisi
    if (!empty($password)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $sql .= ", password_hash = '" . mysqli_real_escape_string($conn, $password_hash) . "'";
    }
    
    $sql .= " WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'User berhasil diupdate']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupdate user: ' . mysqli_error($conn)]);
    }
}

// DELETE - Hapus User
elseif ($action === 'delete') {
    $id = (int)$_POST['id'];
    
    // Jangan hapus diri sendiri
    if ($id == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Tidak dapat menghapus akun sendiri']);
        exit;
    }
    
    $sql = "DELETE FROM users WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'User berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus user: ' . mysqli_error($conn)]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
?>
