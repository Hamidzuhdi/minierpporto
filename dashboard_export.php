<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$export_type = $_GET['export_type'] ?? 'json'; // json, excel, or pdf

// ===== 1. JASA MEKANIK (Service/Labor) =====
if ($action === 'get_jasa_mekanik') {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-t');
    $view_type = $_GET['view_type'] ?? 'detail'; // detail or all
    
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to = mysqli_real_escape_string($conn, $date_to);
    
    if ($view_type === 'all') {
        // Aggregate by spk_id
        $sql = "SELECT 
                    s.id as spk_id,
                    s.kode_unik_reference,
                    DATE_FORMAT(s.tanggal, '%d-%m-%Y') as tanggal,
                    c.name as customer_name,
                    v.nomor_polisi,
                    s.status_spk,
                    COALESCE(SUM(svc.subtotal), 0) as total_jasa
                FROM spk s
                LEFT JOIN customers c ON s.customer_id = c.id
                LEFT JOIN vehicles v ON s.vehicle_id = v.id
                LEFT JOIN spk_services svc ON s.id = svc.spk_id
                WHERE DATE(s.tanggal) BETWEEN '$date_from' AND '$date_to'
                  AND s.status_spk NOT IN ('Dibatalkan', 'Menunggu Konfirmasi')
                GROUP BY s.id, s.kode_unik_reference, s.tanggal, c.name, v.nomor_polisi, s.status_spk
                ORDER BY s.tanggal DESC";
    } else {
        // Detail view per service item
        $sql = "SELECT 
                    s.id as spk_id,
                    s.kode_unik_reference,
                    DATE_FORMAT(s.tanggal, '%d-%m-%Y') as tanggal,
                    c.name as customer_name,
                    v.nomor_polisi,
                    sp.nama_jasa as service_name,
                    svc.qty,
                    svc.harga,
                    COALESCE(svc.subtotal, 0) as total_jasa,
                    s.status_spk
                FROM spk s
                LEFT JOIN customers c ON s.customer_id = c.id
                LEFT JOIN vehicles v ON s.vehicle_id = v.id
                LEFT JOIN spk_services svc ON s.id = svc.spk_id
                LEFT JOIN service_prices sp ON svc.service_price_id = sp.id
                WHERE DATE(s.tanggal) BETWEEN '$date_from' AND '$date_to'
                  AND s.status_spk NOT IN ('Dibatalkan', 'Menunggu Konfirmasi')
                  AND svc.id IS NOT NULL
                ORDER BY s.tanggal DESC, s.id";
    }
    
    $result = mysqli_query($conn, $sql);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    if ($export_type === 'excel') {
        exportToExcel($data, 'Jasa Mekanik', 'jasa_mekanik', $view_type === 'all' ? ['spk_id', 'kode_unik_reference', 'tanggal', 'customer_name', 'nomor_polisi', 'status_spk', 'total_jasa'] : ['spk_id', 'kode_unik_reference', 'tanggal', 'customer_name', 'nomor_polisi', 'service_name', 'qty', 'harga', 'total_jasa', 'status_spk']);
    } else {
        echo json_encode(['success' => true, 'data' => $data]);
    }
}

// ===== 2. BIAYA HPP SPAREPART =====
elseif ($action === 'get_biaya_hpp_sparepart') {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-t');
    
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to = mysqli_real_escape_string($conn, $date_to);
    
    $sql = "SELECT 
                s.id as spk_id,
                s.kode_unik_reference,
                DATE_FORMAT(s.tanggal, '%d-%m-%Y') as tanggal,
                c.name as customer_name,
                v.nomor_polisi,
                sp.kode_sparepart,
                sp.nama as sparepart_name,
                si.qty,
                si.hpp_satuan,
                (si.qty * COALESCE(si.hpp_satuan, sp.harga_beli_default, 0)) as hpp_total,
                s.status_spk
            FROM spk s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN vehicles v ON s.vehicle_id = v.id
            LEFT JOIN spk_items si ON s.id = si.spk_id
            LEFT JOIN spareparts sp ON si.sparepart_id = sp.id
            WHERE DATE(s.tanggal) BETWEEN '$date_from' AND '$date_to'
              AND s.status_spk NOT IN ('Dibatalkan', 'Menunggu Konfirmasi')
              AND si.spk_id IS NOT NULL
            ORDER BY s.tanggal DESC";
    
    $result = mysqli_query($conn, $sql);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    if ($export_type === 'excel') {
        exportToExcel($data, 'Biaya HPP Sparepart', 'hpp_sparepart', ['spk_id', 'kode_unik_reference', 'tanggal', 'customer_name', 'nomor_polisi', 'kode_sparepart', 'sparepart_name', 'qty', 'hpp_satuan', 'hpp_total', 'status_spk']);
    } else {
        echo json_encode(['success' => true, 'data' => $data]);
    }
}

