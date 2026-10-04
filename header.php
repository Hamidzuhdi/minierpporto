<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Mini ERP Bengkel'; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- Tab Manager System -->
    <script>
        // Set base path from PHP
        const BASE_PATH = '<?php echo rtrim(dirname($_SERVER["PHP_SELF"]), "/\\"); ?>';
        
        const TabManager = {
            MAX_TABS: 3,
            STORAGE_KEY: 'erp_tabs',
            
            // Get proper path based on current location
            getProperPath(targetUrl) {
                const currentPath = window.location.pathname;
                const isInSubfolder = currentPath.includes('/customers/') || 
                                     currentPath.includes('/users/') ||
                                     currentPath.includes('/spareparts/') ||
                                     currentPath.includes('/services/') ||
                                     currentPath.includes('/purchases/') ||
                                     currentPath.includes('/spk/') ||
                                     currentPath.includes('/warehouse_out/') ||
                                     currentPath.includes('/payments/') ||
                                     currentPath.includes('/invoices/') ||
                                     currentPath.includes('/audit/');
                
                if (targetUrl === 'dashboard.php') {
                    // From subfolder to dashboard
                    return isInSubfolder ? '../dashboard.php' : './dashboard.php';
                } else if (targetUrl.includes('/')) {
                    // Folder/file path like "customers/index.php"
                    return isInSubfolder ? '../' + targetUrl : './' + targetUrl;
                } else {
                    return './' + targetUrl;
                }
            },
            
            // Initialize tab system
            init() {
                this.loadTabs();
                this.setupSidebarLinks();
                
                // Auto-add current page to tabs
                const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
                const pathParts = window.location.pathname.split('/').filter(p => p && p !== 'minierp');
                
                // Get full URL path (e.g., "customers/index.php" or "dashboard.php")
                let fullPath = currentPage;
                if (pathParts.length > 1) {
                    fullPath = pathParts[pathParts.length - 2] + '/' + currentPage;
                }
                
                // Get label and icon for current page
                const labelMap = {
                    'dashboard.php': 'Dashboard',
                    'users/index.php': 'Users',
                    'customers/index.php': 'Customers',
                    'spareparts/index.php': 'Spareparts',
                    'services/index.php': 'Harga Jasa',
                    'purchases/index.php': 'Purchases',
                    'spk/index.php': 'SPK',
                    'warehouse_out/index.php': 'Warehouse Out',
                    'payments/index.php': 'Payment',
                    'invoices/index.php': 'Invoices',
                    'audit/index.php': 'Audit Log'
                };
                
                // Add current page if not already in tabs
                if (!this.getTabByUrl(fullPath) && !this.getTabByUrl(currentPage)) {
                    const label = labelMap[fullPath] || labelMap[currentPage] || 'Page';
                    
                    // Only add if under max tabs
                    if (window.openTabs.length < this.MAX_TABS) {
                        window.openTabs.push({
                            url: fullPath,
                            label: label,
                            icon: 'fa-file',
                            id: 'tab_' + Date.now()
                        });
                        this.saveTabs();
                    }
                }
                
                this.renderTabs();
                this.setActiveTab(currentPage);
            },
            
            // Load tabs from localStorage
            loadTabs() {
                const stored = localStorage.getItem(this.STORAGE_KEY);
                if (stored) {
                    try {
                        window.openTabs = JSON.parse(stored);
                    } catch (e) {
                        window.openTabs = [];
                    }
                } else {
                    window.openTabs = [];
                }
            },
            
            // Save tabs to localStorage
            saveTabs() {
                localStorage.setItem(this.STORAGE_KEY, JSON.stringify(window.openTabs));
            },
            
            // Get tab by URL (support both formats: "dashboard.php" or "customers/index.php")
            getTabByUrl(url) {
                return window.openTabs.find(tab => 
                    tab.url === url || 
                    tab.url === url.split('/').pop() ||
                    url === tab.url.split('/').pop()
                );
            },
            
            // Add new tab
            addTab(url, label, icon) {
                // Normalize URL
                const pathParts = url.split('/').filter(p => p);
                let normalizedUrl = url;
                
                if (pathParts.length === 1) {
                    // Single file like "dashboard.php"
                    normalizedUrl = url;
                } else if (pathParts.length >= 2) {
                    // Path like "../customers/index.php" -> "customers/index.php"
                    const endParts = pathParts.slice(-2).join('/');
                    if (endParts.endsWith('.php')) {
                        normalizedUrl = endParts;
                    }
                }
                
                // Check if tab already exists
                if (this.getTabByUrl(normalizedUrl)) {
                    this.setActiveTab(normalizedUrl);
                    return true;
                }
                
                // Check max tabs limit
                if (window.openTabs.length >= this.MAX_TABS) {
                    // Return false - don't add tab, but allow navigation
                    return false;
                }
                
                // Add new tab
                window.openTabs.push({
                    url: normalizedUrl,
                    label: label,
                    icon: icon,
                    id: 'tab_' + Date.now()
                });
                
                this.saveTabs();
                this.renderTabs();
                return true;
            },
            
            // Close tab
            closeTab(url) {
                // Normalize URL for comparison
                const normalizeUrl = (u) => {
                    const parts = u.split('/').filter(p => p);
                    return parts.length >= 2 ? parts.slice(-2).join('/') : u;
                };
                
                const normalized = normalizeUrl(url);
                window.openTabs = window.openTabs.filter(tab => normalizeUrl(tab.url) !== normalized);
                this.saveTabs();
                
                // If we had active tab and it's closed, activate first tab
                const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
                const currentNormalized = normalizeUrl(currentPage);
                
                if (normalized === normalizeUrl(currentPage) || normalized === currentPage) {
                    if (window.openTabs.length > 0) {
                        const nextTab = window.openTabs[0].url;
                        window.location.href = this.getProperPath(nextTab);
                    } else {
                        window.location.href = this.getProperPath('dashboard.php');
                    }
                } else {
                    this.renderTabs();
                }
            },
            
            // Set active tab
            setActiveTab(url) {
                const normalizeUrl = (u) => {
                    const parts = u.split('/').filter(p => p);
                    return parts.length >= 2 ? parts.slice(-2).join('/') : u;
                };
                
                const normalized = normalizeUrl(url);
                const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
                
                document.querySelectorAll('.tab-item').forEach(el => {
                    const tabUrl = el.getAttribute('data-url');
                    if (normalizeUrl(tabUrl) === normalized || normalizeUrl(tabUrl) === normalizeUrl(currentPage)) {
                        el.classList.add('active');
                    } else {
                        el.classList.remove('active');
                    }
                });
            },
            
            // Render tabs
            renderTabs() {
                const tabBar = document.getElementById('tabBar');
                if (!tabBar) return;
                
                const normalizeUrl = (u) => {
                    const parts = u.split('/').filter(p => p);
                    return parts.length >= 2 ? parts.slice(-2).join('/') : u;
                };
                
                tabBar.innerHTML = '';
                
                const pathParts = window.location.pathname.split('/').filter(p => p && p !== 'minierp');
                const currentFullPath = pathParts.length > 1 ? 
                    pathParts[pathParts.length - 2] + '/' + pathParts[pathParts.length - 1] : 
                    window.location.pathname.split('/').pop() || 'dashboard.php';
                
                window.openTabs.forEach(tab => {
                    const tabEl = document.createElement('div');
                    tabEl.className = 'tab-item';
                    tabEl.setAttribute('data-url', tab.url);
                    
                    // Check if this is active tab
                    const isActive = normalizeUrl(tab.url) === normalizeUrl(currentFullPath) || 
                                   normalizeUrl(tab.url) === normalizeUrl(window.location.pathname.split('/').pop());
                    
                    if (isActive) {
                        tabEl.classList.add('active');
                    }
                    
                    // Get icon based on URL
                    const iconMap = {
                        'dashboard.php': 'fa-home',
                        'users/index.php': 'fa-users',
                        'customers/index.php': 'fa-user-tie',
                        'spareparts/index.php': 'fa-cog',
                        'services/index.php': 'fa-wrench',
                        'purchases/index.php': 'fa-shopping-cart',
                        'spk/index.php': 'fa-clipboard-list',
                        'warehouse_out/index.php': 'fa-dolly',
                        'payments/index.php': 'fa-wallet',
                        'invoices/index.php': 'fa-file-invoice',
                        'audit/index.php': 'fa-history'
                    };
                    
                    const icon = iconMap[tab.url] || tab.icon || 'fa-file';
                    
                    tabEl.innerHTML = `
                        <i class="fas ${icon} tab-icon"></i>
                        <span class="tab-name">${tab.label}</span>
                        <span class="tab-close" onclick="event.stopPropagation(); TabManager.closeTab('${tab.url}');">
                            <i class="fas fa-times"></i>
                        </span>
                    `;
                    
                    tabEl.addEventListener('click', (e) => {
                        if (!e.target.closest('.tab-close')) {
                            window.location.href = TabManager.getProperPath(tab.url);
                        }
                    });
                    
                    tabBar.appendChild(tabEl);
                });
            },
            
            // Setup sidebar links to open in tabs
            setupSidebarLinks() {
                document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        let href = link.getAttribute('href');
                        const label = link.textContent.trim();
                        const icon = link.querySelector('i')?.className || '';
                        
                        // Normalize href - remove leading ../ 
                        if (href.startsWith('../')) {
                            href = href.substring(3);
                        }
                        
                        // Try to add tab, but navigate regardless
                        this.addTab(href, label, icon);
                        
                        // Always navigate, whether tab was added or not
                        setTimeout(() => {
                            window.location.href = this.getProperPath(href);
                        }, 50);
                    });
                });
            },
            
        };
        
        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            TabManager.init();
        });
    </script>
    
    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
            padding: 48px 0 0;
            box-shadow: inset -1px 0 0 rgba(0, 0, 0, .1);
            background-color: #212529;
        }
        .sidebar-sticky {
            position: relative;
            top: 0;
            height: calc(100vh - 48px);
            padding-top: .5rem;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .sidebar .nav-link {
            font-weight: 500;
            color: #adb5bd;
            padding: .75rem 1rem;
        }
        .sidebar .nav-link:hover {
            color: #fff;
            background-color: #343a40;
        }
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }
        .sidebar .nav-link i {
            margin-right: 0.5rem;
        }
        .navbar {
            background-color: #fff;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);
        }
        
        /* Tab System Styling */
        .tab-bar {
            position: fixed;
            top: 56px;
            left: 240px;
            right: 0;
            height: 48px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            gap: 0;
            padding: 0;
            z-index: 50;
            overflow-x: auto;
            overflow-y: hidden;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.05);
        }
        
        .tab-bar::-webkit-scrollbar {
            height: 4px;
        }
        
        .tab-bar::-webkit-scrollbar-track {
            background: #f8f9fa;
        }
        
        .tab-bar::-webkit-scrollbar-thumb {
            background: #bbb;
            border-radius: 2px;
        }
        
        .tab-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            background-color: #e9ecef;
            border-right: 1px solid #dee2e6;
            cursor: pointer;
            white-space: nowrap;
            font-size: 13px;
            color: #495057;
            transition: all 0.2s;
            min-height: 48px;
            user-select: none;
        }
        
        .tab-item:hover {
            background-color: #dee2e6;
        }
        
        .tab-item.active {
            background-color: #fff;
            color: #0d6efd;
            border-bottom: 3px solid #0d6efd;
            font-weight: 500;
        }
        
        .tab-item .tab-icon {
            font-size: 14px;
        }
        
        .tab-item .tab-name {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .tab-item .tab-close {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 3px;
            opacity: 0.7;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .tab-item .tab-close:hover {
            background-color: rgba(0,0,0,0.1);
            opacity: 1;
        }
        
        main {
            margin-left: 240px;
            padding-top: 104px;
        }
        
        @media (max-width: 768px) {
            main {
                margin-left: 0;
                padding-top: 56px;
            }
            .sidebar {
                display: none;
            }
            .tab-bar {
                left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar navbar-light fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="fas fa-tools"></i> Mini ERP Bengkel
            </a>
            <div class="d-flex">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?php echo $_SESSION['username'] ?? 'User'; ?>
                </span>
                <a href="../logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Tab Bar -->
    <div class="tab-bar" id="tabBar"></div>

    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-sticky">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="../dashboard.php">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../users/index.php">
                        <i class="fas fa-users"></i> Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../customers/index.php">
                        <i class="fas fa-user-tie"></i> Customers
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../spareparts/index.php">
                        <i class="fas fa-cog"></i> Spareparts
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../services/index.php">
                        <i class="fas fa-wrench"></i> Harga Jasa
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../purchases/index.php">
                        <i class="fas fa-shopping-cart"></i> Purchases
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../spk/index.php">
                        <i class="fas fa-clipboard-list"></i> SPK
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../warehouse_out/index.php">
                        <i class="fas fa-dolly"></i> Warehouse Out
                    </a>
                </li>
                <?php
                // Menu Invoice untuk Owner dan Admin
                $user_role = $_SESSION['role'] ?? 'Admin';
                if ($user_role === 'Owner' || $user_role === 'Admin'):
                ?>
                <li class="nav-item">
                    <a class="nav-link" href="../payments/index.php">
                        <i class="fas fa-wallet"></i> Payment
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../invoices/index.php">
                        <i class="fas fa-file-invoice"></i> Invoices
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="../audit/index.php">
                        <i class="fas fa-history"></i> Audit Log
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main>
