<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_role = $_SESSION['role'] ?? 'Admin';
if ($user_role !== 'Owner' && $user_role !== 'Admin') {
    die('Akses ditolak');
}

$page_title = 'Payment & Cashflow';
include '../header.php';
?>

<style>
.approval-scroll {
    max-height: 280px;
    overflow-y: auto;
}

.summary-card-clickable {
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.summary-card-clickable:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.12);
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="fas fa-wallet"></i> Payment & Cashflow</h5>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm summary-card-clickable" id="cashSummaryCard" onclick="openAccountExpenseModal('cash', 'Saldo Cash')">
                <div class="card-body">
                    <small>Saldo Cash</small>
                    <h5 id="sumCash">Rp 0</h5>
                    <small class="text-muted">Klik untuk lihat detail pengeluaran</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm summary-card-clickable" id="bankSummaryCard" onclick="openAccountExpenseModal('bank', 'Saldo Rekening')">
                <div class="card-body">
                    <small>Saldo Rekening</small>
                    <h5 id="sumBank">Rp 0</h5>
                    <small class="text-muted">Klik untuk lihat detail pengeluaran</small>
                </div>
            </div>
        </div>
        <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small>Total Saldo</small><h5 id="sumTotal">Rp 0</h5></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small>Cashflow Bulan Ini</small><h5 id="sumMonthFlow">Rp 0</h5></div></div></div>
    </div>

    <?php if ($user_role === 'Owner'): ?>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Approval Payment (Pending)</strong>
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="loadPendingApprovals()">Refresh</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive approval-scroll">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Akun</th>
                                    <th>Arah</th>
                                    <th>Kategori</th>
                                    <th>Ref</th>
                                    <th>Dibuat Oleh</th>
                                    <th>Catatan</th>
                                    <th class="text-end">Nominal</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="approvalTableBody"><tr><td colspan="9" class="text-center text-muted">Loading...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Histori Mutasi</strong></div>
                <div class="card-body">
                    <div class="mb-2 d-flex gap-2 flex-wrap">
                        <button class="btn btn-sm btn-outline-secondary quick-range-btn" data-range="today" type="button" onclick="applyQuickRange('today')">Hari Ini</button>
                        <button class="btn btn-sm btn-outline-secondary quick-range-btn" data-range="week" type="button" onclick="applyQuickRange('week')">Minggu Ini</button>
                        <button class="btn btn-sm btn-outline-secondary quick-range-btn" data-range="month" type="button" onclick="applyQuickRange('month')">Bulan Ini</button>
                        <button class="btn btn-sm btn-outline-secondary quick-range-btn" data-range="all" type="button" onclick="applyQuickRange('all')">Semua</button>
                        <div class="btn-group ms-auto">
                            <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportTransactionsPDF()"><i class="fas fa-file-pdf me-2 text-danger"></i>Export PDF</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportTransactionsExcel()"><i class="fas fa-file-excel me-2 text-success"></i>Export Excel</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="f_from"></div>
                        <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="f_to"></div>
                        <div class="col-md-2"><select class="form-select form-select-sm" id="f_account"><option value="">Semua Akun</option></select></div>
                        <div class="col-md-2"><select class="form-select form-select-sm" id="f_direction"><option value="">Semua Arah</option><option value="in">Masuk</option><option value="out">Keluar</option><option value="transfer_in">Transfer Masuk</option><option value="transfer_out">Transfer Keluar</option></select></div>
                        <div class="col-md-2"><select class="form-select form-select-sm" id="f_category"><option value="">Semua Kategori</option><option value="IN-CUST-PAYMENT">IN-CUST-PAYMENT</option><option value="IN-CUST-SPAREPART">IN-CUST-SPAREPART</option><option value="OUT-PO">OUT-PO</option><option value="TRF-IN">TRF-IN</option><option value="TRF-OUT">TRF-OUT</option></select></div>
                        <div class="col-md-2"><input type="text" class="form-control form-control-sm" id="f_keyword" placeholder="Keyword"></div>
                        <div class="col-12 col-md-2 d-grid"><button class="btn btn-sm btn-outline-primary" onclick="loadTransactions(1)" type="button">Filter</button></div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Akun</th>
                                    <th>Arah</th>
                                    <th>Status</th>
                                    <th>Kategori</th>
                                    <th>Ref</th>
                                    <th>Dibuat Oleh</th>
                                    <th>Catatan</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody id="txTableBody"><tr><td colspan="9" class="text-center">Loading...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                        <small class="text-muted" id="txPaginationInfo">-</small>
                        <nav aria-label="Pagination Histori Mutasi">
                            <ul class="pagination pagination-sm mb-0" id="txPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Riwayat Diskon Penjualan (Tidak Memengaruhi Saldo)</strong>
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="loadDiscountMemoHistory()">Refresh</button>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        <i class="fas fa-info-circle"></i>
                        Catatan diskon penjualan di bawah ini hanya untuk histori &amp; audit &mdash; nominalnya
                        sudah tercermin pada total invoice yang lebih kecil, sehingga <strong>tidak mengurangi</strong>
                        saldo Kas maupun Rekening manapun.
                    </p>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3"><input type="date" class="form-control form-control-sm" id="dm_from"></div>
                        <div class="col-md-3"><input type="date" class="form-control form-control-sm" id="dm_to"></div>
                        <div class="col-md-4"><input type="text" class="form-control form-control-sm" id="dm_keyword" placeholder="Cari catatan / referensi / kategori"></div>
                        <div class="col-md-2 d-grid"><button class="btn btn-sm btn-outline-primary" type="button" onclick="loadDiscountMemoHistory()">Filter</button></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Kategori</th>
                                    <th>Referensi</th>
                                    <th>Dibuat Oleh</th>
                                    <th>Catatan</th>
                                    <th class="text-end">Nominal</th>
                                </tr>
                            </thead>
                            <tbody id="discountMemoTableBody"><tr><td colspan="6" class="text-center text-muted">Loading...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-2">
                        <small class="text-muted">Total tercatat: <strong id="discountMemoTotal">Rp 0</strong> (memo, bukan pengurang saldo)</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Input Pengeluaran Operasional</strong></div>
                <div class="card-body">
                    <form id="operationalForm">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" class="form-control" id="op_tanggal" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nama Biaya</label>
                                <input type="text" class="form-control" id="op_name" placeholder="PDAM / Listrik / ATK" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Kategori Pengeluaran</label>
                                <select class="form-select" id="op_category" required>
                                    <option value="">-- Pilih Kategori --</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Nominal</label>
                                <input type="number" class="form-control" id="op_amount" min="1" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Sumber Dana</label>
                                <select class="form-select" id="op_account" required>
                                    <option value="">-- Pilih --</option>
                                </select>
                            </div>
                            <div class="col-md-9">
                                <label class="form-label">Catatan</label>
                                <textarea class="form-control" id="op_note" rows="2"></textarea>
                            </div>

                            <div class="col-md-4" id="oplBilledSection" style="display:none;">
                                <label class="form-label">Nominal Ditagih ke Customer <small class="text-muted">(untuk hitung Laba OPL)</small></label>
                                <input type="number" class="form-control" id="op_billed_amount" min="1" placeholder="Harga yang ditagih ke customer utk pekerjaan ini">
                            </div>

                            <div class="col-md-3 d-grid align-self-end">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Simpan Pengeluaran</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Input Pemasukan</strong></div>
                <div class="card-body">
                    <form id="incomeForm">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" class="form-control" id="ic_tanggal" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nama Pemasukan</label>
                                <input type="text" class="form-control" id="ic_name" placeholder="Contoh: Penjualan Lain-lain" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Nominal</label>
                                <input type="number" class="form-control" id="ic_amount" min="1" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Masuk ke Akun</label>
                                <select class="form-select" id="ic_account" required>
                                    <option value="">-- Pilih --</option>
                                </select>
                            </div>
                            <div class="col-md-9">
                                <label class="form-label">Catatan</label>
                                <textarea class="form-control" id="ic_note" rows="2"></textarea>
                            </div>
                            <div class="col-md-3 d-grid align-self-end">
                                <button class="btn btn-success" type="submit"><i class="fas fa-plus-circle"></i> Simpan Pemasukan</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Transfer Antar Akun</strong></div>
                <div class="card-body">
                    <form id="transferForm">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" class="form-control" id="tf_tanggal" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Dari Akun</label>
                                <select class="form-select" id="tf_from" required>
                                    <option value="">-- Pilih --</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Ke Akun</label>
                                <select class="form-select" id="tf_to" required>
                                    <option value="">-- Pilih --</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nominal</label>
                                <input type="number" class="form-control" id="tf_amount" min="1" required>
                            </div>
                            <div class="col-md-9">
                                <label class="form-label">Catatan</label>
                                <textarea class="form-control" id="tf_note" rows="2"></textarea>
                            </div>
                            <div class="col-md-3 d-grid align-self-end">
                                <button class="btn btn-outline-primary" type="submit"><i class="fas fa-exchange-alt"></i> Simpan Transfer</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Master Kategori Pengeluaran</strong></div>
                <div class="card-body">
                    <form id="catForm" class="row g-2 mb-2">
                        <input type="hidden" id="cat_id">
                        <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="cat_code" placeholder="Kode (contoh EXP-LAIN)" required></div>
                        <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="cat_name" placeholder="Nama kategori" required></div>
                        <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="cat_desc" placeholder="Deskripsi (opsional)"></div>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" id="cat_status">
                                <label class="form-check-label" for="cat_status">Biaya Tetap</label>
                            </div>
                            <small class="text-muted">ON=1, OFF=0</small>
                        </div>
                        <div class="col-md-1 d-grid"><button class="btn btn-sm btn-primary" type="submit">Simpan</button></div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light"><tr><th>KODE</th><th>NAMA</th><th>TIPE BEBAN</th><th>AKSI</th></tr></thead>
                            <tbody id="catTableBody"><tr><td colspan="4" class="text-center text-muted">Loading...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="accountExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="accountExpenseTitle">Detail Pengeluaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-2">
                        <input type="date" class="form-control form-control-sm" id="ex_from" placeholder="Dari tanggal">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control form-control-sm" id="ex_to" placeholder="Sampai tanggal">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="ex_direction">
                            <option value="">Semua (Masuk/Keluar)</option>
                            <option value="in">Masuk</option>
                            <option value="out">Keluar</option>
                            <option value="transfer_in">Transfer Masuk</option>
                            <option value="transfer_out">Transfer Keluar</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="ex_status">
                            <option value="">Semua Status</option>
                            <option value="approved">approved</option>
                            <option value="pending">pending</option>
                            <option value="rejected">rejected</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control form-control-sm" id="ex_keyword" placeholder="Cari catatan/ref...">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadAccountExpenses()">Filter</button>
                    </div>
                </div>
                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportAccountExpensesExcel()">
                        <i class="fas fa-file-excel me-1"></i>Export Excel
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>Arah</th>
                                <th>Status</th>
                                <th>Kategori</th>
                                <th>Ref</th>
                                <th>Dibuat Oleh</th>
                                <th>Catatan</th>
                                <th class="text-end">Nominal</th>
                            </tr>
                        </thead>
                        <tbody id="accountExpenseBody"><tr><td colspan="7" class="text-center text-muted">Loading...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="oplDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-hand-holding-usd text-primary"></i> Detail Laba OPL</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="oplDetailBody"><p class="text-muted text-center mb-0">Loading...</p></div>
            </div>
        </div>
    </div>
