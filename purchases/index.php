<?php
session_start();
require_once '../config.php';

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = "Manajemen Pembelian";
include '../header.php';

// Cek role user
$user_role = $_SESSION['role'] ?? 'Admin';
$is_owner = ($user_role === 'Owner');
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Data Pembelian (Purchase)</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#purchaseModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Tambah Purchase
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="searchInput" placeholder="Cari ID, supplier, atau kode sparepart...">
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="statusFilter">
                                <option value="">Semua Status</option>
                                <option value="Pending">Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Sudah Bayar">Sudah Bayar</option>
                                <option value="Belum Bayar">Belum Bayar</option>
                                <option value="Refund">Refund</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="alert alert-success mb-0 py-2">
                                <strong>Saldo Cash:</strong> <span id="balanceCash">Rp 0</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-primary mb-0 py-2">
                                <strong>Saldo Rekening:</strong> <span id="balanceBank">Rp 0</span>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tanggal</th>
                                    <th>Supplier</th>
                                    <th class="price-column">Total</th>
                                    <th>Status</th>
                                    <th>Pembayaran</th>
                                    <th>Dibuat Oleh</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="purchaseTableBody">
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

<!-- Modal Add Purchase -->
<div class="modal fade" id="purchaseModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Purchase</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="purchaseForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="supplier" class="form-label">Supplier *</label>
                            <input type="text" class="form-control" id="supplier" name="supplier" required placeholder="Ketik supplier baru atau pilih dari histori">
                            <select class="form-select mt-2" id="supplier_history_select">
                                <option value="">-- Pilih Supplier Lama --</option>
                            </select>
                            <small class="text-muted">Dropdown untuk supplier lama (bisa search), input atas untuk supplier baru.</small>
                        </div>
                        <div class="col-md-6">
                            <label for="tanggal" class="form-label">Tanggal *</label>
                            <input type="date" class="form-control" id="tanggal" name="tanggal" required>
                        </div>
                    </div>
                    
                    <hr>
                    <h6>Item Pembelian</h6>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered" id="itemsTable">
                            <thead>
                                <tr>
                                    <th width="50%">Sparepart</th>
                                    <th width="10%">Qty</th>
                                    <th width="13%" class="price-column">Harga Beli</th>
                                    <th width="12%">Diskon</th>
                                    <th width="12%" class="price-column">Subtotal</th>
                                    <th width="3%">
                                        <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="itemsTableBody">
                                <!-- Items akan ditambahkan via JS -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="text-end"><strong>Pajak:</strong></td>
                                    <td colspan="2">
                                        <input type="number" class="form-control form-control-sm" id="tax_amount" name="tax_amount" value="0" min="0" step="0.01" oninput="calculateGrandTotal()">
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end"><strong>TOTAL + PAJAK:</strong></td>
                                    <td colspan="2"><strong id="grandTotal">Rp 0</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Purchase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Purchase -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detail Purchase</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailContent">
                <!-- Detail akan diisi via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Purchase -->
<div class="modal fade" id="editPurchaseModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Purchase - Isi Supplier & Harga</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPurchaseForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_purchase_id" name="purchase_id">
                    <input type="hidden" name="action" value="update_purchase">
                    
                    <div class="mb-3">
                        <label for="edit_supplier" class="form-label">Supplier *</label>
                        <input type="text" class="form-control" id="edit_supplier" name="supplier" required>
                    </div>
                    
                    <hr>
                    <h6>Item Pembelian</h6>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Sparepart</th>
                                <th>Qty</th>
                                <th>Harga Beli</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="editItemsTableBody">
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end"><strong>TOTAL:</strong></td>
                                <td><strong id="editGrandTotal">Rp 0</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update Purchase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Pembayaran Purchase -->
