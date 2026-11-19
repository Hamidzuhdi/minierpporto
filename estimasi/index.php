<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Estimasi Biaya";
include '../header.php';
?>

<style>
    #editModal .modal-body {
        max-height: calc(100vh - 180px);
        overflow-y: auto;
    }

    .custom-price-toggle {
        width: 45px;
        height: 24px;
        cursor: pointer !important;
        background-color: #6c757d !important;
        border-color: #6c757d !important;
    }

    .custom-price-toggle:checked {
        background-color: #28a745 !important;
        border-color: #28a745 !important;
    }

    .custom-price-toggle:focus {
        box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25) !important;
    }
</style>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Estimasi Biaya</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Buat Estimasi Baru
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="searchInput" placeholder="Cari kode estimasi, customer, atau nomor polisi...">
                        </div>
                        <div class="col-md-5">
                            <select class="form-select" id="vehicleFilter">
                                <option value="">Semua Kendaraan</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                <input type="month" class="form-control" id="monthFilter" title="Filter berdasarkan bulan estimasi">
                                <button class="btn btn-outline-secondary" type="button" id="btnClearMonthFilter" title="Tampilkan semua bulan">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Kode Estimasi</th>
                                    <th>Tanggal</th>
                                    <th>Customer</th>
                                    <th>Kendaraan</th>
                                    <th>Detail</th>
                                    <th class="text-end">Total Estimasi</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="estimasiTableBody">
                                <tr><td colspan="7" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Buat Estimasi -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Buat Estimasi Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="createForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label for="vehicle_id" class="form-label">Customer / Kendaraan *</label>
                        <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                            <option value="">-- Cari nama, no. telp, atau no. plat --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="tanggal" class="form-label">Tanggal *</label>
                        <input type="date" class="form-control" id="tanggal" name="tanggal" required>
                    </div>

                    <div class="mb-3">
                        <label for="keluhan_customer" class="form-label">Keluhan Customer *</label>
                        <textarea class="form-control" id="keluhan_customer" name="keluhan_customer" rows="4" required placeholder="Jelaskan keluhan/masalah kendaraan..."></textarea>
                    </div>

                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle"></i> Kode estimasi dibuat otomatis. Setelah disimpan, lanjut isi jasa dan sparepart.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Buat Estimasi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Estimasi -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detail Estimasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailContent">
                <!-- Diisi via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Modal Analisa & Estimasi -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Analisa & Rincian Estimasi <small class="text-muted ms-2" id="editKodeLabel"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_id" name="id">
                    <input type="hidden" name="action" value="update_analisa">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="small text-muted" id="editInfoLine"></div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input custom-price-toggle" type="checkbox" id="customPriceToggle" onchange="toggleCustomPrice(this.checked ? 1 : 0)">
                            <label class="form-check-label small" for="customPriceToggle">Harga khusus</label>
                        </div>
                    </div>

                    <h6>Jasa Service</h6>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <select class="form-select form-select-sm" id="service_select">
                                        <option value="">Pilih Jasa...</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" class="form-control form-control-sm" id="service_qty" value="1" min="1" placeholder="Qty">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-primary btn-sm w-100" onclick="addService()">
                                        <i class="fas fa-plus"></i> Tambah
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Jasa</th>
                                <th>Qty</th>
                                <th>Harga</th>
                                <th>Subtotal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="serviceListTable">
                            <tr><td colspan="5" class="text-center text-muted">Belum ada jasa</td></tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3">Total Biaya Jasa:</th>
                                <th colspan="2" id="totalServiceCost">Rp 0</th>
                            </tr>
                        </tfoot>
                    </table>

                    <h6 class="mt-4">Sparepart</h6>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <select class="form-select form-select-sm" id="sparepart_select">
                                        <option value="">Pilih Sparepart...</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" class="form-control form-control-sm" id="sparepart_qty" value="1" min="1" placeholder="Qty">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-success btn-sm w-100" onclick="addSparepart()">
                                        <i class="fas fa-plus"></i> Tambah
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted">Stok tidak dicek dan tidak berkurang pada estimasi.</small>
                        </div>
                    </div>

                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Sparepart</th>
                                <th>Qty</th>
                                <th>Harga</th>
                                <th>Subtotal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="sparepartListTable">
                            <tr><td colspan="5" class="text-center text-muted">Belum ada sparepart</td></tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3">Total Biaya Sparepart:</th>
                                <th colspan="2" id="totalSparepartCost">Rp 0</th>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="mb-3 mt-4">
                        <label for="service_description" class="form-label">Deskripsi Service</label>
                        <textarea class="form-control" id="service_description" name="service_description" rows="3" placeholder="Pekerjaan yang akan dilakukan..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="saran_service" class="form-label">Saran Service</label>
                        <textarea class="form-control" id="saran_service" name="saran_service" rows="2" placeholder="Saran untuk customer..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="analisa_mekanik" class="form-label">Analisa Mekanik</label>
                        <textarea class="form-control" id="analisa_mekanik" name="analisa_mekanik" rows="3" placeholder="Hasil pemeriksaan dan diagnosa..."></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="nama_mekanik" class="form-label">Nama Mekanik</label>
                            <input type="text" class="form-control" id="nama_mekanik" name="nama_mekanik" maxlength="100" placeholder="Contoh: Budi Santoso">
                        </div>
                        <div class="col-md-6">
                            <label for="kilometer" class="form-label">Kilometer Kendaraan</label>
                            <input type="text" class="form-control" id="kilometer" name="kilometer" inputmode="numeric" autocomplete="off" placeholder="Contoh: 125.000">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="discount_amount" class="form-label">Diskon (Rp)</label>
                        <input type="number" class="form-control form-control-sm" id="discount_amount" name="discount_amount" min="0" step="1000" placeholder="0">
                        <small class="text-muted">Opsional. Langsung berlaku pada estimasi, tanpa persetujuan owner.</small>
                    </div>

                    <div class="alert alert-primary d-flex justify-content-between align-items-center mt-3 mb-0">
                        <div>
                            <strong>Total Estimasi (Jasa + Sparepart - Diskon)</strong>
                            <div class="small mt-1" id="estimasiBreakdown">Rp 0 - Rp 0</div>
                        </div>
                        <h5 class="mb-0" id="estimasiTotal">Rp 0</h5>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto fw-semibold text-primary">
                        Total Estimasi: <span id="estimasiTotalFooter">Rp 0</span>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <button type="button" class="btn btn-danger" onclick="saveEstimasi(true)" title="Simpan lalu buka PDF untuk dikirim ke customer">
                        <i class="fas fa-file-pdf"></i> Simpan & PDF
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Harga Khusus (jasa & sparepart) -->
<div class="modal fade" id="priceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Harga Khusus - <span id="priceLineName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="priceLineType">
                <input type="hidden" id="priceLineId">

                <div class="mb-3">
                    <label for="priceLineValue" class="form-label">Harga per Unit (Rp)</label>
                    <input type="number" class="form-control form-control-lg" id="priceLineValue" inputmode="decimal" min="0" step="1000" placeholder="0">
                    <small class="text-muted" id="priceLineTotalPreview">Total: Rp 0</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" onclick="saveLinePrice()">Simpan Harga</button>
            </div>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