</div>

<script>
function fmt(n){ return 'Rp ' + parseFloat(n || 0).toLocaleString('id-ID'); }
const isOwner = '<?php echo $user_role; ?>' === 'Owner';
let expenseCategoryMap = {};

function openOplDetailModal(expenseId){
    $('#oplDetailBody').html('<p class="text-muted text-center mb-0">Loading...</p>');
    new bootstrap.Modal(document.getElementById('oplDetailModal')).show();

    $.getJSON('backend.php', { action: 'get_opl_detail', expense_id: expenseId }, function(res){
        if (!res.success) {
            $('#oplDetailBody').html(`<p class="text-danger text-center mb-0">${res.message}</p>`);
            return;
        }

        const exp = res.expense;
        const billed = exp.billed_amount !== null ? parseFloat(exp.billed_amount) : null;
        const laba = res.laba_opl !== null ? parseFloat(res.laba_opl) : null;
        const labaColor = laba === null ? '' : (laba >= 0 ? 'text-success' : 'text-danger');

        $('#oplDetailBody').html(`
            <table class="table table-sm mb-0">
                <tr><td>Tanggal</td><td class="text-end">${exp.tanggal}</td></tr>
                <tr><td>Nama Biaya</td><td class="text-end">${exp.expense_name}</td></tr>
                <tr><td>Nominal Dibayar ke Pihak Ketiga</td><td class="text-end">${fmt(exp.amount)}</td></tr>
                <tr><td>Nominal Ditagih ke Customer</td><td class="text-end">${billed !== null ? fmt(billed) : '-'}</td></tr>
                <tr class="fw-bold ${labaColor}"><td>Laba OPL</td><td class="text-end">${laba !== null ? fmt(laba) : '-'}</td></tr>
                ${exp.note ? `<tr><td colspan="2" class="text-muted">Catatan: ${exp.note}</td></tr>` : ''}
            </table>
        `);
    });
}

