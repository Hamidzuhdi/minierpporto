<?php
session_start();
require_once '../config.php';

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Manajemen Customer";
include '../header.php';

$reminder_param = (string)($_GET['reminder'] ?? '');
$reminder_from_query = ($reminder_param === '2months') ? '1' : (in_array($reminder_param, ['7days', '1', '6months'], true) ? $reminder_param : '0');
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Data Customer</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Tambah Customer
                    </button>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <input type="text" class="form-control" id="searchInput" placeholder="Cari nama atau telepon...">
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="reminderFilter">
                                <option value="0">Semua Customer</option>
                                <option value="7days">Reminder Customer (>= 7 Hari)</option>
                                <option value="1">Reminder Customer (>= 2 Bulan)</option>
                                <option value="6months">Reminder Customer (>= 6 Bulan)</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="customerTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nama</th>
                                    <th>Telepon</th>
                                    <th>Alamat</th>
                                    <th>Kendaraan</th>
                                    <th>Status Piutang</th>
                                    <th>Follow-up</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="customerTableBody">
                                <tr>
                                    <td colspan="8" class="text-center">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Customer -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customerModalTitle">Tambah Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="customerForm">
                <div class="modal-body">
                    <input type="hidden" id="customerId" name="id">
                    <input type="hidden" id="formAction" name="action" value="create">
                    
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user"></i> Data Customer</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="name" class="form-label">Nama Customer *</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Telepon</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Alamat</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    
                    <hr>
                    
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-car"></i> Data Kendaraan (Opsional)</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="nomor_polisi" class="form-label">Nomor Polisi</label>
                                <input type="text" class="form-control text-uppercase" id="nomor_polisi" name="nomor_polisi" placeholder="B 1234 XYZ">
                                <small class="text-muted">Kosongkan jika tidak ada kendaraan</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="merk" class="form-label">Merk</label>
                                <input type="text" class="form-control" id="merk" name="merk" placeholder="Toyota, Honda, dll">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="model" class="form-label">Model</label>
                                <input type="text" class="form-control" id="model" name="model" placeholder="Avanza, Jazz, dll">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="tahun" class="form-label">Tahun</label>
                                <input type="number" class="form-control" id="tahun" name="tahun" placeholder="2020" min="1900" max="2100">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="note" class="form-label">Catatan Kendaraan</label>
                        <textarea class="form-control" id="note" name="note" rows="2" placeholder="Warna, kondisi, atau catatan lainnya"></textarea>
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

<!-- Modal Detail Customer & Vehicles -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detail Customer & Kendaraan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah Kendaraan -->
<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addVehicleModalTitle">Tambah Kendaraan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addVehicleForm">
                <div class="modal-body">
                    <input type="hidden" id="vehicle_customer_id" name="customer_id">
                    <input type="hidden" id="vehicle_record_id" name="vehicle_id" value="">
                    <input type="hidden" id="addVehicleAction" name="action" value="add_vehicle">
                    
                    <div class="mb-3">
                        <label for="vehicle_nomor_polisi" class="form-label">Nomor Polisi *</label>
                        <input type="text" class="form-control text-uppercase" id="vehicle_nomor_polisi" name="nomor_polisi" required placeholder="B 1234 XYZ">
                    </div>
                    
                    <div class="mb-3">
                        <label for="vehicle_merk" class="form-label">Merk</label>
                        <input type="text" class="form-control" id="vehicle_merk" name="merk" placeholder="Toyota, Honda, dll">
                    </div>
                    
                    <div class="mb-3">
                        <label for="vehicle_model" class="form-label">Model</label>
                        <input type="text" class="form-control" id="vehicle_model" name="model" placeholder="Avanza, Jazz, dll">
                    </div>
                    
                    <div class="mb-3">
                        <label for="vehicle_tahun" class="form-label">Tahun</label>
                        <input type="number" class="form-control" id="vehicle_tahun" name="tahun" placeholder="2020" min="1900" max="2100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="vehicle_note" class="form-label">Catatan</label>
                        <textarea class="form-control" id="vehicle_note" name="note" rows="2" placeholder="Warna, kondisi, dll"></textarea>
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

