// ── INLINE EDIT FUNCTIONS ─────────────────────────────────────────────
function showToast(msg) {
    const t = document.getElementById('saveToast');
    t.textContent = msg || '✓ Saved';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2200);
}

function saveField(cell, clientId, field, value) {
    const input = cell.querySelector('input, textarea');
    const displayVal = cell.querySelector('.display-val');

    fetch('view_saved_reports.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'save_review_fields',
            client_id: clientId,
            field: field,
            value: value
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let displayText = value;
            
            if (field === 'sip_amount_lakhs' && value !== '') {
                displayText = parseFloat(value).toFixed(2) + ' Lakh';
            } else if ((field === 'review_sent_date' || field === 'meeting_date') && value) {
                try {
                    const date = new Date(value);
                    if (!isNaN(date.getTime())) {
                        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        const hours = date.getHours();
                        const minutes = date.getMinutes().toString().padStart(2, '0');
                        const ampm = hours >= 12 ? 'PM' : 'AM';
                        const h12 = ((hours % 12) || 12);
                        displayText = date.getDate() + '-' + months[date.getMonth()] + '-' + date.getFullYear() + 
                                    '\n' + h12 + ':' + minutes + ' ' + ampm;
                    } else {
                        displayText = value;
                    }
                } catch (e) {
                    displayText = value;
                }
            }
            
            if (displayVal) {
                displayVal.textContent = displayText || '—';
                displayVal.classList.toggle('placeholder-text', !displayText || displayText === '—');
            }
            showToast('✓ Saved');
        } else {
            alert('Save failed: ' + (data.error || 'Unknown error'));
        }
        cell.classList.remove('editing');
    })
    .catch(() => {
        alert('Network error while saving.');
        cell.classList.remove('editing');
    });
}

// ── SCHEME MODAL FUNCTIONS ─────────────────────────────────
let currentClientId = null;

function openSchemeModal(clientId, currentModText) {
    currentClientId = clientId;

    const modal = document.getElementById('schemeModal');
    const content = document.getElementById('schemeModalContent');

    // Show loader with animation
    content.innerHTML = `
        <div style="text-align:center; padding:40px;">
            <div style="display:inline-block; width:30px; height:30px; border:3px solid #f3f3f3; border-top:3px solid #0288D1; border-radius:50%; animation: spin 1s linear infinite;"></div>
            <p style="margin-top:15px; color:#666;">Loading scheme changes...</p>
        </div>
        <style>
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    `;
    modal.style.display = 'flex';

    fetch('view_saved_reports.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'fetch_scheme_changes',
            client_id: clientId
        })
    })
    .then(res => {
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        return res.json();
    })
    .then(data => {
        if (!data.success) {
            throw new Error(data.error || 'Failed to load schemes');
        }

        if (!data.schemes || data.schemes.length === 0) {
            content.innerHTML = `
                <div class="no-schemes-message" style="text-align:center; padding:40px; color:#666;">
                    <i class="fa-solid fa-inbox" style="font-size:48px; margin-bottom:15px; opacity:0.5;"></i>
                    <p>No scheme changes found for this client.</p>
                    <p style="font-size:12px; margin-top:10px;">Scheme changes appear when goals are updated in the report.</p>
                </div>
            `;
            return;
        }

        const completedIds = Array.isArray(data.completed_ids) ?
            data.completed_ids.map(id => parseInt(id, 10)) : [];

        renderSchemeModal(data.schemes, completedIds);
    })
    .catch((error) => {
        console.error('Fetch error:', error);
        content.innerHTML = `
            <div style="text-align:center; padding:40px; color:#d32f2f;">
                <i class="fa-solid fa-circle-exclamation" style="font-size:48px; margin-bottom:15px;"></i>
                <p>Error loading scheme changes</p>
                <p style="font-size:12px; margin-top:10px;">${error.message}</p>
                <button onclick="closeSchemeModal()" style="margin-top:15px; padding:8px 20px; background:#0288D1; color:white; border:none; border-radius:4px; cursor:pointer;">Close</button>
            </div>
        `;
    });
}

