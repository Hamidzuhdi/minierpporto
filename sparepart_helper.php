<?php

// Kategori sparepart: penanda barang Fast Moving / Slow Moving.
const SPAREPART_KATEGORI_FAST = 'Fast Moving';
const SPAREPART_KATEGORI_SLOW = 'Slow Moving';
const SPAREPART_KATEGORI_TYPE = "enum('Fast Moving','Slow Moving')";

// Memastikan kolom spareparts.kategori ada dan bertipe ENUM('Fast Moving','Slow Moving') (default 'Slow Moving').
// Aman dijalankan berulang (tidak melakukan apa pun bila sudah sesuai).
//   - kolom belum ada            : ditambahkan, semua sparepart lama menjadi Slow Moving
//   - kolom ada tapi bukan ENUM  : (mis. dibuat manual sebagai teks) nilainya dirapikan lalu tipenya diubah;
//                                  yang diawali "fast" menjadi Fast Moving, selain itu Slow Moving
// Mengembalikan true bila kolom sudah sesuai setelah pemanggilan ini.
function sparepart_ensure_kategori_column(mysqli $conn): bool
{
    try {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM spareparts LIKE 'kategori'");
        $col = $res ? mysqli_fetch_assoc($res) : null;

        if (!$col) {
            mysqli_query(
                $conn,
                "ALTER TABLE spareparts
                 ADD COLUMN kategori ENUM('Fast Moving','Slow Moving') NOT NULL DEFAULT 'Slow Moving' AFTER satuan"
            );
        } elseif (strcasecmp((string)$col['Type'], SPAREPART_KATEGORI_TYPE) !== 0) {
            mysqli_query($conn, "ALTER TABLE spareparts MODIFY COLUMN kategori VARCHAR(30) NULL");
            mysqli_query(
                $conn,
                "UPDATE spareparts
                 SET kategori = CASE WHEN LOWER(TRIM(kategori)) LIKE 'fast%' THEN 'Fast Moving' ELSE 'Slow Moving' END"
            );
            mysqli_query(
                $conn,
                "ALTER TABLE spareparts
                 MODIFY COLUMN kategori ENUM('Fast Moving','Slow Moving') NOT NULL DEFAULT 'Slow Moving'"
            );
        }

        $check = mysqli_query($conn, "SHOW COLUMNS FROM spareparts LIKE 'kategori'");
        $after = $check ? mysqli_fetch_assoc($check) : null;
        return $after && strcasecmp((string)$after['Type'], SPAREPART_KATEGORI_TYPE) === 0;
    } catch (Throwable $e) {
        // Mis. akun DB tanpa hak ALTER: lanjutkan; query berikutnya akan gagal dengan pesan yang jelas.
        return false;
    }
}
