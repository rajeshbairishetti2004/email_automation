<?php
// view_allocation_clients.php
require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';

requireAuth();
$currentUser = getCurrentUser();
$userDesignation = $currentUser['designation'] ?? '';
$navUser = $currentUser['username'] ?? ($_SESSION['username'] ?? 'User');
$myId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? 0);

$pdo = getPdo();

// Get allocation ID from query parameter
$allocationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($allocationId <= 0) {
    header('Location: allocation_log.php');
    exit;
}

// Get allocation details
$stmt = $pdo->prepare("
    SELECT al.*, u.name as user_name, u.username 
    FROM allocation_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE al.id = :id
");
$stmt->execute([':id' => $allocationId]);
$allocation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$allocation) {
    header('Location: allocation_log.php');
    exit;
}

// Initialize search variable
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Build filter conditions for clients query
$filterConditions = [
    "c1.month_year = :month_year",
    "c1.review_cycle = :review_cycle",
    "c1.id = (
        SELECT MAX(c2.id)
        FROM clients c2
        WHERE c2.name = c1.name
        AND c2.month_year = :month_year2
        AND c2.review_cycle = :review_cycle2
    )"
];

$filterParams = [
    ':month_year' => $allocation['month_year'],
    ':review_cycle' => $allocation['target_tag'],
    ':month_year2' => $allocation['month_year'],
    ':review_cycle2' => $allocation['target_tag']
];

// Add search filter
if ($q !== '') {
    $filterConditions[] = "c1.name LIKE :search";
    $filterParams[':search'] = '%' . $q . '%';
}

// Build the WHERE clause
$whereClause = "WHERE " . implode(" AND ", $filterConditions);

