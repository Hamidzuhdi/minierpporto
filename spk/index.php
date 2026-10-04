<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Manajemen SPK";
include '../header.php';

// Cek role untuk hide/show harga
$user_role = $_SESSION['role'] ?? 'Admin';
$is_owner = ($user_role === 'Owner');
?>

<?php if (!$is_owner): ?>
<style>
    .sparepart-price-column {
        display: none !important;
    }
</style>
<?php endif; ?>

<style>
    #analisaModal .modal-body {
        max-height: calc(100vh - 180px);
        overflow-y: auto;
    }

    .spk-row-discount-attention {
        background-color: #fff9e6;
    }

    .discount-inline-flag {
        display: inline-block;
        margin-top: 4px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        background: #ffe08a;
        color: #7a5200;
    }

    /* Custom Toggle Switch Styling */
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
                    <h5 class="mb-0">Surat Perintah Kerja (SPK)</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#spkModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Buat SPK Baru
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <input type="text" class="form-control" id="searchInput" placeholder="Cari kode SPK, customer, atau nomor polisi...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="vehicleFilter">
                                <option value="">Semua Kendaraan</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">Semua Status</option>
                                <option value="Menunggu Konfirmasi">Menunggu Konfirmasi</option>
                                <option value="Disetujui">Disetujui</option>
                                <option value="Dalam Pengerjaan">Dalam Pengerjaan</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Dikirim ke Owner">Dikirim ke Owner</option>
                                <option value="Buat Invoice">Buat Invoice</option>
                                <option value="Sudah Cetak Invoice">Sudah Cetak Invoice</option>
                                <option value="Sudah Reminder WA">Sudah Reminder WA</option>
                                <option value="Sudah FU">Sudah FU</option>
                                <option value="Dibatalkan">Dibatalkan</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="discountFlowFilter">
                                <option value="">Semua Flow Diskon</option>
                                <option value="attention">Diskon Pending Owner</option>
                                <option value="has_request">Ada Pengajuan Diskon Admin</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-12">
                            <select class="form-select" id="revisionFilter">
                                <option value="">Semua SPK</option>
                                <option value="revisions">Hanya Revisi (SPK-REV1, REV2, dst)</option>
                                <option value="has_revisions">SPK yang Sudah Memiliki Revisi</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Kode SPK</th>
                                    <th>Tanggal</th>
                                    <th>Customer</th>
                                    <th>Kendaraan</th>
                                    <th>Detail</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="spkTableBody">
                                <tr><td colspan="7" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Create SPK -->
<div class="modal fade" id="spkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Buat SPK Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="spkForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-3">
                        <label for="customer_id" class="form-label">Customer *</label>
                        <select class="form-select" id="customer_id" name="customer_group" required>
                            <option value="">-- Pilih Customer --</option>
                        </select>
                        <input type="hidden" id="customer_id_real" name="customer_id" value="">
                    </div>
                    
                    <div class="mb-3">
                        <label for="vehicle_id" class="form-label">Kendaraan *</label>
                        <select class="form-select" id="vehicle_id" name="vehicle_id" required disabled>
                            <option value="">-- Pilih Customer Dulu --</option>
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
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Kode SPK akan di-generate otomatis saat disimpan.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Buat SPK</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail SPK -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detail SPK</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Modal Analisa Mekanik -->
<div class="modal fade" id="analisaModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Analisa & Estimasi Mekanik</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="analisaForm">
                <div class="modal-body">
                    <input type="hidden" id="analisa_spk_id" name="id">
                    <input type="hidden" name="action" value="update_analisa">
                    
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
                                    <button type="button" class="btn btn-primary btn-sm w-100" onclick="addServiceToSPK()">
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
                                    <button type="button" class="btn btn-success btn-sm w-100" onclick="addSparepartToSPK()">
                                        <i class="fas fa-plus"></i> Tambah
                                    </button>
                                </div>
                            </div>
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
                            <label for="nama_mekanik" class="form-label">Nama Mekanik *</label>
                            <input type="text" class="form-control" id="nama_mekanik" name="nama_mekanik" maxlength="100" required placeholder="Contoh: Budi Santoso">
                        </div>
                        <div class="col-md-6">
                            <label for="kilometer" class="form-label">Kilometer Kendaraan *</label>
                            <input type="text" class="form-control" id="kilometer" name="kilometer" inputmode="numeric" autocomplete="off" required placeholder="Contoh: 125.000">
                            <small class="text-muted">Isi kilometer/odometer saat kendaraan masuk bengkel.</small>
                        </div>
                    </div>

                    <div class="alert alert-primary d-flex justify-content-between align-items-center mt-3 mb-0">
                        <div>
                            <strong>Estimasi Biaya (Jasa + Sparepart - Diskon)</strong>
                            <div class="small mt-1" id="estimasiBiayaBreakdown">Rp 0 - Rp 0</div>
                        </div>
                        <h5 class="mb-0" id="estimasiBiayaTotal">Rp 0</h5>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <strong>Flow Diskon SPK</strong>
                            <span id="discount_status_badge" class="badge bg-secondary">Diskon: Belum Diajukan</span>
                        </div>
                        <div class="card-body">
                            <h6 class="mb-2">Pengajuan & Review Diskon</h6>
                            <div class="mb-2 small text-muted">
                                Requested: <strong id="discount_requested_summary">Rp 0</strong><br>
                                Approved: <strong id="discount_approved_summary">Rp 0</strong>
                            </div>
                            <div class="mb-2">
                                <label for="discount_amount_requested" class="form-label">Nominal Diskon</label>
                                <input type="number" class="form-control form-control-sm" id="discount_amount_requested" min="0" step="1000" placeholder="Contoh: 50000">
                            </div>
                            <div class="d-grid mb-2" id="discount_admin_actions">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="submitDiscountRequest()">
                                    <i class="fas fa-paper-plane"></i> Kirim Pengajuan Diskon
                                </button>
                            </div>
                            <div class="d-flex gap-2 flex-wrap" id="discount_owner_actions">
                                <button type="button" class="btn btn-success btn-sm" onclick="reviewDiscount('approve')">
                                    <i class="fas fa-check"></i> ACC
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="reviewDiscount('reject')">
                                    <i class="fas fa-times"></i> Tolak
                                </button>
                            </div>
                            <div class="small text-muted mt-2" id="discount_admin_hint"></div>
                            <div class="small text-muted" id="discount_owner_hint"></div>

                            <hr>
                            <h6 class="mb-2">Riwayat Approval Diskon</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Waktu</th>
                                            <th>Aksi</th>
                                            <th>Requested</th>
                                            <th>Approved</th>
                                            <th>Oleh</th>
                                            <th>Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody id="discountHistoryTable">
                                        <tr><td colspan="6" class="text-center text-muted">Belum ada riwayat</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto fw-semibold text-primary">
                        Total Estimasi: <span id="estimasiBiayaTotalFooter">Rp 0</span>
                        <div class="small text-muted" id="estimasiBiayaFooterBreakdown">Rp 0 - Rp 0</div>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Analisa</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Harga Khusus Sparepart -->
