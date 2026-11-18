-- ============================================================================
-- Estimasi biaya: tabel estimasi, estimasi_services, estimasi_items
-- Setara dengan: estimasi_ensure_schema() di estimasi_helper.php (dijalankan otomatis
-- saat halaman Estimasi dibuka; file ini untuk dijalankan manual bila akun DB aplikasi
-- tidak punya hak CREATE). Aman dijalankan berulang.
-- ============================================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
