<?php
session_start();
require_once '../config.php';

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Manajemen Sparepart";
include '../header.php';

// Cek role untuk hide/show kolom harga
$user_role = $_SESSION['role'] ?? 'Admin';
$is_owner = ($user_role === 'Owner');
$is_admin = ($user_role === 'Admin');
?>

<?php if ($is_admin): ?>
<style>
    .buy-price-column {
        display: none !important;
    }
    .buy-price-field {
        display: none !important;
    }
</style>
<?php endif; ?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Data Sparepart</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#sparepartModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Tambah Sparepart
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <input type="text" class="form-control" id="searchInput" placeholder="Cari nama atau kode sparepart...">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="lowStockFilter">
                                <label class="form-check-label" for="lowStockFilter">
                                    Tampilkan hanya stok menipis
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="sparepartTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Kode</th>
                                    <th>Nama</th>
                                    <th>Barcode</th>
                                    <th>Satuan</th>
                                    <th class="buy-price-column">Harga Beli</th>
                                    <th>Harga Jual</th>
                                    <th>Stock</th>
                                    <th>Min Stock</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="sparepartTableBody">
                                <tr>
                                    <td colspan="10" class="text-center">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Sparepart -->
<div class="modal fade" id="sparepartModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sparepartModalTitle">Tambah Sparepart</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="sparepartForm">
                <div class="modal-body">
                    <input type="hidden" id="sparepartId" name="id">
                    <input type="hidden" id="formAction" name="action" value="create">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="kode_sparepart" class="form-label">Kode Sparepart</label>
                                <input type="text" class="form-control" id="kode_sparepart" name="kode_sparepart">
                                <small class="text-muted">Contoh: SP001, BRK-001</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="nama" class="form-label">Nama Sparepart *</label>
                                <input type="text" class="form-control" id="nama" name="nama" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="barcode" class="form-label">Barcode/QR Code</label>
                                <input type="text" class="form-control" id="barcode" name="barcode">
                                <small class="text-muted">Kosongkan jika tidak ada</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="satuan" class="form-label">Satuan</label>
                                <select class="form-select" id="satuan" name="satuan">
                                    <option value="Pcs">Pcs (Pieces)</option>
                                    <option value="Pkt">Pkt (Paket)</option>
                                    <option value="Lt">Lt (Liter)</option>
                                    <option value="Gl">Gl (Galon)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 buy-price-field">
                            <div class="mb-3 price-column">
                                <label for="harga_beli_default" class="form-label">Harga Beli (Moving Average)</label>
                                <input type="number" step="0.01" class="form-control" id="harga_beli_default" name="harga_beli_default" value="0" <?php echo $is_owner ? '' : 'readonly'; ?>>
                                <?php if ($is_owner): ?>
                                <small class="text-muted">Bisa diedit oleh Owner untuk setup awal. Nilai akan tetap terupdate dari Purchase Order.</small>
                                <?php else: ?>
                                <small class="text-muted">Hanya Owner yang bisa mengubah harga beli. Nilai otomatis terupdate dari Purchase Order.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3 price-column">
                                <label for="harga_jual_default" class="form-label">Harga Jual Default</label>
                                <input type="number" step="0.01" class="form-control" id="harga_jual_default" name="harga_jual_default" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="min_stock" class="form-label">Minimum Stock</label>
                                <input type="number" class="form-control" id="min_stock" name="min_stock" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="current_stock" class="form-label">Stock Saat Ini</label>
                                <input type="number" class="form-control" id="current_stock" name="current_stock" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

<script>
const userRole = '<?php echo $user_role; ?>';
const isOwner = (userRole === 'Owner');
const isAdmin = (userRole === 'Admin');

// Load data saat halaman dimuat
$(document).ready(function() {
    loadSpareparts();
    
    // Search dengan delay
    let searchTimeout;
    $('#searchInput').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadSpareparts();
        }, 500);
    });
    
    // Filter low stock
    $('#lowStockFilter').on('change', function() {
        loadSpareparts();
    });
});