<?php if ($is_owner): ?>
<div class="modal fade" id="payPurchaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bayar Purchase</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="payPurchaseForm">
                <div class="modal-body">
                    <input type="hidden" id="pay_purchase_id">
                    <div class="mb-3">
                        <label class="form-label">Tanggal Bayar</label>
                        <input type="date" class="form-control" id="pay_date" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sumber Dana</label>
                        <select class="form-select" id="pay_account_code" required>
                            <option value="">-- Pilih Sumber Dana --</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea class="form-control" id="pay_note" rows="2" placeholder="Opsional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Konfirmasi Bayar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../footer.php'; ?>

<script>
let spareparts = [];
let itemCounter = 0;
let financeAccounts = [];
const userRole = '<?php echo $_SESSION['role'] ?? 'Admin'; ?>';
const isOwner = (userRole === 'Owner');
const PURCHASE_DRAFT_KEY = 'draft_purchase_form_v1';
let isPurchaseDraftHydrating = false;

// Load data saat halaman dimuat
$(document).ready(function() {
    loadPurchases();
    loadSpareparts();
    loadFinanceAccounts();
    loadSupplierHistory();
    
    // Set tanggal hari ini sebagai default
    $('#tanggal').val(new Date().toISOString().split('T')[0]);
    
    // Search dengan delay
    let searchTimeout;
    $('#searchInput').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadPurchases();
        }, 500);
    });
    
    // Filter status
    $('#statusFilter').on('change', function() {
        loadPurchases();
    });

    $('#payPurchaseForm').on('submit', function(e) {
        e.preventDefault();
        const id = $('#pay_purchase_id').val();
        const accountCode = $('#pay_account_code').val();
        const payDate = $('#pay_date').val();
        const note = $('#pay_note').val();
        updatePayment(id, 'Sudah Bayar', accountCode, payDate, note);
    });

    // Autosave draft purchase form (including dynamic item rows)
    $('#purchaseForm').on('input change', '#supplier, #tanggal, #tax_amount, .sparepart-select, .item-qty, .item-price, .item-discount', function() {
        savePurchaseDraft();
    });
});

function loadFinanceAccounts() {
    $.ajax({
        url: 'backend.php?action=get_finance_accounts',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                financeAccounts = response.data;
                const cashBalance = getAccountBalanceByCode('cash');
                const bankBalance = getAccountBalanceByCode('bank');
                $('#balanceCash').text('Rp ' + formatNumber(cashBalance));
                $('#balanceBank').text('Rp ' + formatNumber(bankBalance));

                let options = '<option value="">-- Pilih Sumber Dana --</option>';
                response.data.forEach(function(acc) {
                    options += `<option value="${acc.code}">${acc.name} (Saldo: Rp ${formatNumber(acc.current_balance)})</option>`;
                });
                if ($('#pay_account_code').length) {
                    $('#pay_account_code').html(options);
                }
            }
        }
    });
}

function getAccountBalanceByCode(code) {
    const account = financeAccounts.find(acc => String(acc.code || '').toLowerCase() === String(code || '').toLowerCase());
    return account ? (parseFloat(account.current_balance) || 0) : 0;
}

function loadSupplierHistory() {
    $.ajax({
        url: 'backend.php?action=get_supplier_history',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let options = '<option value="">-- Pilih Supplier Lama --</option>';
                response.data.forEach(function(s) {
                    if (s.supplier) {
                        const safeSupplier = $('<div>').text(s.supplier).html();
                        options += `<option value="${safeSupplier}">${safeSupplier}</option>`;
                    }
                });

                if ($.fn.select2 && $('#supplier_history_select').hasClass('select2-hidden-accessible')) {
                    $('#supplier_history_select').select2('destroy');
                }

                $('#supplier_history_select').html(options);

                if ($.fn.select2) {
                    $('#supplier_history_select').select2({
                        theme: 'bootstrap-5',
                        placeholder: 'Cari supplier lama...',
                        allowClear: true,
                        width: '100%',
                        dropdownParent: $('#purchaseModal')
                    });
                }
            }
        }
    });
}

$('#supplier_history_select').on('change', function() {
    const val = ($(this).val() || '').trim();
    if (val !== '') {
        $('#supplier').val(val);
        savePurchaseDraft();
    }
});

