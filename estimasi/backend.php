<?php
session_start();
require_once '../config.php';
require_once '../estimasi_helper.php';

global $conn;

header('Content-Type: application/json');

function est_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function est_fail(string $message): void
{
    est_json(['success' => false, 'message' => $message]);
}

function est_rupiah(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    est_fail('Unauthorized');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Jumlah jasa + sparepart saat ini (dipakai memvalidasi diskon).
function est_current_subtotal(mysqli $conn, int $id): float
{
    $svc = mysqli_query($conn, "SELECT COALESCE(SUM(subtotal), 0) AS t FROM estimasi_services WHERE estimasi_id = $id");
    $itm = mysqli_query($conn, "SELECT COALESCE(SUM(subtotal), 0) AS t FROM estimasi_items WHERE estimasi_id = $id");
    $svcRow = $svc ? mysqli_fetch_assoc($svc) : [];
    $itmRow = $itm ? mysqli_fetch_assoc($itm) : [];
    return (float)($svcRow['t'] ?? 0) + (float)($itmRow['t'] ?? 0);
}

function est_exists(mysqli $conn, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    $res = mysqli_query($conn, "SELECT id FROM estimasi WHERE id = $id LIMIT 1");
    return $res && mysqli_num_rows($res) > 0;
}

// Harga khusus: kosong/negatif/bukan angka ditolak. Nol diperbolehkan (mis. gratis untuk customer tertentu).
function est_parse_custom_price($raw): ?float
{
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return null;
    }
    $price = (float)$raw;
    return $price < 0 ? null : $price;
}

