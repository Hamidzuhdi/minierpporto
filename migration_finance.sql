-- =====================================================
-- Migration: Finance ledger (cash & rekening)
-- Run once in phpMyAdmin or MySQL CLI
-- =====================================================

CREATE TABLE IF NOT EXISTS finance_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tanggal DATE NOT NULL,
    account_id INT NOT NULL,
    direction ENUM('in','out','transfer_in','transfer_out') NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    reference_type VARCHAR(30) NULL,
    reference_id INT NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_finance_tx_tanggal (tanggal),
    INDEX idx_finance_tx_account (account_id),
    INDEX idx_finance_tx_ref (reference_type, reference_id),
    CONSTRAINT fk_finance_tx_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS operational_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tanggal DATE NOT NULL,
    expense_name VARCHAR(255) NOT NULL,
    category_code VARCHAR(30) NULL,
    amount DECIMAL(14,2) NOT NULL,
    account_id INT NOT NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_operational_tanggal (tanggal),
    INDEX idx_operational_account (account_id),
    CONSTRAINT fk_operational_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Jalankan hanya jika kolom berikut belum ada
ALTER TABLE purchases
    ADD COLUMN payment_account_id INT NULL AFTER is_paid,
    ADD COLUMN paid_at DATETIME NULL AFTER payment_account_id,
    ADD COLUMN payment_note TEXT NULL AFTER paid_at;

ALTER TABLE payments
    ADD COLUMN finance_account_id INT NULL AFTER method,
    ADD COLUMN finance_tx_id INT NULL AFTER finance_account_id,
    ADD COLUMN finance_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER finance_tx_id;

INSERT INTO finance_accounts (code, name, opening_balance, current_balance, is_active)
SELECT 'cash', 'Kas Bengkel', 500000, 500000, 1
WHERE NOT EXISTS (SELECT 1 FROM finance_accounts WHERE code = 'cash');

INSERT INTO finance_accounts (code, name, opening_balance, current_balance, is_active)
SELECT 'bank', 'Rekening Bengkel', 12000000, 12000000, 1
WHERE NOT EXISTS (SELECT 1 FROM finance_accounts WHERE code = 'bank');

INSERT INTO expense_categories (code, name, description, status, is_active)
SELECT 'EXP-LISTRIK', 'Biaya Listrik', 'Tagihan listrik operasional bengkel', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE code = 'EXP-LISTRIK');

INSERT INTO expense_categories (code, name, description, status, is_active)
SELECT 'EXP-PDAM', 'Biaya PDAM', 'Tagihan air operasional bengkel', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE code = 'EXP-PDAM');

INSERT INTO expense_categories (code, name, description, status, is_active)
SELECT 'EXP-ATK', 'ATK & Kertas Nota', 'Pembelian alat tulis kantor dan nota', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE code = 'EXP-ATK');