function renderSchemeModal(schemes, completedIds) {
    let html = '<div class="scheme-list">';
    schemes.forEach(scheme => {
        const actionClass = scheme.action_step.toLowerCase().replace(/\s+/g, '-');
        const isChecked = completedIds.includes(parseInt(scheme.id));

        html += '<div class="scheme-item">';
        html += `<input type="checkbox" class="scheme-checkbox" data-scheme-id="${scheme.id}" value="${escapeHtml(scheme.scheme_name)}-${escapeHtml(scheme.action_step)}" ${isChecked ? 'checked' : ''}>`;
        html += '<div class="scheme-details">';
        html += `<span class="scheme-name">${escapeHtml(scheme.scheme_name)}</span>`;
        html += `<span class="scheme-action ${actionClass}">${escapeHtml(scheme.action_step)}</span>`;
        if (scheme.recommended_scheme || scheme.recommended_amount) {
            html += `<span class="scheme-recommended">→ ${escapeHtml(scheme.recommended_scheme || '')} ${scheme.recommended_amount ? '(' + escapeHtml(scheme.recommended_amount) + ')' : ''}</span>`;
        }
        html += '</div></div>';
    });
    html += '</div>';
    document.getElementById('schemeModalContent').innerHTML = html;
}

function saveSchemeSelections() {
    const checkboxes = document.querySelectorAll('.scheme-checkbox:checked');
    const formData = new URLSearchParams();
    formData.append('action', 'save_scheme_selections');
    formData.append('client_id', currentClientId);

    checkboxes.forEach(cb => {
        formData.append('selected_ids[]', cb.getAttribute('data-scheme-id'));
    });

    fetch('view_saved_reports.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.querySelector(`tr[data-client-id="${currentClientId}"]`);
            if (row) {
                const modCell = row.querySelector('td.col-current.clickable-cell');
                if (modCell && data.modifications_action) {
                    const actionsOnly = extractActionsOnly(data.modifications_action);
                    modCell.innerHTML = actionsOnly;
                    modCell.setAttribute('onclick', `openSchemeModal(${currentClientId})`);
                }
            }
            closeSchemeModal();
            showToast('Scheme selections saved!');
        } else {
            alert('Save failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Network error:', err);
        alert('Network error while saving selections');
    });
}

function closeSchemeModal() {
    document.getElementById('schemeModal').style.display = 'none';
    currentClientId = null;
}

// Helper function to extract only action parts
function extractActionsOnly(modificationsText) {
    if (!modificationsText) return '';
    return modificationsText.split(' | ')
        .map(item => {
            const parts = item.split('-');
            return parts[parts.length - 1].trim();
        })
        .join(' | ');
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ── MEETING HISTORY MODAL ─────────────────────────────────
function openMeetingHistoryModal(clientId, clientName) {
    const modal = document.getElementById('meetingHistoryModal');
    const content = document.getElementById('meetingHistoryContent');
    content.innerHTML = '<div style="text-align:center; padding:20px;">Loading meeting history...</div>';
    modal.style.display = 'flex';

    fetch('view_saved_reports.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'fetch_meeting_history',
            client_name: clientName,
            current_id: clientId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.history && data.history.length > 0) {
            let html = '';
            data.history.forEach(item => {
                const date = new Date(item.created_at).toLocaleDateString('en-IN', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                html += '<div class="history-item">';
                html += `<div class="date">${date} (Review ID: ${item.id})</div>`;
                html += `<div class="comments">${escapeHtml(item.meeting_comments)}</div>`;
                html += '</div>';
            });
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div style="text-align:center; padding:20px; color:#666;">No meeting history found for this client.</div>';
        }
    })
    .catch(err => {
        console.error('Error loading meeting history:', err);
        content.innerHTML = '<div style="text-align:center; padding:20px; color:red;">Error loading meeting history</div>';
    });
}

function closeMeetingHistoryModal() {
    document.getElementById('meetingHistoryModal').style.display = 'none';
}