// ===== 3. PIUTANG AKTIF (INVOICE BELUM LUNAS) =====
elseif ($action === 'get_piutang_aktif') {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-t');
    
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to = mysqli_real_escape_string($conn, $date_to);
    
    $sql = "SELECT 
                i.id as invoice_id,
                i.no_invoice,
                DATE_FORMAT(i.tanggal, '%d-%m-%Y') as tanggal,
                c.name as customer_name,
                i.total,
                COALESCE(p.total_bayar, 0) as sudah_bayar,
                (i.total - COALESCE(p.total_bayar, 0)) as sisa_piutang,
                i.status_piutang
            FROM invoices i
            JOIN spk s ON i.spk_id = s.id
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN (SELECT invoice_id, SUM(amount) as total_bayar FROM payments GROUP BY invoice_id) p ON i.id = p.invoice_id
            WHERE DATE(i.tanggal) BETWEEN '$date_from' AND '$date_to'
              AND i.status_piutang NOT IN ('Lunas', 'Tidak_Aktif')
            ORDER BY i.tanggal DESC";
    
    $result = mysqli_query($conn, $sql);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    if ($export_type === 'excel') {
        exportPiutangAktifExcel($data);
    } elseif ($export_type === 'pdf') {
        require_once 'vendor/autoload.php';
        $html = '<h2>Invoice Belum Bayar</h2>';
        $html .= '<p>Tanggal Export: ' . date('d/m/Y H:i:s') . '</p>';
        $html .= '<table border="1" cellpadding="6" cellspacing="0" width="100%">';
        $html .= '<thead><tr style="background-color:#f0f0f0;"><th>ID Invoice</th><th>No Invoice</th><th>Tanggal</th><th>Customer</th><th>Total</th><th>Sudah Bayar</th><th>Sisa Piutang</th><th>Status</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($data as $row) {
            $html .= '<tr>' .
                '<td>' . htmlspecialchars($row['invoice_id']) . '</td>' .
                '<td>' . htmlspecialchars($row['no_invoice']) . '</td>' .
                '<td>' . htmlspecialchars($row['tanggal']) . '</td>' .
                '<td>' . htmlspecialchars($row['customer_name']) . '</td>' .
                '<td>Rp ' . number_format((float)$row['total'], 0, ',', '.') . '</td>' .
                '<td>Rp ' . number_format((float)$row['sudah_bayar'], 0, ',', '.') . '</td>' .
                '<td>Rp ' . number_format((float)$row['sisa_piutang'], 0, ',', '.') . '</td>' .
                '<td>' . htmlspecialchars($row['status_piutang']) . '</td>' .
                '</tr>';
        }
        $html .= '</tbody></table>';

        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10]);
        $mpdf->WriteHTML($html);
        $filename = 'invoice_belum_bayar_' . date('Ymd_His') . '.pdf';
        $mpdf->Output($filename, 'D');
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $data]);
    }
}

// ===== 4. SPK MONTHLY ENTRY =====
elseif ($action === 'get_spk_monthly') {
    $month = $_GET['month'] ?? date('Y-m');
    
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo json_encode(['success' => false, 'data' => []]);
        exit;
    }
    
    $month_esc = mysqli_real_escape_string($conn, $month);
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $sql = "SELECT 
                s.kode_unik_reference,
                DATE_FORMAT(s.tanggal, '%d-%m-%Y') as tanggal,
                c.name as customer_name,
                v.nomor_polisi,
                s.status_spk
            FROM spk s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN vehicles v ON s.vehicle_id = v.id
            WHERE DATE(s.tanggal) BETWEEN '$month_start' AND '$month_end'
            ORDER BY s.tanggal DESC";
    
    $result = mysqli_query($conn, $sql);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    if ($export_type === 'excel') {
        exportToExcel($data, 'SPK Bulanan', 'spk_monthly', ['kode_unik_reference', 'tanggal', 'customer_name', 'nomor_polisi', 'status_spk']);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $data]);
    }
}