<script>
// Estimasi yang sedang dibuka di modal Analisa & Rincian (hasil read_one terakhir)
let currentEstimasi = null;

$(document).ready(function() {
    loadEstimasi();
    loadVehicleOptions();

    $('#tanggal').val(new Date().toISOString().split('T')[0]);

    let searchTimeout;
    $('#searchInput').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadEstimasi, 500);
    });
    $('#vehicleFilter').on('change', loadEstimasi);
    $('#monthFilter').on('change', loadEstimasi);
    $('#btnClearMonthFilter').on('click', function() {
        $('#monthFilter').val('');
        loadEstimasi();
    });

    $('#discount_amount').on('input', refreshTotals);
    $('#kilometer').on('input', function() {
        this.value = formatKilometerInput(this.value);
    });

    // Cegah dropdown select2 menggantung saat modal ditutup.
    $('#editModal').on('hidden.bs.modal', function() {
        ['#service_select', '#sparepart_select'].forEach(function(sel) {
            if ($.fn.select2 && $(sel).hasClass('select2-hidden-accessible')) {
                $(sel).select2('close');
            }
        });
        currentEstimasi = null;
    });

    $('#priceLineValue').on('input', updatePricePreview);
});

// ========== Helpers ==========

function escapeHtml(text) {
    return $('<div>').text(text == null ? '' : String(text)).html();
}

