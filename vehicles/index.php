<?php
session_start();
require_once '../config.php';

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Manajemen Kendaraan";
include '../header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Data Kendaraan</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#vehicleModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Tambah Kendaraan
                    </button>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="searchInput" placeholder="Cari nomor polisi, merk, atau nama customer...">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="vehicleTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nomor Polisi</th>
                                    <th>Merk/Model</th>
                                    <th>Tahun</th>
                                    <th>Customer</th>
                                    <th>Note</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="vehicleTableBody">
                                <tr>
                                    <td colspan="7" class="text-center">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Vehicle -->
<div class="modal fade" id="vehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vehicleModalTitle">Tambah Kendaraan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="vehicleForm">
                <div class="modal-body">
                    <input type="hidden" id="vehicleId" name="id">
                    <input type="hidden" id="formAction" name="action" value="create">
                    
                    <div class="mb-3">
                        <label for="customer_id" class="form-label">Customer *</label>
                        <select class="form-select" id="customer_id" name="customer_id" required>
                            <option value="">-- Pilih Customer --</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="nomor_polisi" class="form-label">Nomor Polisi *</label>
                        <input type="text" class="form-control" id="nomor_polisi" name="nomor_polisi" required placeholder="B 1234 XYZ">
                    </div>
                    
                    <div class="mb-3">
                        <label for="merk" class="form-label">Merk</label>
                        <input type="text" class="form-control" id="merk" name="merk" placeholder="Toyota, Honda, dll">
                    </div>
                    
                    <div class="mb-3">
                        <label for="model" class="form-label">Model</label>
                        <input type="text" class="form-control" id="model" name="model" placeholder="Avanza, Jazz, dll">
                    </div>
                    
                    <div class="mb-3">
                        <label for="tahun" class="form-label">Tahun</label>
                        <input type="number" class="form-control" id="tahun" name="tahun" placeholder="2020" min="1900" max="2100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="note" class="form-label">Catatan</label>
                        <textarea class="form-control" id="note" name="note" rows="3"></textarea>
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
// Load data saat halaman dimuat
$(document).ready(function() {
    loadVehicles();
    loadCustomers();
    
    // Search dengan delay
    let searchTimeout;
    $('#searchInput').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadVehicles();
        }, 500);
    });
});

// Load semua kendaraan
function loadVehicles() {
    let search = $('#searchInput').val();
    
    $.ajax({
        url: 'backend.php?action=read&search=' + encodeURIComponent(search),
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayVehicles(response.data);
            } else {
                showAlert('danger', 'Gagal memuat data kendaraan');
            }
        },
        error: function() {
            showAlert('danger', 'Terjadi kesalahan saat memuat data');
        }
    });
}

// Load customers untuk dropdown
function loadCustomers() {
    $.ajax({
        url: 'backend.php?action=get_customers',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let options = '<option value="">-- Pilih Customer --</option>';
                response.data.forEach(function(customer) {
                    options += `<option value="${customer.id}">${customer.name} ${customer.phone ? '(' + customer.phone + ')' : ''}</option>`;
                });
                $('#customer_id').html(options);
            }
        }
    });
}

// Tampilkan data kendaraan di tabel
function displayVehicles(vehicles) {
    let html = '';
    
    if (vehicles.length === 0) {
        html = '<tr><td colspan="7" class="text-center">Belum ada data kendaraan</td></tr>';
    } else {
        vehicles.forEach(function(vehicle) {
            let merkModel = [vehicle.merk, vehicle.model].filter(Boolean).join(' ');
            html += `
                <tr>
                    <td>${vehicle.id}</td>
                    <td><strong>${vehicle.nomor_polisi}</strong></td>
                    <td>${merkModel || '-'}</td>
                    <td>${vehicle.tahun || '-'}</td>
                    <td>${vehicle.customer_name}</td>
                    <td>${vehicle.note ? (vehicle.note.length > 30 ? vehicle.note.substring(0, 30) + '...' : vehicle.note) : '-'}</td>
                    <td>
                        <button class="btn btn-warning btn-sm" onclick="editVehicle(${vehicle.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteVehicle(${vehicle.id}, '${vehicle.nomor_polisi}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
    }
    
    $('#vehicleTableBody').html(html);
}

// Buka modal untuk tambah kendaraan
function openAddModal() {
    $('#vehicleModalTitle').text('Tambah Kendaraan');
    $('#vehicleForm')[0].reset();
    $('#vehicleId').val('');
    $('#formAction').val('create');
}

// Edit kendaraan
function editVehicle(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let vehicle = response.data;
                $('#vehicleModalTitle').text('Edit Kendaraan');
                $('#vehicleId').val(vehicle.id);
                $('#customer_id').val(vehicle.customer_id);
                $('#nomor_polisi').val(vehicle.nomor_polisi);
                $('#merk').val(vehicle.merk);
                $('#model').val(vehicle.model);
                $('#tahun').val(vehicle.tahun);
                $('#note').val(vehicle.note);
                $('#formAction').val('update');
                $('#vehicleModal').modal('show');
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// Hapus kendaraan
function deleteVehicle(id, nopol) {
    if (confirm(`Yakin ingin menghapus kendaraan "${nopol}"?`)) {
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
                    loadVehicles();
                } else {
                    showAlert('danger', response.message);
                }
            }
        });
    }
}

// Submit form (Create/Update)
$('#vehicleForm').on('submit', function(e) {
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
                $('#vehicleModal').modal('hide');
                loadVehicles();
            } else {
                showAlert('danger', response.message);
            }
        }
    });
});

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