<div class="modal fade" id="editPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Harga Khusus - <span id="editPriceItemName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPriceItemId">
                <input type="hidden" id="editPriceQty">

                <div class="mb-3">
                    <label for="editPriceValue" class="form-label">Harga per Unit (Rp)</label>
                    <input type="number" class="form-control form-control-lg" id="editPriceValue" inputmode="decimal" min="0" step="1000" placeholder="0">
                    <small class="text-muted" id="editPriceTotalPreview">Total: Rp 0</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" onclick="saveItemCustomPrice()">Simpan Harga</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Harga Khusus Jasa -->
<div class="modal fade" id="editServicePriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Harga Khusus - <span id="editPriceServiceName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPriceServiceId">
                <input type="hidden" id="editPriceServiceQty">

                <div class="mb-3">
                    <label for="editPriceServiceValue" class="form-label">Harga per Unit (Rp)</label>
                    <input type="number" class="form-control form-control-lg" id="editPriceServiceValue" inputmode="decimal" min="0" step="1000" placeholder="0">
                    <small class="text-muted" id="editPriceServiceTotalPreview">Total: Rp 0</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" onclick="saveServiceCustomPrice()">Simpan Harga</button>
            </div>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

<script>
const userRole = '<?php echo $_SESSION['role'] ?? 'Admin'; ?>';
const isOwner = (userRole === 'Owner');
const SPK_CREATE_DRAFT_KEY = 'draft_spk_create_v1';
let isSpkCreateDraftHydrating = false;

$(document).ready(function() {
    loadSPKs();
    loadCustomers();
    loadAllVehiclesForFilter();
    
    // Check if need to open modal after redirect from revision
    let urlParams = new URLSearchParams(window.location.search);
    let openModalId = urlParams.get('open_modal');
    if (openModalId) {
        setTimeout(function() {
            openAnalisaModal(parseInt(openModalId));
            // Remove parameter from URL
            window.history.replaceState({}, document.title, '../spk/index.php');
        }, 500);
    }
    
    // Check if need to open modal from customer detail page (edit_id parameter)
    let editId = urlParams.get('edit_id');
    if (editId) {
        setTimeout(function() {
            viewDetail(parseInt(editId));
            // Remove parameter from URL
            window.history.replaceState({}, document.title, 'index.php');
        }, 500);
    }
    
    $('#tanggal').val(new Date().toISOString().split('T')[0]);
    
    let searchTimeout;
    $('#searchInput').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadSPKs, 500);
    });
    
    $('#statusFilter').on('change', loadSPKs);
    $('#vehicleFilter').on('change', loadSPKs);
    $('#discountFlowFilter').on('change', loadSPKs);
    $('#revisionFilter').on('change', loadSPKs);
    $('#discount_amount_requested').on('input', refreshEstimasiBiaya);
    $('#kilometer').on('input', function() {
        this.value = formatKilometerInput(this.value);
        saveAnalisaDraft();
    });

    // Autosave draft for SPK create form
    $('#spkForm').on('input change', '#customer_id, #vehicle_id, #tanggal, #keluhan_customer', function() {
        saveSpkCreateDraft();
    });

    // Autosave draft for Analisa form basic fields
    $('#analisaForm').on('input change', '#analisa_mekanik, #nama_mekanik, #service_description, #saran_service, #kilometer, #discount_amount_requested', function() {
        saveAnalisaDraft();
    });
    
    // Load vehicles saat customer dipilih
    $('#customer_id').on('change', function() {
        let customerGroup = $(this).val();
        $('#customer_id_real').val('');
        if (customerGroup) {
            loadVehicles(customerGroup);
        } else {
            $('#vehicle_id').html('<option value="">-- Pilih Customer Dulu --</option>').prop('disabled', true);
        }
        saveSpkCreateDraft();
    });

    $('#vehicle_id').on('change', function() {
        const selected = $('#vehicle_id option:selected');
        const customerId = selected.data('customer-id') || '';
        $('#customer_id_real').val(customerId);
        saveSpkCreateDraft();
    });

    // Prevent select2 dropdown from leaving modal in a "stuck" state.
    $('#analisaModal').on('hidden.bs.modal', function() {
        if ($.fn.select2) {
            if ($('#service_select').hasClass('select2-hidden-accessible')) {
                $('#service_select').select2('close');
            }
            if ($('#sparepart_select').hasClass('select2-hidden-accessible')) {
                $('#sparepart_select').select2('close');
            }
        }
    });
});

function loadAllVehiclesForFilter() {
    const prevValue = $('#vehicleFilter').val() || '';

    $.ajax({
        url: 'backend.php?action=get_all_vehicles',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let options = '<option value="">Semua Kendaraan</option>';
                response.data.forEach(function(v) {
                    options += `<option value="${v.id}">${v.customer_name} - ${v.nomor_polisi} - ${v.model || v.merk || 'N/A'}</option>`;
                });

                // Prevent duplicate Select2 wrappers on reload.
                if ($.fn.select2 && $('#vehicleFilter').hasClass('select2-hidden-accessible')) {
                    $('#vehicleFilter').select2('destroy');
                }

                $('#vehicleFilter').html(options);
                if (prevValue) {
                    $('#vehicleFilter').val(prevValue);
                }
                
                // Initialize searchable dropdown when Select2 is available.
                if ($.fn.select2) {
                    $('#vehicleFilter').select2({
                        theme: 'bootstrap-5',
                        placeholder: 'Filter by kendaraan...',
                        allowClear: true,
                        width: '100%'
                    });
                }
            }
        }
    });
}

function loadSPKs() {
    let search = $('#searchInput').val();
    let status = $('#statusFilter').val();
    let vehicleId = $('#vehicleFilter').val();
    let discountFlow = $('#discountFlowFilter').val();
    let revisionFilter = $('#revisionFilter').val();
    
    $.ajax({
        url: 'backend.php?action=read&search=' + encodeURIComponent(search) + '&status=' + encodeURIComponent(status) + '&vehicle_id=' + encodeURIComponent(vehicleId) + '&discount_flow=' + encodeURIComponent(discountFlow) + '&revision_filter=' + encodeURIComponent(revisionFilter) + '&t=' + Date.now(),
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success) {
                displaySPKs(response.data);
            }
        }
    });
}

function loadCustomers() {
    $.ajax({
        url: 'backend.php?action=get_customers',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let options = '<option value="">-- Pilih Customer --</option>';
                response.data.forEach(function(c) {
                    const customerGroupValue = c.customer_ids || c.id;
                    options += `<option value="${customerGroupValue}">${c.name} ${c.phone ? '(' + c.phone + ')' : ''}</option>`;
                });
                $('#customer_id').html(options);

                if ($.fn.select2) {
                    if ($('#customer_id').hasClass('select2-hidden-accessible')) {
                        $('#customer_id').select2('destroy');
                    }
                    $('#customer_id').select2({
                        theme: 'bootstrap-5',
                        placeholder: 'Cari customer...',
                        allowClear: true,
                        width: '100%',
                        dropdownParent: $('#spkModal')
                    });
                }
            }
        }
    });
}

function loadVehicles(customerIds, selectedVehicleId = '') {
    $.ajax({
        url: 'backend.php?action=get_vehicles&customer_ids=' + encodeURIComponent(customerIds),
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let options = '<option value="">-- Pilih Kendaraan --</option>';
                response.data.forEach(function(v) {
                    options += `<option value="${v.id}" data-customer-id="${v.customer_id}">${v.nomor_polisi} - ${v.merk} ${v.model || ''}</option>`;
                });
                $('#vehicle_id').html(options).prop('disabled', false);

                if (selectedVehicleId) {
                    $('#vehicle_id').val(String(selectedVehicleId));
                    const selected = $('#vehicle_id option:selected');
                    const customerId = selected.data('customer-id') || '';
                    $('#customer_id_real').val(customerId);
                }
            }
        }
    });
}