function isOplCategorySelected(){
    // Kategori bisa "OPL", "OPL JASA", "OPL PART", dst - cek substring pada code.
    return /OPL/i.test($('#op_category').val() || '');
}

function toggleOplBilledSection(){
    if (isOplCategorySelected()) {
        $('#oplBilledSection').show();
    } else {
        $('#oplBilledSection').hide();
        $('#op_billed_amount').val('');
    }
}
let currentTxPage = 1;
const TX_PER_PAGE = 20;
let currentExpenseAccountCode = '';
let currentExpenseAccountLabel = '';

$(document).ready(function(){
    const today = new Date().toISOString().split('T')[0];
    $('#op_tanggal').val(today);
    $('#tf_tanggal').val(today);
    $('#ic_tanggal').val(today);
    applyQuickRange('month', false);
    loadAccounts();
    loadExpenseCategories();
    loadSummary();
    loadTransactions();
    loadDiscountMemoHistory();
    if (isOwner) {
        loadPendingApprovals();
    }

    $('#op_category').on('change', toggleOplBilledSection);

    $('#operationalForm').on('submit', function(e){
        e.preventDefault();

        if (isOplCategorySelected()) {
            const billed = parseFloat($('#op_billed_amount').val()) || 0;
            if (billed <= 0) {
                showAlert('warning', 'Kategori OPL wajib diisi nominal yang ditagih ke customer');
                return;
            }
        }

        $.ajax({
            url: 'backend.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'create_operational_expense',
                tanggal: $('#op_tanggal').val(),
                expense_name: $('#op_name').val(),
                category_code: $('#op_category').val(),
                amount: $('#op_amount').val(),
                account_code: $('#op_account').val(),
                note: $('#op_note').val(),
                billed_amount: $('#op_billed_amount').val()
            },
            success: function(res){
                if (res.success){
                    showAlert('success', res.message);
                    $('#op_name').val('');
                    $('#op_category').val('');
                    $('#op_amount').val('');
                    $('#op_note').val('');
                    $('#op_billed_amount').val('');
                    $('#oplBilledSection').hide();
                    loadSummary();
                    loadAccounts();
                    loadTransactions();
                    if (isOwner) loadPendingApprovals();
                } else {
                    showAlert('danger', res.message);
                }
            }
        });
    });

    $('#incomeForm').on('submit', function(e){
        e.preventDefault();
        $.ajax({
            url: 'backend.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'create_income',
                tanggal: $('#ic_tanggal').val(),
                income_name: $('#ic_name').val(),
                amount: $('#ic_amount').val(),
                account_code: $('#ic_account').val(),
                note: $('#ic_note').val()
            },
            success: function(res){
                if (res.success){
                    showAlert('success', res.message);
                    $('#ic_name').val('');
                    $('#ic_amount').val('');
                    $('#ic_note').val('');
                    loadSummary();
                    loadAccounts();
                    loadTransactions();
                } else {
                    showAlert('danger', res.message);
                }
            }
        });
    });

    $('#transferForm').on('submit', function(e){
        e.preventDefault();
        $.ajax({
            url: 'backend.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'create_transfer',
                tanggal: $('#tf_tanggal').val(),
                from_account_code: $('#tf_from').val(),
                to_account_code: $('#tf_to').val(),
                amount: $('#tf_amount').val(),
                note: $('#tf_note').val()
            },
            success: function(res){
                if (res.success){
                    showAlert('success', res.message);
                    $('#tf_amount').val('');
                    $('#tf_note').val('');
                    loadSummary();
                    loadAccounts();
                    loadTransactions();
                    if (isOwner) loadPendingApprovals();
                } else {
                    showAlert('danger', res.message);
                }
            }
        });
    });

    $('#catForm').on('submit', function(e){
        e.preventDefault();
        const id = $('#cat_id').val();
        $.ajax({
            url: 'backend.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: id ? 'update_expense_category' : 'create_expense_category',
                id: id,
                code: $('#cat_code').val(),
                name: $('#cat_name').val(),
                description: $('#cat_desc').val(),
                status: $('#cat_status').is(':checked') ? 1 : 0
            },
            success: function(res){
                if (res.success) {
                    showAlert('success', res.message);
                    resetCategoryForm();
                    loadExpenseCategories();
                } else {
                    showAlert('danger', res.message);
                }
            }
        });
    });
});