// Load semua purchase
function loadPurchases() {
    let search = $('#searchInput').val();
    let status = $('#statusFilter').val();
    
    $.ajax({
        url: 'backend.php?action=read&search=' + encodeURIComponent(search) + '&status=' + encodeURIComponent(status),
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayPurchases(response.data);
            } else {
                showAlert('danger', 'Gagal memuat data purchase');
            }
        }
    });
}

// Load spareparts untuk dropdown
function loadSpareparts() {
    $.ajax({
        url: 'backend.php?action=get_spareparts',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                spareparts = response.data;
            }
        }
    });
}

// Tampilkan data purchase di tabel
function displayPurchases(purchases) {
    let html = '';
    
    if (purchases.length === 0) {
        html = '<tr><td colspan="8" class="text-center">Belum ada data purchase</td></tr>';
    } else {
        purchases.forEach(function(p) {
            let statusBadge = '';
            if (p.status === 'Pending') {
                statusBadge = '<span class="badge bg-warning">Pending</span>';
            } else if (p.status === 'Approved') {
                statusBadge = '<span class="badge bg-success">Approved</span>';
            } else {
                statusBadge = '<span class="badge bg-danger">Refund</span>';
            }
            
            let paymentBadge = p.is_paid === 'Sudah Bayar' 
                ? '<span class="badge bg-success">Sudah Bayar</span>' 
                : '<span class="badge bg-secondary">Belum Bayar</span>';
            
            html += `
                <tr>
                    <td>${p.id}</td>
                    <td>${formatDate(p.tanggal)}</td>
                    <td>${p.supplier}</td>
                    <td class="price-column">Rp ${formatNumber(p.total)}</td>
                    <td>${statusBadge}</td>
                    <td>${paymentBadge}</td>
                    <td>${p.created_by_name || '-'}</td>
                    <td>
                        <button class="btn btn-info btn-sm" onclick="viewDetail(${p.id})" title="Detail">
                            <i class="fas fa-eye"></i>
                        </button>
                        ${p.status === 'Pending' ? `
                        <button class="btn btn-warning btn-sm" onclick="editPurchase(${p.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        ` : ''}
                        ${!isOwner && p.status === 'Pending' && p.is_paid === 'Belum Bayar' ? `
                        <button class="btn btn-danger btn-sm" onclick="updateStatus(${p.id}, 'Refund')" title="Refund">
                            <i class="fas fa-undo"></i>
                        </button>
                        ` : ''}
                        ${isOwner && p.status !== 'Refund' && p.is_paid === 'Belum Bayar' ? `
                        <button class="btn btn-primary btn-sm" onclick="openPayModal(${p.id})" title="Tandai Sudah Bayar">
                            <i class="fas fa-money-bill"></i> Bayar
                        </button>
                        ` : ''}
                        ${isOwner && p.status !== 'Refund' ? `
                        <button class="btn btn-danger btn-sm" onclick="updateStatus(${p.id}, 'Refund')" title="Refund">
                            <i class="fas fa-undo"></i>
                        </button>
                        ` : ''}
                    </td>
                </tr>
            `;
        });
    }
    
    $('#purchaseTableBody').html(html);
}

// Buka modal untuk tambah purchase
function openAddModal() {
    isPurchaseDraftHydrating = true;
    try {
        $('#purchaseForm')[0].reset();
        $('#tanggal').val(new Date().toISOString().split('T')[0]);
        $('#tax_amount').val(0);
        $('#supplier').val('');
        $('#supplier_history_select').val('').trigger('change');
        $('#itemsTableBody').empty();
        itemCounter = 0;
        addItemRow();
        tryRestorePurchaseDraft();
    } finally {
        isPurchaseDraftHydrating = false;
    }
}

// Tambah baris item
function addItemRow() {
    
    let options = '<option value="">-- Pilih Sparepart --</option>';
    spareparts.forEach(function(sp) {
        const kode = String(sp.kode_sparepart || '-').trim();
        const nama = String(sp.nama || '').trim();
        const displayText = (kode && kode !== '-') ? `${kode} - ${nama}` : nama;
        const stock = String(sp.current_stock || 0);
        options += `<option value="${sp.id}" data-price="${sp.harga_beli_default}" data-satuan="${sp.satuan}">${displayText} (${sp.satuan}) - Stock: ${stock}</option>`;
    });
    const priceInput = `<input type="number" step="0.01" class="form-control item-price" min="0" value="0" required autocomplete="off" oninput="calculateSubtotal(${itemCounter})">`;
    
    let html = `
        <tr id="row${itemCounter}" data-counter="${itemCounter}">
            <td>
                <select class="form-select sparepart-select" id="sparepart_row${itemCounter}" required onchange="updatePrice(${itemCounter})">
                    ${options}
                </select>
            </td>
            <td>
                <input type="number" class="form-control item-qty" min="1" value="1" required autocomplete="off" oninput="calculateSubtotal(${itemCounter})">
            </td>
            <td class="price-column">
                ${priceInput}
            </td>
            <td>
                <input type="number" step="0.01" class="form-control item-discount" min="0" value="0" autocomplete="off" oninput="calculateSubtotal(${itemCounter})" placeholder="0">
            </td>
            <td class="price-column">
                <strong class="item-subtotal" data-value="0">Rp 0</strong>
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeItemRow(${itemCounter})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    $('#itemsTableBody').append(html);
    
    // Initialize Select2 untuk dropdown sparepart di row baru
    $(`#sparepart_row${itemCounter}`).select2({
        theme: 'bootstrap-5',
        placeholder: 'Cari / Pilih Sparepart...',
        allowClear: false,
        width: '100%',
        dropdownParent: $('#purchaseModal'),
        language: {
            noResults: function() { return 'Sparepart tidak ditemukan'; }
        }
    });
    
    itemCounter++;
    savePurchaseDraft();
}

// Update harga saat sparepart dipilih
function updatePrice(rowId) {
    let select = $(`#row${rowId} .sparepart-select`);
    let price = select.find(':selected').data('price') || 0;
    $(`#row${rowId} .item-price`).val(price);
    const discountInput = $(`#row${rowId} .item-discount`);
    if (String(discountInput.val()).trim() === '') {
        discountInput.val('0');
    }
    calculateSubtotal(rowId);
    savePurchaseDraft();
}

// Hitung subtotal per item
function calculateSubtotal(rowId) {
    let qty = parseFloat($(`#row${rowId} .item-qty`).val()) || 0;
    let price = parseFloat($(`#row${rowId} .item-price`).val()) || 0;
    let baseTotal = qty * price;
    const discountField = $(`#row${rowId} .item-discount`);
    let discount = parseFloat(discountField.val()) || 0;

    if (discount < 0) {
        discount = 0;
    }
    if (discount > baseTotal) {
        discount = baseTotal;
        discountField.val(discount.toFixed(2));
    }
    if (String(discountField.val()).trim() === '') {
        discountField.val('0');
    }

    let subtotal = baseTotal - discount;
    
    $(`#row${rowId} .item-subtotal`).text('Rp ' + formatNumber(subtotal)).attr('data-value', subtotal);
    calculateGrandTotal();
    savePurchaseDraft();
}

// Hitung grand total
function calculateGrandTotal() {
    let subtotalItems = 0;
    $('.item-subtotal').each(function() {
        subtotalItems += parseFloat($(this).attr('data-value')) || 0;
    });
    const taxAmount = parseFloat($('#tax_amount').val()) || 0;
    const total = subtotalItems + Math.max(0, taxAmount);
    $('#grandTotal').text('Rp ' + formatNumber(total));
}

// Hapus baris item
function removeItemRow(rowId) {
    const select2Element = $(`#sparepart_row${rowId}`);
    if (select2Element.data('select2')) {
        select2Element.select2('destroy');
    }
    $(`#row${rowId}`).remove();
    calculateGrandTotal();
    savePurchaseDraft();
}

// Submit form purchase
$('#purchaseForm').on('submit', function(e) {
    e.preventDefault();
    
    // Kumpulkan data items
    let items = [];
    
    $('#itemsTableBody tr').each(function() {
        let row = $(this);
        let sparepartId = row.find('.sparepart-select').val();
        let qty = row.find('.item-qty').val();
        let price = row.find('.item-price').val();
        let discount = row.find('.item-discount').val();
        
        if (sparepartId && qty) {
            items.push({
                sparepart_id: parseInt(sparepartId),
                qty: parseInt(qty),
                harga_beli: parseFloat(price || 0),
                discount_amount: parseFloat(discount || 0)
            });
        }
    });
    
    if (items.length === 0) {
        showAlert('warning', 'Tambahkan minimal 1 item pembelian');
        return;
    }
    
    let formData = new FormData(this);
    formData.append('items', JSON.stringify(items));
    formData.set('tax_amount', $('#tax_amount').val() || '0');
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                clearPurchaseDraft();
                showAlert('success', response.message);
                $('#purchaseModal').modal('hide');
                loadPurchases();
            } else {
                showAlert('danger', response.message);
            }
        },
        error: function(xhr, status, error) {
            showAlert('danger', 'Error: ' + error);
        }
    });
});

