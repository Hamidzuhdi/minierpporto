<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Manajemen Harga Jasa Service";
include '../header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-wrench"></i> Manajemen Harga Jasa Service</h2>
                    <p class="text-muted">Kelola master data harga jasa service bengkel</p>
                </div>
                <button class="btn btn-primary" onclick="openServiceModal()">
                    <i class="fas fa-plus"></i> Tambah Service
                </button>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-5">
                    <input type="text" class="form-control" id="serviceSearchInput" placeholder="Cari kode jasa atau nama jasa...">
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="serviceCategoryFilter">
                        <option value="">Semua Kategori</option>
                        <option value="Ringan">Ringan</option>
                        <option value="Sedang">Sedang</option>
                        <option value="Berat">Berat</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="serviceStatusFilter">
                        <option value="">Semua Status</option>
                        <option value="Y">Aktif</option>
                        <option value="N">Tidak Aktif</option>
                    </select>
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-outline-secondary" type="button" id="serviceResetFilter" title="Reset Filter">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle" id="serviceTable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Kode Jasa</th>
                            <th>Nama Jasa</th>
                            <th>Harga</th>
                            <th>Kategori</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="serviceTableBody">
                        <tr>
                            <td colspan="7" class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Service -->
<div class="modal fade" id="serviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceModalTitle">Tambah Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="serviceForm">
                    <input type="hidden" id="serviceId">
                    
                    <div class="mb-3">
                        <label class="form-label">Kode Jasa <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="kodeJasa" required>
                        <small class="text-muted">Contoh: SVC001, GANTI-OLI</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Nama Jasa <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="namaJasa" required>
                        <small class="text-muted">Contoh: Ganti Oli Mesin, Tune Up, Spooring</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Harga <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="harga" min="0" step="0.01" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Kategori</label>
                        <select class="form-select" id="kategori">
                            <option value="Ringan">Ringan</option>
                            <option value="Sedang">Sedang</option>
                            <option value="Berat">Berat</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="isActive">
                            <option value="Y">Aktif</option>
                            <option value="N">Tidak Aktif</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" onclick="saveService()">Simpan</button>
            </div>
        </div>
    </div>
</div>

<script>
let serviceModal;
let allServices = [];

document.addEventListener('DOMContentLoaded', function() {
    serviceModal = new bootstrap.Modal(document.getElementById('serviceModal'));
    loadServices();

    document.getElementById('serviceSearchInput').addEventListener('input', applyServiceFilters);
    document.getElementById('serviceCategoryFilter').addEventListener('change', applyServiceFilters);
    document.getElementById('serviceStatusFilter').addEventListener('change', applyServiceFilters);
    document.getElementById('serviceResetFilter').addEventListener('click', function() {
        document.getElementById('serviceSearchInput').value = '';
        document.getElementById('serviceCategoryFilter').value = '';
        document.getElementById('serviceStatusFilter').value = '';
        applyServiceFilters();
    });
});

function loadServices() {
    fetch('backend.php?action=read')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allServices = data.data || [];
                applyServiceFilters();
            } else {
                showAlert('error', 'Gagal memuat data');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('error', 'Terjadi kesalahan saat memuat data');
        });
}

function applyServiceFilters() {
    const keyword = (document.getElementById('serviceSearchInput').value || '').toLowerCase().trim();
    const category = document.getElementById('serviceCategoryFilter').value;
    const status = document.getElementById('serviceStatusFilter').value;

    const filtered = allServices.filter(service => {
        const matchKeyword = keyword === '' ||
            (service.kode_jasa || '').toLowerCase().includes(keyword) ||
            (service.nama_jasa || '').toLowerCase().includes(keyword);
        const matchCategory = category === '' || (service.kategori || '') === category;
        const matchStatus = status === '' || (service.is_active || '') === status;
        return matchKeyword && matchCategory && matchStatus;
    });

    displayServices(filtered);
}

function displayServices(services) {
    const tbody = document.getElementById('serviceTableBody');
    
    if (services.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">Belum ada data service</td></tr>';
        return;
    }
    
    let html = '';
    services.forEach(service => {
        const kategoriClass = {
            'Ringan': 'info',
            'Sedang': 'warning',
            'Berat': 'danger'
        }[service.kategori] || 'secondary';
        
        const statusClass = service.is_active === 'Y' ? 'success' : 'secondary';
        const statusText = service.is_active === 'Y' ? 'Aktif' : 'Tidak Aktif';
        
        html += `
            <tr>
                <td>${service.id}</td>
                <td><strong>${service.kode_jasa}</strong></td>
                <td>${service.nama_jasa}</td>
                <td>Rp ${parseFloat(service.harga).toLocaleString('id-ID')}</td>
                <td><span class="badge bg-${kategoriClass}">${service.kategori}</span></td>
                <td><span class="badge bg-${statusClass}">${statusText}</span></td>
                <td>
                    <button class="btn btn-sm btn-warning" onclick="editService(${service.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="deleteService(${service.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function openServiceModal() {
    document.getElementById('serviceModalTitle').textContent = 'Tambah Service';
    document.getElementById('serviceForm').reset();
    document.getElementById('serviceId').value = '';
    serviceModal.show();
}

function editService(id) {
    fetch(`backend.php?action=read_one&id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const service = data.data;
                document.getElementById('serviceModalTitle').textContent = 'Edit Service';
                document.getElementById('serviceId').value = service.id;
                document.getElementById('kodeJasa').value = service.kode_jasa;
                document.getElementById('namaJasa').value = service.nama_jasa;
                document.getElementById('harga').value = service.harga;
                document.getElementById('kategori').value = service.kategori;
                document.getElementById('isActive').value = service.is_active;
                serviceModal.show();
            }
        });
}

function saveService() {
    const id = document.getElementById('serviceId').value;
    const kode_jasa = document.getElementById('kodeJasa').value.trim();
    const nama_jasa = document.getElementById('namaJasa').value.trim();
    const harga = parseFloat(document.getElementById('harga').value);
    const kategori = document.getElementById('kategori').value;
    const is_active = document.getElementById('isActive').value;
    
    if (!kode_jasa || !nama_jasa || !harga || harga <= 0) {
        showAlert('error', 'Mohon lengkapi semua field yang diperlukan');
        return;
    }
    
    const action = id ? 'update' : 'create';
    const payload = { kode_jasa, nama_jasa, harga, kategori, is_active };
    if (id) payload.id = id;
    
    fetch(`backend.php?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            serviceModal.hide();
            loadServices();
        } else {
            showAlert('error', data.message);
        }
    });
}

function deleteService(id) {
    if (!confirm('Yakin ingin menghapus service ini?')) return;
    
    fetch(`backend.php?action=delete&id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', data.message);
                loadServices();
            } else {
                showAlert('error', data.message);
            }
        });
}

function showAlert(type, message) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    const container = document.querySelector('.container-fluid');
    container.insertAdjacentHTML('afterbegin', alertHtml);
    
    setTimeout(() => {
        const alert = container.querySelector('.alert');
        if (alert) alert.remove();
    }, 3000);
}
</script>

<?php include '../footer.php'; ?>