function formatNumber(num) {
    return (parseFloat(num) || 0).toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 2});
}

function rp(num) {
    return 'Rp ' + formatNumber(num);
}

function formatKilometerInput(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (!digits) {
        return '';
    }
    return parseInt(digits, 10).toLocaleString('id-ID', {maximumFractionDigits: 0});
}

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('id-ID', {day: '2-digit', month: 'short', year: 'numeric'});
}

function nl(text) {
    return escapeHtml(text || '-').replace(/\n/g, '<br>');
}

function ajaxError(prefix) {
    return function(xhr) {
        const text = (xhr && xhr.responseText) ? String(xhr.responseText).trim().substring(0, 200) : '';
        showAlert('danger', escapeHtml(prefix + (text ? ': ' + text : '')));
    };
}

function downloadPDF(id) {
    window.open('generate_pdf.php?id=' + encodeURIComponent(id), '_blank');
}

// ========== Daftar estimasi ==========

function loadVehicleOptions() {
    const prevFilter = $('#vehicleFilter').val() || '';

    $.ajax({
        url: 'backend.php?action=get_all_vehicles',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                return;
            }

            let filterOptions = '<option value="">Semua Kendaraan</option>';
            let createOptions = '<option value="">-- Cari nama, no. telp, atau no. plat --</option>';
            response.data.forEach(function(v) {
                const label = escapeHtml(`${v.customer_name} - ${v.customer_phone || '-'} - ${v.nomor_polisi}`);
                filterOptions += `<option value="${v.id}">${label} - ${escapeHtml(v.model || v.merk || 'N/A')}</option>`;
                createOptions += `<option value="${v.id}">${label}</option>`;
            });

            if ($.fn.select2 && $('#vehicleFilter').hasClass('select2-hidden-accessible')) {
                $('#vehicleFilter').select2('destroy');
            }
            $('#vehicleFilter').html(filterOptions).val(prevFilter);
            if ($.fn.select2) {
                $('#vehicleFilter').select2({
                    theme: 'bootstrap-5',
                    placeholder: 'Filter by kendaraan...',
                    allowClear: true,
                    width: '100%'
                });
            }

            const prevCreate = $('#vehicle_id').val() || '';
            if ($.fn.select2 && $('#vehicle_id').hasClass('select2-hidden-accessible')) {
                $('#vehicle_id').select2('destroy');
            }
            $('#vehicle_id').html(createOptions).val(prevCreate);
            if ($.fn.select2) {
                $('#vehicle_id').select2({
                    theme: 'bootstrap-5',
                    placeholder: 'Cari nama, no. telp, atau no. plat...',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#createModal'),
                    // Abaikan spasi/strip supaya "L1234AB" tetap menemukan "L 1234 AB".
                    matcher: function(params, data) {
                        const term = $.trim(params.term || '');
                        if (term === '') {
                            return data;
                        }
                        if (typeof data.text === 'undefined') {
                            return null;
                        }
                        const normalize = function(s) {
                            return String(s).toLowerCase().replace(/[\s-]+/g, '');
                        };
                        return normalize(data.text).indexOf(normalize(term)) > -1 ? data : null;
                    }
                });
            }
        }
    });
}

function loadEstimasi() {
    const query = $.param({
        action: 'read',
        search: $('#searchInput').val(),
        vehicle_id: $('#vehicleFilter').val() || '',
        month: $('#monthFilter').val(),
        t: Date.now()
    });

    $.ajax({
        url: 'backend.php?' + query,
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success) {
                displayEstimasi(response.data);
            } else {
                showAlert('danger', escapeHtml(response.message || 'Gagal memuat estimasi'));
            }
        },
        error: ajaxError('Gagal memuat daftar estimasi')
    });
}