// ===== HELPER FUNCTIONS =====

function exportToExcel($data, $sheet_name, $file_prefix, $columns) {
    if (empty($data)) {
        echo "No data to export";
        exit;
    }
    
    // Generate filename
    $filename = sanitizeFilename($file_prefix . '_' . date('YmdHis') . '.csv');
    
    // Start building CSV content
    $csv_lines = [];
    
    // Add headers
    $headers = [];
    foreach ($columns as $col) {
        $headers[] = convertColumnNameToLabel($col);
    }
    $csv_lines[] = csvEscapeLine($headers);
    
    // Add data rows
    foreach ($data as $row) {
        $csv_row = [];
        foreach ($columns as $col) {
            $value = $row[$col] ?? '';
            
            // Format numeric values
            if (is_numeric($value)) {
                $isMoneyCol = (strpos($col, 'total') !== false || strpos($col, 'harga') !== false || strpos($col, 'sisa') !== false || strpos($col, 'sudah') !== false || strpos($col, 'amount') !== false || strpos($col, 'hpp') !== false);
                $isQtyCol = (strpos($col, 'qty') !== false || strpos($col, 'stok') !== false || strpos($col, 'stock') !== false);
                if ($isMoneyCol) {
                    // Prefix "Rp" so Excel treats as text, not number (avoids 800.000 → 800 misparse)
                    $value = 'Rp ' . number_format((float)$value, 0, ',', '.');
                } elseif ($isQtyCol) {
                    $value = number_format((float)$value, 0, ',', '.');
                }
            }
            
            $csv_row[] = $value;
        }
        $csv_lines[] = csvEscapeLine($csv_row);
    }
    
    // Join lines
    $csv_content = "\xEF\xBB\xBF"; // UTF-8 BOM
    $csv_content .= implode("\r\n", $csv_lines);
    
    // Send headers for download
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . strlen($csv_content));

    echo $csv_content;
    exit;
}

// 0-based column index -> Excel column letter (0=A, 1=B, ..., 26=AA, ...)
function xlsxColLetter($index) {
    $letter = '';
    $index++;
    while ($index > 0) {
        $rem = ($index - 1) % 26;
        $letter = chr(65 + $rem) . $letter;
        $index = intdiv($index - 1, 26);
    }
    return $letter;
}