function displaySPKs(spks) {
    let html = '';
    
    console.log('Total SPKs:', spks.length);
    
    if (spks.length === 0) {
        html = '<tr><td colspan="7" class="text-center">Belum ada data SPK</td></tr>';
    } else {
        spks.forEach(function(spk) {
            let statusBadge = getStatusBadge(spk.status_spk);
            let discountBadge = '';
            let needsDiscountAttention = (spk.discount_status || '').toLowerCase() === 'pending';
            if (spk.discount_status && spk.discount_status !== 'none') {
                discountBadge = `<div class="mt-1">${getDiscountStatusBadge(spk.discount_status)}</div>`;
            }
            let vehicle = `${spk.nomor_polisi} - ${spk.merk || ''} ${spk.model || ''}`;
            
            // Normalisasi kode agar suffix REV tidak dobel (contoh legacy: -REV1-REV1).
            let rawKode = String(spk.kode_unik_reference || '');
            let baseKode = rawKode.replace(/(?:-REV\d+)+$/, '');
            let displayKode = rawKode;
            if (spk.revision_number && spk.revision_number > 0) {
                displayKode = baseKode + '-REV' + spk.revision_number;
            }
            let kodeCell = `<strong>${displayKode}</strong>`;

            if (needsDiscountAttention) {
                kodeCell += '<div class="discount-inline-flag"><i class="fas fa-tags"></i> Perlu Review Diskon</div>';
            }
            
            html += `
                <tr class="${needsDiscountAttention ? 'spk-row-discount-attention' : ''}">
                    <td>${kodeCell}</td>
                    <td>${formatDate(spk.tanggal)}</td>
                    <td>${spk.customer_name}<br><small class="text-muted">${spk.customer_phone || ''}</small></td>
                    <td>${vehicle}</td>
                    <td><small>Mekanik: ${spk.nama_mekanik || '-'}<br>Kilometer: ${spk.kilometer ? spk.kilometer.toLocaleString('id-ID') + ' km' : '-'}</small></td>
                    <td>${statusBadge}${discountBadge}</td>
                    <td>
                        <button class="btn btn-info btn-sm" onclick="viewDetail(${spk.id})" title="Detail">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-warning btn-sm" onclick="openAnalisaModal(${spk.id})" title="Analisa Mekanik">
                            <i class="fas fa-wrench"></i>
                        </button>
                        <div class="form-check form-switch d-inline-block" style="margin-left: 5px;">
                            <input class="form-check-input custom-price-toggle" type="checkbox" id="toggle_${spk.id}" ${parseInt(spk.use_custom_price) === 1 ? 'checked' : ''} onchange="toggleCustomPrice(${spk.id}, this.checked ? 1 : 0)" style="cursor: pointer;">
                            <label class="form-check-label" for="toggle_${spk.id}" style="cursor: pointer; margin-bottom: 0;"></label>
                        </div>
                        ${spk.status_spk === 'Menunggu Konfirmasi' ? `
                        <button class="btn btn-outline-danger btn-sm" onclick="downloadEstimasiPDF(${spk.id})" title="Download Estimasi PDF">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                        ` : ''}
                        ${isOwner && spk.status_spk === 'Dikirim ke Owner' ? `
                        <button class="btn btn-success btn-sm" onclick="createInvoiceFromSPK(${spk.id})" title="Buat Invoice & Cetak PDF">
                            <i class="fas fa-file-invoice"></i> Buat Invoice
                        </button>
                        ` : ''}
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                Status
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Menunggu Konfirmasi')">Menunggu Konfirmasi</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Disetujui')">Disetujui</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Dalam Pengerjaan')">Dalam Pengerjaan</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Selesai')">Selesai</a></li>
                                ${!isOwner ? `<li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Dikirim ke Owner')">Dikirim ke Owner</a></li>` : ''}
                                ${isOwner ? `<li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Buat Invoice')">Buat Invoice</a></li>` : ''}
                                ${isOwner ? `<li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Sudah Cetak Invoice')">Sudah Cetak Invoice</a></li>` : ''}
                                ${['Sudah Cetak Invoice', 'Sudah Reminder WA', 'Sudah FU'].includes(spk.status_spk) ? `<li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Sudah Reminder WA')">Sudah Reminder WA</a></li><li><a class="dropdown-item" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Sudah FU')">Sudah FU</a></li>` : ''}
                                ${!['Sudah Cetak Invoice', 'Sudah Reminder WA', 'Sudah FU'].includes(spk.status_spk) ? `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="updateStatus(${spk.id}, 'Dibatalkan')">Dibatalkan</a></li>` : ''}
                            </ul>
                        </div>
                    </td>
                </tr>
            `;
        });
    }
    
    $('#spkTableBody').html(html);
    
    // Re-initialize Bootstrap dropdowns after DOM update
    setTimeout(function() {
        try {
            const dropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
            dropdowns.forEach(function(dropdown) {
                if (!bootstrap.Dropdown.getInstance(dropdown)) {
                    new bootstrap.Dropdown(dropdown);
                }
            });
            console.log('Initialized', dropdowns.length, 'dropdowns');
        } catch (e) {
            console.error('Error initializing dropdowns:', e);
        }
    }, 200);
}

function getStatusBadge(status) {
    const badges = {
        'Menunggu Konfirmasi': 'warning',
        'Disetujui': 'info',
        'Dalam Pengerjaan': 'primary',
        'Selesai': 'success',
        'Dikirim ke Owner': 'secondary',
        'Buat Invoice': 'danger',
        'Sudah Cetak Invoice': 'dark',
        'Sudah Reminder WA': 'info',
        'Sudah FU': 'info',
        'Dibatalkan': 'danger'
    };
    return `<span class="badge bg-${badges[status] || 'secondary'}">${status}</span>`;
}

function getDiscountStatusBadge(status) {
    const labels = {
        none: ['secondary', 'Diskon: Belum Diajukan'],
        pending: ['warning', 'Diskon: Menunggu ACC Owner'],
        approved: ['success', 'Diskon: Disetujui'],
        revision: ['warning', 'Diskon: Menunggu ACC Owner'],
        rejected: ['danger', 'Diskon: Ditolak']
    };
    const data = labels[status] || labels.none;
    return `<span class="badge bg-${data[0]}">${data[1]}</span>`;
}

function openAddModal() {
    isSpkCreateDraftHydrating = true;
    try {
        $('#spkForm')[0].reset();
        $('#tanggal').val(new Date().toISOString().split('T')[0]);
        $('#customer_id_real').val('');
        $('#vehicle_id').html('<option value="">-- Pilih Customer Dulu --</option>').prop('disabled', true);

        if ($.fn.select2 && $('#customer_id').hasClass('select2-hidden-accessible')) {
            $('#customer_id').val('').trigger('change');
        }

        tryRestoreSpkCreateDraft();
    } finally {
        isSpkCreateDraftHydrating = false;
    }
}

$('#spkForm').on('submit', function(e) {
    e.preventDefault();

    // Fallback: keep hidden customer id in sync with selected vehicle option.
    if (!$('#customer_id_real').val()) {
        const selectedVehicle = $('#vehicle_id option:selected');
        const derivedCustomerId = selectedVehicle.data('customer-id') || '';
        if (derivedCustomerId) {
            $('#customer_id_real').val(derivedCustomerId);
        }
    }

    if (!$('#vehicle_id').val()) {
        showAlert('warning', 'Pilih kendaraan terlebih dahulu');
        return;
    }

    if (!$('#customer_id_real').val()) {
        showAlert('warning', 'Customer kendaraan belum terdeteksi, pilih ulang kendaraan');
        return;
    }
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                clearSpkCreateDraft();
                showAlert('success', response.message + ' - Kode: ' + response.kode_unik);
                $('#spkModal').modal('hide');
                loadSPKs();
            } else {
                showAlert('danger', response.message);
            }
        },
        error: function(xhr, status, error) {
            const serverText = (xhr && xhr.responseText) ? String(xhr.responseText).trim() : '';
            const shortDetail = serverText ? serverText.substring(0, 250) : (error || status || 'Unknown error');
            showAlert('danger', 'Gagal membuat SPK. Detail: ' + shortDetail);
        }
    });
});

function viewDetail(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let spk = response.data;
                const discountApproved = (spk.discount_status === 'approved') ? (parseFloat(spk.discount_amount_approved) || 0) : 0;
                let html = `
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6>Informasi SPK</h6>
                            <table class="table table-sm">
                                <tr><th width="40%">Kode SPK:</th><td><strong>${spk.kode_unik_reference}</strong></td></tr>
                                <tr><th>Tanggal:</th><td>${formatDate(spk.tanggal)}</td></tr>
                                <tr><th>Status:</th><td>${getStatusBadge(spk.status_spk)}</td></tr>
                                <tr><th>Customer:</th><td>${spk.customer_name}<br><small>${spk.customer_phone || ''}</small></td></tr>
                                <tr><th>Kendaraan:</th><td>${spk.nomor_polisi} - ${spk.merk} ${spk.model || ''} (${spk.tahun || '-'})</td></tr>
                                <tr><th>Nama Mekanik:</th><td>${spk.nama_mekanik || '-'}</td></tr>
                                <tr><th>Kilometer:</th><td>${(spk.kilometer !== null && spk.kilometer !== '') ? (formatNumber(spk.kilometer) + ' KM') : '-'}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>Keluhan & Analisa</h6>
                            <table class="table table-sm">
                                <tr><th width="40%">Keluhan:</th><td>${spk.keluhan_customer || '-'}</td></tr>
                                <tr><th>Analisa:</th><td>${spk.analisa_mekanik || '-'}</td></tr>
                                <tr><th>Service:</th><td>${spk.service_description || '-'}</td></tr>
                                <tr><th>Biaya Jasa:</th><td><strong>Rp ${formatNumber(spk.biaya_jasa)}</strong></td></tr>
                                <tr><th>Saran:</th><td>${spk.saran_service || '-'}</td></tr>
                            </table>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6>Jasa Service</h6>
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Nama Jasa</th>
                                <th>Qty</th>
                                ${isOwner ? '<th>Harga</th><th>Subtotal</th>' : ''}
                            </tr>
                        </thead>
                        <tbody>
                `;
                
                let totalJasa = 0;
                if (spk.services && spk.services.length > 0) {
                    spk.services.forEach(function(svc) {
                        let subtotal = parseFloat(svc.subtotal) || 0;
                        const svcCustomEnabled = parseInt(svc.use_custom_price) === 1;
                        const svcCustomPrice = parseFloat(svc.harga_custom);
                        const svcHasCustom = svc.harga_custom && svcCustomPrice > 0;
                        const svcDisplayPrice = (svcCustomEnabled && svcHasCustom) ? svcCustomPrice : parseFloat(svc.harga);
                        if (isOwner) {
                            html += `
                                <tr>
                                    <td>${svc.nama_jasa}</td>
                                    <td>${svc.qty}</td>
                                    <td>Rp ${formatNumber(svcDisplayPrice)}</td>
                                    <td>Rp ${formatNumber(subtotal)}</td>
                                </tr>
                            `;
                        } else {
                            html += `
                                <tr>
                                    <td>${svc.nama_jasa}</td>
                                    <td>${svc.qty}</td>
                                </tr>
                            `;
                        }
                        totalJasa += subtotal;
                    });
                } else {
                    html += `<tr><td colspan="${isOwner ? '4' : '2'}" class="text-center">Belum ada jasa service</td></tr>`;
                }
                
                html += `
                        </tbody>
                    </table>
                    
                    <hr>
                    
                    <h6>Sparepart yang Digunakan</h6>
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Nama Sparepart</th>
                                <th>Qty</th>
                                <th>Harga</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                
                let totalSparepart = 0;
                if (spk.items && spk.items.length > 0) {
                    spk.items.forEach(function(item) {
                        let subtotal = parseFloat(item.subtotal) || 0;
                        // Check if use_custom_price is ON and harga_custom is set
                        const itemCustomEnabled = parseInt(item.use_custom_price) === 1;
                        const customPriceVal = parseFloat(item.harga_custom);
                        const hasCustomPrice = item.harga_custom && customPriceVal > 0;
                        
                        let displayPrice;
                        if (itemCustomEnabled && hasCustomPrice) {
                            displayPrice = customPriceVal;
                        } else {
                            displayPrice = (item.harga_satuan_eff || item.harga_jual_default);
                        }
                        
                        html += `
                            <tr>
                                <td>${item.sparepart_name}</td>
                                <td>${item.qty} ${item.satuan}</td>
                                <td>Rp ${formatNumber(displayPrice)}</td>
                                <td>Rp ${formatNumber(subtotal)}</td>
                            </tr>
                        `;
                        totalSparepart += subtotal;
                    });
                } else {
                    html += `<tr><td colspan="4" class="text-center">Belum ada sparepart</td></tr>`;
                }

                const grandBeforeDiscount = totalSparepart + totalJasa;
                const grandAfterDiscount = Math.max(0, grandBeforeDiscount - discountApproved);

                html += `
                        </tbody>
                        <tfoot>
                            <tr><th colspan="3">Total Sparepart:</th><th>Rp ${formatNumber(totalSparepart)}</th></tr>
                            <tr><th colspan="3">Total Jasa Service:</th><th>Rp ${formatNumber(totalJasa)}</th></tr>
                            <tr><th colspan="3">Diskon Disetujui:</th><th>Rp ${formatNumber(discountApproved)}</th></tr>
                            <tr><th colspan="3">GRAND TOTAL:</th><th><strong>Rp ${formatNumber(grandAfterDiscount)}</strong></th></tr>
                        </tfoot>
                    </table>

                    <hr>

                    <div class="mb-3">
                        ${!isOwner && spk.status_spk !== 'Sudah Cetak Invoice' ? `
                        <button class="btn btn-primary btn-lg" onclick="openAnalisaModal(${spk.id})">
                            <i class="fas fa-edit"></i> Edit Analisa & Estimasi
                        </button>
                        ` : ''}
                        ${(isOwner || spk.status_spk === 'Sudah Cetak Invoice') ? `
                        <button class="btn btn-danger btn-lg" onclick="downloadInvoicePDF(${spk.id})">
                            <i class="fas fa-file-pdf"></i> Cetak / Download Invoice PDF
                        </button>
                        ` : ''}
                        ${!isOwner && spk.status_spk !== 'Sudah Cetak Invoice' ? `
                        <button class="btn btn-outline-danger btn-sm" onclick="downloadEstimasiPDF(${spk.id})" title="Download Estimasi PDF">
                            <i class="fas fa-file-pdf"></i> Download Estimasi PDF
                        </button>
                        ` : ''}
                        ${isOwner && spk.status_spk !== 'Sudah Cetak Invoice' ? `
                        <button class="btn btn-success btn-lg" onclick="createInvoiceFromSPK(${spk.id})">
                            <i class="fas fa-check-circle"></i> Cetak PDF & Tandai Selesai
                        </button>
                        ` : ''}
                    </div>
                `;
                $('#detailContent').html(html);
                $('#detailModal').modal('show');
            }
        }
    });
}

