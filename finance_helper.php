<?php

function finance_ensure_default_accounts(mysqli $conn): void
{
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS finance_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    mysqli_query($conn, "
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
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            approved_by INT NULL,
            approved_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_finance_tx_tanggal (tanggal),
            INDEX idx_finance_tx_account (account_id),
            INDEX idx_finance_tx_ref (reference_type, reference_id),
            INDEX idx_finance_tx_status (status),
            CONSTRAINT fk_finance_tx_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id)
        ) ENGINE=InnoDB
    ");

    $txStatusCol = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE 'status'");
    if ($txStatusCol && mysqli_num_rows($txStatusCol) === 0) {
        mysqli_query($conn, "ALTER TABLE finance_transactions ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER created_by");
        // Existing historical transactions should be treated as approved.
        mysqli_query($conn, "UPDATE finance_transactions SET status = 'approved' WHERE status = 'pending'");
    }

    $txApprovedByCol = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE 'approved_by'");
    if ($txApprovedByCol && mysqli_num_rows($txApprovedByCol) === 0) {
        mysqli_query($conn, "ALTER TABLE finance_transactions ADD COLUMN approved_by INT NULL AFTER status");
    }

    $txApprovedAtCol = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE 'approved_at'");
    if ($txApprovedAtCol && mysqli_num_rows($txApprovedAtCol) === 0) {
        mysqli_query($conn, "ALTER TABLE finance_transactions ADD COLUMN approved_at DATETIME NULL AFTER approved_by");
    }

    $txRejectedByCol = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE 'rejected_by'");
    if ($txRejectedByCol && mysqli_num_rows($txRejectedByCol) === 0) {
        mysqli_query($conn, "ALTER TABLE finance_transactions ADD COLUMN rejected_by INT NULL AFTER approved_at");
    }

    $txRejectedAtCol = mysqli_query($conn, "SHOW COLUMNS FROM finance_transactions LIKE 'rejected_at'");
    if ($txRejectedAtCol && mysqli_num_rows($txRejectedAtCol) === 0) {
        mysqli_query($conn, "ALTER TABLE finance_transactions ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by");
    }

    $idxStatus = mysqli_query($conn, "SHOW INDEX FROM finance_transactions WHERE Key_name = 'idx_finance_tx_status'");
    if (!$idxStatus || mysqli_num_rows($idxStatus) === 0) {
        mysqli_query($conn, "ALTER TABLE finance_transactions ADD INDEX idx_finance_tx_status (status)");
    }

    mysqli_query($conn, "
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
        ) ENGINE=InnoDB
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS expense_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $expenseStatusCol = mysqli_query($conn, "SHOW COLUMNS FROM expense_categories LIKE 'status'");
    if ($expenseStatusCol && mysqli_num_rows($expenseStatusCol) === 0) {
        mysqli_query($conn, "ALTER TABLE expense_categories ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 0 AFTER description");
    }

    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM operational_expenses LIKE 'category_code'");
    if ($colCheck && mysqli_num_rows($colCheck) === 0) {
        mysqli_query($conn, "ALTER TABLE operational_expenses ADD COLUMN category_code VARCHAR(30) NULL AFTER expense_name");
    }

    // Keep created_by values referentially valid before attaching FK.
    mysqli_query($conn, "
        UPDATE finance_transactions ft
        LEFT JOIN users u ON u.id = ft.created_by
        SET ft.created_by = NULL
        WHERE ft.created_by IS NOT NULL AND u.id IS NULL
    ");

    $idxCreatedBy = mysqli_query($conn, "SHOW INDEX FROM finance_transactions WHERE Key_name = 'idx_finance_tx_created_by'");
    if (!$idxCreatedBy || mysqli_num_rows($idxCreatedBy) === 0) {
        mysqli_query($conn, "ALTER TABLE finance_transactions ADD INDEX idx_finance_tx_created_by (created_by)");
    }

    $fkCreatedBy = mysqli_query($conn, "
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'finance_transactions'
          AND CONSTRAINT_NAME = 'fk_finance_tx_created_by'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        LIMIT 1
    ");
    if (!$fkCreatedBy || mysqli_num_rows($fkCreatedBy) === 0) {
        mysqli_query($conn, "
            ALTER TABLE finance_transactions
            ADD CONSTRAINT fk_finance_tx_created_by
            FOREIGN KEY (created_by) REFERENCES users(id)
            ON UPDATE CASCADE
            ON DELETE SET NULL
        ");
    }

    mysqli_query($conn, "
        INSERT INTO finance_accounts (code, name, opening_balance, current_balance, is_active)
        SELECT 'cash', 'Kas Bengkel', 500000, 500000, 1
        WHERE NOT EXISTS (SELECT 1 FROM finance_accounts WHERE code = 'cash')
    ");

    mysqli_query($conn, "
        INSERT INTO finance_accounts (code, name, opening_balance, current_balance, is_active)
        SELECT 'bank', 'Rekening Bengkel', 12000000, 12000000, 1
        WHERE NOT EXISTS (SELECT 1 FROM finance_accounts WHERE code = 'bank')
    ");

    mysqli_query($conn, "
        INSERT INTO expense_categories (code, name, description, status, is_active)
        SELECT 'EXP-LISTRIK', 'Biaya Listrik', 'Tagihan listrik operasional bengkel', 0, 1
        WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE code = 'EXP-LISTRIK')
    ");

    mysqli_query($conn, "
        INSERT INTO expense_categories (code, name, description, status, is_active)
        SELECT 'EXP-PDAM', 'Biaya PDAM', 'Tagihan air operasional bengkel', 0, 1
        WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE code = 'EXP-PDAM')
    ");

    mysqli_query($conn, "
        INSERT INTO expense_categories (code, name, description, status, is_active)
        SELECT 'EXP-ATK', 'ATK & Kertas Nota', 'Pembelian alat tulis kantor dan nota', 0, 1
        WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE code = 'EXP-ATK')
    ");
}

function finance_get_account_by_code(mysqli $conn, string $code): ?array
{
    $code = mysqli_real_escape_string($conn, $code);
    $res = mysqli_query($conn, "SELECT * FROM finance_accounts WHERE code = '$code' AND is_active = 1 LIMIT 1");
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

function finance_get_account_by_id(mysqli $conn, int $id): ?array
{
    $res = mysqli_query($conn, "SELECT * FROM finance_accounts WHERE id = $id AND is_active = 1 LIMIT 1");
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

function finance_add_transaction(
    mysqli $conn,
    string $tanggal,
    int $accountId,
    string $direction,
    string $category,
    float $amount,
    ?string $referenceType,
    ?int $referenceId,
    ?string $note,
    ?int $createdBy,
    string $status = 'approved',
    bool $affectsBalance = true
): array {
    $allowedDirections = ['in', 'out', 'transfer_in', 'transfer_out'];
    if (!in_array($direction, $allowedDirections, true)) {
        return ['success' => false, 'message' => 'Direction tidak valid'];
    }
    $allowedStatus = ['pending', 'approved', 'rejected'];
    if (!in_array($status, $allowedStatus, true)) {
        return ['success' => false, 'message' => 'Status transaksi tidak valid'];
    }
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Amount harus lebih dari 0'];
    }

    $account = finance_get_account_by_id($conn, $accountId);
    if (!$account) {
        return ['success' => false, 'message' => 'Account tidak ditemukan'];
    }

    $newBalance = (float)$account['current_balance'];
    if ($status === 'approved' && $affectsBalance) {
        $isOut = in_array($direction, ['out', 'transfer_out'], true);
        $delta = $isOut ? -$amount : $amount;
        $newBalance = (float)$account['current_balance'] + $delta;

        if ($newBalance < 0) {
            return ['success' => false, 'message' => 'Saldo tidak mencukupi untuk transaksi ini'];
        }
    }

    $categoryEsc = mysqli_real_escape_string($conn, $category);
    $noteEsc = mysqli_real_escape_string($conn, (string)$note);
    $referenceTypeSql = $referenceType ? ("'" . mysqli_real_escape_string($conn, $referenceType) . "'") : 'NULL';
    $referenceIdSql = $referenceId ? (string)$referenceId : 'NULL';
    $createdBySql = $createdBy ? (string)$createdBy : 'NULL';
    $directionEsc = mysqli_real_escape_string($conn, $direction);
    $statusEsc = mysqli_real_escape_string($conn, $status);
    $approvedBySql = ($status === 'approved' && $createdBy) ? (string)$createdBy : 'NULL';
    $approvedAtSql = ($status === 'approved') ? 'NOW()' : 'NULL';

    $affectsBalanceSql = $affectsBalance ? '1' : '0';

    $sqlInsert = "INSERT INTO finance_transactions
        (tanggal, account_id, direction, category, amount, reference_type, reference_id, note, created_by, status, affects_balance, approved_by, approved_at)
        VALUES
        ('" . mysqli_real_escape_string($conn, $tanggal) . "', $accountId, '$directionEsc', '$categoryEsc', $amount, $referenceTypeSql, $referenceIdSql, '$noteEsc', $createdBySql, '$statusEsc', $affectsBalanceSql, $approvedBySql, $approvedAtSql)";

    if (!mysqli_query($conn, $sqlInsert)) {
        return ['success' => false, 'message' => 'Gagal insert transaksi keuangan: ' . mysqli_error($conn)];
    }

    // Capture insert id immediately before running other queries (e.g. UPDATE balance)
    // so FK references can reliably point to the created finance transaction row.
    $transactionId = (int)mysqli_insert_id($conn);

    if ($status === 'approved' && $affectsBalance) {
        $sqlBalance = "UPDATE finance_accounts SET current_balance = $newBalance WHERE id = $accountId";
        if (!mysqli_query($conn, $sqlBalance)) {
            return ['success' => false, 'message' => 'Gagal update saldo akun: ' . mysqli_error($conn)];
        }
    }

    return [
        'success' => true,
        'transaction_id' => $transactionId,
        'new_balance' => $newBalance,
    ];
}

function finance_reverse_transaction(mysqli $conn, int $transactionId): array
{
    $res = mysqli_query($conn, "SELECT * FROM finance_transactions WHERE id = $transactionId LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) {
        return ['success' => false, 'message' => 'Transaksi keuangan tidak ditemukan'];
    }

    $tx = mysqli_fetch_assoc($res);
    $account = finance_get_account_by_id($conn, (int)$tx['account_id']);
    if (!$account) {
        return ['success' => false, 'message' => 'Account transaksi tidak ditemukan'];
    }

    $newBalance = (float)$account['current_balance'];
    if (($tx['status'] ?? 'approved') === 'approved') {
        $amount = (float)$tx['amount'];
        $direction = $tx['direction'];
        $isOut = in_array($direction, ['out', 'transfer_out'], true);

        // reverse: if old was out then add back, else subtract
        $newBalance = $isOut
            ? ((float)$account['current_balance'] + $amount)
            : ((float)$account['current_balance'] - $amount);

        if ($newBalance < 0) {
            return ['success' => false, 'message' => 'Saldo tidak valid saat reverse transaksi'];
        }

        if (!mysqli_query($conn, "UPDATE finance_accounts SET current_balance = $newBalance WHERE id = {$tx['account_id']}")) {
            return ['success' => false, 'message' => 'Gagal rollback saldo: ' . mysqli_error($conn)];
        }
    }

    if (!mysqli_query($conn, "DELETE FROM finance_transactions WHERE id = $transactionId")) {
        return ['success' => false, 'message' => 'Gagal hapus transaksi keuangan: ' . mysqli_error($conn)];
    }

    return ['success' => true, 'new_balance' => $newBalance];
}

function finance_approve_transaction(mysqli $conn, int $transactionId, ?int $approvedBy = null): array
{
    mysqli_begin_transaction($conn);
    try {
        $res = mysqli_query($conn, "SELECT * FROM finance_transactions WHERE id = $transactionId LIMIT 1 FOR UPDATE");
        $tx = $res ? mysqli_fetch_assoc($res) : null;
        if (!$tx) {
            throw new Exception('Transaksi tidak ditemukan');
        }
        if (($tx['status'] ?? '') !== 'pending') {
            throw new Exception('Transaksi bukan status pending');
        }

        $toApprove = [(int)$tx['id']];

        // For transfer, auto-approve paired pending entry as well.
        if (($tx['reference_type'] ?? '') === 'transfer' && !empty($tx['reference_id'])) {
            $pairId = (int)$tx['reference_id'];
            if ($pairId > 0) {
                $pairRes = mysqli_query($conn, "SELECT * FROM finance_transactions WHERE id = $pairId LIMIT 1 FOR UPDATE");
                $pair = $pairRes ? mysqli_fetch_assoc($pairRes) : null;
                if ($pair && ($pair['status'] ?? '') === 'pending') {
                    $toApprove[] = (int)$pair['id'];
                }
            }
        }

        foreach ($toApprove as $id) {
            $rowRes = mysqli_query($conn, "SELECT * FROM finance_transactions WHERE id = $id LIMIT 1 FOR UPDATE");
            $row = $rowRes ? mysqli_fetch_assoc($rowRes) : null;
            if (!$row || ($row['status'] ?? '') !== 'pending') {
                continue;
            }

            $accountId = (int)$row['account_id'];
            $amount = (float)$row['amount'];
            $isOut = in_array($row['direction'], ['out', 'transfer_out'], true);

            $accRes = mysqli_query($conn, "SELECT current_balance FROM finance_accounts WHERE id = $accountId LIMIT 1 FOR UPDATE");
            $acc = $accRes ? mysqli_fetch_assoc($accRes) : null;
            if (!$acc) {
                throw new Exception('Akun transaksi tidak ditemukan');
            }

            $newBalance = $isOut
                ? ((float)$acc['current_balance'] - $amount)
                : ((float)$acc['current_balance'] + $amount);
            if ($newBalance < 0) {
                throw new Exception('Saldo tidak mencukupi untuk approve transaksi #' . $id);
            }

            if (!mysqli_query($conn, "UPDATE finance_accounts SET current_balance = $newBalance WHERE id = $accountId")) {
                throw new Exception('Gagal update saldo akun: ' . mysqli_error($conn));
            }

            $approvedBySql = $approvedBy ? (int)$approvedBy : 'NULL';
            if (!mysqli_query($conn, "UPDATE finance_transactions SET status = 'approved', approved_by = $approvedBySql, approved_at = NOW() WHERE id = $id")) {
                throw new Exception('Gagal approve transaksi: ' . mysqli_error($conn));
            }
        }

        mysqli_commit($conn);
        return ['success' => true];
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function finance_reject_transaction(mysqli $conn, int $transactionId, ?int $rejectedBy = null): array
{
    $res = mysqli_query($conn, "SELECT id, status, reference_type, reference_id FROM finance_transactions WHERE id = $transactionId LIMIT 1");
    $tx = $res ? mysqli_fetch_assoc($res) : null;
    if (!$tx) {
        return ['success' => false, 'message' => 'Transaksi tidak ditemukan'];
    }
    if (($tx['status'] ?? '') !== 'pending') {
        return ['success' => false, 'message' => 'Transaksi bukan status pending'];
    }

    $ids = [(int)$tx['id']];
    if (($tx['reference_type'] ?? '') === 'transfer' && !empty($tx['reference_id'])) {
        $pairId = (int)$tx['reference_id'];
        if ($pairId > 0) {
            $pairRes = mysqli_query($conn, "SELECT id, status FROM finance_transactions WHERE id = $pairId LIMIT 1");
            $pair = $pairRes ? mysqli_fetch_assoc($pairRes) : null;
            if ($pair && ($pair['status'] ?? '') === 'pending') {
                $ids[] = (int)$pair['id'];
            }
        }
    }

    $rejectedBySql = $rejectedBy ? (int)$rejectedBy : 'NULL';
    foreach ($ids as $id) {
        if (!mysqli_query($conn, "UPDATE finance_transactions SET status = 'rejected', rejected_by = $rejectedBySql, rejected_at = NOW() WHERE id = $id AND status = 'pending'")) {
            return ['success' => false, 'message' => 'Gagal reject transaksi: ' . mysqli_error($conn)];
        }
    }

    return ['success' => true];
}