// Load semua sparepart
function loadSpareparts() {
    let search = $('#searchInput').val();
    let lowStock = $('#lowStockFilter').is(':checked') ? '1' : '';
    
    $.ajax({
        url: 'backend.php?action=read&search=' + encodeURIComponent(search) + '&low_stock=' + lowStock,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displaySpareparts(response.data);
            } else {
                showAlert('danger', 'Gagal memuat data sparepart');
            }
        },
        error: function() {
            showAlert('danger', 'Terjadi kesalahan saat memuat data');
        }
    });
}

// Tampilkan data sparepart di tabel
function displaySpareparts(spareparts) {
    let html = '';
    
    if (spareparts.length === 0) {
        html = '<tr><td colspan="10" class="text-center">Belum ada data sparepart</td></tr>';
    } else {
        spareparts.forEach(function(sp) {
            // Konversi ke integer untuk perbandingan yang benar
            let currentStock = parseInt(sp.current_stock);
            let minStock = parseInt(sp.min_stock);
            
            let stockClass = currentStock <= minStock ? 'text-danger fw-bold' : '';
            let stockIcon = currentStock <= minStock ? '<i class="fas fa-exclamation-triangle text-warning"></i> ' : '';
            
            html += `
                <tr>
                    <td>${sp.id}</td>
                    <td><strong>${sp.kode_sparepart || '-'}</strong></td>
                    <td>${sp.nama}</td>
                    <td>${sp.barcode || '-'}</td>
                    <td>${sp.satuan}</td>
                    <td class="buy-price-column">Rp ${formatNumber(sp.harga_beli_default)}</td>
                    <td>Rp ${formatNumber(sp.harga_jual_default)}</td>
                    <td class="${stockClass}">${stockIcon}${currentStock}</td>
                    <td>${minStock}</td>
                    <td>
                        <button class="btn btn-warning btn-sm" onclick="editSparepart(${sp.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteSparepart(${sp.id}, '${sp.nama.replace(/'/g, "\\'")}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
    }
    
    $('#sparepartTableBody').html(html);
}

// Buka modal untuk tambah sparepart
function openAddModal() {
    $('#sparepartModalTitle').text('Tambah Sparepart');
    $('#sparepartForm')[0].reset();
    $('#sparepartId').val('');
    $('#formAction').val('create');
    $('#satuan').val('Pcs');
    $('#harga_beli_default').val('0');
    $('#harga_jual_default').val('0');
    $('#min_stock').val('0');
    $('#current_stock').val('0');
}

// Edit sparepart
function editSparepart(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let sp = response.data;
                $('#sparepartModalTitle').text('Edit Sparepart');
                $('#sparepartId').val(sp.id);
                $('#kode_sparepart').val(sp.kode_sparepart);
                $('#nama').val(sp.nama);
                $('#barcode').val(sp.barcode);
                $('#satuan').val(sp.satuan);
                $('#harga_beli_default').val(sp.harga_beli_default);
                $('#harga_jual_default').val(sp.harga_jual_default);
                $('#min_stock').val(sp.min_stock);
                $('#current_stock').val(sp.current_stock);
                $('#formAction').val('update');
                $('#sparepartModal').modal('show');
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// Hapus sparepart
function deleteSparepart(id, nama) {
    if (confirm(`Yakin ingin menghapus sparepart "${nama}"?`)) {
        $.ajax({
            url: 'backend.php',
            type: 'POST',
            data: {
                action: 'delete',
                id: id
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.message);
                    loadSpareparts();
                } else {
                    showAlert('danger', response.message);
                }
            }
        });
    }
}

// Submit form (Create/Update)
$('#sparepartForm').on('submit', function(e) {
    e.preventDefault();
    
    let formData = $(this).serialize();
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                $('#sparepartModal').modal('hide');
                loadSpareparts();
            } else {
                showAlert('danger', response.message);
            }
        }
    });
});

// Helper: Format number
function formatNumber(num) {
    return parseFloat(num).toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 2});
}

// Helper: Show alert
function showAlert(type, message) {
    let alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    $('.container-fluid').prepend(alertHtml);
    
    setTimeout(function() {
        $('.alert').fadeOut(function() {
            $(this).remove();
        });
    }, 3000);
}

// Auto-open edit modal if edit_id param is in URL
$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const editId = urlParams.get('edit_id');
    if (editId) {
        // Wait for spareparts to load then open modal
        setTimeout(function() {
            editSparepart(editId);
        }, 600);
    }
});
</script>
