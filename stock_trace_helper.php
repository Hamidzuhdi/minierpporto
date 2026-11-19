<?php

// Penelusuran pemakaian stok untuk halaman Warehouse Out: item sebuah PO dipakai di SPK mana saja.
//
// Stok sebuah sparepart dianggap antrean FIFO (yang paling lama habis lebih dulu):
//   1. stok awal      : stok yang sudah ada sebelum PO pertama yang tercatat (bukan dari PO mana pun),
//   2. PO-PO          : urut tanggal PO (lalu nomor PO), tanpa PO yang sudah di-Refund.
// Pemakaian diambil dari spk_items, urut tanggal SPK (lalu nomor SPK), lalu menghabiskan antrean dari depan.
//
// Akibatnya alokasi SPK lama tidak pernah bergeser oleh SPK yang lebih baru; SPK baru hanya
// mengambil sisa stok yang masih ada setelah semua SPK sebelumnya.
//
// Pemakaian dihitung dari SEMUA SPK yang stoknya sudah terpotong, yaitu semua status kecuali 'Dibatalkan'
// (stok dipotong saat sparepart ditambahkan ke SPK dan baru dikembalikan saat SPK dibatalkan). Termasuk
// 'Menunggu Konfirmasi' dan status lanjutan seperti 'Sudah Reminder WA' / 'Sudah FU'.

// Alokasi FIFO untuk satu sparepart.
// Hasil: [purchase_item_id => ['qty_pakai' => int, 'spk_usage' => [ ['spk_id','kode_spk','tanggal','status_spk','qty'], ... ]]]
// Hanya item PO yang memang kebagian pemakaian yang ada di hasil.
function stock_trace_allocate(mysqli $conn, int $sparepartId): array
{
    $spRes = mysqli_query($conn, "SELECT current_stock FROM spareparts WHERE id = $sparepartId LIMIT 1");
    $spRow = $spRes ? mysqli_fetch_assoc($spRes) : null;
    $currentStock = (int)($spRow['current_stock'] ?? 0);

    $layerRes = mysqli_query(
        $conn,
        "SELECT pi.id, pi.qty
         FROM purchase_items pi
         JOIN purchases p ON p.id = pi.purchase_id
         WHERE pi.sparepart_id = $sparepartId
           AND COALESCE(p.status, '') <> 'Refund'
         ORDER BY p.tanggal ASC, p.id ASC, pi.id ASC"
    );
    $layers = [];
    $totalPurchased = 0;
    while ($layerRes && ($row = mysqli_fetch_assoc($layerRes))) {
        $layers[] = ['id' => (int)$row['id'], 'remaining' => (int)$row['qty']];
        $totalPurchased += (int)$row['qty'];
    }

    $eventRes = mysqli_query(
        $conn,
        "SELECT si.qty, s.id AS spk_id, s.kode_unik_reference AS kode_spk, s.tanggal, s.status_spk
         FROM spk_items si
         JOIN spk s ON s.id = si.spk_id
         WHERE si.sparepart_id = $sparepartId
           AND COALESCE(s.status_spk, '') <> 'Dibatalkan'
         ORDER BY s.tanggal ASC, s.id ASC, si.id ASC"
    );
    $events = [];
    $totalUsed = 0;
    while ($eventRes && ($row = mysqli_fetch_assoc($eventRes))) {
        $events[] = $row;
        $totalUsed += (int)$row['qty'];
    }

    // stok sekarang = stok awal + total PO - total terpakai  =>  stok awal = sisanya.
    // Penyesuaian stok manual ikut terserap di sini. Tidak boleh negatif.
    $opening = max(0, $currentStock - $totalPurchased + $totalUsed);

    $queue = array_merge([['id' => null, 'remaining' => $opening]], $layers);
    $head = 0;
    $result = [];

    foreach ($events as $event) {
        $need = (int)$event['qty'];
        while ($need > 0 && $head < count($queue)) {
            if ($queue[$head]['remaining'] <= 0) {
                $head++;
                continue;
            }

            $take = min($need, $queue[$head]['remaining']);
            $layerId = $queue[$head]['id'];

            // Jatah dari stok awal tidak ditampilkan di PO mana pun.
            if ($layerId !== null) {
                if (!isset($result[$layerId])) {
                    $result[$layerId] = ['qty_pakai' => 0, 'spk_usage' => []];
                }
                $result[$layerId]['qty_pakai'] += $take;

                $spkId = (int)$event['spk_id'];
                if (!isset($result[$layerId]['spk_usage'][$spkId])) {
                    $result[$layerId]['spk_usage'][$spkId] = [
                        'spk_id' => $spkId,
                        'kode_spk' => $event['kode_spk'],
                        'tanggal' => $event['tanggal'],
                        'status_spk' => $event['status_spk'],
                        'qty' => 0,
                    ];
                }
                $result[$layerId]['spk_usage'][$spkId]['qty'] += $take;
            }

            $queue[$head]['remaining'] -= $take;
            $need -= $take;
        }
    }

    foreach ($result as $layerId => $entry) {
        $result[$layerId]['spk_usage'] = array_values($entry['spk_usage']);
    }

    return $result;
}

// Item sebuah PO beserta jumlah terpakai/sisa dan daftar SPK yang memakainya. null jika PO tidak ada.
function stock_trace_purchase_items(mysqli $conn, int $purchaseId): ?array
{
    $pRes = mysqli_query($conn, "SELECT status FROM purchases WHERE id = $purchaseId LIMIT 1");
    $purchase = $pRes ? mysqli_fetch_assoc($pRes) : null;
    if (!$purchase) {
        return null;
    }
    // Stok PO yang di-Refund sudah dikurangi, jadi tidak ada lagi yang bisa "terpakai" atau "tersisa".
    $refunded = (($purchase['status'] ?? '') === 'Refund');

    $res = mysqli_query(
        $conn,
        "SELECT pi.id, pi.purchase_id, pi.sparepart_id, pi.qty AS qty_beli, pi.harga_beli,
                s.nama AS sparepart_name, s.kode_sparepart, s.satuan
         FROM purchase_items pi
         JOIN spareparts s ON s.id = pi.sparepart_id
         WHERE pi.purchase_id = $purchaseId
         ORDER BY s.nama ASC, pi.id ASC"
    );

    $allocations = [];
    $items = [];
    while ($res && ($item = mysqli_fetch_assoc($res))) {
        $sparepartId = (int)$item['sparepart_id'];
        if (!isset($allocations[$sparepartId])) {
            $allocations[$sparepartId] = stock_trace_allocate($conn, $sparepartId);
        }

        $alloc = $refunded ? null : ($allocations[$sparepartId][(int)$item['id']] ?? null);
        $qtyBeli = (int)$item['qty_beli'];
        $qtyPakai = $alloc ? min($qtyBeli, (int)$alloc['qty_pakai']) : 0;

        $item['qty_beli'] = $qtyBeli;
        $item['qty_pakai'] = $qtyPakai;
        $item['qty_sisa'] = $refunded ? 0 : max(0, $qtyBeli - $qtyPakai);
        $item['spk_usage'] = $alloc ? $alloc['spk_usage'] : [];
        $item['refunded'] = $refunded;
        $items[] = $item;
    }

    return $items;
}