function applyQuickRange(type, triggerLoad = true) {
    $('.quick-range-btn')
        .removeClass('btn-secondary')
        .addClass('btn-outline-secondary');
    $(`.quick-range-btn[data-range="${type}"]`)
        .removeClass('btn-outline-secondary')
        .addClass('btn-secondary');

    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    const today = `${yyyy}-${mm}-${dd}`;

    if (type === 'today') {
        $('#f_from').val(today);
        $('#f_to').val(today);
    } else if (type === 'week') {
        const day = now.getDay();
        const diffToMonday = day === 0 ? -6 : 1 - day;
        const monday = new Date(now);
        monday.setDate(now.getDate() + diffToMonday);
        const mon = `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`;
        $('#f_from').val(mon);
        $('#f_to').val(today);
    } else if (type === 'month') {
        $('#f_from').val(`${yyyy}-${mm}-01`);
        $('#f_to').val(today);
    } else {
        $('#f_from').val('');
        $('#f_to').val('');
    }

    if (triggerLoad) {
        loadTransactions(1);
    }
}

function loadAccounts(){
    $.getJSON('backend.php?action=get_accounts', function(res){
        if (!res.success) return;
        let op = '<option value="">-- Pilih --</option>';
        let tfFrom = '<option value="">-- Pilih --</option>';
        let tfTo = '<option value="">-- Pilih --</option>';
        let filter = '<option value="">Semua Akun</option>';
        res.data.forEach(function(a){
            const label = a.name + ' (Saldo: ' + fmt(a.current_balance) + ')';
            op += `<option value="${a.code}">${label}</option>`;
            tfFrom += `<option value="${a.code}">${label}</option>`;
            tfTo += `<option value="${a.code}">${label}</option>`;
            filter += `<option value="${a.code}">${a.name}</option>`;
        });
        $('#op_account').html(op);
        $('#ic_account').html(op);
        $('#tf_from').html(tfFrom);
        $('#tf_to').html(tfTo);
        $('#f_account').html(filter);

        // Auto-pick default account to avoid empty account on submit.
        if (res.data.length > 0) {
            const firstCode = res.data[0].code;
            const secondCode = res.data.length > 1 ? res.data[1].code : firstCode;
            if (!$('#op_account').val()) {
                $('#op_account').val(firstCode);
            }
            if (!$('#tf_from').val()) {
                $('#tf_from').val(firstCode);
            }
            if (!$('#tf_to').val()) {
                $('#tf_to').val(secondCode);
            }
        }
    });
}