function displayEstimasi(rows) {
    if (rows.length === 0) {
        $('#estimasiTableBody').html('<tr><td colspan="7" class="text-center">Belum ada data estimasi</td></tr>');
        return;
    }

    let html = '';
    rows.forEach(function(row) {
        const vehicle = `${row.nomor_polisi || '-'} - ${row.merk || ''} ${row.model || ''}`;
        const km = row.kilometer ? parseInt(row.kilometer, 10).toLocaleString('id-ID') + ' km' : '-';
        html += `
            <tr>
                <td><strong>${escapeHtml(row.kode_estimasi)}</strong></td>
                <td>${formatDate(row.tanggal)}</td>
                <td>${escapeHtml(row.customer_name || '-')}<br><small class="text-muted">${escapeHtml(row.customer_phone || '')}</small></td>
                <td>${escapeHtml(vehicle)}</td>
                <td><small>Mekanik: ${escapeHtml(row.nama_mekanik || '-')}<br>Kilometer: ${escapeHtml(km)}</small></td>
                <td class="text-end"><strong>${rp(row.total_estimasi)}</strong></td>
                <td>
                    <button class="btn btn-info btn-sm" onclick="viewDetail(${row.id})" title="Detail">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-warning btn-sm" onclick="openEditModal(${row.id})" title="Analisa & Rincian Estimasi">
                        <i class="fas fa-wrench"></i>
                    </button>
                    <button class="btn btn-outline-danger btn-sm" onclick="downloadPDF(${row.id})" title="Download Estimasi PDF">
                        <i class="fas fa-file-pdf"></i>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="deleteEstimasi(${row.id}, '${escapeHtml(row.kode_estimasi)}')" title="Hapus Estimasi">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    $('#estimasiTableBody').html(html);
}

// ========== Buat estimasi ==========

function openAddModal() {
    $('#createForm')[0].reset();
    $('#tanggal').val(new Date().toISOString().split('T')[0]);
    if ($.fn.select2 && $('#vehicle_id').hasClass('select2-hidden-accessible')) {
        $('#vehicle_id').val('').trigger('change');
    }
}

$('#createForm').on('submit', function(e) {
    e.preventDefault();

    if (!$('#vehicle_id').val()) {
        showAlert('warning', 'Pilih kendaraan terlebih dahulu');
        return;
    }

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', escapeHtml(response.message + ' - Kode: ' + response.kode));
                loadEstimasi();
                // Langsung lanjut mengisi jasa & sparepart
                openEditModalAfterHide('#createModal', response.estimasi_id);
            } else {
                showAlert('danger', escapeHtml(response.message));
            }
        },
        error: ajaxError('Gagal membuat estimasi')
    });
});

// ========== Detail ==========

function viewDetail(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                showAlert('danger', escapeHtml(response.message));
                return;
            }

            const e = response.data;
            const t = e.totals;
            const vehicle = `${e.nomor_polisi || '-'} - ${e.merk || ''} ${e.model || ''} (${e.tahun || '-'})`;
            const km = (e.kilometer !== null && e.kilometer !== '') ? formatNumber(e.kilometer) + ' KM' : '-';

            let svcRows = '';
            e.services.forEach(function(svc) {
                svcRows += `<tr><td>${escapeHtml(svc.nama_jasa)}</td><td>${svc.qty}</td><td>${rp(svc.harga_eff)}</td><td>${rp(svc.subtotal)}</td></tr>`;
            });
            if (!svcRows) {
                svcRows = '<tr><td colspan="4" class="text-center">Belum ada jasa service</td></tr>';
            }

            let itemRows = '';
            e.items.forEach(function(item) {
                itemRows += `<tr><td>${escapeHtml(item.sparepart_name)}</td><td>${item.qty} ${escapeHtml(item.satuan)}</td><td>${rp(item.harga_eff)}</td><td>${rp(item.subtotal)}</td></tr>`;
            });
            if (!itemRows) {
                itemRows = '<tr><td colspan="4" class="text-center">Belum ada sparepart</td></tr>';
            }

            const html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6>Informasi Estimasi</h6>
                        <table class="table table-sm">
                            <tr><th width="40%">Kode Estimasi:</th><td><strong>${escapeHtml(e.kode_estimasi)}</strong></td></tr>
                            <tr><th>Tanggal:</th><td>${formatDate(e.tanggal)}</td></tr>
                            <tr><th>Customer:</th><td>${escapeHtml(e.customer_name || '-')}<br><small>${escapeHtml(e.customer_phone || '')}</small></td></tr>
                            <tr><th>Kendaraan:</th><td>${escapeHtml(vehicle)}</td></tr>
                            <tr><th>Nama Mekanik:</th><td>${escapeHtml(e.nama_mekanik || '-')}</td></tr>
                            <tr><th>Kilometer:</th><td>${escapeHtml(km)}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Keluhan & Analisa</h6>
                        <table class="table table-sm">
                            <tr><th width="40%">Keluhan:</th><td>${nl(e.keluhan_customer)}</td></tr>
                            <tr><th>Analisa:</th><td>${nl(e.analisa_mekanik)}</td></tr>
                            <tr><th>Service:</th><td>${nl(e.service_description)}</td></tr>
                            <tr><th>Saran:</th><td>${nl(e.saran_service)}</td></tr>
                        </table>
                    </div>
                </div>

                <hr>
                <h6>Jasa Service</h6>
                <table class="table table-bordered table-sm">
                    <thead><tr><th>Nama Jasa</th><th>Qty</th><th>Harga</th><th>Subtotal</th></tr></thead>
                    <tbody>${svcRows}</tbody>
                </table>

                <hr>
                <h6>Sparepart</h6>
                <table class="table table-bordered table-sm">
                    <thead><tr><th>Nama Sparepart</th><th>Qty</th><th>Harga</th><th>Subtotal</th></tr></thead>
                    <tbody>${itemRows}</tbody>
                    <tfoot>
                        <tr><th colspan="3">Total Sparepart:</th><th>${rp(t.total_sparepart)}</th></tr>
                        <tr><th colspan="3">Total Jasa Service:</th><th>${rp(t.total_jasa)}</th></tr>
                        <tr><th colspan="3">Diskon:</th><th>${rp(t.discount_amount)}</th></tr>
                        <tr><th colspan="3">TOTAL ESTIMASI:</th><th><strong>${rp(t.total_estimasi)}</strong></th></tr>
                    </tfoot>
                </table>

                <hr>
                <div>
                    <button class="btn btn-primary" onclick="openEditModalAfterHide('#detailModal', ${e.id})">
                        <i class="fas fa-edit"></i> Edit Analisa & Rincian
                    </button>
                    <button class="btn btn-danger" onclick="downloadPDF(${e.id})">
                        <i class="fas fa-file-pdf"></i> Download Estimasi PDF
                    </button>
                </div>
            `;
            $('#detailContent').html(html);
            $('#detailModal').modal('show');
        },
        error: ajaxError('Gagal memuat detail estimasi')
    });
}

