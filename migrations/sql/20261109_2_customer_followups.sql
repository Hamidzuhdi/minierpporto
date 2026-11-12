-- ============================================================================
-- 2) Follow-up customer: tabel riwayat follow-up (No respon / Respon / Prospek)
-- Setara dengan: migrations/20261109_customer_followups_migration.php
-- Aman dijalankan berulang. (File migrations/20261110_followup_hasil_rename_migration.php
-- TIDAK perlu dijalankan di server yang belum pernah punya tabel ini.)
-- ============================================================================

CREATE TABLE IF NOT EXISTS customer_followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    spk_id INT NULL,
    tahap ENUM('7days','2months','6months') NOT NULL,
    hasil ENUM('no_respon','respon','prospek') NOT NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_followup_customer (customer_id),
    INDEX idx_followup_spk_tahap (spk_id, tahap),
    CONSTRAINT fk_followup_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