<!-- Modal Catat Follow-up -->
<div class="modal fade" id="followupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-phone"></i> Catat Follow-up</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="followupForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_followup">
                    <input type="hidden" id="followup_customer_id" name="customer_id">
                    <input type="hidden" id="followup_tahap" name="tahap">

                    <div class="mb-3">
                        <div class="fw-bold" id="followupCustomerInfo"></div>
                        <small class="text-muted" id="followupTahapInfo"></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Hasil Follow-up *</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="hasil" id="hasil_no_respon" value="no_respon" required>
                            <label class="form-check-label" for="hasil_no_respon">No respon</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="hasil" id="hasil_respon" value="respon">
                            <label class="form-check-label" for="hasil_respon">Respon</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="hasil" id="hasil_prospek" value="prospek">
                            <label class="form-check-label" for="hasil_prospek">Prospek</label>
                        </div>
                        <small class="text-muted">"No respon" tetap muncul di daftar reminder; "Respon" dan "Prospek" mengeluarkan customer dari tahap ini.</small>
                    </div>

                    <div class="mb-3">
                        <label for="followup_note" class="form-label">Catatan</label>
                        <textarea class="form-control" id="followup_note" name="note" rows="2" placeholder="Opsional"></textarea>
                    </div>

                    <h6 class="border-bottom pb-2 mb-2">Riwayat Follow-up</h6>
                    <div id="followupHistory" class="small">Memuat...</div>
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
const reminderFromQuery = '<?php echo $reminder_from_query; ?>';
// Nilai filter reminder -> tahap follow-up di backend
const FOLLOWUP_TAHAP_BY_FILTER = {'7days': '7days', '1': '2months', '6months': '6months'};
const FOLLOWUP_TAHAP_LABEL = {'7days': '7 Hari', '2months': '2 Bulan', '6months': '6 Bulan'};
const FOLLOWUP_HASIL_BADGE = {
    no_respon: ['warning text-dark', 'No respon'],
    respon: ['info', 'Respon'],
    prospek: ['success', 'Prospek']
};
let customerRows = {};
let currentDetailCustomerId = null;
let reopenDetailAfterVehicleSave = false;

// Load data saat halaman dimuat
$(document).ready(function() {
    if (FOLLOWUP_TAHAP_BY_FILTER[reminderFromQuery]) {
        $('#reminderFilter').val(reminderFromQuery);
    }

    loadCustomers();
    
    // Search dengan delay
    let searchTimeout;
    $('#searchInput').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadCustomers();
        }, 500);
    });

    $('#reminderFilter').on('change', function() {
        loadCustomers();
    });
});

// Load semua customer
function loadCustomers() {
    let search = $('#searchInput').val();
    let reminder = $('#reminderFilter').val() || '0';
    
    $.ajax({
        url: 'backend.php?action=read&search=' + encodeURIComponent(search) + '&reminder=' + encodeURIComponent(reminder),
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayCustomers(response.data);
            } else {
                showAlert('danger', 'Gagal memuat data customer');
            }
        },
        error: function() {
            showAlert('danger', 'Terjadi kesalahan saat memuat data');
        }
    });
}