// ========== Modal Analisa & Rincian ==========

// Bootstrap 5 bisa meninggalkan backdrop "nyangkut" jika modal baru dibuka saat modal lama
// masih transisi tutup, jadi tunggu sampai modal lama benar-benar tertutup.
function openEditModalAfterHide(fromModalSelector, id) {
    const $from = $(fromModalSelector);
    if ($from.hasClass('show')) {
        $from.one('hidden.bs.modal', function() { openEditModal(id); });
        $from.modal('hide');
    } else {
        openEditModal(id);
    }
}

function openEditModal(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id + '&t=' + Date.now(),
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (!response.success) {
                showAlert('danger', escapeHtml(response.message));
                return;
            }

            const e = response.data;
            currentEstimasi = e;

            $('#edit_id').val(e.id);
            $('#editKodeLabel').text(e.kode_estimasi);
            $('#editInfoLine').text(`${e.customer_name || '-'} • ${e.nomor_polisi || '-'} ${e.merk || ''} ${e.model || ''} • ${formatDate(e.tanggal)}`);
            $('#analisa_mekanik').val(e.analisa_mekanik || '');
            $('#nama_mekanik').val(e.nama_mekanik || '');
            $('#service_description').val(e.service_description || '');
            $('#saran_service').val(e.saran_service || '');
            $('#kilometer').val((e.kilometer !== null && e.kilometer !== '') ? formatKilometerInput(e.kilometer) : '');
            $('#discount_amount').val(parseFloat(e.discount_amount) > 0 ? e.discount_amount : '');
            $('#service_qty').val(1);
            $('#sparepart_qty').val(1);

            renderLines();
            loadServicePrices();
            loadSpareparts();

            $('#editModal').modal('show');
        },
        error: ajaxError('Gagal membuka estimasi')
    });
}