// Get LATEST clients for this allocation
$clientStmt = $pdo->prepare("
    SELECT 
        c1.id,
        c1.name as client_name,
        c1.email,
        c1.assigned_to,
        c1.review_assigned_to,
        c1.report_state,
        c1.month_year,
        c1.review_cycle,
        c1.total_amount as aum,
        c1.priority,
        c1.created_at,
        c1.updated_at,
        c1.allocation_id,
        c1.reviewed_at,
        c1.sent_at,
        c1.ready_at,
        c1.draft_at,
        rm.name as rm_name,
        reviewer.name as reviewer_name,
        DATE_FORMAT(c1.updated_at, '%d %b %Y %h:%i %p') as last_updated
    FROM clients c1
    LEFT JOIN users rm ON c1.assigned_to = rm.id
    LEFT JOIN users reviewer ON c1.review_assigned_to = reviewer.id
    {$whereClause}
    ORDER BY c1.name
");

$clientStmt->execute($filterParams);
$clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate client statistics
$totalClients = count($clients);
$assignedClients = array_filter($clients, function($c) {
    return !empty($c['assigned_to']);
});
$unassignedClients = $totalClients - count($assignedClients);

// Function to get client names for autocomplete
function getClientNamesForAutocomplete($pdo, $allocationId, $searchTerm) {
    $stmt = $pdo->prepare("SELECT * FROM allocation_log WHERE id = :id");
    $stmt->execute([':id' => $allocationId]);
    $allocation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$allocation) {
        return [];
    }
    
    $sql = "
        SELECT DISTINCT c1.name
        FROM clients c1
        WHERE c1.month_year = :month_year 
        AND c1.review_cycle = :review_cycle
        AND c1.id = (
            SELECT MAX(c2.id)
            FROM clients c2
            WHERE c2.name = c1.name
            AND c2.month_year = :month_year2
            AND c2.review_cycle = :review_cycle2
        )
    ";
    
    if ($searchTerm !== '') {
        $sql .= " AND c1.name LIKE :search";
    }
    
    $sql .= " ORDER BY c1.name LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $params = [
        ':month_year' => $allocation['month_year'],
        ':review_cycle' => $allocation['target_tag'],
        ':month_year2' => $allocation['month_year'],
        ':review_cycle2' => $allocation['target_tag']
    ];
    
    if ($searchTerm !== '') {
        $params[':search'] = '%' . $searchTerm . '%';
    }
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Allocation Clients - <?php echo htmlspecialchars($allocation['month_year']); ?> - <?php echo htmlspecialchars($allocation['target_tag']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="public/css/view_allocation_clients.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container">
        <button class="back-button" onclick="window.location.href='allocation_log.php'">
            <i class="fas fa-arrow-left"></i> Back to Allocation Log
        </button>
        
        <h2>
            <i class="fas fa-users"></i> 
            Clients for Allocation: <?php echo htmlspecialchars($allocation['month_year']); ?> 
            <span class="tag-badge"><?php echo htmlspecialchars($allocation['target_tag']); ?></span>
        </h2>
        
        <div class="allocation-info">
            <div class="info-item">
                <span class="info-label">Imported by</span>
                <span class="info-value"><?php echo htmlspecialchars($allocation['user_name'] ?: $allocation['username']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Import Date & Time</span>
                <span class="info-value"><?php echo date('d M Y h:i A', strtotime($allocation['created_at'])); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Clients</span>
                <span class="info-value"><?php echo number_format($allocation['clients_count']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Assigned Clients</span>
                <span class="info-value">
                    <?php 
                    $assignedPercent = $allocation['clients_count'] > 0 
                        ? round(($allocation['assigned_count'] / $allocation['clients_count']) * 100) 
                        : 0;
                    echo number_format($allocation['assigned_count']) . " ($assignedPercent%)";
                    ?>
                </span>
            </div>
            <div class="info-item">
                <span class="info-label">New / Updated</span>
                <span class="info-value">
                    +<?php echo number_format($allocation['inserted_count']); ?> new /
                    ↑<?php echo number_format($allocation['updated_count']); ?> updated
                </span>
            </div>
            <div class="info-item">
                <span class="info-label">Source File</span>
                <span class="info-value"><?php echo htmlspecialchars($allocation['file_name'] ?? 'N/A'); ?></span>
            </div>
        </div>

        <?php if (!empty($clients)): ?>
        <!-- Search Section -->
       <div class="search-section">
    <form method="get" class="search-box" id="searchForm">
        <input type="hidden" name="id" value="<?php echo $allocationId; ?>">

        <div class="search-wrapper">
            <input type="text"
                   name="q"
                   id="client-search"
                   placeholder="Search client name..."
                   value="<?php echo htmlspecialchars($q); ?>"
                   autocomplete="off">

            <div id="client-search-dropdown"></div>
        </div>

        <button type="submit">
            <i class="fas fa-search"></i> Search
        </button>

        <?php if (!empty($q)): ?>
            <a href="view_allocation_clients.php?id=<?php echo $allocationId; ?>">
                <i class="fas fa-times"></i> Clear Search
            </a>
        <?php endif; ?>
    </form>
</div>


        <div class="client-summary">
            <div class="summary-text">
                <strong>Client Summary:</strong> 
                Total: <?php echo $totalClients; ?> | 
                Assigned: <?php echo count($assignedClients); ?> | 
                Unassigned: <?php echo $unassignedClients; ?>
                <?php if (!empty($q)): ?>
                    | <span style="color: #0288D1; font-weight: 600;">Search Results: <?php echo $totalClients; ?> clients</span>
                <?php endif; ?>
            </div>
            <div>
                <button class="export-btn" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Export to CSV
                </button>
            </div>
        </div>
        
        <div class="client-table-container">
            <table class="client-table">
                <thead>
                    <tr>
                        <th>Client Name</th>
                        <th>Email</th>
                        <th>Relationship Manager</th>
                        <th>Reviewed By</th>
                        <th>Review Cycle</th>
                        <th>AUM (₹)</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody id="client-table-body">
                    <?php foreach ($clients as $client): 
                        $status = $client['report_state'] ?? 'pending';
                        $statusClass = 'status-' . $status;
                        $statusText = ucfirst($status);
                        if ($status === 'pending') $statusText = 'Pending';
                        if ($status === 'sent') $statusText = 'Sent';
                    ?>
                    <tr>
                        <td>
                            <span class="client-name-link" 
                                  onclick="showClientHistory(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['client_name'] ?? 'Client', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($client['email'] ?? 'N/A', ENT_QUOTES); ?>')">
                                <?php echo htmlspecialchars($client['client_name'] ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($client['rm_name'] ?? 'Unassigned'); ?></td>
                        <td>
                            <?php 
                            if (!empty($client['reviewer_name'])) {
                                echo htmlspecialchars($client['reviewer_name']);
                            } else {
                                echo 'Unassigned';
                            }
                            ?>
                        </td>
                        <td><span class="tag-badge"><?php echo htmlspecialchars($client['review_cycle'] ?? 'N/A'); ?></span></td>
                        <td class="aum-value"> ₹<?= number_format(((float)($client['aum'] ?? 0)) / 10000000, 2); ?> Cr</td>
                        <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                        <td><?php echo $client['last_updated'] ?? 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <h3>No clients found</h3>
            <p>
                <?php if (!empty($q)): ?>
                    No clients match your search criteria.
                    <a href="view_allocation_clients.php?id=<?php echo $allocationId; ?>" style="color: #0288D1; font-weight: 600;">Clear search</a> to see all clients.
                <?php else: ?>
                    This allocation might not have any clients assigned yet, or the clients may not match the criteria.
                <?php endif; ?>
            </p>
            <p style="margin-top: 10px; font-size: 14px;">
                <strong>Allocation Criteria:</strong><br>
                Month: <?php echo htmlspecialchars($allocation['month_year']); ?><br>
                Review Cycle: <?php echo htmlspecialchars($allocation['target_tag']); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="history-modal">
        <div class="history-modal-content">
            <div class="history-modal-header">
                <h3><i class="fas fa-history"></i> Client Review History</h3>
               <button class="history-modal-close" onclick="closeHistoryModal()">×</button>
            </div>
            <div class="history-modal-body" id="historyModalBody">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
<script>
    function exportToCSV() {
        const table = document.querySelector('.client-table');
        let csv = [];
        
        const headers = [];
        table.querySelectorAll('thead th').forEach(header => {
            headers.push(header.textContent.trim());
        });
        csv.push(headers.join(','));
        
        table.querySelectorAll('tbody tr').forEach(row => {
            const rowData = [];
            row.querySelectorAll('td').forEach(cell => {
                const text = cell.textContent.trim().replace(/,/g, ';');
                rowData.push(`"${text}"`);
            });
            csv.push(rowData.join(','));
        });
        
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', `allocation_clients_<?php echo htmlspecialchars($allocation['month_year']); ?>_<?php echo htmlspecialchars($allocation['target_tag']); ?>.csv`);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    // Utility function
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // History Function
    function showClientHistory(clientId, clientName, clientEmail) {
        const modal = document.getElementById('historyModal');
        const modalBody = document.getElementById('historyModalBody');
        
        if (!modal || !modalBody) {
            alert('History modal not found. Please refresh the page.');
            return;
        }
        
        // Show loading message
        modalBody.innerHTML = `
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
                <div style="margin-left: 15px;">
                    <div>Loading complete client history...</div>
                    <div style="font-size: 12px; margin-top: 5px; color: #666;">
                        Client: ${escapeHtml(clientName)}<br>
                        ID: ${clientId}
                    </div>
                </div>
            </div>
        `;
        
        // Show modal
        modal.style.display = 'flex';
        
        // Fetch data
        const apiUrl = `get_client_history.php?client_name=${encodeURIComponent(clientName)}&include_attachments=1`;
        
        fetch(apiUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    displayCompleteHistory(data, clientName, clientEmail, clientId);
                } else {
                    modalBody.innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #ff9800; margin-bottom: 20px;"></i>
                            <h3>No History Found</h3>
                            <p>${data.error || 'No review history available for this client.'}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #dc3545; margin-bottom: 20px;"></i>
                        <h3>Error Loading History</h3>
                        <p>Could not load client history. Please try again.</p>
                    </div>
                `;
            });
    }
    
    function displayCompleteHistory(data, clientName, clientEmail, currentClientId) {
        const modalBody = document.getElementById('historyModalBody');
        
        // Calculate statistics
        const totalRecords = data.history ? data.history.length : 0;
        let totalAUM = 0;
        let years = new Set();
        let cycles = new Set();
        let statuses = new Set();
        
        if (data.history && data.history.length > 0) {
            data.history.forEach(review => {
                totalAUM += parseFloat(review.aum || 0);
                if (review.month_year) {
                    const year = review.month_year.split('-')[0];
                    if (year) years.add(year);
                }
                if (review.review_cycle) cycles.add(review.review_cycle);
                if (review.report_state) statuses.add(review.report_state);
            });
        }
        
        // Get RM and Reviewer names from current record
        let currentRM = 'Unassigned';
        let currentReviewer = 'Unassigned';
        let currentAUM = '₹0.00';
        if (data.history && data.history.length > 0) {
            const currentRecord = data.history.find(r => parseInt(r.id) === parseInt(currentClientId));
            if (currentRecord) {
                currentRM = currentRecord.rm_name || 'Unassigned';
                currentReviewer = currentRecord.reviewer_name || 'Unassigned';
                currentAUM = parseFloat(currentRecord.aum || 0) > 0 
                    ? `₹${(parseFloat(currentRecord.aum || 0)/10000000).toFixed(2)} Cr` 
                    : '₹0.00';
            }
        }
        
        let html = `
            <!-- CLIENT HEADER SECTION -->
            <div class="history-client-header">
                <div class="history-client-title">
                    <i class="fas fa-user-circle"></i>
                    Client Overview
                </div>
                
                <div class="history-client-grid">
                    <div class="history-client-item">
                        <span class="history-client-label">Client Name</span>
                        <span class="history-client-value">${escapeHtml(clientName)}</span>
                        <span class="history-client-email">${escapeHtml(clientEmail || 'N/A')}</span>
                    </div>
                    
                    <div class="history-client-item">
                        <span class="history-client-label">Relationship Manager</span>
                        <span class="history-client-value">${escapeHtml(currentRM)}</span>
                    </div>
                    
                    <div class="history-client-item">
                        <span class="history-client-label">Reviewed By</span>
                        <span class="history-client-value">${escapeHtml(currentReviewer)}</span>
                    </div>
                    
                    <div class="history-client-item">
                        <span class="history-client-label">Current AUM</span>
                        <span class="history-client-value">${currentAUM}</span>
                    </div>
                </div>
                
                <div class="history-stats-bar">
                    <div class="history-stat-item">
                        <span class="history-stat-value">${totalRecords}</span>
                        <span class="history-stat-label">Total Records</span>
                    </div>
                    
                    <div class="history-stat-item">
                        <span class="history-stat-value">${Array.from(years).length}</span>
                        <span class="history-stat-label">Years Covered</span>
                    </div>
                    
                    <div class="history-stat-item">
                        <span class="history-stat-value">${currentClientId}</span>
                        <span class="history-stat-label">Current ID</span>
                    </div>
                </div>
            </div>
            
            <hr class="history-divider">
        `;
        
        if (data.history && data.history.length > 0) {
            
            html += `<div class="history-table-container">`;
            html += `<table class="compact-history-table" id="historyTable">`;
            html += `<thead>
                <tr>
                    <th>ID</th>
                    <th>Month/Year</th>
                    <th>Review Cycle</th>
                    <th>RM</th>
                    <th>Reviewed By</th>
                    <th>AUM (₹)</th>
                    <th>Status</th>
                    <th>Date & Time</th>
                    <th>Actions</th>
                </tr>
            </thead><tbody>`;
            
            // Sort by created_at descending (newest first)
            data.history.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            
            data.history.forEach(review => {
                const status = review.report_state || 'pending';
                const statusClass = `compact-status-badge status-${status}`;
                const statusText = status.charAt(0).toUpperCase() + status.slice(1);
                
                // Check if this is the current row
                const isCurrentRow = parseInt(review.id) === parseInt(currentClientId);
                const rowClass = isCurrentRow ? 'current-history-row' : '';
                
                // Format date and time
                const createdDate = review.created_at ? new Date(review.created_at) : null;
                let dateTimeHtml = 'N/A';
                if (createdDate) {
                    const dateStr = createdDate.toLocaleDateString('en-GB', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });
                    const timeStr = createdDate.toLocaleTimeString('en-US', {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    }).toLowerCase();
                    dateTimeHtml = `
                        <div class="date-time-value">
                            <span class="date">${dateStr}</span>
                            <span class="time">${timeStr}</span>
                        </div>
                    `;
                }
                
                // Format AUM
                const aumValue = parseFloat(review.aum || 0);
                const aumDisplay = aumValue > 0 ? `₹${(aumValue/10000000).toFixed(2)} Cr` : '₹0.00';
                
                // View button - only show if report exists
                const canView = status !== 'pending';
                const viewButton = canView ? 
                    `<button class="btn-view-history-report" onclick="window.open('view_report.php?id=${review.id}', '_blank')">
                        <i class="fas fa-eye"></i> View Report
                    </button>` :
                    `<span class="no-report-badge">No report</span>`;
                
                html += `
                    <tr class="${rowClass}">
                        <td>
                            ${review.id} 
                            ${isCurrentRow ? '<i class="fas fa-star" style="color: #ff9800; margin-left: 5px;" title="Current Record"></i>' : ''}
                        </td>
                        <td><strong>${escapeHtml(review.month_year || 'N/A')}</strong></td>
                        <td><span class="compact-tag-badge">${escapeHtml(review.review_cycle || 'N/A')}</span></td>
                        <td>${escapeHtml(review.rm_name || 'Unassigned')}</td>
                        <td>${escapeHtml(review.reviewer_name || 'Unassigned')}</td>
                        <td style="font-weight: 600;">${aumDisplay}</td>
                        <td><span class="${statusClass}">${statusText}</span></td>
                        <td class="date-time-cell">${dateTimeHtml}</td>
                        <td>${viewButton}</td>
                    </tr>
                `;
            });
            
            html += `</tbody></table></div>`;
        } else {
            html += `
                <div class="empty-history">
                    <i class="fas fa-history"></i>
                    <h3>No Review History</h3>
                    <p>This client doesn't have any review history yet.</p>
                </div>
            `;
        }
        
        html += `</div>`;
        
        modalBody.innerHTML = html;
    }
    
    function closeHistoryModal() {
        document.getElementById('historyModal').style.display = 'none';
        document.getElementById('historyModalBody').innerHTML = '';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('historyModal');
        if (event.target === modal) {
            closeHistoryModal();
        }
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeHistoryModal();
        }
    });
    
    // --- Client Name Autocomplete ---
    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('client-search');
        const dropdown = document.getElementById('client-search-dropdown');
        if (!input) return;
        
        let autocompleteTimeout;
        
        // Autocomplete functionality
        input.addEventListener('input', function() {
            const searchValue = input.value.trim();
            
            // Clear previous timeout
            if (autocompleteTimeout) {
                clearTimeout(autocompleteTimeout);
            }
            
            // Show/hide dropdown for autocomplete
            if (searchValue.length < 1) {
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
                return;
            }
            
            // Fetch client names for autocomplete after delay
            autocompleteTimeout = setTimeout(() => {
                fetch(`?id=<?php echo $allocationId; ?>&q=${encodeURIComponent(searchValue)}&autocomplete=1`)
                    .then(res => res.text())
                    .then(html => {
                        // Create a temporary div to parse the HTML
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = html;
                        
                        // Extract client names from the table
                        const clientNames = [];
                        const rows = tempDiv.querySelectorAll('.client-table tbody tr');
                        rows.forEach(row => {
                            const nameCell = row.querySelector('td:first-child .client-name-link');
                            if (nameCell) {
                                clientNames.push(nameCell.textContent.trim());
                            }
                        });
                        
                        if (clientNames.length > 0) {
                            // Sort alphabetically and remove duplicates
                            const uniqueNames = [...new Set(clientNames)].sort((a, b) => a.localeCompare(b));
                            dropdown.innerHTML = uniqueNames.map(name =>
                                `<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;" 
                                    onmousedown="selectClientName('${name.replace(/'/g,"\\'")}')"
                                    onmouseover="this.style.backgroundColor='#f5f5f5'"
                                    onmouseout="this.style.backgroundColor='white'">
                                    ${name}
                                </div>`
                            ).join('');
                            dropdown.style.display = 'block';
                        } else {
                            dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;font-style:italic;">No clients found</div>';
                            dropdown.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching client names:', error);
                    });
            }, 300); // 300ms delay
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (dropdown && !dropdown.contains(e.target) && e.target !== input) {
                dropdown.style.display = 'none';
            }
        });
        
        // Submit form when Enter is pressed
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('searchForm').submit();
            }
        });
    });
    
    function selectClientName(name) {
        const input = document.getElementById('client-search');
        const dropdown = document.getElementById('client-search-dropdown');
        
        input.value = name;
        dropdown.style.display = 'none';
        
        // Submit the form immediately when a name is selected
        document.getElementById('searchForm').submit();
    }
</script>
</body>
</html>