// ── PREVIOUS MODIFICATIONS MODAL ─────────────────────────────────
function openPrevModificationsModal(clientId) {
    const modal = document.getElementById('modificationsHistoryModal');
    const content = document.getElementById('modificationsHistoryContent');

    content.innerHTML = '<div style="text-align:center; padding:20px;">Loading previous modifications...</div>';
    modal.style.display = 'flex';

    const actionColors = {
        'drop': '#f44336',
        'switch': '#9c27b0',
        'sip cancellation': '#ff5722',
        'under observation': '#607d8b',
        'partially redeem': '#795548',
    };

    fetch('view_saved_reports.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'fetch_prev_scheme_changes',
            client_id: clientId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            content.innerHTML = '<div class="no-schemes-message">Error loading previous modifications.</div>';
            return;
        }
        if (!data.schemes || data.schemes.length === 0) {
            content.innerHTML = '<div class="no-schemes-message">No previous modifications found.</div>';
            return;
        }

        const completedIds = Array.isArray(data.completed_ids) ?
            data.completed_ids.map(id => parseInt(id, 10)) : [];

        let html = '<div class="scheme-list">';
        data.schemes.forEach(scheme => {
            const actionStep = scheme.action_step || '';
            const actionClass = actionStep.toLowerCase().replace(/\s+/g, '-');
            const actionColor = actionColors[actionStep.toLowerCase()] || '#ff9800';
            const isChecked = completedIds.includes(parseInt(scheme.id));

            html += `<div class="scheme-item" style="${isChecked ? 'background:#e3f2fd;' : ''}">`;
            html += `<input type="checkbox" class="scheme-checkbox" disabled ${isChecked ? 'checked' : ''} style="cursor:not-allowed; accent-color:#0288D1;">`;
            html += '<div class="scheme-details">';
            html += `<span class="scheme-name">${escapeHtml(scheme.scheme_name)}</span>`;
            html += `<span class="scheme-action ${actionClass}" style="background:${actionColor};">${escapeHtml(actionStep)}</span>`;
            if (scheme.recommended_scheme || scheme.recommended_amount) {
                html += `<span class="scheme-recommended">→ ${escapeHtml(scheme.recommended_scheme || '')} ${scheme.recommended_amount ? '(' + escapeHtml(scheme.recommended_amount) + ')' : ''}</span>`;
            }
            html += '</div></div>';
        });
        html += '</div>';
        html += '<div style="margin-top:10px; font-size:12px; color:#999; text-align:right;">🔒 Read-only — previous review data</div>';
        content.innerHTML = html;
    })
    .catch(() => {
        content.innerHTML = '<div style="text-align:center; padding:20px; color:red;">Network error loading previous modifications.</div>';
    });
}

function closeModificationsHistoryModal() {
    document.getElementById('modificationsHistoryModal').style.display = 'none';
}

// ── MEETING STATUS FUNCTIONS ───────────────────────────────────────────
function handleListMeetingChange(select, clientId) {
    const status = select.value;
    const remarksBtn = document.getElementById('meet_btn_' + clientId);
    const storedRemarks = document.getElementById('remarks_store_' + clientId).value;

    if (status === 'yes') {
        openListMeetingModal(clientId);
        if (remarksBtn) remarksBtn.style.display = 'inline-flex';
    } else {
        saveMeetingData(clientId, status, storedRemarks, false);
        if (remarksBtn) remarksBtn.style.display = (status === 'pending') ? 'none' : 'inline-flex';
    }
}

function openListMeetingModal(clientId) {
    const remarks = document.getElementById('remarks_store_' + clientId).value;
    document.getElementById('current_modal_client_id').value = clientId;

    const textarea = document.getElementById('listModalRemarks');
    textarea.value = remarks;
    
    document.getElementById('listMeetingModal').style.display = 'flex';
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
    textarea.focus();
}

function closeListMeetingModal() {
    document.getElementById('listMeetingModal').style.display = 'none';
}

function saveListMeetingRemarks() {
    const clientId = document.getElementById('current_modal_client_id').value;
    const remarks = document.getElementById('listModalRemarks').value;
    const select = document.getElementById('meet_select_' + clientId);
    const status = select ? select.value : 'yes';
    saveMeetingData(clientId, status, remarks, true);
}