// Muat ulang jasa & sparepart saja. Isian teks di form (yang belum disimpan) tidak disentuh.
function refreshLines() {
    if (!currentEstimasi) {
        return;
    }
    const id = currentEstimasi.id;

    $.ajax({
        url: 'backend.php?action=read_one&id=' + id + '&t=' + Date.now(),
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success && currentEstimasi && currentEstimasi.id === id) {
                currentEstimasi.services = response.data.services;
                currentEstimasi.items = response.data.items;
                currentEstimasi.totals = response.data.totals;
                currentEstimasi.use_custom_price = response.data.use_custom_price;
                renderLines();
            }
        }
    });
}

function renderLines() {
    const e = currentEstimasi;
    if (!e) {
        return;
    }
    const customOn = parseInt(e.use_custom_price) === 1;
    $('#customPriceToggle').prop('checked', customOn);

    const priceCell = function(line) {
        const badge = parseInt(line.use_custom_price) === 1 ? ' <span class="badge bg-success">khusus</span>' : '';
        return rp(line.harga_eff) + badge;
    };
    const actions = function(type, line) {
        return `
            ${customOn ? `<button type="button" class="btn btn-primary btn-sm" onclick="editLinePrice('${type}', ${line.id})" title="Edit Harga">
                <i class="fas fa-edit"></i>
            </button>` : ''}
            <button type="button" class="btn btn-danger btn-sm" onclick="${type === 'service' ? 'deleteService' : 'deleteSparepart'}(${line.id})" title="Hapus">
                <i class="fas fa-trash"></i>
            </button>`;
    };

    let svcHtml = '';
    e.services.forEach(function(svc) {
        svcHtml += `
            <tr>
                <td>${escapeHtml(svc.nama_jasa)}</td>
                <td>${svc.qty}</td>
                <td>${priceCell(svc)}</td>
                <td>${rp(svc.subtotal)}</td>
                <td>${actions('service', svc)}</td>
            </tr>`;
    });
    $('#serviceListTable').html(svcHtml || '<tr><td colspan="5" class="text-center text-muted">Belum ada jasa</td></tr>');
    $('#totalServiceCost').text(rp(e.totals.total_jasa));

    let itemHtml = '';
    e.items.forEach(function(item) {
        itemHtml += `
            <tr>
                <td>${escapeHtml(item.sparepart_name)}</td>
                <td>${item.qty} ${escapeHtml(item.satuan)}</td>
                <td>${priceCell(item)}</td>
                <td>${rp(item.subtotal)}</td>
                <td>${actions('item', item)}</td>
            </tr>`;
    });
    $('#sparepartListTable').html(itemHtml || '<tr><td colspan="5" class="text-center text-muted">Belum ada sparepart</td></tr>');
    $('#totalSparepartCost').text(rp(e.totals.total_sparepart));

    refreshTotals();
}

// Total di bagian bawah mengikuti isian diskon yang sedang diketik (belum tentu sudah disimpan).
function refreshTotals() {
    if (!currentEstimasi) {
        return;
    }
    const subtotal = currentEstimasi.totals.subtotal;
    const draft = parseFloat($('#discount_amount').val()) || 0;
    const discount = Math.max(0, Math.min(draft, subtotal));
    const label = rp(subtotal - discount);

    $('#estimasiTotal').text(label);
    $('#estimasiTotalFooter').text(label);
    $('#estimasiBreakdown').text(rp(subtotal) + ' - ' + rp(discount));
}