function xlsxTextCell($colIndex, $rowNum, $text, $styleId = null) {
    $ref = xlsxColLetter($colIndex) . $rowNum;
    $sAttr = $styleId !== null ? ' s="' . $styleId . '"' : '';
    $escaped = htmlspecialchars((string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
}

function xlsxNumberCell($colIndex, $rowNum, $number, $styleId = null) {
    $ref = xlsxColLetter($colIndex) . $rowNum;
    $sAttr = $styleId !== null ? ' s="' . $styleId . '"' : '';
    $num = is_numeric($number) ? $number : 0;
    return '<c r="' . $ref . '"' . $sAttr . '><v>' . $num . '</v></c>';
}

// Bangun 1 sheet <worksheet> dari array of rows, setiap row = array of ['type'=>'text'|'number','value'=>...,'style'=>int|null]
function xlsxBuildSheetXml($rows, $colWidths = []) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    if (!empty($colWidths)) {
        $xml .= '<cols>';
        foreach ($colWidths as $index => $width) {
            $colNum = $index + 1;
            $xml .= '<col min="' . $colNum . '" max="' . $colNum . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }
    $xml .= '<sheetData>';
    $rowNum = 1;
    foreach ($rows as $cells) {
        $xml .= '<row r="' . $rowNum . '">';
        $colIndex = 0;
        foreach ($cells as $cell) {
            if ($cell['type'] === 'number') {
                $xml .= xlsxNumberCell($colIndex, $rowNum, $cell['value'], $cell['style'] ?? null);
            } else {
                $xml .= xlsxTextCell($colIndex, $rowNum, $cell['value'], $cell['style'] ?? null);
            }
            $colIndex++;
        }
        $xml .= '</row>';
        $rowNum++;
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

// Export 2 sheet dalam 1 file .xlsx asli (Office Open XML, dibangun via ZipArchive
// bawaan PHP) - supaya dibuka Excel tanpa warning "format tidak cocok".
function exportPiutangAktifExcel($data) {
    if (empty($data)) {
        echo "No data to export";
        exit;
    }

    // Rekap per customer dari data yang sama (supaya konsisten dengan sheet detail)
    $customerSummary = [];
    foreach ($data as $row) {
        $name = $row['customer_name'] !== null && $row['customer_name'] !== '' ? $row['customer_name'] : '(Tanpa Nama)';
        if (!isset($customerSummary[$name])) {
            $customerSummary[$name] = ['jumlah_invoice' => 0, 'total_hutang' => 0.0];
        }
        $customerSummary[$name]['jumlah_invoice']++;
        $customerSummary[$name]['total_hutang'] += (float)$row['sisa_piutang'];
    }
    uasort($customerSummary, function ($a, $b) {
        return $b['total_hutang'] <=> $a['total_hutang'];
    });

    $styleHeader = 1;
    $styleCurrency = 2;

    // Sheet 1: Detail Invoice
    $sheet1Rows = [];
    $sheet1Rows[] = array_map(function ($h) use ($styleHeader) {
        return ['type' => 'text', 'value' => $h, 'style' => $styleHeader];
    }, ['ID Invoice', 'No Invoice', 'Tanggal', 'Customer', 'Total', 'Sudah Bayar', 'Sisa Piutang', 'Status Piutang']);
    foreach ($data as $row) {
        $sheet1Rows[] = [
            ['type' => 'number', 'value' => (int)$row['invoice_id']],
            ['type' => 'text', 'value' => $row['no_invoice']],
            ['type' => 'text', 'value' => $row['tanggal']],
            ['type' => 'text', 'value' => $row['customer_name']],
            ['type' => 'number', 'value' => (float)$row['total'], 'style' => $styleCurrency],
            ['type' => 'number', 'value' => (float)$row['sudah_bayar'], 'style' => $styleCurrency],
            ['type' => 'number', 'value' => (float)$row['sisa_piutang'], 'style' => $styleCurrency],
            ['type' => 'text', 'value' => $row['status_piutang']],
        ];
    }

    // Sheet 2: Rekap Total Hutang per Customer
    $sheet2Rows = [];
    $sheet2Rows[] = array_map(function ($h) use ($styleHeader) {
        return ['type' => 'text', 'value' => $h, 'style' => $styleHeader];
    }, ['Customer', 'Jumlah Invoice', 'Total Hutang']);
    foreach ($customerSummary as $name => $sum) {
        $sheet2Rows[] = [
            ['type' => 'text', 'value' => $name],
            ['type' => 'number', 'value' => (int)$sum['jumlah_invoice']],
            ['type' => 'number', 'value' => (float)$sum['total_hutang'], 'style' => $styleCurrency],
        ];
    }

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>' .
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' .
        '<sheet name="Detail Invoice" sheetId="1" r:id="rId1"/>' .
        '<sheet name="Rekap per Customer" sheetId="2" r:id="rId2"/>' .
        '</sheets>' .
        '</workbook>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp &quot;#,##0"/></numFmts>' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
        '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF0F0F0"/><bgColor indexed="64"/></patternFill></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="3">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' .
        '</cellXfs>' .
        '</styleSheet>';

    $sheet1Xml = xlsxBuildSheetXml($sheet1Rows, [10, 16, 12, 24, 15, 15, 15, 14]);
    $sheet2Xml = xlsxBuildSheetXml($sheet2Rows, [26, 14, 16]);

    $filename = sanitizeFilename('piutang_aktif_' . date('YmdHis') . '.xlsx');
    $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');

    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);
    $zip->addEmptyDir('_rels');
    $zip->addEmptyDir('xl');
    $zip->addEmptyDir('xl/_rels');
    $zip->addEmptyDir('xl/worksheets');
    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($tmpPath));
    readfile($tmpPath);
    unlink($tmpPath);
    exit;
}

function csvEscapeLine($fields) {
    $escaped = [];
    foreach ($fields as $field) {
        // Escape double quotes by doubling them
        $field = str_replace('"', '""', $field);
        // Quote fields that contain comma, newline, or quotes
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            $field = '"' . $field . '"';
        }
        $escaped[] = $field;
    }
    return implode(',', $escaped);
}

function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return $filename;
}

function convertColumnNameToLabel($col) {
    // Convert snake_case or camelCase to readable label
    $col = str_replace(['_', 'Id', 'id'], ' ', $col);
    $col = ucwords($col);
    return $col;
}