function saveMeetingData(clientId, status, remarks, isModal) {
    const formData = new URLSearchParams();
    formData.append('action', 'save_meeting_status');
    formData.append('client_id', clientId);
    formData.append('status', status);
    formData.append('remarks', remarks);

    fetch('meeting_tracker.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const store = document.getElementById('remarks_store_' + clientId);
            if (store) store.value = remarks;
            
            if (isModal) {
                closeListMeetingModal();
                showToast("Meeting remarks saved!");
            }
        } else {
            alert("Error: " + data.error);
        }
    })
    .catch(() => {
        alert("Network error saving meeting data");
    });
}

// ── BULK ACTIONS ────────────────────────────────────────────
function toggleSelectAll(checkbox) {
    document.querySelectorAll('.client-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.client-checkbox');
    const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    const elem = document.getElementById('selectedCount');
    if (elem) elem.textContent = selectedCount + ' item' + (selectedCount !== 1 ? 's' : '') + ' selected';

    const selectAll = document.getElementById('selectAllCheckbox');
    if (selectAll) {
        selectAll.checked = selectedCount > 0 && Array.from(checkboxes).every(c => c.checked);
        selectAll.indeterminate = Array.from(checkboxes).some(c => c.checked) && !selectAll.checked;
    }
}

function confirmDelete() {
    const selectedCount = Array.from(document.querySelectorAll('.client-checkbox')).filter(cb => cb.checked).length;
    if (selectedCount === 0) {
        alert('Please select at least one client to delete.');
        return;
    }
    if (confirm('Delete ' + selectedCount + ' selected client(s)? This cannot be undone.')) {
        document.getElementById('bulkDeleteForm').submit();
    }
}

// ── UPLOAD ──────────────────────────────────────────────────
function triggerUpload(clientId) {
    const form = document.getElementById('uploadForm_' + clientId);
    if (form) {
        form.querySelector('input[type="file"]').click();
    }
}

function submitUpload(clientId) {
    const form = document.getElementById('uploadForm_' + clientId);
    const fileInput = form.querySelector('input[type="file"]');
    if (fileInput && fileInput.files.length) {
        form.submit();
    }
}

// ── CLIENT SEARCH AUTOCOMPLETE ───────────────────────────────
function selectClientName(name) {
    document.getElementById('client-search').value = name;
    document.getElementById('client-search-dropdown').style.display = 'none';
    document.getElementById('filterForm').submit();
}

// ── RECOMPUTE AUTO FIELDS ─────────────────────────────────
window.recomputeAutoFields = function(clientId) {
    fetch('view_saved_reports.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'recompute_auto_fields',
            client_id: clientId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.querySelector(`tr[data-client-id="${clientId}"]`);
            if (row && data.sip_amount_lakhs) {
                const sipCell = row.querySelector('.editable-cell[data-field="sip_amount_lakhs"]');
                if (sipCell) {
                    const dv = sipCell.querySelector('.display-val');
                    const inp = sipCell.querySelector('input');
                    if (dv) {
                        dv.textContent = parseFloat(data.sip_amount_lakhs).toFixed(2) + ' Lakh';
                        dv.classList.remove('placeholder-text');
                    }
                    if (inp) inp.value = data.sip_amount_lakhs;
                }
            }
            if (row && data.modifications_action) {
                const modCell = row.querySelector('.clickable-cell');
                if (modCell) {
                    const actionsOnly = extractActionsOnly(data.modifications_action);
                    modCell.innerHTML = actionsOnly;
                }
            }
        }
    })
    .catch(err => console.warn('recomputeAutoFields failed:', err));
};

