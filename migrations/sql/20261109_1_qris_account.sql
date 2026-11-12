-- ============================================================================
-- 1) QRIS: akun "Saldo QRIS" dan metode pembayaran 'qris'
-- Setara dengan: migrations/20261109_qris_account_migration.php
-- Aman dijalankan berulang. Jalankan SEBELUM file 3 (DP), karena DP bisa dibayar via QRIS.
-- ============================================================================

-- Akun keuangan baru (saldo awal 0)
INSERT INTO finance_accounts (code, name, opening_balance, current_balance, is_active)
SELECT 'qris', 'Saldo QRIS', 0, 0, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM finance_accounts WHERE code = 'qris');

-- Metode pembayaran customer: tambah nilai 'qris'
ALTER TABLE payments MODIFY COLUMN method ENUM('cash','transfer','qris') DEFAULT 'transfer';

-- Kolom pending_method (hanya ada jika flow approval pembayaran pernah dipasang); dilewati bila tidak ada
SET @sql = (
    SELECT IF(COUNT(*) > 0,
        "ALTER TABLE payments MODIFY COLUMN pending_method ENUM('cash','transfer','qris') NULL",
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'pending_method'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
