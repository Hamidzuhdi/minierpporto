<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Barang Keluar Gudang";
include '../header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history"></i> History Barang Keluar dari Purchase</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="searchInput" placeholder="Cari ID, supplier, pembuat, atau kode sparepart...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="paidFilter">
                                <option value="">Semua Status Bayar</option>
                                <option value="Belum Bayar">Belum Bayar</option>
                                <option value="Sudah Bayar">Sudah Bayar</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-danger w-100" onclick="exportAllPDF()">
                                <i class="fas fa-file-pdf"></i> Export All PDF
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID Purchase</th>
                                    <th>Tanggal</th>
                                    <th>Supplier</th>
                                    <th>Jumlah Item</th>
                                    <th>Total PO</th>
                                    <th>Dibuat Oleh</th>
                                    <th>Status Purchase</th>
                                    <th>Status Bayar</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody">
                                <tr><td colspan="8" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

<!-- Modal Detail Purchase -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Detail Purchase</h5>
                <div class="ms-auto">
                    <button class="btn btn-sm btn-danger me-2" id="exportDetailPdfBtn" onclick="exportDetailPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body" id="detailContent">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    loadPurchaseHistory();

    $('#paidFilter').on('change', loadPurchaseHistory);
    
    let searchTimeout;
    $('#searchInput').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadPurchaseHistory, 400);
    });
});

// Global variable untuk store purchase ID saat detail dibuka
let currentDetailPurchaseId = null;

function loadPurchaseHistory() {
    let search = $('#searchInput').val();
    let isPaid = $('#paidFilter').val();
    
    $.ajax({
        url: 'backend.php?action=read&search=' + encodeURIComponent(search) + '&is_paid=' + encodeURIComponent(isPaid),
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderPurchaseHistory(response.data || []);
            } else {
                showAlert('danger', response.message || 'Gagal memuat history purchase');
            }
        },
        error: function() {
            showAlert('danger', 'Gagal memuat history purchase');
        }
    });
}

function renderPurchaseHistory(rows) {
    let html = '';
    
    if (rows.length === 0) {
        html = '<tr><td colspan="8" class="text-center">Belum ada history purchase</td></tr>';
    } else {
        rows.forEach(function(row) {
            const paidBadge = (row.is_paid === 'Sudah Bayar')
                ? '<span class="badge bg-success">Sudah Bayar</span>'
                : '<span class="badge bg-warning text-dark">Belum Bayar</span>';

            const purchaseStatusClass = row.status === 'Refund' ? 'bg-danger' : 'bg-info';
            const purchaseStatusBadge = `<span class="badge ${purchaseStatusClass}">${row.status || '-'}</span>`;

            const createdBy = row.created_by_name || '-';
            const createdRole = row.created_by_role ? ` <small class="text-muted">(${row.created_by_role})</small>` : '';
            const itemSummary = `${row.item_count || 0} item / ${row.qty_total || 0} qty`;
            const itemDetails = (row.item_detail || '').split(' | ').filter(Boolean).join('<br>');
            const itemDetailHtml = itemDetails ? `<br><small class="text-muted">${itemDetails}</small>` : '';
            
            html += `
                <tr>
                    <td>#${row.id}</td>
                    <td>${formatDate(row.tanggal)}</td>
                    <td>${row.supplier || '-'}</td>
                    <td>${itemSummary}${itemDetailHtml}</td>
                    <td><strong>${formatCurrency(row.total)}</strong></td>
                    <td>${createdBy}${createdRole}<br><small class="text-muted">${formatDateTime(row.created_at)}</small></td>
                    <td>${purchaseStatusBadge}</td>
                    <td>${paidBadge}${row.paid_at ? `<br><small class="text-muted">${formatDateTime(row.paid_at)}</small>` : ''}</td>
                    <td><button class="btn btn-sm btn-info" onclick="showPurchaseDetail(${row.id}, '${row.supplier}')"><i class="fas fa-eye"></i> Detail</button></td>
                </tr>
            `;
        });
    }
    
    $('#historyTableBody').html(html);
}