// ── DOMContentLoaded INITIALIZATION ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Initialize editable cells with proper value preservation
    document.querySelectorAll('.editable-cell').forEach(cell => {
        const displayVal = cell.querySelector('.display-val');
        const input = cell.querySelector('input, textarea');
        const clientId = cell.dataset.client;
        const field = cell.dataset.field;
        const type = cell.dataset.type;

        if (!displayVal || !input) return;

        // Set input value from display value before editing starts
        let rawValue = '';
        if (field === 'sip_amount_lakhs') {
            const displayText = displayVal.textContent.trim();
            const match = displayText.match(/^([\d.]+)/);
            rawValue = match ? match[1] : '';
        } else if ((field === 'review_sent_date' || field === 'meeting_date') && type === 'datetime-local') {
            rawValue = input.getAttribute('value') || '';
        } else {
            const displayText = displayVal.textContent.trim();
            if (displayText !== 'click to edit' && displayText !== 'click to set' && displayText !== '—') {
                rawValue = displayText;
            }
        }
        
        if (rawValue && input.value !== rawValue) {
            input.value = rawValue;
        }

        // Click to edit
        displayVal.addEventListener('click', function() {
            if (type === 'datetime-local') {
                openDtPicker(cell);
            } else {
                // Refresh input value before editing
                if (field === 'sip_amount_lakhs') {
                    const displayText = displayVal.textContent.trim();
                    const match = displayText.match(/^([\d.]+)/);
                    if (match) input.value = match[1];
                } else if (field !== 'review_sent_date' && field !== 'meeting_date') {
                    const displayText = displayVal.textContent.trim();
                    if (displayText !== 'click to edit' && displayText !== 'click to set' && displayText !== '—') {
                        input.value = displayText;
                    }
                }
                cell.classList.add('editing');
                input.focus();
                if (input.tagName === 'TEXTAREA') {
                    input.selectionStart = input.selectionEnd = input.value.length;
                }
            }
        });

        // Save on blur for non-datetime fields
        if (type !== 'datetime-local') {
            input.addEventListener('blur', function() {
                saveField(cell, clientId, field, input.value);
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    input.blur();
                }
                if (e.key === 'Escape') {
                    cell.classList.remove('editing');
                }
            });
        }
    });

    // Initialize checkbox selection count
    if (document.querySelector('.client-checkbox')) {
        updateSelectedCount();
        document.querySelectorAll('.client-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });
    }

    // Bulk reassign form validation
    const bulkReassignForm = document.getElementById('bulkReassignForm');
    if (bulkReassignForm) {
        bulkReassignForm.addEventListener('submit', function(e) {
            const newOwner = bulkReassignForm.querySelector('select[name="new_owner_id"]').value;
            const selectedCount = Array.from(document.querySelectorAll('.client-checkbox')).filter(cb => cb.checked).length;
            if (!newOwner) {
                e.preventDefault();
                alert('Please select a user to assign to.');
            } else if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one client.');
            }
        });
    }

    // Auto-submit filter dropdowns
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.querySelectorAll('select').forEach(select => {
            select.addEventListener('change', function() {
                filterForm.submit();
            });
        });
    }

    // Client search autocomplete
    const searchInput = document.getElementById('client-search');
    const searchDropdown = document.getElementById('client-search-dropdown');
    if (searchInput && searchDropdown) {
        searchInput.addEventListener('input', function() {
            const val = searchInput.value.trim();
            if (val.length < 1) {
                searchDropdown.style.display = 'none';
                searchDropdown.innerHTML = '';
                return;
            }
            const cycleVal = document.getElementById('cycle-filter') ? document.getElementById('cycle-filter').value : '';
fetch('view_saved_reports.php?search_client=1&q=' + encodeURIComponent(val) + '&cycle_filter=' + encodeURIComponent(cycleVal))
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        searchDropdown.innerHTML = data.map(name =>
                            `<div style="padding:8px 12px;cursor:pointer;"
                                onmousedown="selectClientName('${name.replace(/'/g, "\\'")}')">${escapeHtml(name)}</div>`
                        ).join('');
                        searchDropdown.style.display = 'block';
                    } else {
                        searchDropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">No clients found</div>';
                        searchDropdown.style.display = 'block';
                    }
                });
        });

        document.addEventListener('click', function(e) {
            if (searchDropdown && !searchDropdown.contains(e.target) && e.target !== searchInput) {
                searchDropdown.style.display = 'none';
            }
        });
    }

    // Textarea auto-resize
    const textarea = document.getElementById('listModalRemarks');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    }

    // Auto-hide success message
    const successMessage = document.getElementById('successMessage');
    if (successMessage) {
        setTimeout(function() {
            successMessage.style.transition = 'opacity 0.5s ease';
            successMessage.style.opacity = '0';
            setTimeout(() => successMessage.style.display = 'none', 500);
        }, 3000);
    }
});