// ===== HPP DETAIL (Keuntungan Sparepart) =====
if ($action === 'get_hpp_detail_json') {
    $month = trim($_GET['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo json_encode(['success' => false, 'data' => []]);
        exit;
    }
    $month_esc = mysqli_real_escape_string($conn, $month);

    // Per-sparepart profit summary for the month.
    // Accrual: dihitung begitu item dipakai di SPK, tidak menunggu invoice Lunas.
    // si.subtotal (GENERATED) sudah menghitung harga_custom jika harga khusus aktif.
    $sql = "SELECT
                sp.id, sp.nama as sparepart_name,
                SUM(si.qty) as total_qty,
                SUM(si.qty * si.hpp_satuan) as total_cost,
                SUM(si.subtotal) as total_revenue,
                SUM(si.subtotal - (si.qty * si.hpp_satuan)) as total_profit
            FROM spk_items si
            JOIN spareparts sp ON si.sparepart_id = sp.id
            JOIN spk s ON s.id = si.spk_id
            WHERE s.status_spk <> 'Dibatalkan'
              AND DATE_FORMAT(s.tanggal, '%Y-%m') = '$month_esc'
            GROUP BY sp.id, sp.nama
            ORDER BY total_profit DESC";

    $res = mysqli_query($conn, $sql);
    $data = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $data[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

elseif ($action === 'get_hpp_detail_excel') {
    $month = trim($_GET['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo json_encode(['success' => false]);
        exit;
    }
    $month_esc = mysqli_real_escape_string($conn, $month);

    // Detailed list per SPK item.
    // harga_jual pakai harga_custom jika harga khusus aktif, konsisten dengan si.subtotal (GENERATED).
    $sql = "SELECT
                sp.kode_sparepart as sparepart_code,
                sp.nama as sparepart_name,
                s.kode_unik_reference as spk_code,
                si.qty,
                si.hpp_satuan as harga_beli,
                CASE WHEN si.use_custom_price = 1 AND si.harga_custom IS NOT NULL
                     THEN si.harga_custom
                     ELSE COALESCE(NULLIF(si.harga_satuan, 0), sp.harga_jual_default)
                END as harga_jual,
                (si.qty * si.hpp_satuan) as total_cost,
                si.subtotal as total_revenue,
                (si.subtotal - (si.qty * si.hpp_satuan)) as profit
            FROM spk_items si
            JOIN spareparts sp ON si.sparepart_id = sp.id
            JOIN spk s ON si.spk_id = s.id
            WHERE s.status_spk <> 'Dibatalkan'
              AND DATE_FORMAT(s.tanggal, '%Y-%m') = '$month_esc'
            ORDER BY s.id DESC, sp.nama ASC";

    $res = mysqli_query($conn, $sql);

    $escapeCsvLine = function(array $fields): string {
        $escaped = array_map(function($f) {
            $f = str_replace('"', '""', (string)$f);
            if (preg_match('/[",\n]/', $f)) $f = '"' . $f . '"';
            return $f;
        }, $fields);
        return implode(',', $escaped);
    };

    $lines = [];
    $lines[] = $escapeCsvLine(['Kode Sparepart', 'Sparepart', 'SPK', 'Qty', 'Harga Beli', 'Harga Jual', 'Total Cost', 'Total Revenue', 'Profit']);

    $totalProfit = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        $totalProfit += (float)$row['profit'];
        $lines[] = $escapeCsvLine([
            $row['sparepart_code'],
            $row['sparepart_name'],
            $row['spk_code'],
            (int)$row['qty'],
            'Rp ' . number_format((float)$row['harga_beli'], 0, ',', '.'),
            'Rp ' . number_format((float)$row['harga_jual'], 0, ',', '.'),
            'Rp ' . number_format((float)$row['total_cost'], 0, ',', '.'),
            'Rp ' . number_format((float)$row['total_revenue'], 0, ',', '.'),
            'Rp ' . number_format((float)$row['profit'], 0, ',', '.'),
        ]);
    }

    // Summary row
    $lines[] = '';
    $lines[] = $escapeCsvLine(['', '', '', '', 'TOTAL PROFIT:', '', '', '', 'Rp ' . number_format($totalProfit, 0, ',', '.')]);

    $csvContent = "\xEF\xBB\xBF" . implode("\r\n", $lines);
    $filename = 'keuntungan_sparepart_' . $month . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . strlen($csvContent));
    echo $csvContent;
    exit;
}
?>