function loadServicePrices() {
    $.ajax({
        url: '../services/backend.php?action=read_active',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                showAlert('danger', escapeHtml(response.message || 'Gagal memuat daftar jasa'));
                return;
            }

            let options = '<option value="">Pilih Jasa...</option>';
            response.data.forEach(function(service) {
                options += `<option value="${service.id}">${escapeHtml(service.kode_jasa + ' - ' + service.nama_jasa)} - ${rp(service.harga)}</option>`;
            });

            if ($.fn.select2 && $('#service_select').hasClass('select2-hidden-accessible')) {
                $('#service_select').select2('destroy');
            }
            $('#service_select').html(options);
            if ($.fn.select2) {
                $('#service_select').select2({
                    theme: 'bootstrap-5',
                    placeholder: 'Cari by kode jasa atau nama jasa...',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#editModal')
                });
            }
        },
        error: ajaxError('Gagal memuat daftar jasa')
    });
}

function loadSpareparts() {
    $.ajax({
        url: 'backend.php?action=get_all_spareparts',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                showAlert('danger', escapeHtml(response.message || 'Gagal memuat daftar sparepart'));
                return;
            }

            let options = '<option value="">Pilih Sparepart...</option>';
            response.data.forEach(function(sp) {
                options += `<option value="${sp.id}">${escapeHtml((sp.kode_sparepart || '-') + ' - ' + sp.nama)} - ${rp(sp.harga_jual_default)}</option>`;
            });

            if ($.fn.select2 && $('#sparepart_select').hasClass('select2-hidden-accessible')) {
                $('#sparepart_select').select2('destroy');
            }
            $('#sparepart_select').html(options);
            if ($.fn.select2) {
                $('#sparepart_select').select2({
                    theme: 'bootstrap-5',
                    placeholder: 'Cari by kode atau nama sparepart...',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#editModal')
                });
            }
        },
        error: ajaxError('Gagal memuat daftar sparepart')
    });
}

function addService() {
    if (!currentEstimasi) return;
    const servicePriceId = $('#service_select').val();
    const qty = parseInt($('#service_qty').val(), 10) || 0;

    if (!servicePriceId) {
        showAlert('warning', 'Pilih jasa terlebih dahulu');
        return;
    }
    if (qty <= 0) {
        showAlert('warning', 'Qty harus lebih dari 0');
        return;
    }

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: { action: 'add_service', estimasi_id: currentEstimasi.id, service_price_id: servicePriceId, qty: qty },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', escapeHtml(response.message));
                refreshLines();
                $('#service_select').val('').trigger('change');
                $('#service_qty').val(1);
            } else {
                showAlert('danger', escapeHtml(response.message));
            }
        },
        error: ajaxError('Gagal menambahkan jasa')
    });
}

function addSparepart() {
    if (!currentEstimasi) return;
    const sparepartId = $('#sparepart_select').val();
    const qty = parseInt($('#sparepart_qty').val(), 10) || 0;

    if (!sparepartId) {
        showAlert('warning', 'Pilih sparepart terlebih dahulu');
        return;
    }
    if (qty <= 0) {
        showAlert('warning', 'Qty harus lebih dari 0');
        return;
    }

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: { action: 'add_sparepart', estimasi_id: currentEstimasi.id, sparepart_id: sparepartId, qty: qty },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', escapeHtml(response.message));
                refreshLines();
                $('#sparepart_select').val('').trigger('change');
                $('#sparepart_qty').val(1);
            } else {
                showAlert('danger', escapeHtml(response.message));
            }
        },
        error: ajaxError('Gagal menambahkan sparepart')
    });
}

function deleteService(lineId) {
    if (!confirm('Hapus jasa ini?')) return;
    deleteLine('delete_service', lineId);
}

function deleteSparepart(lineId) {
    if (!confirm('Hapus sparepart ini dari estimasi?')) return;
    deleteLine('delete_sparepart', lineId);
}

function deleteLine(action, lineId) {
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: { action: action, id: lineId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', escapeHtml(response.message));
                refreshLines();
            } else {
                showAlert('danger', escapeHtml(response.message));
            }
        },
        error: ajaxError('Gagal menghapus baris')
    });
}

// ========== Harga khusus ==========

