-- =====================================================
-- DATABASE CHANGES SUMMARY - MiniERP Project
-- Date: May 31, 2026
-- =====================================================
-- Resume semua perubahan database yang telah dilakukan
-- untuk implement Custom Pricing dan Purchase Refund Flow
-- =====================================================

-- =====================================================
-- 1. SPK CUSTOM PRICE FEATURE
-- =====================================================
-- Menambah support untuk harga khusus (custom price) di SPK
-- User dapat toggle custom pricing mode dan edit harga per item

-- Add use_custom_price column to spk_items table
-- Menandai apakah item ini menggunakan custom price
ALTER TABLE spk_items ADD COLUMN use_custom_price TINYINT(1) DEFAULT 0;

-- Add harga_custom column to spk_items table
-- Menyimpan harga khusus jika custom pricing diaktifkan
ALTER TABLE spk_items ADD COLUMN harga_custom DECIMAL(14,2) DEFAULT NULL;

-- Add use_custom_price column to spk table for toggle
-- Menandai apakah entire SPK menggunakan custom pricing mode
ALTER TABLE spk ADD COLUMN use_custom_price TINYINT(1) DEFAULT 0 AFTER status_spk;

-- =====================================================
-- 2. FIX SUBTOTAL GENERATED COLUMN
-- =====================================================
-- Modify subtotal GENERATED column di spk_items
-- Supaya subtotal dihitung dengan logic conditional:
-- - Jika use_custom_price = 1 dan harga_custom ada → qty * harga_custom
-- - Selain itu → qty * harga_satuan

ALTER TABLE spk_items MODIFY COLUMN subtotal DECIMAL(14,2) 
GENERATED ALWAYS AS (
    CASE 
        WHEN use_custom_price = 1 AND harga_custom IS NOT NULL 
        THEN qty * harga_custom
        ELSE qty * harga_satuan
    END
) STORED;

-- =====================================================
-- 3. SPK STATUS ENUM UPDATE
-- =====================================================
-- Tambah 'Sudah Reminder WA' dan 'Sudah FU' ke ENUM status_spk
-- Untuk support follow-up workflow setelah invoice sudah dicetak

ALTER TABLE spk MODIFY COLUMN status_spk ENUM(
    'Menunggu Konfirmasi',
    'Disetujui',
    'Dalam Pengerjaan',
    'Selesai',
    'Dikirim ke Owner',
    'Buat Invoice',
    'Sudah Cetak Invoice',
    'Sudah Reminder WA',
    'Sudah FU',
    'Dibatalkan'
) DEFAULT 'Menunggu Konfirmasi';

-- =====================================================
-- 4. PURCHASE STATUS COLUMN FIX
-- =====================================================
-- Expand status column untuk accommodate semua status values
-- Termasuk status baru 'Refund' yang ditambahkan untuk flow baru

ALTER TABLE purchases MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Pending';

-- =====================================================
-- ADDITIONAL CODE CHANGES (Kesampaian dengan Database)
-- =====================================================

-- generate_invoice_pdf.php:
-- - Removed manual calculation: (si.qty * sp.harga_jual_default) as subtotal_jual
-- - Now uses GENERATED column: si.subtotal (yang sudah include custom price logic)
-- - Display price logic: gunakan harga_custom jika use_custom_price=1, else harga_jual_default

-- generate_estimasi_pdf.php:
-- - Removed conditional query (hasHargaCol check)
-- - Now always uses: SELECT si.* ... si.subtotal
-- - Display price logic: gunakan harga_custom jika use_custom_price=1, else harga_jual_default

-- spk/index.php:
-- - viewDetail() modal (line ~931): Fixed display logic untuk custom price
-- - displaySparepartsList() (line ~1694): Display logic dengan custom price check

-- purchases/index.php:
-- - Removed Delete button untuk Admin (hanya ada untuk Pending status)
-- - Admin sekarang punya button Refund (bukan Delete)

-- purchases/backend.php:
-- - update_status action: Allow Admin role (bukan hanya Owner)
-- - Removed delete action (sebelumnya ada, tapi tidak recommend)

-- =====================================================
-- VERIFICATION QUERIES (untuk check hasil)
-- =====================================================

-- Verifikasi spk table columns (check ENUM values)
-- DESCRIBE spk;
-- SHOW FULL COLUMNS FROM spk WHERE Field='status_spk';

-- Verifikasi status values di SPK
-- SELECT DISTINCT status_spk FROM spk ORDER BY status_spk;

-- Check SPK dengan status Sudah Reminder WA
-- SELECT id, kode_unik_reference, status_spk FROM spk WHERE status_spk = 'Sudah Reminder WA';

-- Check SPK dengan status Sudah FU
-- SELECT id, kode_unik_reference, status_spk FROM spk WHERE status_spk = 'Sudah FU';

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. subtotal adalah GENERATED COLUMN (read-only)
--    Nilai dihitung automatic oleh database
--    Tidak perlu UPDATE manual
--
-- 2. Custom pricing dapat di-toggle di level SPK
--    Ketika toggle ON: item dapat edit harga khusus
--    Ketika toggle OFF: harga kembali ke harga_satuan default
--
-- 3. Stock management:
--    - PO Created: stock nambah
--    - PO Refunded: stock di-restore (dikurangi)
--    - Tidak ada yang namanya DELETE, hanya REFUND
--
-- 4. Finance tracking:
--    - PO tidak pengaruh uang sampai payment dikonfirm
--    - Refund tidak pengaruh uang jika Belum Bayar
--    - Pembayaran di-track terpisah via finance_transactions
--
-- 5. SPK Status Flow (Follow-up Workflow):
--    - Sudah Cetak Invoice: Invoice sudah dicetak ke customer
--    - Sudah Reminder WA: Sudah send reminder ke customer via WhatsApp
--    - Sudah FU: Sudah follow-up ke customer (untuk pembayaran/konfirmasi)
--    - Urutan: Selesai → Dikirim ke Owner → Buat Invoice → 
--             Sudah Cetak Invoice → Sudah Reminder WA → Sudah FU
-- =====================================================