// View detail purchase
function viewDetail(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let p = response.data;
                let statusBadge = p.status === 'Approved' ? 'success' : (p.status === 'Refund' ? 'danger' : 'warning');
                
                let itemsHtml = '';
                let subtotalItems = 0;
                let taxAmount = parseFloat(p.tax_amount || 0);
                p.items.forEach(function(item) {
                    if (isOwner) {
                        itemsHtml += `
                            <tr>
                                <td>[${item.kode_sparepart}] ${item.sparepart_name}</td>
                                <td>${item.qty} ${item.satuan}</td>
                                <td>Rp ${formatNumber(item.harga_beli)}</td>
                                <td>Rp ${formatNumber(item.discount_amount || 0)}</td>
                                <td>Rp ${formatNumber(item.subtotal)}</td>
                            </tr>
                        `;
                    } else {
                        itemsHtml += `
                            <tr>
                                <td>[${item.kode_sparepart}] ${item.sparepart_name}</td>
                                <td>${item.qty} ${item.satuan}</td>
                            </tr>
                        `;
                    }
                    subtotalItems += parseFloat(item.subtotal);
                });
                
                let tableHeader = isOwner 
                    ? `<tr>
                        <th>Sparepart</th>
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Diskon</th>
                        <th>Subtotal</th>
                    </tr>`
                    : `<tr>
                        <th>Sparepart</th>
                        <th>Qty</th>
                    </tr>`;
                
                let tableFooter = isOwner 
                    ? `<tfoot>
                        <tr>
                            <td colspan="4" class="text-end"><strong>Subtotal Item:</strong></td>
                            <td><strong>Rp ${formatNumber(subtotalItems)}</strong></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end"><strong>Pajak:</strong></td>
                            <td><strong>Rp ${formatNumber(taxAmount)}</strong></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end"><strong>TOTAL + PAJAK:</strong></td>
                            <td><strong>Rp ${formatNumber(subtotalItems + taxAmount)}</strong></td>
                        </tr>
                    </tfoot>`
                    : '';
                
                let html = `
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>ID Purchase:</strong> ${p.id}<br>
                            <strong>Supplier:</strong> ${p.supplier}<br>
                            <strong>Tanggal:</strong> ${formatDate(p.tanggal)}
                        </div>
                        <div class="col-md-6">
                            <strong>Status:</strong> <span class="badge bg-${statusBadge}">${p.status}</span><br>
                            <strong>Pembayaran:</strong> ${p.is_paid}<br>
                            <strong>Dibuat oleh:</strong> ${p.created_by_name || '-'}
                        </div>
                    </div>
                    <hr>
                    <h6>Item Pembelian:</h6>
                    <table class="table table-bordered">
                        <thead>
                            ${tableHeader}
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                        ${tableFooter}
                    </table>
                `;
                
                $('#detailContent').html(html);
                $('#detailModal').modal('show');
            }
        }
    });
}