function openAnalisaModal(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id + '&t=' + Date.now(),
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success) {
                let spk = response.data;
                currentAnalisaSpk = spk;
                currentServiceTotal = 0;
                currentSparepartTotal = 0;
                refreshEstimasiBiaya();
                $('#analisa_spk_id').val(spk.id);
                $('#analisaForm').data('use-custom-price', spk.use_custom_price || 0);
                $('#analisa_mekanik').val(spk.analisa_mekanik || '');
                $('#nama_mekanik').val(spk.nama_mekanik || '');
                $('#service_description').val(spk.service_description || '');
                $('#saran_service').val(spk.saran_service || '');
                $('#kilometer').val((spk.kilometer !== null && spk.kilometer !== '') ? formatKilometerInput(spk.kilometer) : '');
                restoreAnalisaDraft(spk.id);
                renderDiscountSection(spk);
                loadDiscountHistory(spk.id);
                
                // Load services for this SPK
                loadSPKServices(spk.id);
                // Load available service prices
                loadServicePrices();
                
                // Load spareparts for this SPK
                loadSPKSpareparts(spk.id);
                // Load available spareparts
                loadSpareparts();
                
                $('#analisaModal').modal('show');
            }
        }
    });
}

$('#analisaForm').on('submit', function(e) {
    e.preventDefault();
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const spkId = parseInt($('#analisa_spk_id').val(), 10) || 0;
                clearAnalisaDraft(spkId);
                showAlert('success', response.message);
                $('#analisaModal').modal('hide');
                loadSPKs();
            } else {
                showAlert('danger', response.message);
            }
        }
    });
});