// Tampilkan data customer di tabel
function displayCustomers(customers) {
    let html = '';
    const followupTahap = FOLLOWUP_TAHAP_BY_FILTER[$('#reminderFilter').val()] || null;
    customerRows = {};

    if (customers.length === 0) {
        html = '<tr><td colspan="8" class="text-center">Belum ada data customer</td></tr>';
    } else {
        customers.forEach(function(customer) {
            customerRows[customer.id] = customer;

            // Kolom follow-up hanya bermakna saat filter reminder aktif
            let followupCell = '-';
            if (followupTahap) {
                const attempts = parseInt(customer.fu_attempts) || 0;
                const followupStatus = attempts > 0
                    ? `<span class="badge bg-warning text-dark">No respon ${attempts}x</span><br><small class="text-muted">terakhir ${formatDate(customer.fu_last_at)}</small>`
                    : '<span class="badge bg-light text-dark border">Belum di-follow-up</span>';
                followupCell = `${followupStatus}<br>
                    <button class="btn btn-success btn-sm mt-1" onclick="openFollowupModal(${customer.id})" title="Catat hasil follow-up">
                        <i class="fas fa-phone"></i> Catat
                    </button>`;
            }

            let vehicleInfo = '-';
            if (customer.vehicles && customer.vehicles.length > 0) {
                vehicleInfo = customer.vehicles.map(v => `<div class="mb-1"><span class="badge bg-primary">${v.nomor_polisi} - ${v.model || v.merk || 'N/A'}</span></div>`).join('');
            }

            let hutang = parseFloat(customer.total_hutang) || 0;
            let piutangInfo = hutang > 0
                ? `<span class="badge bg-danger">Hutang: Rp ${hutang.toLocaleString('id-ID')}</span>`
                : `<span class="badge bg-success">Lunas</span>`;

            html += `
                <tr>
                    <td>${customer.id}</td>
                    <td>${customer.name}</td>
                    <td>${customer.phone || '-'}</td>
                    <td>${customer.address ? (customer.address.length > 50 ? customer.address.substring(0, 50) + '...' : customer.address) : '-'}</td>
                    <td>${vehicleInfo}</td>
                    <td>${piutangInfo}</td>
                    <td>${followupCell}</td>
                    <td>
                        <button class="btn btn-info btn-sm" onclick="viewCustomerDetail(${customer.id})" title="Detail & Kendaraan">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-warning btn-sm" onclick="editCustomer(${customer.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteCustomer(${customer.id}, '${customer.name.replace(/'/g, "\\'")}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
    }
    
    $('#customerTableBody').html(html);
}

function escapeHtml(text) {
    return $('<div>').text(text == null ? '' : String(text)).html();
}

// Buka modal catat follow-up untuk satu customer pada tahap filter reminder yang sedang aktif
function openFollowupModal(customerId) {
    const customer = customerRows[customerId];
    const tahap = FOLLOWUP_TAHAP_BY_FILTER[$('#reminderFilter').val()];
    if (!customer || !tahap) {
        return;
    }

    $('#followupForm')[0].reset();
    $('#followup_customer_id').val(customerId);
    $('#followup_tahap').val(tahap);
    $('#followupCustomerInfo').text(customer.name + (customer.phone ? ' - ' + customer.phone : ''));
    $('#followupTahapInfo').text('Reminder tahap ' + FOLLOWUP_TAHAP_LABEL[tahap] + ' - SPK terakhir: ' + formatDate(customer.last_spk_at));
    $('#followupHistory').text('Memuat...');
    $('#followupModal').modal('show');

    $.ajax({
        url: 'backend.php?action=get_followups&customer_id=' + customerId,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (!response.success || response.data.length === 0) {
                $('#followupHistory').html('<span class="text-muted">Belum ada riwayat follow-up.</span>');
                return;
            }

            let rows = response.data.map(function(f) {
                const badge = FOLLOWUP_HASIL_BADGE[f.hasil] || ['secondary', f.hasil];
                return `<tr>
                    <td>${formatDateTime(f.created_at)}</td>
                    <td>${FOLLOWUP_TAHAP_LABEL[f.tahap] || f.tahap}</td>
                    <td><span class="badge bg-${badge[0]}">${badge[1]}</span></td>
                    <td>${escapeHtml(f.note) || '-'}</td>
                    <td>${escapeHtml(f.created_by_name) || '-'}</td>
                </tr>`;
            }).join('');
            $('#followupHistory').html(`
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light"><tr><th>Waktu</th><th>Tahap</th><th>Hasil</th><th>Catatan</th><th>Oleh</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>`);
        },
        error: function() {
            $('#followupHistory').html('<span class="text-danger">Gagal memuat riwayat.</span>');
        }
    });
}

