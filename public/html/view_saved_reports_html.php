    <!DOCTYPE html>
    <html>

    <head>
        <title>Stored Client Reports</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="public/css/view_saved_reports.css">
        <link rel="stylesheet" href="public/css/navbar.css">
        <script src="public/js/view_saved_reports.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>

    <body class="<?php echo ($deleteMode || $reassignMode) ? 'action-mode-active' : ''; ?>">
        <?php include 'navbar.php'; ?>

        <!-- Save toast notification -->
        <div id="saveToast">✓ Saved</div>

        <div class="main-scroll-container" style="height: calc(100vh - 72px); overflow-y: auto;">

            <div class="container">

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <!-- LEFT: Action buttons (reassign) -->
                    <div class="action-icons-container">
                        <?php if ($isAdmin && !$deleteMode && !$reassignMode): ?>
                            <a href="?mode=reassign<?php echo $q ? '&q=' . urlencode($q) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?><?php echo $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : ''; ?><?php echo $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : ''; ?><?php echo $sortBy ? '&sort=' . urlencode($sortBy) : ''; ?><?php echo $sortOrder !== 'DESC' ? '&order=' . strtolower($sortOrder) : ''; ?>"
                                class="action-btn reassign-btn" title="Reassign Clients">
                                <i class="fa-solid fa-user-group"></i>
                                <span>Reassign</span>
                            </a>
                        <?php elseif ($deleteMode || $reassignMode): ?>
                            <a href="view_saved_reports.php" class="cancel-action-btn">
                                <i class="fa-solid fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- CENTER: Reassigned Summary -->
                    <?php if ($showReassignedSummary && !empty($reassignedSummary)): ?>
                        <div class="dashboard-summary">
                            <div class="dashboard-summary-inner">
                                <span class="dashboard-summary-title">Reassigned Summary</span>

                                <?php foreach ($reassignedSummary as $row): ?>
                                    <?php
                                    $uid = null;
                                    foreach ($allUsers as $u) {
                                        if ($u['username'] === $row['username']) {
                                            $uid = $u['id'];
                                            break;
                                        }
                                    }
                                    $filterParams = [];
                                    if ($q !== '') $filterParams['q'] = $q;
                                    if ($filter !== '') $filterParams['filter'] = $filter;
                                    if ($cycleFilter !== '') $filterParams['cycle_filter'] = $cycleFilter;
                                    if ($sortBy !== 'updated_at') $filterParams['sort'] = $sortBy;
                                    if ($sortOrder !== 'DESC') $filterParams['order'] = strtolower($sortOrder);
                                    $filterParams['owner_filter'] = $uid;
                                    $filterUrl = 'view_saved_reports.php?' . http_build_query($filterParams);
                                    ?>
                                    <a
                                        href="<?= htmlspecialchars($filterUrl) ?>"
                                        class="dashboard-summary-user"
                                        style="color:#1976d2; font-weight:600; text-decoration:none; cursor:pointer; margin:0 16px;">
                                        <?= htmlspecialchars($row['username']) ?> - <b><?= (int)$row['total'] ?></b>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- RIGHT: Reset button -->
                    <a href="view_saved_reports.php?reset=1" class="btn btn-reset">Reset Filters</a>
                </div>
                <div style="margin-bottom: 8px; font-weight:600; color:#1976d2;">
                    <?php
                    echo "Showing $totalDistinctNames client" . ($totalDistinctNames !== 1 ? "s" : "") . " for current filters.";
                    ?>
                </div>

                <?php if ($successMessage): ?>
                    <div class="alert alert-success" id="successMessage">
                        <?php echo htmlspecialchars($successMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <?php if ($deleteMode): ?>
                    <div class="alert alert-warning">
                        <strong><i class="fa-solid fa-exclamation-triangle"></i> Delete Mode Active</strong>
                        <p style="margin: 5px 0 0 0;">Select clients using checkboxes, then click "Delete Selected" to remove them.</p>
                    </div>
                <?php elseif ($reassignMode): ?>
                    <div class="alert alert-info">
                        <strong><i class="fa-solid fa-user-group"></i> Reassign Mode Active</strong>
                        <p style="margin: 5px 0 0 0;">Select clients using checkboxes, choose a user from dropdown, then click "Reassign".</p>
                    </div>
                <?php endif; ?>


                <form method="get" class="search-box" id="filterForm" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
                    <div style="position:relative; flex:1; max-width:60%;">
                        <input type="text"
                            name="q"
                            id="client-search"
                            placeholder="Search..."
                            value="<?php echo htmlspecialchars($q); ?>"
                            autocomplete="off"
                            style="width:60%; padding:10px 14px; font-size:15px; box-sizing:border-box;">

                        <div id="client-search-dropdown"
                            style="display:none;
                                position:absolute;
                                top:100%;
                                left:0;
                                width:60%;
                                background:#fff;
                                z-index:1000;
                                border:1px solid #e2e8f0;
                                border-top:none;
                                max-height:200px;
                                overflow-y:auto;
                                box-sizing:border-box;">
                        </div>
                    </div>

                    <select id="cycle-filter" name="cycle_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:140px;">
                        <option value="">All Cycles</option>
                        <?php foreach (['RJ', 'RM', 'RF'] as $c): ?>
                            <option value="<?= $c ?>" <?= $cycleFilter === $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($isAdmin): ?>
                        <select id="owner-filter" name="owner_filter"
                            style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:180px;">
                            <option value="all">All Owners / Global View</option>
                            <option value="mine" <?= ($ownerFilter === 'mine') ? 'selected' : '' ?>>My Reports</option>

                            <?php foreach ($ownerTotals as $uid => $info): ?>
                                <?php if ((int)$uid === (int)$myId) continue; ?>
                                <option value="<?= $uid ?>" <?= (string)$ownerFilter === (string)$uid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($info['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <select id="stateFilter" name="filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:160px;">
                        <option value="">All States (<?php echo $allStatesTotal; ?>)</option>
                        <option value="pending" <?= ($filter === 'pending')  ? 'selected' : '' ?>>Review Not Started (<?= $statusTotals['pending']  ?? 0 ?>)</option>
                        <option value="draft" <?= ($filter === 'draft')    ? 'selected' : '' ?>>Draft (<?= $statusTotals['draft']    ?? 0 ?>)</option>
                        <option value="ready" <?= ($filter === 'ready')    ? 'selected' : '' ?>>Ready (<?= $statusTotals['ready']    ?? 0 ?>)</option>
                        <option value="reviewed" <?= ($filter === 'reviewed') ? 'selected' : '' ?>>Reviewed (<?= $statusTotals['reviewed'] ?? 0 ?>)</option>
                        <option value="sent" <?= ($filter === 'sent')     ? 'selected' : '' ?>>Sent (<?= $statusTotals['sent']     ?? 0 ?>)</option>
                    </select>

                    <select name="sort" class="sort-dropdown">
                        <option value="updated_at" <?php echo $sortBy === 'updated_at' ? 'selected' : ''; ?>>Sort by: Last Updated</option>
                        <option value="id" <?php echo $sortBy === 'id'         ? 'selected' : ''; ?>>Sort by: ID</option>
                        <option value="priority" <?php echo $sortBy === 'priority'   ? 'selected' : ''; ?>>Sort by: Priority</option>
                        <option value="aum" <?php echo $sortBy === 'aum'        ? 'selected' : ''; ?>>Sort by: AUM</option>
                        <option value="name" <?php echo $sortBy === 'name'       ? 'selected' : ''; ?>>Sort by: Client Name</option>
                        <option value="report_state" <?php echo $sortBy === 'report_state' ? 'selected' : ''; ?>>Sort by: Status</option>
                    </select>

                    <select name="order" style="padding:8px; border:1px solid #ccc; border-radius:4px; font-size:14px;">
                        <option value="desc" <?php echo $sortOrder === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        <option value="asc" <?php echo $sortOrder === 'ASC'  ? 'selected' : ''; ?>>Ascending</option>
                    </select>
                    <select name="meeting_filter" style="padding:8px; border:1px solid #ccc; border-radius:4px; min-width:180px;">
                        <option value="" <?= $meetingFilter === '' ? 'selected' : '' ?>>
                            All Meetings
                        </option>
                        <option value="fixed" <?= $meetingFilter === 'fixed' ? 'selected' : '' ?>>
                            Meetings Fixed
                        </option>
                        <option value="not_fixed" <?= $meetingFilter === 'not_fixed' ? 'selected' : '' ?>>
                            Meetings Not Fixed
                        </option>
                    </select>
                    <input type="hidden" name="mode" value="<?php echo $deleteMode ? 'delete' : ($reassignMode ? 'reassign' : ''); ?>">


                </form>

                <?php if (!$clients): ?>
                    <p>No reports found. Use the Upload button next to a client.</p>
                <?php else: ?>

                    <!-- ── BULK ACTION FORMS ───────────────────────────── -->
                    <?php if ($deleteMode): ?>
                        <form method="post" id="bulkDeleteForm">
                            <input type="hidden" name="action_type" value="delete">
                            <div class="bulk-actions-bar">
                                <span class="bulk-selection-info">With Selected:</span>
                                <button type="button" onclick="confirmDelete()" class="delete-btn">
                                    <i class="fa-solid fa-trash"></i> Delete Selected
                                </button>
                                <span id="selectedCount" style="color: #666; font-size: 13px;">0 items selected</span>
                            </div>
                        <?php elseif ($reassignMode): ?>
                            <form method="post" id="bulkReassignForm">
                                <input type="hidden" name="action_type" value="reassign">
                                <div class="bulk-actions-bar">
                                    <span class="bulk-selection-info">With Selected:</span>
                                    <select name="new_owner_id" class="reassign-select" required>
                                        <option value="">-- Assign to... --</option>
                                        <?php foreach ($allUsers as $user): ?>
                                            <option value="<?php echo (int)$user['id']; ?>">
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="reassign-submit-btn">Reassign</button>
                                    <span id="selectedCount" style="color: #666; font-size: 13px;">0 items selected</span>
                                </div>
                            <?php else: ?>
                                <!-- No bulk-action form needed in normal view -->
                            <?php endif; ?>

                            <!-- ── MAIN TABLE inside scroll wrapper ───────────── -->
                            <div class="table-scroll-wrapper">
                                <table>
                                    <thead>

                                        <tr class="group-header">
                                            <?php
                                            // Calculate base columns count dynamically
                                            $baseColCount = 6; // ID, Client Name, AUM, Last Updated, Status = 5
                                            if ($isAdmin) $baseColCount += 3; // Add 3 admin columns (Drafted By, RM, Review Assigned to)
                                            if ($deleteMode || $reassignMode) $baseColCount++; // Add checkbox column

                                            // Current Review columns: SIP, Review Sent, Mtg Date, Modifications/Action, Meeting Status, Meeting Comments = 6
                                            $currentReviewCols = 6;

                                            // Last Review columns: Prev SIP, Last Review, Last Meeting, Prev Modifications, Prev Mtg Comments = 5
                                            $lastReviewCols = 6;

                                            // Action and View Prev Review columns = 2
                                            $actionCols = 2;
                                            ?>
                                            <th colspan="<?= $baseColCount ?>" style="background:#f9f9f9; border:none;"></th>
                                            <th colspan="<?= $currentReviewCols ?>" class="th-section-current">Current Review</th>
                                            <th colspan="<?= $lastReviewCols ?>" class="th-section-prev">Last Review</th>
                                            <th colspan="<?= $actionCols ?>" style="background:#f9f9f9; border:none;"></th>
                                        </tr>

                                        <tr class="col-header">
                                            <!-- checkbox / empty -->
                                            <?php if ($deleteMode || $reassignMode): ?>
                                                <th style="width: 40px;" class="select-all-cell">
                                                    <input type="checkbox" id="selectAllCheckbox" class="action-checkbox" onclick="toggleSelectAll(this)">
                                                    <span class="select-all-label">All</span>
                                                </th>
                                            <?php else: ?>
                                                <th style="width: 40px;" class="action-icon-cell"></th>
                                            <?php endif; ?>

                                            <!-- Base columns -->
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=id&order=<?= ($sortBy === 'id' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    ID <?= $sortBy === 'id' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=name&order=<?= ($sortBy === 'name' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    Client Name <?= $sortBy === 'name' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=aum&order=<?= ($sortBy === 'aum' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    AUM<?= $sortBy === 'aum' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>

                                            <!-- Admin-only columns -->
                                            <?php if ($isAdmin): ?>
                                                <!-- <th>Drafted By</th> -->
                                                <th>RM</th>
                                                <th>Review Assigned to</th>
                                            <?php endif; ?>

                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=updated_at&order=<?= ($sortBy === 'updated_at' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    Last Updated <?= $sortBy === 'updated_at' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?<?= $deleteMode ? 'mode=delete&' : ($reassignMode ? 'mode=reassign&' : '') ?>sort=report_state&order=<?= ($sortBy === 'report_state' && $sortOrder === 'DESC') ? 'asc' : 'desc' ?><?= $q ? '&q=' . urlencode($q) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $ownerFilter ? '&owner_filter=' . urlencode($ownerFilter) : '' ?><?= $cycleFilter ? '&cycle_filter=' . urlencode($cycleFilter) : '' ?><?= $meetingFilter ? '&meeting_filter=' . urlencode($meetingFilter) : '' ?>" style="color:#333;text-decoration:none;display:flex;align-items:center;gap:4px;">
                                                    Status <?= $sortBy === 'report_state' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                                                </a>
                                            </th>

                                            <!-- CURRENT REVIEW columns -->
                                            <th class="col-current">SIP</th>
                                            <th class="col-current">Review Sent</th>
                                            <th class="col-current">Mtg Date</th>
                                            <th class="col-current">Modifications / Action</th>
                                            <th style="text-align:center; width:120px;">Meeting Status</th>
                                            <th style="text-align:center; width:140px;">Meeting Comments</th>

                                            <!-- LAST REVIEW columns (read-only) -->
                                            <th class="col-prev">Prev SIP</th>
                                            <th class="col-prev">Last Review</th>
                                            <th class="col-prev">Last Meeting</th>
                                            <th class="col-prev">Prev Modifications</th>
                                            <th class="col-prev">Prev Mtg Comments</th>

                                            <!-- Previous Review HTML view -->
                                            <th class="col-prev-review" style="text-align:center; min-width:110px;">View Prev Review</th>

                                            <!-- Action -->
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clients as $c): ?>
                                            <?php
                                            $clientAttachDir = __DIR__ . '/uploads/attachments/client_' . (int)$c['id'];
                                            $hasAttachments = is_dir($clientAttachDir) && count(glob($clientAttachDir . '/*')) > 0;

                                            // Determine if this row is sent (locked)
                                            $isSent = (($c['report_state'] ?? '') === 'sent');
                                            $rowClass = '';

                                            // Effective display values (stored takes priority, computed is fallback)
                                            $displaySip = $c['sip_amount_lakhs'] ?? '';
                                            $displayMod = $c['modifications_action'] ?? '';
                                            ?>
                                            <tr class="<?= $rowClass ?>" data-client-id="<?= (int)$c['id'] ?>" data-client-name="<?= htmlspecialchars($c['name']) ?>">
                                                <!-- Checkbox / empty -->
                                                <?php if ($deleteMode || $reassignMode): ?>
                                                    <td>
                                                        <input type="checkbox"
                                                            class="action-checkbox client-checkbox"
                                                            name="selected_ids[]"
                                                            value="<?php echo (int)$c['id']; ?>"
                                                            onchange="updateSelectedCount()">
                                                    </td>
                                                <?php else: ?>
                                                    <td class="action-icon-cell"></td>
                                                <?php endif; ?>

                                                <!-- ID -->
                                                <td><?php echo (int)$c['id']; ?></td>

                                                <!-- Client Name -->
                                                <td>
                                                    <div style="font-weight:600; color:#333; display:flex; align-items:center; gap:8px;">
                                                        <span><?php echo htmlspecialchars($c['name']); ?></span>
                                                        <?php if ($hasAttachments): ?>
                                                            <span title="Has Attachments">📎</span>
                                                        <?php endif; ?>

                                                    </div>
                                                </td>

                                                <!-- AUM -->
                                                <!-- AUM -->
                                                <td>
                                                    <span style="font-weight:600; color:#1976d2;">
                                                        <?php
                                                        $aumValue = (float)($c['aum'] ?? 0);
                                                        if ($aumValue < 1) {
                                                            // Convert to lakhs (1 crore = 100 lakhs)
                                                            $lakhsValue = $aumValue * 100;
                                                            echo '₹' . number_format($lakhsValue, 2) . ' L';
                                                        } else {
                                                            echo '₹' . number_format($aumValue, 2) . ' Cr';
                                                        }
                                                        ?>
                                                    </span>
                                                </td>

                                                <!-- Drafted By -->
                                                <?php if ($isAdmin): ?>
                                                    <!-- <td>
                                                        <?php $currState = strtolower($c['report_state'] ?? 'draft'); ?>
                                                        <?php if ($currState === 'pending'): ?>
                                                            <span style="color:#999; font-size:0.85em; font-weight:600;">Not Drafted</span>
                                                        <?php else: ?>
                                                            <?php if (!empty($c['created_by_username'])): ?>
                                                                <span class="badge" style="background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:700;">
                                                                    <?php echo htmlspecialchars($c['created_by_username']); ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span style="color:#999; font-size:0.85em;">System</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td> -->

                                                    <!-- RM -->
                                                    <td>
                                                        <span style="color:#333; font-weight:600;">
                                                            <?php echo !empty($c['rm_username']) ? htmlspecialchars($c['rm_username']) : '—'; ?>
                                                        </span>
                                                    </td>

                                                    <!-- Reviewer -->
                                                    <td>
                                                        <?php
                                                        $isReviewer = ((int)($c['review_assigned_to'] ?? 0) === $myId);
                                                        $reviewerStyle = 'background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:700;';
                                                        if ($isReviewer) $reviewerStyle .= ' font-weight:800; border-color:#1565c0;';
                                                        ?>
                                                        <?php if (!empty($c['reviewer_username'])): ?>
                                                            <span class="badge" style="<?php echo $reviewerStyle; ?>">
                                                                <?php echo htmlspecialchars($c['reviewer_username']); ?>
                                                                <?php if ($isReviewer): ?><span style="margin-left:6px; color:#0d47a1; font-weight:800;">You</span><?php endif; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color:#999; font-size:0.85em;">Unassigned</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>


                                                <!-- Last Updated -->
                                                <!-- Last Updated -->
                                                <td style="white-space:nowrap; font-size:12px; line-height:1.5;">
                                                    <?php
                                                    if ($isSent) {
                                                        $displayTs = !empty($c['created_at']) ? strtotime($c['created_at']) : 0;
                                                    } else {
                                                        $tsUpdated = !empty($c['updated_at']) ? strtotime($c['updated_at']) : 0;
                                                        $tsCreated = !empty($c['created_at']) ? strtotime($c['created_at']) : 0;
                                                        $displayTs = ($tsUpdated > $tsCreated) ? $tsUpdated : $tsCreated;
                                                    }
                                                    ?>
                                                    <?php if ($displayTs): ?>
                                                        <?= date('d-M-Y', $displayTs) ?>
                                                        <br><span style="color:#999; font-size:11px;"><?= date('h:i A', $displayTs) ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#999;">N/A</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Status badge -->
                                                <?php
                                                $state = $c['report_state'] ?? 'draft';
                                                $statusMap = [
                                                    'pending'  => '<span class="badge badge-grey">Pending</span>',
                                                    'draft'    => '<span class="badge badge-yellow">Draft</span>',
                                                    'ready'    => '<span class="badge badge-blue">Ready</span>',
                                                    'reviewed' => '<span class="badge badge-purple">Reviewed</span>',
                                                    'sent'     => '<span class="badge badge-green">Sent</span>',
                                                ];
                                                $statusHtml = $statusMap[$state] ?? '<span class="badge badge-grey">Unknown</span>';
                                                ?>
                                                <td><?php echo $statusHtml; ?></td>

                                                <!-- SIP (Lakhs) — auto-filled from goals total -->
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="sip_amount_lakhs" data-type="number">
                                                    <span class="display-val <?= (empty($displaySip) || (float)$displaySip == 0) ? 'placeholder-text' : '' ?>">
                                                        <?= (!empty($displaySip) && (float)$displaySip > 0) ? number_format((float)$displaySip, 2) . ' Lakh' : 'click to edit' ?>
                                                    </span>
                                                    <input type="number" step="0.01" value="<?= htmlspecialchars($displaySip ?? '') ?>">
                                                </td>


                                                <!-- Review Sent Date — read-only, same format as Last Review column -->
                                                <td class="col-current" style="background-color:#f9f9f9; white-space:nowrap; font-size:12px; line-height:1.5;">
                                                    <?php
                                                    if (!empty($c['review_sent_date'])) {
                                                        $ts = strtotime($c['review_sent_date']);
                                                        echo date('d-M-Y', $ts);
                                                        echo '<br><span style="color:#999;font-size:11px;">';
                                                        // Use created_at time if sent, else updated_at
                                                        $timeSrc = !empty($c['review_sent_date']) ? ($c['updated_at'] ?? $c['created_at']) : $c['created_at'];
                                                        echo date('h:i A', strtotime($timeSrc));
                                                        echo '</span>';
                                                    } else {
                                                        echo '—';
                                                    }
                                                    ?>
                                                </td><!-- Meeting Date -->
                                                <td class="col-current editable-cell" data-client="<?= $c['id'] ?>" data-field="meeting_date" data-type="date">
                                                    <span class="display-val <?= empty($c['meeting_date']) ? 'placeholder-text' : '' ?>">
                                                        <?= fmtDate($c['meeting_date'], 'd-M') ?>
                                                    </span>
                                                    <input type="date" value="<?= htmlspecialchars($c['meeting_date'] ?? '') ?>">
                                                </td>

                                                <!-- Modifications / Action — shows only non-Continue actions with clickable modal -->
                                                <!-- Modifications / Action — shows only non-Continue actions with clickable modal -->
                                                <td class="col-current clickable-cell" style="min-width: 180px; max-width: 400px; font-size:12px; cursor:pointer; white-space: normal; word-wrap: break-word;"
                                                    onclick="openSchemeModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($displayMod)) ?>')">
                                                    <?php if (!empty($displayMod)): ?>
                                                        <?= htmlspecialchars(extractActionsOnly($displayMod)) ?>
                                                        <span style="color:#0288D1; font-size:10px; margin-left:5px;"></span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Meeting Status dropdown -->
                                                <td style="text-align:center;">
                                                    <select
                                                        onchange="handleListMeetingChange(this, <?php echo $c['id']; ?>)"
                                                        class="meet-select"
                                                        id="meet_select_<?php echo $c['id']; ?>">
                                                        <option value="pending" <?php echo ($c['meeting_status'] === 'pending') ? 'selected' : ''; ?>>⏳ Pending</option>
                                                        <option value="yes" <?php echo ($c['meeting_status'] === 'yes')     ? 'selected' : ''; ?>>✅ Yes</option>
                                                        <option value="no" <?php echo ($c['meeting_status'] === 'no')      ? 'selected' : ''; ?>>❌ No</option>
                                                    </select>
                                                </td>

                                                <td style="text-align:center; height:auto;">

                                                    <button type="button"
                                                        id="meet_btn_<?php echo $c['id']; ?>"
                                                        class="meet-btn"
                                                        onclick="openListMeetingModal(<?php echo $c['id']; ?>)"
                                                        style="display: <?php echo ($c['meeting_status'] !== 'pending') ? 'inline-block' : 'none'; ?>;">
                                                        Comments <?php echo !empty($c['meeting_remarks']) ? '(Edit)' : '(Add)'; ?>
                                                    </button>

                                                    <?php if (!empty($c['meeting_remarks'])): ?>
                                                        <div style="margin-top:4px; font-size:12px; color:#555;"
                                                            title="<?= htmlspecialchars($c['meeting_remarks']) ?>">
                                                            <?= htmlspecialchars(mb_strimwidth($c['meeting_remarks'], 0, 40, '…')) ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div style="margin-top:4px; color:#ccc; font-size:12px;">—</div>
                                                    <?php endif; ?>

                                                    <input type="hidden"
                                                        id="remarks_store_<?php echo $c['id']; ?>"
                                                        value="<?php echo htmlspecialchars($c['meeting_remarks'] ?? ''); ?>">

                                                </td>



                                                <!-- ════════════════════════════════════════
                                        LAST REVIEW — read-only, from previous record
                                        ════════════════════════════════════════ -->
                                                <td class="col-prev" style="text-align:right; font-size:12px;">
                                                    <?php
                                                    $prevSip = $c['prev_sip_amount_lakhs'] ?? null;
                                                    echo (!empty($prevSip) && (float)$prevSip > 0)
                                                        ? number_format((float)$prevSip, 2) . ' L'
                                                        : '<span style="color:#ccc;">—</span>';
                                                    ?>
                                                </td>
                                                <td class="col-prev" style="white-space:nowrap; font-size:12px; line-height:1.5;">
                                                    <?= fmtDateTime($c['last_review_date'] ?? null) ?>
                                                </td>


                                                <td class="col-prev" style="white-space:nowrap; font-size:12px; line-height:1.5;">
                                                    <?= fmtDateTime($c['last_meeting_date'] ?? null) ?>
                                                </td>

                                                <!-- Prev Modifications — opens read-only scheme-style modal -->
                                                <td class="col-prev clickable-cell" style="min-width:180px; max-width:400px; font-size:12px; cursor:pointer; white-space:normal; word-wrap:break-word;"
                                                    onclick="openPrevModificationsModal(<?= (int)$c['id'] ?>)">
                                                    <?php $prevMod = $c['prev_modifications_action'] ?? ''; ?>
                                                    <?php if ($prevMod): ?>
                                                        <?= htmlspecialchars(extractActionsOnly($prevMod)) ?>
                                                        <span style="color:#0288D1; font-size:10px; margin-left:5px;">🔍</span>
                                                    <?php else: ?>
                                                        <span style="color:#ccc;">—</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Prev Mtg Comments — same styling as current Meeting Comments cell -->
                                                <!-- Prev Mtg Comments — compact preview matching current Meeting Comments style -->
                                                <td class="col-prev" style="text-align:center; vertical-align:top; padding:8px;">
                                                    <?php $prevCmt = $c['prev_meeting_comments'] ?? ''; ?>
                                                    <?php if ($prevCmt): ?>
                                                        <button type="button"
                                                            class="meet-btn"
                                                            onclick="openMeetingHistoryModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                                                            View History
                                                        </button>
                                                        <div style="margin-top:4px; font-size:12px; color:#555; text-align:left;"
                                                            title="<?= htmlspecialchars($prevCmt) ?>">
                                                            <?= htmlspecialchars(mb_strimwidth($prevCmt, 0, 40, '…')) ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div style="margin-top:4px; color:#ccc; font-size:12px;">—</div>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- View Previous Review -->
                                                <td class="col-prev-review">
                                                    <?php
                                                    $prevId    = (int)($c['previous_version_id'] ?? 0);
                                                    $prevState = $c['prev_version_state'] ?? '';
                                                    $hasPrev   = $prevId > 0 && $prevState !== '' && $prevState !== 'pending';
                                                    ?>
                                                    <?php if ($hasPrev): ?>
                                                        <a href="view_report.php?id=<?= $prevId ?>"
                                                            target="_blank"
                                                            class="btn-prev-review"
                                                            title="Open previous review report (ID <?= $prevId ?>)">
                                                            <i class="fa-solid fa-eye"></i> View
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="btn-prev-review no-data" title="No previous review available">
                                                            <i class="fa-solid fa-eye-slash"></i> None
                                                        </span>
                                                    <?php endif; ?>
                                                </td>



                                                <!-- Action links -->
                                                <td>
                                                    <?php
                                                    $hasReport = ($c['report_state'] !== 'pending');
                                                    $isUploadAllowed = !$hasReport && isset($c['review_cycle']) && $c['review_cycle'] === $systemCurrentCycle;
                                                    ?>

                                                    <?php if ($hasReport): ?>
                                                        <a href="view_report.php?id=<?= (int)$c['id']; ?>" class="action-link open-link">Open</a>
                                                    <?php endif; ?>

                                                    <?php if ($isUploadAllowed): ?>
                                                        <button type="button" class="action-link upload-link" onclick="triggerUpload(<?= (int)$c['id']; ?>)">Upload</button>

                                                        <form id="uploadForm_<?= (int)$c['id']; ?>" method="post" enctype="multipart/form-data" style="display:none;">
                                                            <input type="hidden" name="expected_client_id" value="<?= (int)$c['id']; ?>">
                                                            <input type="hidden" name="expected_client_name" value="<?= htmlspecialchars($c['name']); ?>">
                                                            <input type="hidden" name="review_cycle" value="<?= htmlspecialchars($c['review_cycle']); ?>">
                                                            <input type="file" name="client_files[]" multiple onchange="submitUpload(<?= (int)$c['id']; ?>)">
                                                        </form>
                                                    <?php endif; ?>
                                                </td>

                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div><!-- /table-scroll-wrapper -->

                            <?php if ($deleteMode || $reassignMode): ?>
                            </form>
                        <?php endif; ?>

                        <div class="pagination">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>:
                            <?php
                            for ($p = 1; $p <= $totalPages; $p++) {
                                if ($p == $page) {
                                    echo "<strong>{$p}</strong> ";
                                } else {
                                    $params = ['page' => $p];
                                    if ($deleteMode)   $params['mode'] = 'delete';
                                    if ($reassignMode) $params['mode'] = 'reassign';
                                    if ($q !== '')         $params['q']            = $q;
                                    if ($filter !== '')    $params['filter']       = $filter;
                                    if ($ownerFilter !== '') $params['owner_filter'] = $ownerFilter;
                                    if ($cycleFilter !== '') $params['cycle_filter'] = $cycleFilter;
                                    if ($meetingFilter !== '') $params['meeting_filter'] = $meetingFilter;
                                    if ($sortBy !== 'updated_at') $params['sort'] = $sortBy;
                                    if ($sortOrder !== 'DESC')    $params['order'] = strtolower($sortOrder);
                                    $url = 'view_saved_reports.php?' . http_build_query($params);
                                    echo "<a href=\"{$url}\">{$p}</a> ";
                                }
                            }
                            ?>
                        </div>
                    <?php endif; ?>
            </div><!-- /container -->

            <!-- ────────────── Meeting Remarks Modal ────────────── -->
            <div id="listMeetingModal" class="modal-overlay" style="display:none;">
                <div class="modal-card">
                    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
    
    <div style="display:flex; align-items:center; gap:10px;">
        <div class="modal-icon">📝</div>
        <div>
            <h3 style="margin:0;">Meeting Comments</h3>
            <p style="margin:0; font-size:12px; color:#666;">Enter details about the discussion</p>
        </div>
    </div>

    <!-- ❌ Close Button -->
    <button type="button" 
            onclick="closeListMeetingModal()" 
            style="background:none; border:none; font-size:20px; cursor:pointer; color:#999;">
        &times;
    </button>

</div>
                    <input type="hidden" id="current_modal_client_id">
                    <textarea id="listModalRemarks"
                        placeholder="e.g., Client agreed to increase SIP, follow-up next month..."
                        style="width:100%; height:auto; min-height:80px; resize:none; box-sizing:border-box; overflow:hidden; border:1px solid #ddd; border-radius:6px; padding:10px; font-size:14px; line-height:1.6; font-family:inherit;"></textarea>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-reset" onclick="closeListMeetingModal()">Cancel</button>
                        <button type="button" class="btn btn-search" onclick="saveListMeetingRemarks()">Save Comments</button>
                    </div>
                </div>
            </div>

            <!-- ────────────── Scheme Selection Modal ────────────── -->
            <div id="schemeModal" class="scheme-modal-overlay" style="display:none;">
                <div class="scheme-modal-card">
                    <div class="modal-header">
                        <h3>Select Scheme Changes</h3>
                        <button class="close-modal" onclick="closeSchemeModal()">&times;</button>
                    </div>
                    <div id="schemeModalContent">
                        <!-- Content loaded dynamically -->
                        <div style="text-align:center; padding:20px;">Loading schemes...</div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeSchemeModal()">Cancel</button>
                        <button type="button" class="btn-primary" onclick="saveSchemeSelections()">Save Selections</button>
                    </div>
                </div>
            </div>

            <!-- ────────────── Meeting History Modal ────────────── -->
            <div id="meetingHistoryModal" class="history-modal-overlay" style="display:none;">
                <div class="history-modal-card">
                    <div class="modal-header">
                        <h3>Meeting Comments History</h3>
                        <button class="close-modal" onclick="closeMeetingHistoryModal()">&times;</button>
                    </div>
                    <div id="meetingHistoryContent">
                        <!-- Content loaded dynamically -->
                        <div style="text-align:center; padding:20px;">Loading history...</div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeMeetingHistoryModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- ────────────── Modifications History Modal ────────────── -->
            <div id="modificationsHistoryModal" class="history-modal-overlay" style="display:none;">
                <div class="history-modal-card">
                    <div class="modal-header">
                        <h3>Previous Modifications / Actions</h3>
                        <button class="close-modal" onclick="closeModificationsHistoryModal()">&times;</button>
                    </div>
                    <div id="modificationsHistoryContent" style="padding:20px;">
                        <!-- Content set dynamically -->
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeModificationsHistoryModal()">Close</button>
                    </div>
                </div>
            </div>

        </div><!-- /main-scroll-container -->

    </body>

    </html>