function updateStatus(id, status) {
    if (confirm('Update status SPK ke: ' + status + '?')) {
        $.ajax({
            url: 'backend.php',
            type: 'POST',
            data: { action: 'update_status', id: id, status: status },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.message);
                    loadSPKs();
                    
                    // Jika status diubah ke "Buat Invoice", langsung download PDF
                    if (status === 'Buat Invoice') {
                        setTimeout(function() {
                            downloadInvoicePDF(id);
                        }, 1000);
                    }
                } else {
                    showAlert('danger', response.message);
                }
            }
        });
    }
}

// Download Invoice PDF
function downloadInvoicePDF(spkId) {
    window.open('generate_invoice_pdf.php?spk_id=' + spkId, '_blank');
}

function downloadEstimasiPDF(spkId) {
    window.open('generate_estimasi_pdf.php?spk_id=' + spkId, '_blank');
}

// Cetak Invoice PDF + Update status ke "Sudah Cetak Invoice"
function createInvoiceFromSPK(spkId) {
    if (!confirm('Cetak Invoice PDF dan tandai SPK ini sebagai "Sudah Cetak Invoice"?')) {
        return;
    }
    
    // Update status dulu
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'update_status',
            id: spkId,
            status: 'Sudah Cetak Invoice'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', 'Status SPK diubah ke "Sudah Cetak Invoice". Membuka PDF...');
                loadSPKs();
                
                // Buka PDF setelah status berhasil diupdate
                setTimeout(function() {
                    downloadInvoicePDF(spkId);
                }, 500);
            } else {
                showAlert('danger', response.message);
            }
        },
        error: function() {
            showAlert('danger', 'Gagal mengubah status. Silakan coba lagi.');
        }
    });
}

function formatNumber(num) {
    return parseFloat(num).toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 2});
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
    let d = new Date(date);
    return d.toLocaleDateString('id-ID', {day: '2-digit', month: 'short', year: 'numeric'});
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

function buildSpkCreateDraftPayload() {
    return {
        customer_group: $('#customer_id').val() || '',
        customer_id_real: $('#customer_id_real').val() || '',
        vehicle_id: $('#vehicle_id').val() || '',
        tanggal: $('#tanggal').val() || '',
        keluhan_customer: $('#keluhan_customer').val() || '',
        saved_at: Date.now()
    };
}

function saveSpkCreateDraft() {
    if (isSpkCreateDraftHydrating) {
        return;
    }

    try {
        const payload = buildSpkCreateDraftPayload();
        localStorage.setItem(SPK_CREATE_DRAFT_KEY, JSON.stringify(payload));
    } catch (e) {
        console.warn('Gagal menyimpan draft SPK:', e);
    }
}

function loadSpkCreateDraft() {
    try {
        const raw = localStorage.getItem(SPK_CREATE_DRAFT_KEY);
        if (!raw) {
            return null;
        }
        return JSON.parse(raw);
    } catch (e) {
        console.warn('Gagal membaca draft SPK:', e);
        return null;
    }
}

function clearSpkCreateDraft() {
    localStorage.removeItem(SPK_CREATE_DRAFT_KEY);
}

function tryRestoreSpkCreateDraft() {
    const draft = loadSpkCreateDraft();
    if (!draft || typeof draft !== 'object') {
        return;
    }

    if (draft.tanggal) {
        $('#tanggal').val(draft.tanggal);
    }
    if (draft.keluhan_customer) {
        $('#keluhan_customer').val(draft.keluhan_customer);
    }

    if (draft.customer_group) {
        $('#customer_id').val(draft.customer_group).trigger('change');
        loadVehicles(draft.customer_group, draft.vehicle_id || '');
    }

    if (draft.customer_id_real) {
        $('#customer_id_real').val(draft.customer_id_real);
    }

    showAlert('info', 'Draft SPK terakhir dipulihkan.');
}

function getAnalisaDraftKey(spkId) {
    return 'draft_spk_analisa_' + String(spkId || 0);
}

function saveAnalisaDraft() {
    const spkId = parseInt($('#analisa_spk_id').val(), 10) || 0;
    if (spkId <= 0) {
        return;
    }

    const payload = {
        analisa_mekanik: $('#analisa_mekanik').val() || '',
        nama_mekanik: $('#nama_mekanik').val() || '',
        service_description: $('#service_description').val() || '',
        saran_service: $('#saran_service').val() || '',
        kilometer: $('#kilometer').val() || '',
        discount_amount_requested: $('#discount_amount_requested').val() || '',
        saved_at: Date.now()
    };

    try {
        localStorage.setItem(getAnalisaDraftKey(spkId), JSON.stringify(payload));
    } catch (e) {
        console.warn('Gagal menyimpan draft analisa:', e);
    }
}

