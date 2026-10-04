-- ===================================================================
-- TRACE OPENING BALANCE & CASH FLOW ANALYSIS
-- ===================================================================

-- 1. LIHAT ACCOUNT SAAT INI + OPENING BALANCE
SELECT 
    id,
    code,
    name,
    opening_balance,
    current_balance,
    (current_balance - opening_balance) AS net_flow,
    is_active
FROM finance_accounts
ORDER BY code;

-- ===================================================================

-- 2. LIHAT SEMUA TRANSAKSI PER ACCOUNT, URUT DARI AWAL
-- Ganti account_id sesuai ID account (1=cash, 2=bank, dst)
SELECT 
    ft.id,
    ft.tanggal,
    ft.direction,
    ft.category,
    ft.amount,
    ft.reference_type,
    ft.reference_id,
    ft.note,
    ft.status,
    u.username as created_by_name
FROM finance_transactions ft
LEFT JOIN users u ON ft.created_by = u.id
WHERE ft.account_id = 1  -- GANTI DENGAN ID ACCOUNT YANG MAU DI-TRACE
ORDER BY ft.tanggal ASC, ft.id ASC;

-- ===================================================================

-- 3. RUNNING BALANCE PER ACCOUNT (TRACE DARI AWAL)
-- Ganti account_id sesuai ID account
SELECT 
    ft.id,
    ft.tanggal,
    ft.direction,
    ft.category,
    ft.amount,
    CASE 
        WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount
        ELSE -ft.amount
    END AS net_amount,
    SUM(CASE 
        WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount
        ELSE -ft.amount
    END) OVER (ORDER BY ft.tanggal, ft.id) AS running_balance,
    ft.note,
    u.username as created_by_name
FROM finance_transactions ft
LEFT JOIN users u ON ft.created_by = u.id
WHERE ft.account_id = 1  -- GANTI DENGAN ID ACCOUNT YANG MATI DI-TRACE
ORDER BY ft.tanggal ASC, ft.id ASC;

-- ===================================================================

-- 4. TOTAL MASUK & KELUAR PER ACCOUNT (UNTUK REKONSILIASI)
SELECT 
    fa.id,
    fa.code,
    fa.name,
    fa.opening_balance,
    fa.current_balance,
    COALESCE(SUM(CASE WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount ELSE 0 END), 0) AS total_in,
    COALESCE(SUM(CASE WHEN ft.direction IN ('out', 'transfer_out') THEN ft.amount ELSE 0 END), 0) AS total_out,
    COALESCE(SUM(CASE WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount ELSE -ft.amount END), 0) AS total_net_flow,
    (fa.opening_balance + COALESCE(SUM(CASE WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount ELSE -ft.amount END), 0)) AS calculated_balance,
    (fa.current_balance - (fa.opening_balance + COALESCE(SUM(CASE WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount ELSE -ft.amount END), 0))) AS difference
FROM finance_accounts fa
LEFT JOIN finance_transactions ft ON fa.id = ft.account_id
GROUP BY fa.id, fa.code, fa.name, fa.opening_balance, fa.current_balance
ORDER BY fa.code;

-- ===================================================================

-- 5. TRANSAKSI PERTAMA PER ACCOUNT (LIHAT TANGGAL MULAI)
SELECT 
    fa.id,
    fa.code,
    fa.name,
    MIN(ft.tanggal) AS first_transaction_date,
    MAX(ft.tanggal) AS last_transaction_date,
    COUNT(ft.id) AS total_transactions
FROM finance_accounts fa
LEFT JOIN finance_transactions ft ON fa.id = ft.account_id
GROUP BY fa.id, fa.code, fa.name
ORDER BY fa.code;

-- ===================================================================

-- 6. CASH FLOW BULAN INI (TANPA MODAL AWAL)
-- Lihat transaksi masuk & keluar bulan ini saja
SELECT 
    fa.code,
    fa.name,
    MONTH(ft.tanggal) AS bulan,
    YEAR(ft.tanggal) AS tahun,
    SUM(CASE WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount ELSE 0 END) AS total_masuk,
    SUM(CASE WHEN ft.direction IN ('out', 'transfer_out') THEN ft.amount ELSE 0 END) AS total_keluar,
    SUM(CASE WHEN ft.direction IN ('in', 'transfer_in') THEN ft.amount ELSE -ft.amount END) AS net_flow
FROM finance_accounts fa
LEFT JOIN finance_transactions ft ON fa.id = ft.account_id
WHERE YEAR(ft.tanggal) = YEAR(CURDATE()) AND MONTH(ft.tanggal) = MONTH(CURDATE())
GROUP BY fa.id, fa.code, fa.name
ORDER BY fa.code;

-- ===================================================================

-- 7. DETAIL UNTUK REKONSILIASI MANUAL
-- Lihat transaction per kategori
SELECT 
    fa.code,
    fa.name,
    ft.category,
    ft.direction,
    COUNT(*) AS jumlah_transaksi,
    SUM(ft.amount) AS total_amount,
    MIN(ft.tanggal) AS tanggal_awal,
    MAX(ft.tanggal) AS tanggal_akhir
FROM finance_accounts fa
LEFT JOIN finance_transactions ft ON fa.id = ft.account_id
GROUP BY fa.id, fa.code, fa.name, ft.category, ft.direction
ORDER BY fa.code, ft.direction DESC, ft.category;

-- ===================================================================

-- 8. CEK TRANSAKSI PENDING/REJECTED
SELECT 
    fa.code,
    fa.name,
    ft.status,
    COUNT(*) AS jumlah,
    SUM(ft.amount) AS total_amount
FROM finance_accounts fa
LEFT JOIN finance_transactions ft ON fa.id = ft.account_id
WHERE ft.status IN ('pending', 'rejected')
GROUP BY fa.id, fa.code, fa.name, ft.status
ORDER BY fa.code, ft.status;