function toggleCustomPrice(useCustom) {
    if (!currentEstimasi) return;

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: { action: 'toggle_custom_price', id: currentEstimasi.id, use_custom: useCustom },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', escapeHtml(response.message));
                currentEstimasi.use_custom_price = useCustom;
                refreshLines();
                loadEstimasi();
            } else {
                showAlert('danger', escapeHtml(response.message));
                $('#customPriceToggle').prop('checked', !useCustom);
            }
        },
        error: function(xhr) {
            $('#customPriceToggle').prop('checked', !useCustom);
            ajaxError('Gagal mengubah mode harga khusus')(xhr);
        }
    });
}

function findLine(type, lineId) {
    const list = type === 'service' ? currentEstimasi.services : currentEstimasi.items;
    return list.find(function(line) { return parseInt(line.id) === lineId; });
}

function editLinePrice(type, lineId) {
    const line = currentEstimasi ? findLine(type, lineId) : null;
    if (!line) return;

    $('#priceLineType').val(type);
    $('#priceLineId').val(lineId);
    $('#priceLineName').text(type === 'service' ? line.nama_jasa : line.sparepart_name);
    $('#priceLineValue').val(line.harga_eff);
    updatePricePreview();
    $('#priceModal').modal('show');
}

function updatePricePreview() {
    if (!currentEstimasi) return;
    const line = findLine($('#priceLineType').val(), parseInt($('#priceLineId').val(), 10));
    const price = parseFloat($('#priceLineValue').val()) || 0;
    $('#priceLineTotalPreview').text('Total: ' + rp(price * (line ? parseFloat(line.qty) : 0)));
}

function saveLinePrice() {
    const type = $('#priceLineType').val();
    const lineId = parseInt($('#priceLineId').val(), 10);
    const raw = $('#priceLineValue').val();
    const price = parseFloat(raw);

    if (raw === '' || isNaN(price) || price < 0) {
        showAlert('warning', 'Harga harus berupa angka 0 atau lebih');
        return;
    }

    const data = type === 'service'
        ? { action: 'update_service_custom_price', service_id: lineId, harga_custom: price }
        : { action: 'update_item_custom_price', item_id: lineId, harga_custom: price };

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', escapeHtml(response.message));
                $('#priceModal').modal('hide');
                refreshLines();
                loadEstimasi();
            } else {
                showAlert('danger', escapeHtml(response.message));
            }
        },
        error: ajaxError('Gagal menyimpan harga khusus')
    });
}

// ========== Simpan & hapus ==========

$('#editForm').on('submit', function(e) {
    e.preventDefault();
    saveEstimasi(false);
});

function saveEstimasi(openPdf) {
    if (!currentEstimasi) return;
    const id = currentEstimasi.id;

    // Jendela dibuka sekarang (masih dalam klik pengguna) supaya tidak diblokir popup blocker;
    // alamat PDF diisi setelah simpan berhasil.
    const pdfWindow = openPdf ? window.open('', '_blank') : null;
    const closePdfWindow = function() {
        if (pdfWindow) pdfWindow.close();
    };

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: $('#editForm').serialize(),
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                closePdfWindow();
                showAlert('danger', escapeHtml(response.message));
                return;
            }

            showAlert('success', escapeHtml(response.message));
            $('#editModal').modal('hide');
            loadEstimasi();

            if (openPdf) {
                const url = 'generate_pdf.php?id=' + encodeURIComponent(id);
                if (pdfWindow) {
                    pdfWindow.location.href = url;
                } else {
                    downloadPDF(id);
                }
            }
        },
        error: function(xhr) {
            closePdfWindow();
            ajaxError('Gagal menyimpan estimasi')(xhr);
        }
    });
}

function deleteEstimasi(id, kode) {
    if (!confirm('Hapus estimasi ' + kode + '? Tindakan ini tidak bisa dibatalkan.')) return;

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: { action: 'delete', id: id },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', escapeHtml(response.message));
                loadEstimasi();
            } else {
                showAlert('danger', escapeHtml(response.message));
            }
        },
        error: ajaxError('Gagal menghapus estimasi')
    });
}
</script>
