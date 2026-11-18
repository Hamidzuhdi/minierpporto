-- ============================================================================
-- Kategori sparepart: kolom spareparts.kategori ENUM('Fast Moving','Slow Moving')
-- Setara dengan: migrations/20261119_sparepart_kategori_migration.php
-- (kolom ini juga dibuat otomatis saat halaman Spareparts dibuka; file ini untuk
--  dijalankan manual bila akun DB aplikasi tidak punya hak ALTER). Aman dijalankan berulang.
-- ============================================================================

-- 1) Kolom belum ada: tambahkan (semua sparepart lama otomatis Slow Moving)
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE spareparts ADD COLUMN kategori ENUM(''Fast Moving'',''Slow Moving'') NOT NULL DEFAULT ''Slow Moving'' AFTER satuan',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spareparts' AND COLUMN_NAME = 'kategori'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Kolom sudah ada tapi BUKAN ENUM yang tepat (mis. dibuat manual sebagai teks): ubah jadi teks bebas dulu
SET @sql = (
    SELECT IF(COUNT(*) = 1,
        'ALTER TABLE spareparts MODIFY COLUMN kategori VARCHAR(30) NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spareparts' AND COLUMN_NAME = 'kategori'
      AND COLUMN_TYPE <> 'enum(''Fast Moving'',''Slow Moving'')'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Rapikan nilainya (yang diawali "fast" = Fast Moving, selain itu Slow Moving) bila kolom masih teks
SET @sql = (
    SELECT IF(COUNT(*) = 1,
        'UPDATE spareparts SET kategori = CASE WHEN LOWER(TRIM(kategori)) LIKE ''fast%'' THEN ''Fast Moving'' ELSE ''Slow Moving'' END',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spareparts' AND COLUMN_NAME = 'kategori'
      AND DATA_TYPE = 'varchar'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) Kunci menjadi ENUM bila tadi diubah ke teks
SET @sql = (
    SELECT IF(COUNT(*) = 1,
        'ALTER TABLE spareparts MODIFY COLUMN kategori ENUM(''Fast Moving'',''Slow Moving'') NOT NULL DEFAULT ''Slow Moving''',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spareparts' AND COLUMN_NAME = 'kategori'
      AND DATA_TYPE = 'varchar'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
