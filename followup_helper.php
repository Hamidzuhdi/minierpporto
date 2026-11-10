<?php

// Follow-up customer: reminder dihitung dari SPK terakhir (tidak dibatalkan) tiap customer,
// hasil follow-up dicatat per tahap (7 hari / 2 bulan / 6 bulan) sebagai riwayat.

const FOLLOWUP_TAHAP_INTERVAL = [
    '7days' => '7 DAY',
    '2months' => '2 MONTH',
    '6months' => '6 MONTH',
];

const FOLLOWUP_HASIL = ['no_respon', 'respon', 'prospek'];

function followup_ensure_table(mysqli $conn): void
{
    mysqli_query($conn, "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    followup_ensure_hasil_values($conn);
}

// Nama hasil lama (tidak_jawab / jawab / jawab_lanjut) diganti menjadi no_respon / respon / prospek.
// Tabel yang sudah terlanjur dibuat dengan nama lama dimigrasikan beserta datanya.
function followup_ensure_hasil_values(mysqli $conn): void
{
    $res = mysqli_query($conn, "SHOW COLUMNS FROM customer_followups LIKE 'hasil'");
    $col = $res ? mysqli_fetch_assoc($res) : null;
    if (!$col || stripos((string)($col['Type'] ?? ''), "'tidak_jawab'") === false) {
        return;
    }

    mysqli_query($conn, "ALTER TABLE customer_followups MODIFY COLUMN hasil ENUM('tidak_jawab','jawab','jawab_lanjut','no_respon','respon','prospek') NOT NULL");
    mysqli_query($conn, "
        UPDATE customer_followups
        SET hasil = CASE hasil
            WHEN 'tidak_jawab' THEN 'no_respon'
            WHEN 'jawab' THEN 'respon'
            WHEN 'jawab_lanjut' THEN 'prospek'
            ELSE hasil
        END
    ");
    mysqli_query($conn, "ALTER TABLE customer_followups MODIFY COLUMN hasil ENUM('no_respon','respon','prospek') NOT NULL");
}

// Nilai filter reminder di halaman Customers (7days | 1 / 2months | 6months) -> tahap follow-up.
function followup_tahap_from_filter(string $reminder): ?string
{
    if ($reminder === '7days') {
        return '7days';
    }
    if ($reminder === '1' || $reminder === '2months') {
        return '2months';
    }
    if ($reminder === '6months') {
        return '6months';
    }
    return null;
}

// Subquery: SPK terakhir (tidak dibatalkan) tiap customer.
function followup_last_spk_sql(): string
{
    return "SELECT s.customer_id, s.id AS last_spk_id, s.created_at AS last_spk_at
            FROM spk s
            WHERE COALESCE(s.status_spk, '') <> 'Dibatalkan'
              AND s.id = (
                  SELECT s2.id
                  FROM spk s2
                  WHERE s2.customer_id = s.customer_id
                    AND COALESCE(s2.status_spk, '') <> 'Dibatalkan'
                  ORDER BY s2.created_at DESC, s2.id DESC
                  LIMIT 1
              )";
}

// Kondisi WHERE: SPK terakhir sudah melewati tahap, dan customer belum menjawab follow-up tahap itu.
// "No respon" tetap masuk daftar (perlu dicoba lagi); "respon" / "prospek" mengeluarkannya.
function followup_due_condition_sql(string $tahap, string $lastSpkAlias = 'ls'): string
{
    $interval = FOLLOWUP_TAHAP_INTERVAL[$tahap];
    return "DATE_ADD(DATE($lastSpkAlias.last_spk_at), INTERVAL $interval) <= CURDATE()
            AND NOT EXISTS (
                SELECT 1 FROM customer_followups f
                WHERE f.spk_id = $lastSpkAlias.last_spk_id
                  AND f.tahap = '$tahap'
                  AND f.hasil IN ('respon', 'prospek')
            )";
}

function followup_count_due(mysqli $conn, string $tahap): int
{
    if (!isset(FOLLOWUP_TAHAP_INTERVAL[$tahap])) {
        return 0;
    }

    $res = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM customers c
         JOIN (" . followup_last_spk_sql() . ") ls ON ls.customer_id = c.id
         WHERE " . followup_due_condition_sql($tahap)
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return (int)($row['total'] ?? 0);
}