$('#followupForm').on('submit', function(e) {
    e.preventDefault();

    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                $('#followupModal').modal('hide');
                loadCustomers();
            } else {
                showAlert('danger', response.message);
            }
        },
        error: function() {
            showAlert('danger', 'Terjadi kesalahan saat menyimpan follow-up');
        }
    });
});

// Buka modal untuk tambah customer
function openAddModal() {
    $('#customerModalTitle').text('Tambah Customer');
    $('#customerForm')[0].reset();
    $('#customerId').val('');
    $('#formAction').val('create');
    $('#nomor_polisi').val('');
    $('#merk').val('');
    $('#model').val('');
    $('#tahun').val('');
    $('#note').val('');
}

// View customer detail with vehicles
function viewCustomerDetail(id) {
    currentDetailCustomerId = id;
    $.ajax({
        url: 'backend.php?action=read_one_with_vehicles&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let customer = response.data.customer;
                let vehicles = response.data.vehicles || [];
                let lastSpk = response.data.last_spk;
                
                let html = `
                    <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user"></i> Informasi Customer</h6>
                    <table class="table table-sm">
                        <tr><th width="30%">Nama:</th><td>${customer.name}</td></tr>
                        <tr><th>Telepon:</th><td>${customer.phone || '-'}</td></tr>
                        <tr><th>Alamat:</th><td>${customer.address || '-'}</td></tr>
                        <tr><th>Dibuat:</th><td>${formatDateTime(customer.created_at)}</td></tr>
                    </table>
                `;
                
                // Tampilkan SPK terakhir jika ada
                if (lastSpk) {
                    html += `
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-file-invoice"></i> SPK Terakhir</h6>
                        <table class="table table-sm">
                            <tr><th width="30%">No. SPK:</th><td><strong>${lastSpk.kode_unik_reference}</strong></td></tr>
                            <tr><th>Tanggal:</th><td>${formatDate(lastSpk.tanggal)}</td></tr>
                            <tr><th>Keluhan:</th><td>${lastSpk.keluhan_customer || '-'}</td></tr>
                            <tr><th>Status:</th><td><span class="badge bg-info">${lastSpk.status_spk}</span></td></tr>
                            <tr><th>Dibuat:</th><td>${formatDateTime(lastSpk.created_at)}</td></tr>
                        </table>
                        <a href="../spk/index.php?edit_id=${lastSpk.id}" class="btn btn-sm btn-primary" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Buka SPK
                        </a>
                    `;
                } else {
                    html += `<div class="alert alert-info"><small>Belum ada riwayat SPK untuk customer ini.</small></div>`;
                }
                
                html += `
                    <h6 class="border-bottom pb-2 mb-3 mt-4 d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-car"></i> Kendaraan Terdaftar</span>
                        <button type="button" class="btn btn-sm btn-success" onclick="openAddVehicleModal(${customer.id}, true)">
                            <i class="fas fa-plus"></i> Tambah Kendaraan
                        </button>
                    </h6>
                `;
                
                if (vehicles.length > 0) {
                    html += '<div class="table-responsive"><table class="table table-bordered table-sm">';
                    html += '<thead><tr><th>Nomor Polisi</th><th>Merk</th><th>Model</th><th>Tahun</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody>';
                    vehicles.forEach(function(v) {
                        html += `
                            <tr>
                                <td><strong>${v.nomor_polisi}</strong></td>
                                <td>${v.merk || '-'}</td>
                                <td>${v.model || '-'}</td>
                                <td>${v.tahun || '-'}</td>
                                <td>${v.note || '-'}</td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editCustomerVehicle(${v.id})" title="Edit Kendaraan">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<div class="alert alert-info">Belum ada kendaraan terdaftar untuk customer ini.</div>';
                }
                
                $('#detailContent').html(html);
                $('#detailModal').modal('show');
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// Edit customer
function editCustomer(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let customer = response.data;
                $('#customerModalTitle').html('Edit Customer <button type="button" class="btn btn-sm btn-success ms-2" onclick="openAddVehicleModal(' + customer.id + ')"><i class="fas fa-plus"></i> Tambah Kendaraan</button>');
                $('#customerId').val(customer.id);
                $('#name').val(customer.name);
                $('#phone').val(customer.phone);
                $('#address').val(customer.address);
                $('#formAction').val('update');
                $('#customerModal').modal('show');
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// Open add vehicle modal
function openAddVehicleModal(customerId, returnToDetail = false) {
    reopenDetailAfterVehicleSave = !!returnToDetail;
    if (reopenDetailAfterVehicleSave) {
        currentDetailCustomerId = customerId;
    }

    $('#addVehicleModalTitle').text('Tambah Kendaraan');
    $('#vehicle_customer_id').val(customerId);
    $('#addVehicleForm')[0].reset();
    $('#vehicle_customer_id').val(customerId); // Set lagi after reset
    $('#vehicle_record_id').val('');
    $('#addVehicleAction').val('add_vehicle');
    $('#customerModal').modal('hide');
    $('#detailModal').modal('hide');
    $('#addVehicleModal').modal('show');
}

function editCustomerVehicle(vehicleId) {
    $.ajax({
        url: 'backend.php?action=read_vehicle&vehicle_id=' + vehicleId,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const vehicle = response.data;
                currentDetailCustomerId = vehicle.customer_id;
                reopenDetailAfterVehicleSave = true;

                $('#addVehicleModalTitle').text('Edit Kendaraan');
                $('#addVehicleForm')[0].reset();
                $('#vehicle_customer_id').val(vehicle.customer_id);
                $('#vehicle_record_id').val(vehicle.id);
                $('#addVehicleAction').val('update_vehicle');
                $('#vehicle_nomor_polisi').val(vehicle.nomor_polisi || '');
                $('#vehicle_merk').val(vehicle.merk || '');
                $('#vehicle_model').val(vehicle.model || '');
                $('#vehicle_tahun').val(vehicle.tahun || '');
                $('#vehicle_note').val(vehicle.note || '');

                $('#detailModal').modal('hide');
                $('#customerModal').modal('hide');
                $('#addVehicleModal').modal('show');
            } else {
                showAlert('danger', response.message || 'Data kendaraan tidak ditemukan');
            }
        },
        error: function() {
            showAlert('danger', 'Terjadi kesalahan saat mengambil data kendaraan');
        }
    });
}

// Submit add vehicle form
$('#addVehicleForm').on('submit', function(e) {
    e.preventDefault();
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                $('#addVehicleModal').modal('hide');
                loadCustomers();
                if (reopenDetailAfterVehicleSave && currentDetailCustomerId) {
                    viewCustomerDetail(currentDetailCustomerId);
                }
                reopenDetailAfterVehicleSave = false;
            } else {
                showAlert('danger', response.message);
            }
        }
    });
});

// Hapus customer
function deleteCustomer(id, name) {
    if (confirm(`Yakin ingin menghapus customer "${name}"?`)) {
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
                    loadCustomers();
                } else {
                    showAlert('danger', response.message);
                }
            }
        });
    }
}

// Submit form (Create/Update)
$('#customerForm').on('submit', function(e) {
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
                $('#customerModal').modal('hide');
                loadCustomers();
            } else {
                showAlert('danger', response.message);
            }
        }
    });
});

// Helper: Format datetime
function formatDateTime(datetime) {
    if (!datetime) return '-';
    let d = new Date(datetime);
    return d.toLocaleDateString('id-ID') + ' ' + d.toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'});
}

// Helper: Format date only (YYYY-MM-DD)
function formatDate(date) {
    if (!date) return '-';
    let d = new Date(date);
    return d.toLocaleDateString('id-ID');
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
</script>