// ── CLOSE MODALS ON ESC ────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSchemeModal();
        closeMeetingHistoryModal();
        closeModificationsHistoryModal();
        closeListMeetingModal();
        const dtModal = document.getElementById('dtPickerModal');
        if (dtModal && dtModal.classList.contains('open')) {
            dtModal.classList.remove('open');
        }
    }
});

// ── CLICK OUTSIDE MODAL TO CLOSE ───────────────────────────
window.onclick = function(event) {
    const listModal = document.getElementById('listMeetingModal');
    if (listModal && event.target === listModal) {
        closeListMeetingModal();
    }
    const schemeModal = document.getElementById('schemeModal');
    if (schemeModal && event.target === schemeModal) {
        closeSchemeModal();
    }
    const meetingModal = document.getElementById('meetingHistoryModal');
    if (meetingModal && event.target === meetingModal) {
        closeMeetingHistoryModal();
    }
    const modModal = document.getElementById('modificationsHistoryModal');
    if (modModal && event.target === modModal) {
        closeModificationsHistoryModal();
    }
};

// ── CUSTOM DATETIME PICKER ───────────────────────────────────
(function() {
    const MONTHS = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
    const SHORT_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun',
                          'Jul','Aug','Sep','Oct','Nov','Dec'];

    let activeDtCell = null;
    let activeDtInput = null;
    let origDtValue = '';
    let pickYear, pickMonth, pickDay, pickHour, pickMinute, pickAmPm;

    function openDtPicker(cell) {
        activeDtCell = cell;
        activeDtInput = cell.querySelector('input[type="datetime-local"]');
        origDtValue = activeDtInput ? activeDtInput.value : '';

        const now = new Date();
        if (origDtValue) {
            const parts = origDtValue.split('T');
            const d = parts[0].split('-');
            const t = parts[1] ? parts[1].split(':') : ['12','00'];
            pickYear = parseInt(d[0]);
            pickMonth = parseInt(d[1]) - 1;
            pickDay = parseInt(d[2]);
            const h24 = parseInt(t[0]);
            pickMinute = parseInt(t[1]);
            pickAmPm = h24 >= 12 ? 'PM' : 'AM';
            pickHour = h24 % 12 || 12;
        } else {
            pickYear = now.getFullYear();
            pickMonth = now.getMonth();
            pickDay = now.getDate();
            pickHour = 12;
            pickMinute = 0;
            pickAmPm = 'AM';
        }

        syncTimeInputs();
        renderCalendar();
        updateHeader();

        document.getElementById('dtPickerModal').classList.add('open');
    }

    function closeDtPicker(save) {
        if (save && activeDtCell && activeDtInput) {
            const h24 = to24(pickHour, pickAmPm);
            const mm = String(pickMinute).padStart(2, '0');
            const hh = String(h24).padStart(2, '0');
            const dd = String(pickDay).padStart(2, '0');
            const mo = String(pickMonth + 1).padStart(2, '0');
            const val = `${pickYear}-${mo}-${dd}T${hh}:${mm}`;

            activeDtInput.value = val;

            const clientId = activeDtCell.dataset.client;
            const field = activeDtCell.dataset.field;
            saveField(activeDtCell, clientId, field, val);
        } else if (!save && activeDtCell) {
            if (activeDtInput) activeDtInput.value = origDtValue;
            activeDtCell.classList.remove('editing');
        }

        document.getElementById('dtPickerModal').classList.remove('open');
        activeDtCell = null;
        activeDtInput = null;
    }

    function to24(h12, ampm) {
        if (ampm === 'AM') return h12 === 12 ? 0 : h12;
        return h12 === 12 ? 12 : h12 + 12;
    }

    function syncTimeInputs() {
        document.getElementById('dtHour').value = pickHour;
        document.getElementById('dtMinute').value = String(pickMinute).padStart(2, '0');
        document.getElementById('dtAmBtn').classList.toggle('active', pickAmPm === 'AM');
        document.getElementById('dtPmBtn').classList.toggle('active', pickAmPm === 'PM');
    }

    function updateHeader() {
        const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        const d = new Date(pickYear, pickMonth, pickDay);
        document.getElementById('dtHeaderDisplay').textContent =
            `${dayNames[d.getDay()]}, ${pickDay} ${SHORT_MONTHS[pickMonth]} ${pickYear}`;
    }

    function renderCalendar() {
        document.getElementById('dtMonthLabel').textContent =
            `${MONTHS[pickMonth]} ${pickYear}`;

        const grid = document.getElementById('dtDaysGrid');
        grid.innerHTML = '';

        const firstDay = new Date(pickYear, pickMonth, 1).getDay();
        const daysInMonth = new Date(pickYear, pickMonth + 1, 0).getDate();
        const today = new Date();

        // Previous month days
        const daysInPrev = new Date(pickYear, pickMonth, 0).getDate();
        for (let i = firstDay - 1; i >= 0; i--) {
            const d = daysInPrev - i;
            addDay(grid, d, 'other-month');
        }
        
        // Current month days
        for (let d = 1; d <= daysInMonth; d++) {
            const isToday = (d === today.getDate() && pickMonth === today.getMonth() && pickYear === today.getFullYear());
            const isSelected = (d === pickDay);
            addDay(grid, d, (isToday ? 'today ' : '') + (isSelected ? 'selected' : ''), d);
        }
        
        // Next month days
        const total = firstDay + daysInMonth;
        const remaining = total % 7 === 0 ? 0 : 7 - (total % 7);
        for (let d = 1; d <= remaining; d++) {
            addDay(grid, d, 'other-month');
        }
    }

    function addDay(grid, label, cls, dayVal) {
        const el = document.createElement('div');
        el.className = 'dt-day ' + cls.trim();
        el.textContent = label;
        if (dayVal) {
            el.addEventListener('click', function() {
                pickDay = dayVal;
                renderCalendar();
                updateHeader();
            });
        }
        grid.appendChild(el);
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Navigation buttons
        document.getElementById('dtPrevMonth').addEventListener('click', function() {
            pickMonth--;
            if (pickMonth < 0) {
                pickMonth = 11;
                pickYear--;
            }
            const maxDay = new Date(pickYear, pickMonth + 1, 0).getDate();
            if (pickDay > maxDay) pickDay = maxDay;
            renderCalendar();
            updateHeader();
        });

        document.getElementById('dtNextMonth').addEventListener('click', function() {
            pickMonth++;
            if (pickMonth > 11) {
                pickMonth = 0;
                pickYear++;
            }
            const maxDay = new Date(pickYear, pickMonth + 1, 0).getDate();
            if (pickDay > maxDay) pickDay = maxDay;
            renderCalendar();
            updateHeader();
        });

        // Time inputs
        document.getElementById('dtHour').addEventListener('input', function() {
            let v = parseInt(this.value) || 1;
            if (v < 1) v = 1;
            if (v > 12) v = 12;
            pickHour = v;
            updateHeader();
        });

        document.getElementById('dtMinute').addEventListener('input', function() {
            let v = parseInt(this.value);
            if (isNaN(v) || v < 0) v = 0;
            if (v > 59) v = 59;
            pickMinute = v;
            updateHeader();
        });

        // AM/PM toggle
        document.getElementById('dtAmBtn').addEventListener('click', function() {
            pickAmPm = 'AM';
            syncTimeInputs();
            updateHeader();
        });
        
        document.getElementById('dtPmBtn').addEventListener('click', function() {
            pickAmPm = 'PM';
            syncTimeInputs();
            updateHeader();
        });

        // Buttons
        document.getElementById('dtOkBtn').addEventListener('click', function() {
            closeDtPicker(true);
        });
        
        document.getElementById('dtCancelBtn').addEventListener('click', function() {
            closeDtPicker(false);
        });
        
        document.getElementById('dtClearBtn').addEventListener('click', function() {
            if (activeDtCell && activeDtInput) {
                activeDtInput.value = '';
                const clientId = activeDtCell.dataset.client;
                const field = activeDtCell.dataset.field;
                saveField(activeDtCell, clientId, field, '');
            }
            document.getElementById('dtPickerModal').classList.remove('open');
            activeDtCell = null;
            activeDtInput = null;
        });

        // Close on outside click
        document.getElementById('dtPickerModal').addEventListener('click', function(e) {
            if (e.target === this) closeDtPicker(false);
        });
    });

    window.openDtPicker = openDtPicker;
})();