try {
    estimasi_ensure_schema($conn);

    switch ($action) {

        // CREATE - Buat estimasi baru (header saja; jasa & sparepart diisi di modal Analisa & Estimasi)
        case 'create':
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $tanggal = trim((string)($_POST['tanggal'] ?? ''));
            $keluhan = trim((string)($_POST['keluhan_customer'] ?? ''));

            if ($vehicleId <= 0) {
                est_fail('Kendaraan wajib dipilih');
            }
            if ($tanggal === '') {
                est_fail('Tanggal wajib diisi');
            }
            if ($keluhan === '') {
                est_fail('Keluhan customer wajib diisi');
            }

            $date = DateTime::createFromFormat('Y-m-d', $tanggal);
            if ($date === false || $date->format('Y-m-d') !== $tanggal) {
                est_fail('Format tanggal tidak valid');
            }

            // Customer selalu mengikuti pemilik kendaraan agar tidak bisa tidak cocok.
            $vehicleRes = mysqli_query($conn, "SELECT customer_id FROM vehicles WHERE id = $vehicleId LIMIT 1");
            $vehicleRow = $vehicleRes ? mysqli_fetch_assoc($vehicleRes) : null;
            if (!$vehicleRow) {
                est_fail('Kendaraan tidak ditemukan');
            }
            $customerId = (int)($vehicleRow['customer_id'] ?? 0);
            if ($customerId <= 0) {
                est_fail('Customer untuk kendaraan tidak valid');
            }

            $kodePrefix = 'EST-' . $date->format('Ymd') . '-';
            $kodePrefixEsc = mysqli_real_escape_string($conn, $kodePrefix);
            $tanggalEsc = mysqli_real_escape_string($conn, $tanggal);
            $keluhanEsc = mysqli_real_escape_string($conn, $keluhan);
            $userSql = $currentUserId > 0 ? (string)$currentUserId : 'NULL';

            $estimasiId = 0;
            $kode = '';
            $lastError = '';

            // Ulangi beberapa kali bila dua estimasi dibuat bersamaan dan nomor urutnya bentrok.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $seqRes = mysqli_query(
                    $conn,
                    "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(kode_estimasi, '-', -1) AS UNSIGNED)), 0) AS max_seq
                     FROM estimasi
                     WHERE kode_estimasi LIKE '{$kodePrefixEsc}%'"
                );
                $seqRow = $seqRes ? mysqli_fetch_assoc($seqRes) : [];
                $kode = $kodePrefix . str_pad((string)(((int)($seqRow['max_seq'] ?? 0)) + 1), 4, '0', STR_PAD_LEFT);
                $kodeEsc = mysqli_real_escape_string($conn, $kode);

                $errno = 0;
                $ok = estimasi_query(
                    $conn,
                    "INSERT INTO estimasi (kode_estimasi, customer_id, vehicle_id, tanggal, keluhan_customer, created_by)
                     VALUES ('$kodeEsc', $customerId, $vehicleId, '$tanggalEsc', '$keluhanEsc', $userSql)",
                    $errno
                );

                if ($ok !== false) {
                    $estimasiId = (int)mysqli_insert_id($conn);
                    break;
                }
                if ($errno !== 1062) {
                    $lastError = mysqli_error($conn);
                    break;
                }
            }

            if ($estimasiId <= 0) {
                est_fail('Gagal membuat estimasi: ' . ($lastError !== '' ? $lastError : 'Kode estimasi bentrok, silakan coba lagi'));
            }

            estimasi_audit($conn, $currentUserId, 'CREATE_ESTIMASI', $estimasiId, "Estimasi $kode dibuat");
            est_json([
                'success' => true,
                'message' => 'Estimasi berhasil dibuat',
                'kode' => $kode,
                'estimasi_id' => $estimasiId,
            ]);
            break;

        // READ - Daftar estimasi
        case 'read':
            $search = trim((string)($_GET['search'] ?? ''));
            $vehicleId = (int)($_GET['vehicle_id'] ?? 0);
            $month = trim((string)($_GET['month'] ?? ''));

            $conditions = [];
            if ($search !== '') {
                $searchEsc = mysqli_real_escape_string($conn, $search);
                $conditions[] = "(e.kode_estimasi LIKE '%$searchEsc%'
                                  OR c.name LIKE '%$searchEsc%'
                                  OR v.nomor_polisi LIKE '%$searchEsc%')";
            }
            if ($vehicleId > 0) {
                $conditions[] = "e.vehicle_id = $vehicleId";
            }
            if ($month !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                $conditions[] = "e.tanggal >= '$month-01' AND e.tanggal < DATE_ADD('$month-01', INTERVAL 1 MONTH)";
            }

            $sql = "SELECT e.id, e.kode_estimasi, e.tanggal, e.kilometer, e.nama_mekanik, e.discount_amount, e.use_custom_price,
                           c.name AS customer_name, c.phone AS customer_phone,
                           v.nomor_polisi, v.merk, v.model,
                           COALESCE((SELECT SUM(subtotal) FROM estimasi_services WHERE estimasi_id = e.id), 0) AS total_jasa,
                           COALESCE((SELECT SUM(subtotal) FROM estimasi_items WHERE estimasi_id = e.id), 0) AS total_sparepart
                    FROM estimasi e
                    LEFT JOIN customers c ON c.id = e.customer_id
                    LEFT JOIN vehicles v ON v.id = e.vehicle_id";
            if ($conditions) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $sql .= ' ORDER BY e.id DESC';

            $result = mysqli_query($conn, $sql);
            $rows = [];
            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $totals = estimasi_totals((float)$row['total_jasa'], (float)$row['total_sparepart'], (float)$row['discount_amount']);
                $row['total_estimasi'] = $totals['total_estimasi'];
                $rows[] = $row;
            }
            est_json(['success' => true, 'data' => $rows]);
            break;

        // READ ONE - Detail estimasi beserta jasa & sparepart
        case 'read_one':
            $estimasi = estimasi_load($conn, (int)($_GET['id'] ?? 0));
            if (!$estimasi) {
                est_fail('Estimasi tidak ditemukan');
            }
            est_json(['success' => true, 'data' => $estimasi]);
            break;

        // Daftar kendaraan (beserta customer) untuk filter & form buat estimasi
        case 'get_all_vehicles':
            $result = mysqli_query(
                $conn,
                "SELECT v.id, v.customer_id, v.nomor_polisi, v.merk, v.model,
                        c.name AS customer_name, c.phone AS customer_phone
                 FROM vehicles v
                 JOIN customers c ON v.customer_id = c.id
                 ORDER BY c.name ASC, v.nomor_polisi ASC"
            );
            $vehicles = [];
            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $vehicles[] = $row;
            }
            est_json(['success' => true, 'data' => $vehicles]);
            break;

        // Semua sparepart untuk dropdown. Berbeda dari SPK: stok TIDAK dicek dan tidak ditampilkan.
        case 'get_all_spareparts':
            $result = mysqli_query(
                $conn,
                "SELECT id, kode_sparepart, nama, satuan, harga_jual_default
                 FROM spareparts
                 ORDER BY nama ASC"
            );
            if (!$result) {
                est_fail('Gagal memuat sparepart: ' . mysqli_error($conn));
            }
            $spareparts = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $spareparts[] = $row;
            }
            est_json(['success' => true, 'data' => $spareparts]);
            break;

        // UPDATE - Analisa mekanik, deskripsi, saran, kilometer, dan diskon
        case 'update_analisa':
            $id = (int)($_POST['id'] ?? 0);
            if (!est_exists($conn, $id)) {
                est_fail('Estimasi tidak ditemukan');
            }

            $analisa = trim((string)($_POST['analisa_mekanik'] ?? ''));
            $namaMekanik = trim((string)($_POST['nama_mekanik'] ?? ''));
            $serviceDescription = trim((string)($_POST['service_description'] ?? ''));
            $saran = trim((string)($_POST['saran_service'] ?? ''));

            // Kilometer opsional pada estimasi (kendaraan sering belum di bengkel), tetapi bila diisi harus angka.
            $kilometerRaw = str_replace(['.', ',', ' '], '', trim((string)($_POST['kilometer'] ?? '')));
            if ($kilometerRaw === '') {
                $kilometerSql = 'NULL';
            } elseif (ctype_digit($kilometerRaw) && strlen($kilometerRaw) <= 9) {
                $kilometerSql = (string)(int)$kilometerRaw;
            } else {
                est_fail('Kilometer harus berupa angka');
            }

            $discountRaw = trim((string)($_POST['discount_amount'] ?? ''));
            if ($discountRaw === '') {
                $discount = 0.0;
            } elseif (is_numeric($discountRaw) && (float)$discountRaw >= 0) {
                $discount = (float)$discountRaw;
            } else {
                est_fail('Diskon harus berupa angka 0 atau lebih');
            }

            $subtotal = est_current_subtotal($conn, $id);
            if ($discount > $subtotal) {
                est_fail('Diskon (' . est_rupiah($discount) . ') melebihi subtotal estimasi (' . est_rupiah($subtotal) . ')');
            }

            $ok = mysqli_query(
                $conn,
                "UPDATE estimasi SET
                    analisa_mekanik = '" . mysqli_real_escape_string($conn, $analisa) . "',
                    nama_mekanik = " . ($namaMekanik === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $namaMekanik) . "'") . ",
                    service_description = '" . mysqli_real_escape_string($conn, $serviceDescription) . "',
                    saran_service = '" . mysqli_real_escape_string($conn, $saran) . "',
                    kilometer = $kilometerSql,
                    discount_amount = $discount
                 WHERE id = $id"
            );
            if (!$ok) {
                est_fail('Gagal menyimpan: ' . mysqli_error($conn));
            }
            est_json(['success' => true, 'message' => 'Estimasi berhasil disimpan']);
            break;

        // ADD SERVICE - harga diambil dari master jasa di server, bukan dari kiriman browser
        case 'add_service':
            $id = (int)($_POST['estimasi_id'] ?? 0);
            $servicePriceId = (int)($_POST['service_price_id'] ?? 0);
            $qty = (int)($_POST['qty'] ?? 0);

            if ($qty <= 0) {
                est_fail('Qty harus lebih dari 0');
            }
            if (!est_exists($conn, $id)) {
                est_fail('Estimasi tidak ditemukan');
            }

            $svcRes = mysqli_query($conn, "SELECT harga FROM service_prices WHERE id = $servicePriceId AND is_active = 'Y' LIMIT 1");
            $svcRow = $svcRes ? mysqli_fetch_assoc($svcRes) : null;
            if (!$svcRow) {
                est_fail('Jasa tidak ditemukan atau tidak aktif');
            }
            $harga = (float)$svcRow['harga'];

            $ok = mysqli_query(
                $conn,
                "INSERT INTO estimasi_services (estimasi_id, service_price_id, qty, harga)
                 VALUES ($id, $servicePriceId, $qty, $harga)"
            );
            if (!$ok) {
                est_fail('Gagal menambahkan jasa: ' . mysqli_error($conn));
            }
            est_json(['success' => true, 'message' => 'Jasa berhasil ditambahkan']);
            break;

        case 'delete_service':
            $lineId = (int)($_POST['id'] ?? 0);
            mysqli_query($conn, "DELETE FROM estimasi_services WHERE id = $lineId");
            if (mysqli_affected_rows($conn) < 1) {
                est_fail('Jasa tidak ditemukan');
            }
            est_json(['success' => true, 'message' => 'Jasa berhasil dihapus']);
            break;

        // ADD SPAREPART - tanpa cek stok dan tanpa mengurangi stok
        case 'add_sparepart':
            $id = (int)($_POST['estimasi_id'] ?? 0);
            $sparepartId = (int)($_POST['sparepart_id'] ?? 0);
            $qty = (int)($_POST['qty'] ?? 0);

            if ($qty <= 0) {
                est_fail('Qty harus lebih dari 0');
            }
            if (!est_exists($conn, $id)) {
                est_fail('Estimasi tidak ditemukan');
            }

            $spRes = mysqli_query($conn, "SELECT harga_jual_default FROM spareparts WHERE id = $sparepartId LIMIT 1");
            $spRow = $spRes ? mysqli_fetch_assoc($spRes) : null;
            if (!$spRow) {
                est_fail('Sparepart tidak ditemukan');
            }
            $hargaSatuan = (float)$spRow['harga_jual_default'];

            $ok = mysqli_query(
                $conn,
                "INSERT INTO estimasi_items (estimasi_id, sparepart_id, qty, harga_satuan)
                 VALUES ($id, $sparepartId, $qty, $hargaSatuan)"
            );
            if (!$ok) {
                est_fail('Gagal menambahkan sparepart: ' . mysqli_error($conn));
            }
            est_json(['success' => true, 'message' => 'Sparepart berhasil ditambahkan']);
            break;

        case 'delete_sparepart':
            $lineId = (int)($_POST['id'] ?? 0);
            mysqli_query($conn, "DELETE FROM estimasi_items WHERE id = $lineId");
            if (mysqli_affected_rows($conn) < 1) {
                est_fail('Sparepart tidak ditemukan');
            }
            est_json(['success' => true, 'message' => 'Sparepart berhasil dihapus']);
            break;

        // TOGGLE CUSTOM PRICE - mengaktifkan/menonaktifkan mode harga khusus untuk estimasi ini
        case 'toggle_custom_price':
            $id = (int)($_POST['id'] ?? 0);
            $useCustom = (int)($_POST['use_custom'] ?? 0) === 1 ? 1 : 0;
            if (!est_exists($conn, $id)) {
                est_fail('Estimasi tidak ditemukan');
            }

            mysqli_query($conn, "UPDATE estimasi SET use_custom_price = $useCustom WHERE id = $id");
            // Saat dimatikan, semua baris kembali memakai harga normal (harga khusus tetap tersimpan).
            if ($useCustom === 0) {
                mysqli_query($conn, "UPDATE estimasi_items SET use_custom_price = 0 WHERE estimasi_id = $id");
                mysqli_query($conn, "UPDATE estimasi_services SET use_custom_price = 0 WHERE estimasi_id = $id");
            }
            est_json(['success' => true, 'message' => 'Mode harga khusus ' . ($useCustom ? 'diaktifkan' : 'dinonaktifkan')]);
            break;

        case 'update_item_custom_price':
            $itemId = (int)($_POST['item_id'] ?? 0);
            $hargaCustom = est_parse_custom_price($_POST['harga_custom'] ?? null);
            if ($itemId <= 0 || $hargaCustom === null) {
                est_fail('Harga khusus harus berupa angka 0 atau lebih');
            }

            mysqli_query($conn, "UPDATE estimasi_items SET harga_custom = $hargaCustom, use_custom_price = 1 WHERE id = $itemId");
            $found = mysqli_query($conn, "SELECT id FROM estimasi_items WHERE id = $itemId LIMIT 1");
            if (!$found || mysqli_num_rows($found) === 0) {
                est_fail('Sparepart tidak ditemukan');
            }
            est_json(['success' => true, 'message' => 'Harga khusus berhasil diupdate']);
            break;

        case 'update_service_custom_price':
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $hargaCustom = est_parse_custom_price($_POST['harga_custom'] ?? null);
            if ($serviceId <= 0 || $hargaCustom === null) {
                est_fail('Harga khusus harus berupa angka 0 atau lebih');
            }

            mysqli_query($conn, "UPDATE estimasi_services SET harga_custom = $hargaCustom, use_custom_price = 1 WHERE id = $serviceId");
            $found = mysqli_query($conn, "SELECT id FROM estimasi_services WHERE id = $serviceId LIMIT 1");
            if (!$found || mysqli_num_rows($found) === 0) {
                est_fail('Jasa tidak ditemukan');
            }
            est_json(['success' => true, 'message' => 'Harga khusus jasa berhasil diupdate']);
            break;

        // DELETE - Estimasi tidak punya status & tidak menyentuh keuangan/stok, jadi boleh dihapus.
        // Baris jasa & sparepart ikut terhapus lewat ON DELETE CASCADE.
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $infoRes = mysqli_query(
                $conn,
                "SELECT e.kode_estimasi, c.name AS customer_name
                 FROM estimasi e LEFT JOIN customers c ON c.id = e.customer_id
                 WHERE e.id = $id LIMIT 1"
            );
            $info = $infoRes ? mysqli_fetch_assoc($infoRes) : null;
            if (!$info) {
                est_fail('Estimasi tidak ditemukan');
            }

            if (!mysqli_query($conn, "DELETE FROM estimasi WHERE id = $id")) {
                est_fail('Gagal menghapus estimasi: ' . mysqli_error($conn));
            }
            estimasi_audit($conn, $currentUserId, 'DELETE_ESTIMASI', $id, "Estimasi {$info['kode_estimasi']} ({$info['customer_name']}) dihapus");
            est_json(['success' => true, 'message' => 'Estimasi berhasil dihapus']);
            break;

        default:
            est_fail('Action tidak valid');
    }
} catch (Throwable $e) {
    est_fail('Terjadi kesalahan pada server: ' . $e->getMessage());
}