function restoreAnalisaDraft(spkId) {
    if (!spkId) {
        return;
    }

    try {
        const raw = localStorage.getItem(getAnalisaDraftKey(spkId));
        if (!raw) {
            return;
        }

        const draft = JSON.parse(raw);
        if (!draft || typeof draft !== 'object') {
            return;
        }

        $('#analisa_mekanik').val(draft.analisa_mekanik || $('#analisa_mekanik').val() || '');
        $('#nama_mekanik').val(draft.nama_mekanik || $('#nama_mekanik').val() || '');
        $('#service_description').val(draft.service_description || $('#service_description').val() || '');
        $('#saran_service').val(draft.saran_service || $('#saran_service').val() || '');
        $('#kilometer').val(draft.kilometer || $('#kilometer').val() || '');
        if (draft.discount_amount_requested !== undefined) {
            $('#discount_amount_requested').val(draft.discount_amount_requested || '');
        }

        refreshEstimasiBiaya();
        showAlert('info', 'Draft analisa SPK dipulihkan.');
    } catch (e) {
        console.warn('Gagal memulihkan draft analisa:', e);
    }
}

function clearAnalisaDraft(spkId) {
    if (!spkId) {
        return;
    }
    localStorage.removeItem(getAnalisaDraftKey(spkId));
}

// ========== Service Management Functions ==========
let servicePricesCache = [];
let currentServiceTotal = 0;
let currentSparepartTotal = 0;
let currentAnalisaSpk = null;

function refreshEstimasiBiaya() {
    const subtotal = currentServiceTotal + currentSparepartTotal;
    const discountDraft = parseFloat($('#discount_amount_requested').val()) || 0;
    const discount = Math.max(0, Math.min(discountDraft, subtotal));
    const netTotal = subtotal - discount;
    const label = 'Rp ' + formatNumber(netTotal);
    const breakdown = 'Rp ' + formatNumber(subtotal) + ' - Rp ' + formatNumber(discount);

    $('#estimasiBiayaTotal').text(label);
    $('#estimasiBiayaTotalFooter').text(label);
    $('#estimasiBiayaBreakdown').text(breakdown);
    $('#estimasiBiayaFooterBreakdown').text(breakdown);
}

function renderDiscountSection(spk) {
    const status = spk.discount_status || 'none';
    const requestedAmount = parseFloat(spk.discount_amount_requested) || 0;
    const approvedAmount = parseFloat(spk.discount_amount_approved) || 0;

    const badgeMeta = {
        none: ['secondary', 'Diskon: Belum Diajukan'],
        pending: ['warning', 'Diskon: Menunggu ACC Owner'],
        approved: ['success', 'Diskon: Disetujui'],
        revision: ['warning', 'Diskon: Menunggu ACC Owner'],
        rejected: ['danger', 'Diskon: Ditolak']
    };
    const badge = badgeMeta[status] || badgeMeta.none;

    $('#discount_status_badge')
        .removeClass('bg-secondary bg-warning bg-success bg-info bg-danger')
        .addClass('bg-' + badge[0])
        .text(badge[1]);
    $('#discount_requested_summary').text('Rp ' + formatNumber(requestedAmount));
    $('#discount_approved_summary').text('Rp ' + formatNumber(approvedAmount));
    $('#discount_amount_requested').val(requestedAmount > 0 ? requestedAmount : '');

    const canAdminSubmit = !isOwner && ['none', 'pending', 'revision', 'rejected'].includes(status);
    const canOwnerReview = isOwner && ['pending', 'revision'].includes(status);

    $('#discount_amount_requested').prop('disabled', !canAdminSubmit);
    $('#discount_admin_actions').toggle(!isOwner);
    $('#discount_owner_actions').toggle(isOwner);
    $('#discount_admin_actions button')
        .prop('disabled', !canAdminSubmit)
        .html('<i class="fas fa-paper-plane"></i> Kirim Pengajuan Diskon');
    $('#discount_admin_hint').text(!isOwner && canAdminSubmit ? 'Admin dapat mengajukan atau update nominal diskon untuk menunggu ACC owner.' : '');

    $('#discount_owner_actions button').prop('disabled', !canOwnerReview);
    $('#discount_owner_hint').text(isOwner ? (canOwnerReview ? 'Owner hanya dapat ACC atau Tolak pengajuan diskon.' : 'Review owner aktif saat status pending.') : '');

    refreshEstimasiBiaya();
}

function refreshDiscountSection(spkId) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + spkId,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                currentAnalisaSpk = response.data;
                renderDiscountSection(response.data);
                loadDiscountHistory(spkId);
                loadSPKs();
            }
        }
    });
}

function submitDiscountRequest() {
    const spkId = parseInt($('#analisa_spk_id').val(), 10) || 0;
    const amount = parseFloat($('#discount_amount_requested').val()) || 0;

    if (spkId <= 0) {
        showAlert('danger', 'SPK tidak valid. Tutup modal lalu buka kembali.');
        return;
    }
    if (amount <= 0) {
        showAlert('warning', 'Nominal diskon harus lebih dari 0');
        return;
    }

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'submit_discount',
            id: spkId,
            discount_amount_requested: amount
        },
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                refreshDiscountSection(spkId);
            } else {
                showAlert('danger', response.message || 'Gagal mengajukan diskon');
            }
        },
        error: function() {
            showAlert('danger', 'Gagal mengajukan diskon');
        }
    });
}

function reviewDiscount(decision) {
    const spkId = parseInt($('#analisa_spk_id').val(), 10) || 0;

    if (spkId <= 0) {
        showAlert('danger', 'SPK tidak valid. Tutup modal lalu buka kembali.');
        return;
    }

    const decisionLabel = { approve: 'ACC', reject: 'Tolak' };
    if (!confirm('Yakin proses keputusan: ' + (decisionLabel[decision] || decision) + '?')) {
        return;
    }

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'review_discount',
            id: spkId,
            decision: decision
        },
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                refreshDiscountSection(spkId);
            } else {
                showAlert('danger', response.message || 'Gagal review diskon');
            }
        },
        error: function() {
            showAlert('danger', 'Gagal review diskon');
        }
    });
}

function loadDiscountHistory(spkId) {
    $.ajax({
        url: 'backend.php?action=get_discount_history&id=' + spkId,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderDiscountHistory(response.data || []);
            } else {
                $('#discountHistoryTable').html('<tr><td colspan="6" class="text-center text-muted">Belum ada riwayat</td></tr>');
            }
        },
        error: function() {
            $('#discountHistoryTable').html('<tr><td colspan="6" class="text-center text-muted">Gagal memuat riwayat</td></tr>');
        }
    });
}

function renderDiscountHistory(rows) {
    if (!rows || rows.length === 0) {
        $('#discountHistoryTable').html('<tr><td colspan="6" class="text-center text-muted">Belum ada riwayat</td></tr>');
        return;
    }

    const html = rows.map(function(row) {
        return `
            <tr>
                <td>${formatDate(row.created_at)}</td>
                <td>${row.action_type}</td>
                <td>Rp ${formatNumber(row.requested_amount || 0)}</td>
                <td>Rp ${formatNumber(row.approved_amount || 0)}</td>
                <td>${row.acted_by_name || '-'} (${row.acted_role || '-'})</td>
                <td>${row.note || '-'}</td>
            </tr>
        `;
    }).join('');

    $('#discountHistoryTable').html(html);
}

