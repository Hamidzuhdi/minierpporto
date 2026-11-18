<?php

// Estimasi biaya: penawaran harga untuk customer sebelum SPK dibuat.
//
// Sengaja terpisah dari tabel SPK karena aturannya berbeda:
//   - tidak punya status,
//   - tidak mengecek maupun mengurangi stok sparepart,
//   - tidak menyentuh keuangan, invoice, atau DP.
// Harga tiap baris di-snapshot saat ditambahkan, jadi perubahan harga master
// di kemudian hari tidak mengubah estimasi yang sudah dibuat.

function estimasi_ensure_schema(mysqli $conn): void
{
    // Kegagalan CREATE (mis. akun DB tanpa hak CREATE, tabel sudah dibuat lewat SQL manual)
    // sengaja tidak fatal; query berikutnya akan gagal sendiri bila tabelnya memang belum ada.
    estimasi_query($conn, "
        CREATE TABLE IF NOT EXISTS estimasi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_estimasi VARCHAR(64) NOT NULL,
            customer_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            tanggal DATE NOT NULL,
            kilometer INT UNSIGNED NULL,
            keluhan_customer TEXT NULL,
            analisa_mekanik TEXT NULL,
            nama_mekanik VARCHAR(100) NULL,
            service_description TEXT NULL,
            saran_service TEXT NULL,
            discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            use_custom_price TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_estimasi_kode (kode_estimasi),
            INDEX idx_estimasi_customer (customer_id),
            INDEX idx_estimasi_vehicle (vehicle_id),
            INDEX idx_estimasi_tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    estimasi_query($conn, "
        CREATE TABLE IF NOT EXISTS estimasi_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estimasi_id INT NOT NULL,
            service_price_id INT NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            harga DECIMAL(14,2) NOT NULL,
            use_custom_price TINYINT(1) NOT NULL DEFAULT 0,
            harga_custom DECIMAL(14,2) NULL,
            subtotal DECIMAL(14,2) GENERATED ALWAYS AS (
                CASE WHEN use_custom_price = 1 AND harga_custom IS NOT NULL
                     THEN qty * harga_custom ELSE qty * harga END
            ) STORED,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_estimasi_services_estimasi (estimasi_id),
            CONSTRAINT fk_estimasi_services_estimasi FOREIGN KEY (estimasi_id) REFERENCES estimasi(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    estimasi_query($conn, "
        CREATE TABLE IF NOT EXISTS estimasi_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estimasi_id INT NOT NULL,
            sparepart_id INT NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            harga_satuan DECIMAL(14,2) NOT NULL,
            use_custom_price TINYINT(1) NOT NULL DEFAULT 0,
            harga_custom DECIMAL(14,2) NULL,
            subtotal DECIMAL(14,2) GENERATED ALWAYS AS (
                CASE WHEN use_custom_price = 1 AND harga_custom IS NOT NULL
                     THEN qty * harga_custom ELSE qty * harga_satuan END
            ) STORED,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_estimasi_items_estimasi (estimasi_id),
            CONSTRAINT fk_estimasi_items_estimasi FOREIGN KEY (estimasi_id) REFERENCES estimasi(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

// Jalankan query dan kembalikan hasilnya tanpa peduli mode pelaporan error mysqli
// (PHP 8.1+ melempar exception secara default, mode lama mengembalikan false).
// $errno diisi nomor error MySQL jika gagal, agar pemanggil bisa mengenali duplicate key (1062).
function estimasi_query(mysqli $conn, string $sql, int &$errno = 0)
{
    $errno = 0;
    try {
        $result = mysqli_query($conn, $sql);
    } catch (mysqli_sql_exception $e) {
        $errno = (int)$e->getCode();
        return false;
    }
    if ($result === false) {
        $errno = mysqli_errno($conn);
    }
    return $result;
}

function estimasi_audit(mysqli $conn, int $userId, string $action, int $targetId, string $description): void
{
    $userSql = $userId > 0 ? (string)$userId : 'NULL';
    $actionEsc = mysqli_real_escape_string($conn, $action);
    $descEsc = mysqli_real_escape_string($conn, $description);
    // Audit log tidak boleh menggagalkan aksi utama.
    estimasi_query(
        $conn,
        "INSERT INTO audit_logs (user_id, action, target_table, target_id, description, created_at)
         VALUES ($userSql, '$actionEsc', 'estimasi', $targetId, '$descEsc', NOW())"
    );
}

// Harga satuan yang berlaku: harga khusus bila diaktifkan, selain itu harga snapshot.
// Aturannya sama dengan kolom subtotal (generated) agar tampilan dan total tidak pernah beda.
const ESTIMASI_SERVICE_PRICE_SQL = "CASE WHEN es.use_custom_price = 1 AND es.harga_custom IS NOT NULL THEN es.harga_custom ELSE es.harga END";
const ESTIMASI_ITEM_PRICE_SQL = "CASE WHEN ei.use_custom_price = 1 AND ei.harga_custom IS NOT NULL THEN ei.harga_custom ELSE ei.harga_satuan END";

// Hitung total dari jasa + sparepart. Diskon dibatasi agar tidak melebihi subtotal.
function estimasi_totals(float $totalJasa, float $totalSparepart, float $discount): array
{
    $subtotal = $totalJasa + $totalSparepart;
    $discountApplied = max(0.0, min($discount, $subtotal));

    return [
        'total_jasa' => $totalJasa,
        'total_sparepart' => $totalSparepart,
        'subtotal' => $subtotal,
        'discount_amount' => $discountApplied,
        'total_estimasi' => $subtotal - $discountApplied,
    ];
}

// Muat satu estimasi lengkap (customer, kendaraan, jasa, sparepart, total). null jika tidak ada.
// LEFT JOIN dipakai agar estimasi tidak hilang diam-diam bila data master terlanjur dihapus.
function estimasi_load(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $res = mysqli_query(
        $conn,
        "SELECT e.*,
                c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                v.nomor_polisi, v.merk, v.model, v.tahun
         FROM estimasi e
         LEFT JOIN customers c ON c.id = e.customer_id
         LEFT JOIN vehicles v ON v.id = e.vehicle_id
         WHERE e.id = $id
         LIMIT 1"
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return null;
    }

    $servicePriceSql = ESTIMASI_SERVICE_PRICE_SQL;
    $resSvc = mysqli_query(
        $conn,
        "SELECT es.id, es.service_price_id, es.qty, es.harga, es.use_custom_price, es.harga_custom, es.subtotal,
                ($servicePriceSql) AS harga_eff,
                COALESCE(sp.kode_jasa, '') AS kode_jasa,
                COALESCE(sp.nama_jasa, '(jasa dihapus)') AS nama_jasa
         FROM estimasi_services es
         LEFT JOIN service_prices sp ON sp.id = es.service_price_id
         WHERE es.estimasi_id = $id
         ORDER BY es.id ASC"
    );
    $services = [];
    $totalJasa = 0.0;
    while ($resSvc && ($svc = mysqli_fetch_assoc($resSvc))) {
        $services[] = $svc;
        $totalJasa += (float)$svc['subtotal'];
    }

    $itemPriceSql = ESTIMASI_ITEM_PRICE_SQL;
    $resItems = mysqli_query(
        $conn,
        "SELECT ei.id, ei.sparepart_id, ei.qty, ei.harga_satuan, ei.use_custom_price, ei.harga_custom, ei.subtotal,
                ($itemPriceSql) AS harga_eff,
                COALESCE(sp.kode_sparepart, '') AS kode_sparepart,
                COALESCE(sp.nama, '(sparepart dihapus)') AS sparepart_name,
                COALESCE(sp.satuan, '') AS satuan
         FROM estimasi_items ei
         LEFT JOIN spareparts sp ON sp.id = ei.sparepart_id
         WHERE ei.estimasi_id = $id
         ORDER BY ei.id ASC"
    );
    $items = [];
    $totalSparepart = 0.0;
    while ($resItems && ($item = mysqli_fetch_assoc($resItems))) {
        $items[] = $item;
        $totalSparepart += (float)$item['subtotal'];
    }

    $row['services'] = $services;
    $row['items'] = $items;
    $row['totals'] = estimasi_totals($totalJasa, $totalSparepart, (float)($row['discount_amount'] ?? 0));

    return $row;
}
