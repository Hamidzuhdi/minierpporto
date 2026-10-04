<?php
session_start();
require_once '../config.php';

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Manajemen User";
include '../header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Data User</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Tambah User
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="userTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Nama Lengkap</th>
                                    <th>Role</th>
                                    <th>Dibuat</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="userTableBody">
                                <tr>
                                    <td colspan="6" class="text-center">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Add/Edit User -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">Tambah User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm">
                <div class="modal-body">
                    <input type="hidden" id="userId" name="id">
                    <input type="hidden" id="formAction" name="action" value="create">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password <span id="passwordOptional"></span></label>
                        <input type="password" class="form-control" id="password" name="password">
                        <small class="text-muted">Minimal 6 karakter</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Nama Lengkap</label>
                        <input type="text" class="form-control" id="full_name" name="full_name">
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Role *</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="Admin">Admin</option>
                            <option value="Warehouse">Warehouse</option>
                            <option value="Owner">Owner</option>
                        </select>
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
    loadUsers();
});

// Load semua user
function loadUsers() {
    $.ajax({
        url: 'backend.php?action=read',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayUsers(response.data);
            } else {
                showAlert('danger', 'Gagal memuat data user');
            }
        },
        error: function() {
            showAlert('danger', 'Terjadi kesalahan saat memuat data');
        }
    });
}

// Tampilkan data user di tabel
function displayUsers(users) {
    let html = '';
    
    if (users.length === 0) {
        html = '<tr><td colspan="6" class="text-center">Belum ada data user</td></tr>';
    } else {
        users.forEach(function(user) {
            let roleClass = user.role === 'Admin' ? 'primary' : (user.role === 'Owner' ? 'success' : 'info');
            html += `
                <tr>
                    <td>${user.id}</td>
                    <td>${user.username}</td>
                    <td>${user.full_name || '-'}</td>
                    <td><span class="badge bg-${roleClass}">${user.role}</span></td>
                    <td>${formatDateTime(user.created_at)}</td>
                    <td>
                        <button class="btn btn-warning btn-sm" onclick="editUser(${user.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteUser(${user.id}, '${user.username}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
    }
    
    $('#userTableBody').html(html);
}

// Buka modal untuk tambah user
function openAddModal() {
    $('#userModalTitle').text('Tambah User');
    $('#userForm')[0].reset();
    $('#userId').val('');
    $('#formAction').val('create');
    $('#password').prop('required', true);
    $('#passwordOptional').text('*');
}

// Edit user
function editUser(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let user = response.data;
                $('#userModalTitle').text('Edit User');
                $('#userId').val(user.id);
                $('#username').val(user.username);
                $('#full_name').val(user.full_name);
                $('#role').val(user.role);
                $('#password').val('').prop('required', false);
                $('#passwordOptional').text('(kosongkan jika tidak diubah)');
                $('#formAction').val('update');
                $('#userModal').modal('show');
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

// Hapus user
function deleteUser(id, username) {
    if (confirm(`Yakin ingin menghapus user "${username}"?`)) {
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
                    loadUsers();
                } else {
                    showAlert('danger', response.message);
                }
            }
        });
    }
}

// Submit form (Create/Update)
$('#userForm').on('submit', function(e) {
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
                $('#userModal').modal('hide');
                loadUsers();
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