function loadServicePrices() {
    $.ajax({
        url: '../services/backend.php?action=read_active',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                servicePricesCache = response.data;
                let options = '<option value="">Pilih Jasa...</option>';
                response.data.forEach(function(service) {
                    options += `<option value="${service.id}" data-price="${service.harga}">${service.kode_jasa} - ${service.nama_jasa} - Rp ${formatNumber(service.harga)}</option>`;
                });

                if ($.fn.select2 && $('#service_select').hasClass('select2-hidden-accessible')) {
                    $('#service_select').select2('destroy');
                }
                $('#service_select').html(options);
                
                // Initialize Select2 for searchable dropdown
                if ($.fn.select2) {
                    $('#service_select').select2({
                        theme: 'bootstrap-5',
                        placeholder: 'Cari by kode jasa atau nama jasa...',
                        allowClear: true,
                        width: '100%',
                        dropdownParent: $('#analisaModal')
                    });
                }
            } else {
                showAlert('danger', response.message || 'Gagal memuat daftar jasa');
            }
        },
        error: function(xhr) {
            showAlert('danger', 'Error load jasa: ' + (xhr.responseText || 'Unknown error'));
        }
    });
}

function loadSPKServices(spkId) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + spkId + '&t=' + Date.now(),
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success && response.data.services) {
                displayServicesList(response.data.services);
            }
        }
    });
}

function displayServicesList(services) {
    let html = '';
    let total = 0;
    const useCustomPrice = parseInt($('#analisaForm').data('use-custom-price')) || 0;

    if (services && services.length > 0) {
        services.forEach(function(svc) {
            const svcCustomEnabled = parseInt(svc.use_custom_price) === 1;
            const customPriceVal = parseFloat(svc.harga_custom);
            const hasCustomPrice = svc.harga_custom && customPriceVal > 0;
            const displayPrice = (svcCustomEnabled && hasCustomPrice) ? customPriceVal : parseFloat(svc.harga);
            let subtotal = parseFloat(svc.qty) * displayPrice;
            total += subtotal;
            html += `
                <tr>
                    <td>${svc.nama_jasa}</td>
                    <td>${svc.qty}</td>
                    <td>Rp ${formatNumber(displayPrice)}</td>
                    <td>Rp ${formatNumber(subtotal)}</td>
                    <td>
                        ${useCustomPrice ? `
                            <button type="button" class="btn btn-primary btn-sm" onclick="editServicePrice(${svc.id}, ${svc.qty}, '${svc.nama_jasa}', ${displayPrice})" title="Edit Harga">
                                <i class="fas fa-edit"></i>
                            </button>
                        ` : ''}
                        <button type="button" class="btn btn-danger btn-sm" onclick="deleteServiceFromSPK(${svc.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
    } else {
        html = '<tr><td colspan="5" class="text-center text-muted">Belum ada jasa</td></tr>';
    }
    
    currentServiceTotal = total;
    $('#serviceListTable').html(html);
    $('#totalServiceCost').html('Rp ' + formatNumber(total));
    refreshEstimasiBiaya();
}

function addServiceToSPK() {
    const spkId = $('#analisa_spk_id').val();
    const servicePriceId = $('#service_select').val();
    const qty = parseInt($('#service_qty').val()) || 1;
    
    if (!servicePriceId) {
        showAlert('warning', 'Pilih jasa terlebih dahulu');
        return;
    }
    
    const selectedOption = $('#service_select option:selected');
    const harga = parseFloat(selectedOption.data('price'));
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'add_service',
            spk_id: spkId,
            service_price_id: servicePriceId,
            qty: qty,
            harga: harga
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                loadSPKServices(spkId);
                $('#service_select').val('');
                $('#service_qty').val(1);
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

function deleteServiceFromSPK(serviceId) {
    if (!confirm('Hapus jasa ini?')) return;
    
    const spkId = $('#analisa_spk_id').val();
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'delete_service',
            id: serviceId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                loadSPKServices(spkId);
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// ========== Sparepart Management Functions ==========
let sparepartsCache = [];

function loadSpareparts() {
    $.ajax({
        url: 'backend.php?action=get_all_spareparts',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                sparepartsCache = response.data;
                let options = '<option value="">Pilih Sparepart...</option>';
                response.data.forEach(function(sp) {
                    options += `<option value="${sp.id}" data-price="${sp.harga_jual_default}" data-stok="${sp.stok}">${sp.kode_sparepart} - ${sp.nama} (Stok: ${sp.stok}) - Rp ${formatNumber(sp.harga_jual_default)}</option>`;
                });

                if ($.fn.select2 && $('#sparepart_select').hasClass('select2-hidden-accessible')) {
                    $('#sparepart_select').select2('destroy');
                }
                $('#sparepart_select').html(options);
                
                // Initialize Select2 for searchable dropdown
                if ($.fn.select2) {
                    $('#sparepart_select').select2({
                        theme: 'bootstrap-5',
                        placeholder: 'Cari by kode atau nama sparepart...',
                        allowClear: true,
                        width: '100%',
                        dropdownParent: $('#analisaModal')
                    });
                }
            } else {
                showAlert('danger', response.message || 'Gagal memuat daftar sparepart');
            }
        },
        error: function(xhr) {
            showAlert('danger', 'Error load sparepart: ' + (xhr.responseText || 'Unknown error'));
        }
    });
}

function loadSPKSpareparts(spkId) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + spkId + '&t=' + Date.now(),
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success && response.data.items) {
                displaySparepartsList(response.data.items);
            }
        }
    });
}

function displaySparepartsList(spareparts) {
    let html = '';
    let total = 0;
    const useCustomPrice = parseInt($('#analisaForm').data('use-custom-price')) || 0;
    
    if (spareparts && spareparts.length > 0) {
        spareparts.forEach(function(sp) {
            let subtotal = parseFloat(sp.subtotal) || 0;
            total += subtotal;
            // Tampil custom price HANYA jika item punya use_custom_price = 1
            const itemCustomEnabled = parseInt(sp.use_custom_price) === 1;
            const customPriceVal = parseFloat(sp.harga_custom);
            const hasCustomPrice = sp.harga_custom && customPriceVal > 0;
            
            let displayPrice;
            if (itemCustomEnabled && hasCustomPrice) {
                displayPrice = customPriceVal;
            } else {
                displayPrice = (sp.harga_satuan_eff || sp.harga_jual_default);
            }
            
            console.log('=== RENDER: ' + sp.sparepart_name + ' ===');
            console.log('Raw values:');
            console.log('  sp.use_custom_price = ' + sp.use_custom_price + ' (typeof: ' + typeof sp.use_custom_price + ')');
            console.log('  sp.harga_custom = ' + sp.harga_custom);
            console.log('  sp.harga_satuan_eff = ' + sp.harga_satuan_eff);
            console.log('Logic:');
            console.log('  parseInt(sp.use_custom_price) = ' + parseInt(sp.use_custom_price));
            console.log('  itemCustomEnabled (=== 1?) = ' + itemCustomEnabled);
            console.log('  customPriceVal = ' + customPriceVal);
            console.log('  hasCustomPrice (> 0?) = ' + hasCustomPrice);
            console.log('Decision:');
            console.log('  displayPrice CHOSEN = ' + displayPrice);
            console.log('  formatNumber(displayPrice) = ' + formatNumber(displayPrice));
            console.log('');
            
            html += `
                <tr>
                    <td>${sp.sparepart_name}</td>
                    <td>${sp.qty} ${sp.satuan}</td>
                    <td>Rp ${formatNumber(displayPrice)}</td>
                    <td>Rp ${formatNumber(subtotal)}</td>
                    <td>
                        ${useCustomPrice ? `
                            <button type="button" class="btn btn-primary btn-sm" onclick="editItemPrice(${sp.id}, ${sp.qty}, '${sp.sparepart_name}', ${displayPrice})" title="Edit Harga">
                                <i class="fas fa-edit"></i>
                            </button>
                        ` : ''}
                        <button type="button" class="btn btn-danger btn-sm" onclick="deleteSparepartFromSPK(${sp.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
    } else {
        html = '<tr><td colspan="5" class="text-center text-muted">Belum ada sparepart</td></tr>';
    }
    
    currentSparepartTotal = total;
    console.log('=== BEFORE RENDER TO DOM ===');
    console.log('HTML to inject:', html.substring(0, 200) + '...');
    $('#sparepartListTable').html(html);
    console.log('=== AFTER RENDER TO DOM ===');
    console.log('Table HTML now:', $('#sparepartListTable').html().substring(0, 200) + '...');
    $('#totalSparepartCost').html('Rp ' + formatNumber(total));
    refreshEstimasiBiaya();
}

function addSparepartToSPK() {
    const spkId = $('#analisa_spk_id').val();
    const sparepartId = $('#sparepart_select').val();
    const qty = parseInt($('#sparepart_qty').val()) || 1;

    if (!spkId) {
        showAlert('danger', 'SPK tidak valid. Tutup modal lalu buka lagi Analisa.');
        return;
    }
    
    if (!sparepartId) {
        showAlert('warning', 'Pilih sparepart terlebih dahulu');
        return;
    }

    if (qty <= 0) {
        showAlert('warning', 'Qty harus lebih dari 0');
        return;
    }
    
    const selectedOption = $('#sparepart_select option:selected');
    const stok = parseInt(selectedOption.data('stok'));
    
    if (qty > stok) {
        showAlert('danger', 'Qty melebihi stok tersedia (' + stok + ')');
        return;
    }
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'add_sparepart',
            spk_id: spkId,
            sparepart_id: sparepartId,
            qty: qty
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                loadSPKSpareparts(spkId);
                loadSpareparts(); // Reload to update stock
                $('#sparepart_select').val('').trigger('change');
                $('#sparepart_qty').val(1);
            } else {
                showAlert('danger', response.message);
            }
        },
        error: function(xhr) {
            showAlert('danger', 'Gagal menambahkan sparepart: ' + (xhr.responseText || 'Unknown error'));
        }
    });
}

