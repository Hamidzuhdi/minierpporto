-- ============================================================================
-- 3) DP (uang muka) SPK: tabel spk_down_payments + penanda pembayaran asal DP
-- Setara dengan: migrations/20261109_spk_down_payments_migration.php
-- Aman dijalankan berulang. Jalankan SETELAH file 1 (QRIS).
-- ============================================================================

CREATE TABLE IF NOT EXISTS spk_down_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spk_id INT NOT NULL,
    tanggal DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    method ENUM('cash','transfer','qris') NOT NULL DEFAULT 'transfer',
    finance_account_id INT NULL,
    finance_tx_id INT NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    refunded_at DATETIME NULL,
    INDEX idx_spk_dp_spk (spk_id),
    CONSTRAINT fk_spk_dp_spk FOREIGN KEY (spk_id) REFERENCES spk(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Penanda baris pembayaran invoice yang berasal dari DP; dilewati jika kolom sudah ada
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE payments ADD COLUMN spk_dp_id INT NULL, ADD INDEX idx_payments_spk_dp (spk_dp_id)',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'spk_dp_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