// Edit Purchase (Owner only) - Isi supplier dan harga
function editPurchase(id) {
    $.ajax({
        url: 'backend.php?action=read_one&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let p = response.data;
                $('#edit_purchase_id').val(p.id);
                $('#edit_supplier').val(p.supplier === 'Pending - Akan diisi Owner' ? '' : p.supplier);
                
                let itemsHtml = '';
                let total = 0;
                p.items.forEach(function(item, index) {
                    let subtotal = item.qty * item.harga_beli;
                    total += subtotal;
                    itemsHtml += `
                        <tr>
                            <td>
                                ${item.sparepart_name}
                                <input type="hidden" name="items[${index}][sparepart_id]" value="${item.sparepart_id}">
                            </td>
                            <td>
                                ${item.qty}
                                <input type="hidden" name="items[${index}][qty]" value="${item.qty}">
                            </td>
                            <td>
                                <input type="number" step="0.01" class="form-control edit-item-price" name="items[${index}][harga_beli]" value="${item.harga_beli}" min="0" required onchange="calculateEditSubtotal()">
                            </td>
                            <td>
                                <strong class="edit-item-subtotal">Rp ${formatNumber(subtotal)}</strong>
                            </td>
                        </tr>
                    `;
                });
                
                $('#editItemsTableBody').html(itemsHtml);
                $('#editGrandTotal').text('Rp ' + formatNumber(total));
                $('#editPurchaseModal').modal('show');
            }
        }
    });
}