function formatDateTime(datetime) {
    if (!datetime) return '-';
    let d = new Date(datetime);
    return d.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatDate(date) {
    if (!date) return '-';
    let d = new Date(date);
    return d.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

function formatCurrency(num) {
    const value = parseFloat(num || 0);
    return 'Rp ' + value.toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 2});
}

function showAlert(type, message) {
    let alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    $('.container-fluid').prepend(alertHtml);
    setTimeout(function() {
        $('.alert').fadeOut(function() { $(this).remove(); });
    }, 3000);
}

function showPurchaseDetail(purchaseId, supplier) {
    currentDetailPurchaseId = purchaseId;
    $('#modalTitle').text(`Detail Purchase #${purchaseId} - ${supplier}`);
    $('#detailContent').html(`
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `);
    
    $.ajax({
        url: 'backend.php?action=get_purchase_detail&purchase_id=' + purchaseId,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderPurchaseDetailModal(response.data || []);
            } else {
                $('#detailContent').html(`<div class="alert alert-danger">${response.message || 'Gagal memuat detail'}</div>`);
            }
        },
        error: function() {
            $('#detailContent').html(`<div class="alert alert-danger">Gagal memuat detail purchase</div>`);
        }
    });
    
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function renderPurchaseDetailModal(items) {
    let html = '';
    
    if (items.length === 0) {
        html = '<div class="alert alert-info">Tidak ada item dalam purchase ini</div>';
    } else {
        items.forEach(function(item, index) {
            const qtyPakai = item.qty_pakai || 0;
            const qtySisa = item.qty_sisa || 0;
            const statusClass = qtySisa > 0 ? 'warning' : (qtyPakai > 0 ? 'success' : 'secondary');
            const statusText = qtySisa > 0 ? 'Sebagian Terpakai' : (qtyPakai > 0 ? 'Semua Terpakai' : 'Belum Terpakai');
            
            let spkDetails = '';
            if (item.spk_usage && item.spk_usage.length > 0) {
                spkDetails = '<ul class="ms-3 mt-2 mb-0">';
                item.spk_usage.forEach(function(spk) {
                    spkDetails += `<li>SPK ${spk.kode_spk} - ${spk.qty} qty (${formatDate(spk.tanggal)})</li>`;
                });
                spkDetails += '</ul>';
            } else if (qtyPakai === 0) {
                spkDetails = '<small class="text-muted ms-3 mt-2 d-block"><i>Belum dipakai di SPK manapun</i></small>';
            }
            
            html += `
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h6 class="mb-2"><strong>${item.sparepart_name}</strong></h6>
                                <small class="text-muted">Kode: ${item.kode_sparepart || '-'} | Satuan: ${item.satuan || '-'}</small>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge bg-${statusClass}">${statusText}</span>
                            </div>
                        </div>
                        
                        <div class="row mt-3 mb-3">
                            <div class="col-md-3">
                                <small class="text-muted">Qty Beli</small><br>
                                <strong>${item.qty_beli} ${item.satuan || ''}</strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Qty Terpakai</small><br>
                                <strong class="text-success">${qtyPakai} ${item.satuan || ''}</strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Qty Sisa</small><br>
                                <strong class="text-warning">${qtySisa} ${item.satuan || ''}</strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Harga</small><br>
                                <strong>${formatCurrency(item.harga_beli)}</strong>
                            </div>
                        </div>
                        
                        ${spkDetails}
                    </div>
                </div>
            `;
        });
    }
    
    $('#detailContent').html(html);
}

function exportDetailPDF() {
    if (!currentDetailPurchaseId) {
        showAlert('danger', 'Purchase ID tidak ditemukan');
        return;
    }
    
    window.location.href = 'backend.php?action=export_detail_pdf&purchase_id=' + currentDetailPurchaseId;
}

function exportAllPDF() {
    let search = $('#searchInput').val();
    let isPaid = $('#paidFilter').val();
    
    window.location.href = 'backend.php?action=export_all_pdf&search=' + encodeURIComponent(search) + '&is_paid=' + encodeURIComponent(isPaid);
}
</script>