function deleteSparepartFromSPK(sparepartItemId) {
    if (!confirm('Hapus sparepart ini? Stok akan dikembalikan.')) return;
    
    const spkId = $('#analisa_spk_id').val();
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'delete_sparepart',
            id: sparepartItemId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                loadSPKSpareparts(spkId);
                loadSpareparts(); // Reload to update stock
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// TOGGLE CUSTOM PRICE MODE
function toggleCustomPrice(spkId, useCustom) {
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'toggle_custom_price',
            id: spkId,
            use_custom: useCustom
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                // Reload SPK list untuk update toggle state
                loadSPKs();
                
                // Delay untuk memastikan database sudah sync
                setTimeout(function() {
                    // Jika modal sudah terbuka, reload data di dalamnya
                    const modalSpkId = parseInt($('#analisa_spk_id').val());
                    if (modalSpkId === spkId) {
                        // Update flag di modal DULU sebelum loadSPKSpareparts
                        $('#analisaForm').data('use-custom-price', useCustom);
                        // Reload sparepart table dengan data terbaru
                        loadSPKSpareparts(spkId);
                    }
                    
                    // Jika toggle ON dan modal belum terbuka, buka sekarang
                    if (useCustom === 1 && !$('#analisaModal').hasClass('show')) {
                        openAnalisaModal(spkId);
                    }
                }, 300);
            } else {
                showAlert('danger', response.message);
                // Revert toggle jika gagal
                $('#toggle_' + spkId).prop('checked', !useCustom);
            }
        }
    });
}

// EDIT ITEM CUSTOM PRICE
function editItemPrice(itemId, qty, itemName, currentPrice) {
    $('#editPriceItemId').val(itemId);
    $('#editPriceQty').val(qty);
    $('#editPriceItemName').text(itemName);
    $('#editPriceValue').val(currentPrice);
    
    // Update preview on input
    $('#editPriceValue').off('input').on('input', function() {
        const price = parseFloat($(this).val()) || 0;
        const total = price * qty;
        $('#editPriceTotalPreview').text('Total: Rp ' + formatNumber(total));
    });
    
    // Trigger initial preview
    $('#editPriceValue').trigger('input');
    
    $('#editPriceModal').modal('show');
}

// SAVE CUSTOM PRICE
function saveItemCustomPrice() {
    const itemId = parseInt($('#editPriceItemId').val());
    const hargaCustom = parseFloat($('#editPriceValue').val()) || 0;
    const qty = parseInt($('#editPriceQty').val());
    const spkId = parseInt($('#analisa_spk_id').val());
    
    if (itemId <= 0 || hargaCustom < 0 || qty <= 0) {
        showAlert('warning', 'Data tidak valid');
        return;
    }
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'update_item_custom_price',
            item_id: itemId,
            harga_custom: hargaCustom,
            qty: qty
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                $('#editPriceModal').modal('hide');
                // loadSPKSpareparts will reload and automatically call refreshEstimasiBiaya()
                loadSPKSpareparts(spkId);
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// EDIT SERVICE CUSTOM PRICE
function editServicePrice(serviceId, qty, serviceName, currentPrice) {
    $('#editPriceServiceId').val(serviceId);
    $('#editPriceServiceQty').val(qty);
    $('#editPriceServiceName').text(serviceName);
    $('#editPriceServiceValue').val(currentPrice);

    // Update preview on input
    $('#editPriceServiceValue').off('input').on('input', function() {
        const price = parseFloat($(this).val()) || 0;
        const total = price * qty;
        $('#editPriceServiceTotalPreview').text('Total: Rp ' + formatNumber(total));
    });

    // Trigger initial preview
    $('#editPriceServiceValue').trigger('input');

    $('#editServicePriceModal').modal('show');
}

// SAVE SERVICE CUSTOM PRICE
function saveServiceCustomPrice() {
    const serviceId = parseInt($('#editPriceServiceId').val());
    const hargaCustom = parseFloat($('#editPriceServiceValue').val()) || 0;
    const qty = parseInt($('#editPriceServiceQty').val());
    const spkId = parseInt($('#analisa_spk_id').val());

    if (serviceId <= 0 || hargaCustom < 0 || qty <= 0) {
        showAlert('warning', 'Data tidak valid');
        return;
    }

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'update_service_custom_price',
            service_id: serviceId,
            harga_custom: hargaCustom,
            qty: qty
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                $('#editServicePriceModal').modal('hide');
                // Reload services dan refresh estimasi
                loadSPKServices(spkId);
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}
</script>