// Calculate subtotal di edit modal
function calculateEditSubtotal() {
    let total = 0;
    $('.edit-item-price').each(function(index) {
        let price = parseFloat($(this).val()) || 0;
        let qty = parseInt($('input[name="items[' + index + '][qty]"]').val()) || 0;
        let subtotal = qty * price;
        total += subtotal;
        $('.edit-item-subtotal').eq(index).text('Rp ' + formatNumber(subtotal));
    });
    $('#editGrandTotal').text('Rp ' + formatNumber(total));
}

// Submit edit purchase form
$('#editPurchaseForm').on('submit', function(e) {
    e.preventDefault();
    
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showAlert('success', response.message);
                $('#editPurchaseModal').modal('hide');
                loadPurchases();
            } else {
                showAlert('danger', response.message);
            }
        }
    });
});

// Update status (Refund)
function updateStatus(id, status) {
    const confirmMsg = 'Refund purchase ini? Stock sparepart akan dikurangi dan saldo akun akan bertambah.';
    
    if (confirm(confirmMsg)) {
        $.ajax({
            url: 'backend.php',
            type: 'POST',
            data: {
                action: 'update_status',
                id: id,
                status: status
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.message);
                    loadPurchases();
                } else {
                    showAlert('danger', response.message);
                }
            }
        });
    }
}

function openPayModal(purchaseId) {
    $('#pay_purchase_id').val(purchaseId);
    $('#pay_date').val(new Date().toISOString().split('T')[0]);
    $('#pay_note').val('');
    $('#pay_account_code').val('');
    $('#payPurchaseModal').modal('show');
}

// Update payment status
function updatePayment(id, isPaid, accountCode = '', payDate = '', note = '') {
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        data: {
            action: 'update_payment',
            id: id,
            is_paid: isPaid,
            payment_account_code: accountCode,
            payment_date: payDate,
            payment_note: note
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#payPurchaseModal').modal('hide');
                showAlert('success', response.message);
                if (isOwner) {
                    loadFinanceAccounts();
                }
                loadPurchases();
            } else {
                showAlert('danger', response.message);
            }
        }
    });
}