function loadExpenseCategories(){
    $.getJSON('backend.php?action=get_expense_categories', function(res){
        if (!res.success) return;
        let opCat = '<option value="">-- Pilih Kategori --</option>';
        let catHtml = '';
        expenseCategoryMap = {};
        res.data.forEach(function(c){
            expenseCategoryMap[c.id] = c;
            opCat += `<option value="${c.code}">${c.code}</option>`;
            const isFixed = parseInt(c.status, 10) === 1;
            catHtml += `<tr>
                <td>${c.code}</td>
                <td>${c.name}</td>
                <td>
                    <span class="badge ${isFixed ? 'bg-primary' : 'bg-secondary'}">${isFixed ? 'Beban Tetap' : 'Beban Tidak Tetap'}</span>
                        <div class="form-check form-switch mt-1 mb-0">
                            <input class="form-check-input" type="checkbox" ${isFixed ? 'checked' : ''} onchange="toggleCategoryStatus(${c.id}, this.checked)">
                        </div>
                </td>
                <td>
                        <button type="button" class="btn btn-warning btn-sm me-1" onclick="editCategory(${c.id})"><i class="fas fa-edit"></i></button>
                        <button type="button" class="btn btn-danger btn-sm" onclick='deleteCategory(${c.id})'><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        });
        if (!catHtml) catHtml = '<tr><td colspan="4" class="text-center text-muted">Belum ada kategori</td></tr>';
        $('#op_category').html(opCat);
        $('#catTableBody').html(catHtml);

        // Rebuild filter categories (dynamic + static)
        let fCat = '<option value="">Semua Kategori</option>' +
                   '<option value="IN-CUST-PAYMENT">IN-CUST-PAYMENT</option>' +
                   '<option value="IN-CUST-SPAREPART">IN-CUST-SPAREPART</option>' +
                   '<option value="OUT-PO">OUT-PO</option>' +
                   '<option value="TRF-IN">TRF-IN</option>' +
                   '<option value="TRF-OUT">TRF-OUT</option>';
        res.data.forEach(function(c){
            fCat += `<option value="${c.code}">${c.code}</option>`;
        });
        $('#f_category').html(fCat);
    });
}

function editCategory(categoryId){
    const c = expenseCategoryMap[categoryId];
    if (!c) return;
    $('#cat_id').val(c.id);
    $('#cat_code').val(c.code);
    $('#cat_name').val(c.name);
    $('#cat_desc').val(c.description || '');
    $('#cat_status').prop('checked', parseInt(c.status, 10) === 1);
}

function resetCategoryForm(){
    $('#cat_id').val('');
    $('#cat_code').val('');
    $('#cat_name').val('');
    $('#cat_desc').val('');
    $('#cat_status').prop('checked', false);
}

function toggleCategoryStatus(id, isChecked){
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'toggle_expense_category_status',
            id: id,
            status: isChecked ? 1 : 0
        },
        success: function(res){
            if (res.success){
                loadExpenseCategories();
            } else {
                showAlert('danger', res.message || 'Gagal update status kategori');
                loadExpenseCategories();
            }
        },
        error: function(){
            showAlert('danger', 'Gagal update status kategori');
            loadExpenseCategories();
        }
    });
}

function deleteCategory(id){
    if (!confirm('Hapus kategori ini?')) return;
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'delete_expense_category', id: id },
        success: function(res){
            if (res.success){
                showAlert('success', res.message);
                resetCategoryForm();
                loadExpenseCategories();
            } else {
                showAlert('danger', res.message);
            }
        }
    });
}

function getStatusBadgeHtml(status) {
    const safeStatus = (status || 'approved').toString().toLowerCase();
    const statusBadgeMap = {
        approved: 'success',
        pending: 'warning',
        rejected: 'danger'
    };
    return `<span class="badge bg-${statusBadgeMap[safeStatus] || 'secondary'}">${safeStatus}</span>`;
}

function openAccountExpenseModal(accountCode, accountLabel) {
    currentExpenseAccountCode = accountCode;
    currentExpenseAccountLabel = accountLabel;
    $('#accountExpenseTitle').text('Detail Pengeluaran - ' + accountLabel);
    $('#ex_from').val('');
    $('#ex_to').val('');
    $('#ex_direction').val('');
    $('#ex_status').val('');
    $('#ex_keyword').val('');
    $('#accountExpenseBody').html('<tr><td colspan="8" class="text-center text-muted">Loading...</td></tr>');

    if (window.bootstrap && bootstrap.Modal) {
        const modalEl = document.getElementById('accountExpenseModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    } else {
        $('#accountExpenseModal').modal('show');
    }

    loadAccountExpenses();
}

function loadAccountExpenses() {
    if (!currentExpenseAccountCode) {
        return;
    }

    const query = $.param({
        action: 'read_account_expenses',
        account_code: currentExpenseAccountCode,
        from: $('#ex_from').val(),
        to: $('#ex_to').val(),
        direction: $('#ex_direction').val(),
        status: $('#ex_status').val(),
        keyword: $('#ex_keyword').val()
    });

    $.getJSON('backend.php?' + query, function(res) {
        if (!res.success) {
            $('#accountExpenseBody').html('<tr><td colspan="8" class="text-center text-danger">' + (res.message || 'Gagal memuat data') + '</td></tr>');
            return;
        }

        let html = '';
        if (!res.data.length) {
            html = '<tr><td colspan="8" class="text-center text-muted">Belum ada data</td></tr>';
        } else {
            res.data.forEach(function(row) {
                const amount = parseFloat(row.amount || 0);
                const reference = (row.reference_type || '-') + (row.reference_id ? (' #' + row.reference_id) : '');
                const creator = row.created_by_name || (row.created_by ? ('User #' + row.created_by) : '-');
                const dirLabelMap = {
                    in: 'Masuk',
                    out: 'Keluar',
                    transfer_in: 'Transfer Masuk',
                    transfer_out: 'Transfer Keluar'
                };
                const dirLabel = dirLabelMap[row.direction] || row.direction;
                const isOut = (row.direction === 'out' || row.direction === 'transfer_out');
                const cls = isOut ? 'text-danger' : 'text-success';
                const sign = isOut ? '-' : '+';
                const isOplRow2 = (/OPL/i.test(row.category || '') && row.reference_type === 'operational' && row.reference_id);
                const rowAttr2 = isOplRow2 ? `style="cursor:pointer;" onclick="openOplDetailModal(${row.reference_id})" title="Klik untuk lihat Laba OPL"` : '';
                const categoryCell2 = isOplRow2 ? `${row.category} <i class="fas fa-hand-holding-usd text-primary" title="Lihat Laba OPL"></i>` : (row.category || '-');
                html += `<tr ${rowAttr2}>
                    <td>${row.tanggal || '-'}</td>
                    <td>${dirLabel}</td>
                    <td>${getStatusBadgeHtml(row.status)}</td>
                    <td>${categoryCell2}</td>
                    <td>${reference}</td>
                    <td>${creator}</td>
                    <td>${row.note || '-'}</td>
                    <td class="text-end ${cls}">${sign}${fmt(amount)}</td>
                </tr>`;
            });
        }

        $('#accountExpenseBody').html(html);
    });
}

function exportAccountExpensesExcel() {
    if (!currentExpenseAccountCode) {
        return;
    }

    const params = $.param({
        action: 'export_account_expenses_excel',
        account_code: currentExpenseAccountCode,
        from: $('#ex_from').val(),
        to: $('#ex_to').val(),
        direction: $('#ex_direction').val(),
        status: $('#ex_status').val(),
        keyword: $('#ex_keyword').val()
    });

    window.location.href = 'backend.php?' + params;
}

function loadDiscountMemoHistory() {
    const query = $.param({
        action: 'read_balance_memo_transactions',
        from: $('#dm_from').val(),
        to: $('#dm_to').val(),
        keyword: $('#dm_keyword').val()
    });

    $.getJSON('backend.php?' + query, function(res) {
        if (!res.success) {
            $('#discountMemoTableBody').html('<tr><td colspan="6" class="text-center text-danger">' + (res.message || 'Gagal memuat data') + '</td></tr>');
            return;
        }

        let html = '';
        if (!res.data.length) {
            html = '<tr><td colspan="6" class="text-center text-muted">Belum ada data</td></tr>';
        } else {
            res.data.forEach(function(row) {
                const amount = parseFloat(row.amount || 0);
                const reference = (row.reference_type || '-') + (row.reference_id ? (' #' + row.reference_id) : '');
                const creator = row.created_by_name || '-';
                html += `<tr>
                    <td>${row.tanggal || '-'}</td>
                    <td>${row.category || '-'}</td>
                    <td>${reference}</td>
                    <td>${creator}</td>
                    <td>${row.note || '-'}</td>
                    <td class="text-end text-muted">${fmt(amount)}</td>
                </tr>`;
            });
        }

        $('#discountMemoTableBody').html(html);
        $('#discountMemoTotal').text(fmt(parseFloat(res.total || 0)));
    });
}

function loadSummary(){
    $.getJSON('backend.php?action=summary', function(res){
        if (!res.success) return;
        const d = res.data;
        $('#sumCash').text(fmt(d.cash_balance));
        $('#sumBank').text(fmt(d.bank_balance));
        $('#sumTotal').text(fmt(d.total_balance));
        $('#sumMonthFlow').text(fmt(d.month_in - d.month_out));
    });
}

function loadTransactions(){
    const page = arguments.length > 0 && arguments[0] ? parseInt(arguments[0], 10) : currentTxPage;
    currentTxPage = Number.isNaN(page) || page < 1 ? 1 : page;

    $.ajax({
        url: 'backend.php',
        dataType: 'json',
        data: {
            action: 'read_transactions',
            from: $('#f_from').val(),
            to: $('#f_to').val(),
            account: $('#f_account').val(),
            direction: $('#f_direction').val(),
            category: $('#f_category').val(),
            keyword: $('#f_keyword').val(),
            page: currentTxPage,
            per_page: TX_PER_PAGE
        },
        success: function(res){
            if (!res.success) return;
            let html = '';
            if (!res.data.length){
                html = '<tr><td colspan="9" class="text-center text-muted">Belum ada data</td></tr>';
            } else {
                res.data.forEach(function(t){
                    const isMemo = parseInt(t.affects_balance || 1, 10) === 0;
                    const out = (t.direction === 'out' || t.direction === 'transfer_out');
                    const cls = isMemo ? 'text-muted' : (out ? 'text-danger' : 'text-success');
                    const sign = isMemo ? '' : (out ? '-' : '+');
                    const dirLabelMap = {
                        in: 'Masuk',
                        out: 'Keluar',
                        transfer_in: 'Transfer Masuk',
                        transfer_out: 'Transfer Keluar'
                    };
                    const accountLabel = isMemo ? '—' : t.account_name;
                    const directionLabel = isMemo ? 'Catat' : (dirLabelMap[t.direction] || t.direction);

                    let creatorLabel = '-';
                    if (t.created_by) {
                        const id = parseInt(t.created_by, 10);
                        const roleMap = { 1: 'Owner', 2: 'Admin' };
                        creatorLabel = roleMap[id] || ('User #' + id);
                        if (t.created_by_name) {
                            creatorLabel += ' (' + t.created_by_name + ')';
                        }
                    }

                    const statusBadge = getStatusBadgeHtml(t.status);
                    const isOplRow = (/OPL/i.test(t.category || '') && t.reference_type === 'operational' && t.reference_id);
                    const rowAttr = isOplRow ? `style="cursor:pointer;" onclick="openOplDetailModal(${t.reference_id})" title="Klik untuk lihat Laba OPL"` : '';
                    const categoryCell = isOplRow ? `${t.category} <i class="fas fa-hand-holding-usd text-primary" title="Lihat Laba OPL"></i>` : (t.category || '-');

                    html += `<tr ${rowAttr}>
                        <td>${t.tanggal}</td>
                        <td>${accountLabel}</td>
                        <td>${directionLabel}</td>
                        <td>${statusBadge}</td>
                        <td>${categoryCell}</td>
                        <td>${(t.reference_type || '-')}${t.reference_id ? (' #' + t.reference_id) : ''}</td>
                        <td>${creatorLabel}</td>
                        <td>${t.note || '-'}</td>
                        <td class="text-end ${cls}">${sign}${fmt(t.amount)}</td>
                    </tr>`;
                });
            }
            $('#txTableBody').html(html);
            renderTxPagination(res.pagination || null);
        }
    });
}

function exportTransactionsPDF() {
    const params = $.param({
        action: 'export_transactions_pdf',
        from: $('#f_from').val(),
        to: $('#f_to').val(),
        account: $('#f_account').val(),
        direction: $('#f_direction').val(),
        category: $('#f_category').val(),
        keyword: $('#f_keyword').val()
    });
    window.location.href = 'backend.php?' + params;
}

function exportTransactionsExcel() {
    const params = $.param({
        action: 'export_transactions_excel',
        from: $('#f_from').val(),
        to: $('#f_to').val(),
        account: $('#f_account').val(),
        direction: $('#f_direction').val(),
        category: $('#f_category').val(),
        keyword: $('#f_keyword').val()
    });
    window.location.href = 'backend.php?' + params;
}

function renderTxPagination(pagination) {
    if (!pagination) {
        $('#txPagination').html('');
        $('#txPaginationInfo').text('-');
        return;
    }

    const page = parseInt(pagination.page || 1, 10);
    const perPage = parseInt(pagination.per_page || TX_PER_PAGE, 10);
    const total = parseInt(pagination.total || 0, 10);
    const totalPages = Math.max(1, parseInt(pagination.total_pages || 1, 10));
    const start = total === 0 ? 0 : ((page - 1) * perPage) + 1;
    const end = Math.min(page * perPage, total);

    $('#txPaginationInfo').text('Menampilkan ' + start + ' - ' + end + ' dari ' + total + ' data');

    if (totalPages <= 1) {
        $('#txPagination').html('');
        return;
    }

    let items = '';
    const prevDisabled = page <= 1 ? ' disabled' : '';
    const nextDisabled = page >= totalPages ? ' disabled' : '';
    const prevPage = page - 1;
    const nextPage = page + 1;

    items += `<li class="page-item${prevDisabled}"><a class="page-link" href="javascript:void(0)" onclick="loadTransactions(${prevPage})">Prev</a></li>`;

    const firstPage = Math.max(1, page - 2);
    const lastPage = Math.min(totalPages, page + 2);

    if (firstPage > 1) {
        items += `<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="loadTransactions(1)">1</a></li>`;
        if (firstPage > 2) {
            items += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for (let i = firstPage; i <= lastPage; i++) {
        const active = i === page ? ' active' : '';
        items += `<li class="page-item${active}"><a class="page-link" href="javascript:void(0)" onclick="loadTransactions(${i})">${i}</a></li>`;
    }

    if (lastPage < totalPages) {
        if (lastPage < totalPages - 1) {
            items += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        items += `<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="loadTransactions(${totalPages})">${totalPages}</a></li>`;
    }

    items += `<li class="page-item${nextDisabled}"><a class="page-link" href="javascript:void(0)" onclick="loadTransactions(${nextPage})">Next</a></li>`;

    $('#txPagination').html(items);
}

function loadPendingApprovals() {
    if (!isOwner) return;
    $.getJSON('backend.php?action=read_pending_transactions', function(res) {
        if (!res.success) {
            $('#approvalTableBody').html('<tr><td colspan="9" class="text-center text-danger">Gagal memuat approval pending</td></tr>');
            return;
        }

        let html = '';
        if (!res.data.length) {
            html = '<tr><td colspan="9" class="text-center text-muted">Tidak ada transaksi pending</td></tr>';
        } else {
            const dirLabelMap = {
                in: 'Masuk',
                out: 'Keluar',
                transfer_in: 'Transfer Masuk',
                transfer_out: 'Transfer Keluar'
            };

            res.data.forEach(function(t) {
                const out = (t.direction === 'out' || t.direction === 'transfer_out');
                const cls = out ? 'text-danger' : 'text-success';
                const sign = out ? '-' : '+';
                const creator = t.created_by_name ? t.created_by_name : ('User #' + (t.created_by || '-'));

                html += `<tr>
                    <td>${t.tanggal}</td>
                    <td>${t.account_name || '-'}</td>
                    <td>${dirLabelMap[t.direction] || t.direction}</td>
                    <td>${t.category || '-'}</td>
                    <td>${(t.reference_type || '-')}${t.reference_id ? (' #' + t.reference_id) : ''}</td>
                    <td>${creator}</td>
                    <td>${t.note || '-'}</td>
                    <td class="text-end ${cls}">${sign}${fmt(t.amount)}</td>
                    <td>
                        <button class="btn btn-success btn-sm me-1" onclick="approveTransaction(${t.id})">Approve</button>
                        <button class="btn btn-outline-danger btn-sm" onclick="rejectTransaction(${t.id})">Reject</button>
                    </td>
                </tr>`;
            });
        }

        $('#approvalTableBody').html(html);
    });
}

function approveTransaction(id) {
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'approve_transaction', id: id },
        success: function(res) {
            if (res.success) {
                showAlert('success', res.message);
                loadPendingApprovals();
                loadSummary();
                loadAccounts();
                loadTransactions();
            } else {
                showAlert('danger', res.message || 'Gagal approve transaksi');
            }
        }
    });
}

function rejectTransaction(id) {
    $.ajax({
        url: 'backend.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'reject_transaction', id: id },
        success: function(res) {
            if (res.success) {
                showAlert('success', res.message);
                loadPendingApprovals();
                loadTransactions();
            } else {
                showAlert('danger', res.message || 'Gagal reject transaksi');
            }
        }
    });
}

function showAlert(type, message) {
    let alertHtml = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
    $('.container-fluid').prepend(alertHtml);
    setTimeout(function(){ $('.alert').fadeOut(function(){ $(this).remove(); }); }, 3000);
}
</script>

<?php include '../footer.php'; ?>