function buildPurchaseDraftPayload() {
    let items = [];
    $('#itemsTableBody tr').each(function() {
        const row = $(this);
        items.push({
            sparepart_id: row.find('.sparepart-select').val() || '',
            qty: row.find('.item-qty').val() || '1',
            harga_beli: row.find('.item-price').val() || '0',
            discount_amount: row.find('.item-discount').val() || '0'
        });
    });

    return {
        supplier: $('#supplier').val() || '',
        tanggal: $('#tanggal').val() || '',
        tax_amount: $('#tax_amount').val() || '0',
        items: items,
        saved_at: Date.now()
    };
}

function savePurchaseDraft() {
    if (isPurchaseDraftHydrating) {
        return;
    }

    try {
        const payload = buildPurchaseDraftPayload();
        localStorage.setItem(PURCHASE_DRAFT_KEY, JSON.stringify(payload));
    } catch (e) {
        console.warn('Gagal menyimpan draft purchase:', e);
    }
}

function loadPurchaseDraft() {
    try {
        const raw = localStorage.getItem(PURCHASE_DRAFT_KEY);
        if (!raw) {
            return null;
        }
        return JSON.parse(raw);
    } catch (e) {
        console.warn('Gagal membaca draft purchase:', e);
        return null;
    }
}

function clearPurchaseDraft() {
    localStorage.removeItem(PURCHASE_DRAFT_KEY);
}

function tryRestorePurchaseDraft() {
    const draft = loadPurchaseDraft();
    if (!draft || typeof draft !== 'object') {
        return;
    }

    if (draft.supplier) {
        $('#supplier').val(draft.supplier);
    }
    if (draft.tanggal) {
        $('#tanggal').val(draft.tanggal);
    }
    $('#tax_amount').val(draft.tax_amount || '0');

    const items = Array.isArray(draft.items) ? draft.items : [];
    if (items.length > 0) {
        $('#itemsTableBody').empty();
        itemCounter = 0;

        items.forEach(function(item) {
            addItemRow();
            const row = $('#itemsTableBody tr').last();
            const rowCounter = row.data('counter');

            const sparepartSelect = row.find('.sparepart-select');
            sparepartSelect.val(item.sparepart_id || '');
            
            // Trigger Select2 to refresh displayed value
            if (sparepartSelect.data('select2')) {
                sparepartSelect.trigger('change');
            }
            
            row.find('.item-qty').val(item.qty || '1');
            row.find('.item-price').val(item.harga_beli || '0');
            row.find('.item-discount').val(item.discount_amount || '0');

            if (rowCounter !== undefined) {
                calculateSubtotal(rowCounter);
            }
        });
    }

    calculateGrandTotal();
    showAlert('info', 'Draft purchase terakhir dipulihkan.');
}

// Helper functions
function formatNumber(num) {
    return parseFloat(num).toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 2});
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
        $('.alert').fadeOut(function() {
            $(this).remove();
        });
    }, 3000);
}
</script>

<style>
/* Word wrapping untuk kolom Sparepart di modal Tambah Purchase */
#itemsTable {
    table-layout: fixed;
}

#itemsTable td:first-child {
    white-space: normal !important;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
    max-width: 400px;
    padding: 8px !important;
    vertical-align: middle;
    overflow: visible !important;
}

#itemsTable tbody tr {
    height: auto;
}

#itemsTable .sparepart-select {
    min-height: auto;
}

/* Select2 dropdown styling untuk text wrapping */
.select2-container--bootstrap-5 .select2-results__option {
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
    padding: 6px 8px;
}

/* Select2 rendered text (selected value) */
.select2-container--bootstrap-5 .select2-selection__rendered {
    padding: 6px 6px !important;
    white-space: normal !important;
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
    word-break: break-word !important;
    display: block !important;
    height: auto !important;
    min-height: 38px;
    line-height: 1.4;
    overflow: visible !important;
}

.select2-container--bootstrap-5.select2-container--open .select2-selection__rendered {
    white-space: normal !important;
}

.select2-selection__arrow {
    top: 0 !important;
}
</style>
