// ── INLINE EDIT ─────────────────────────────────────────────
function showToast(msg) {
    const t = document.getElementById('saveToast');
    t.textContent = msg || '✓ Saved';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2200);
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.editable-cell').forEach(cell => {
        const displayVal = cell.querySelector('.display-val');
        const input = cell.querySelector('input, textarea');
        const clientId = cell.dataset.client;
        const field = cell.dataset.field;

        // Click to edit
        displayVal.addEventListener('click', function () {
            cell.classList.add('editing');
            input.focus();
            if (input.tagName === 'TEXTAREA') {
                input.selectionStart = input.selectionEnd = input.value.length;
            }
        });

        // Save on blur — but NOT for datetime-local (calendar picker causes premature blur)
        input.addEventListener('blur', function () {
            if (input.type === 'datetime-local') return;
            saveField(cell, clientId, field, input.value);
        });

        // For datetime-local: show a confirm button
        if (input.type === 'datetime-local') {
            let confirmBtn = cell.querySelector('.datetime-confirm-btn');
            if (!confirmBtn) {
                confirmBtn = document.createElement('button');
                confirmBtn.className = 'datetime-confirm-btn';
                confirmBtn.textContent = '✓ Save';
                confirmBtn.style.cssText = 'margin-top:4px; padding:4px 10px; background:#0288D1; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px; font-weight:600; display:none; width:100%;';
                cell.appendChild(confirmBtn);

                confirmBtn.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // prevent blur firing before click
                    saveField(cell, clientId, field, input.value);
                    confirmBtn.style.display = 'none';
                });
            }

            displayVal.addEventListener('click', function () {
                confirmBtn.style.display = 'block';
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    confirmBtn.style.display = 'none';
                }
            });
        }

        // Save on Enter (for single-line inputs), Escape to cancel
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                e.preventDefault();
                input.blur();
            }
            if (e.key === 'Escape') {
                cell.classList.remove('editing');
                const btn = cell.querySelector('.datetime-confirm-btn');
                if (btn) btn.style.display = 'none';
            }
        });
    });
});

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
                    displayText = parseFloat(value).toFixed(2);
                } else if ((field === 'review_sent_date' || field === 'meeting_date') && value) {
                    const d = new Date(value);
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    const hours = d.getHours();
                    const minutes = d.getMinutes().toString().padStart(2, '0');
                    const ampm = hours >= 12 ? 'PM' : 'AM';
                    const h12 = ((hours % 12) || 12);
                    displayText = d.getDate() + '-' + months[d.getMonth()] + '-' + d.getFullYear()
                        + '\n' + h12 + ':' + minutes + ' ' + ampm;
                }
                displayVal.textContent = displayText || '—';
                displayVal.classList.toggle('placeholder-text', !displayText);
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

function openSchemeModal(clientId) {
    currentClientId = clientId;

    const modal = document.getElementById('schemeModal');
    const content = document.getElementById('schemeModalContent');

    content.innerHTML = `
        <div style="text-align:center; padding:20px;">
            Loading scheme changes...
        </div>
    `;
    modal.style.display = 'flex';

    // ── FIXED: was incorrectly sending save_review_fields instead of fetch_scheme_changes
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
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = `
                    <div style="text-align:center; padding:20px; color:red;">
                        Error loading scheme changes
                    </div>
                `;
                return;
            }

            if (!data.schemes || data.schemes.length === 0) {
                content.innerHTML = `
                    <div class="no-schemes-message">
                        No scheme changes found for this client.
                    </div>
                `;
                return;
            }

            const completedIds = Array.isArray(data.completed_ids) ?
                data.completed_ids.map(id => parseInt(id, 10)) : [];

            renderSchemeModal(data.schemes, completedIds);
        })
        .catch(() => {
            content.innerHTML = `
                <div style="text-align:center; padding:20px; color:red;">
                    Network error loading schemes
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
        html += `<input type="checkbox" class="scheme-checkbox" data-scheme-id="${scheme.id}" value="${scheme.scheme_name}-${scheme.action_step}" ${isChecked ? 'checked' : ''}>`;
        html += '<div class="scheme-details">';
        html += `<span class="scheme-name">${scheme.scheme_name}</span>`;
        html += `<span class="scheme-action ${actionClass}">${scheme.action_step}</span>`;
        if (scheme.recommended_scheme || scheme.recommended_amount) {
            html += `<span class="scheme-recommended">→ ${scheme.recommended_scheme || ''} ${scheme.recommended_amount ? '(' + scheme.recommended_amount + ')' : ''}</span>`;
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
                    if (modCell) {
                        if (data.modifications_action) {
                            const actionsOnly = data.modifications_action
                                .split(' | ')
                                .map(item => {
                                    const parts = item.split('-');
                                    return parts[parts.length - 1].trim();
                                })
                                .join(' | ');
                            modCell.innerHTML = actionsOnly;
                        } else {
                            modCell.innerHTML = '';
                        }
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
            if (data.success && data.history.length > 0) {
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
                    html += `<div class="comments">${item.meeting_comments}</div>`;
                    html += '</div>';
                });
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div style="text-align:center; padding:20px; color:#666;">No meeting history found for this client.</div>';
            }
        })
        .catch(err => {
            content.innerHTML = '<div style="text-align:center; padding:20px; color:red;">Error loading meeting history</div>';
        });
}

function closeMeetingHistoryModal() {
    document.getElementById('meetingHistoryModal').style.display = 'none';
}

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
                html += `<span class="scheme-name">${scheme.scheme_name}</span>`;
                html += `<span class="scheme-action ${actionClass}" style="background:${actionColor};">${actionStep}</span>`;
                if (scheme.recommended_scheme || scheme.recommended_amount) {
                    html += `<span class="scheme-recommended">→ ${scheme.recommended_scheme || ''} ${scheme.recommended_amount ? '(' + scheme.recommended_amount + ')' : ''}</span>`;
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

// ── RECOMPUTE AUTO FIELDS (called after scheme/goal changes) ─
window.recomputeAutoFields = function (clientId) {
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
                if (!row) return;

                if (data.sip_amount_lakhs) {
                    const sipCell = row.querySelector('.editable-cell[data-field="sip_amount_lakhs"]');
                    if (sipCell) {
                        const dv = sipCell.querySelector('.display-val');
                        const inp = sipCell.querySelector('input');
                        if (dv) {
                            dv.textContent = data.sip_amount_lakhs + ' Lakh';
                            dv.classList.remove('placeholder-text');
                        }
                        if (inp) inp.value = data.sip_amount_lakhs;
                    }
                }
                if (data.modifications_action) {
                    const modCell = row.querySelector('.clickable-cell');
                    if (modCell) {
                        const truncated = data.modifications_action.length > 80 ?
                            data.modifications_action.substring(0, 80) + '…' :
                            data.modifications_action;
                        modCell.innerHTML = truncated + ' <span style="color:#0288D1; font-size:10px; margin-left:5px;">✎</span>';
                    }
                }
            }
        })
        .catch(err => console.warn('recomputeAutoFields failed:', err));
};

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
    if (!selectAll) return;
    selectAll.checked = selectedCount > 0 && Array.from(checkboxes).every(c => c.checked);
    selectAll.indeterminate = Array.from(checkboxes).some(c => c.checked) && !selectAll.checked;
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

document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('.client-checkbox')) updateSelectedCount();

    document.querySelectorAll('.client-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    const bulkReassignForm = document.getElementById('bulkReassignForm');
    if (bulkReassignForm) {
        bulkReassignForm.addEventListener('submit', function (e) {
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
});

// ── UPLOAD ──────────────────────────────────────────────────
function triggerUpload(clientId) {
    const form = document.getElementById('uploadForm_' + clientId);
    if (!form) return;
    form.querySelector('input[type="file"]').click();
}

function submitUpload(clientId) {
    const form = document.getElementById('uploadForm_' + clientId);
    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput.files.length) return;
    form.submit();
}

// ── MEETING STATUS ───────────────────────────────────────────
function handleListMeetingChange(select, clientId) {
    const status = select.value;
    const remarksBtn = document.getElementById('meet_btn_' + clientId);
    const storedRemarks = document.getElementById('remarks_store_' + clientId).value;

    if (status === 'yes') {
        openListMeetingModal(clientId);
        if (remarksBtn) remarksBtn.style.display = 'inline-flex';
    } else {
        saveData(clientId, status, storedRemarks, false);
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
    saveData(clientId, status, remarks, true);
}

function saveData(clientId, status, remarks, isModal) {
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
                const btn = document.getElementById('meet_btn_' + clientId);
                if (btn) btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#555" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zm0 12.5a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>';
                if (isModal) {
                    closeListMeetingModal();
                    showToast("Meeting remarks saved!");
                }
            } else {
                alert("Error: " + data.error);
            }
        });
}

// ── CLIENT SEARCH AUTOCOMPLETE ───────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('client-search');
    const dropdown = document.getElementById('client-search-dropdown');
    if (!input) return;

    input.addEventListener('input', function () {
        const val = input.value.trim();
        if (val.length < 1) {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            return;
        }
        fetch('view_saved_reports.php?search_client=1&q=' + encodeURIComponent(val))
            .then(res => res.json())
            .then(data => {
                if (data.length > 0) {
                    dropdown.innerHTML = data.map(name =>
                        `<div style="padding:8px 12px;cursor:pointer;"
                        onmousedown="selectClientName('${name.replace(/'/g, "\\'")}')">${name}</div>`
                    ).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">No clients found</div>';
                    dropdown.style.display = 'block';
                }
            });
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = 'none';
    });
});

// ── TEXTAREA AUTO-RESIZE ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const textarea = document.getElementById('listModalRemarks');
    if (textarea) {
        textarea.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    }
});

function selectClientName(name) {
    document.getElementById('client-search').value = name;
    document.getElementById('client-search-dropdown').style.display = 'none';
    document.getElementById('filterForm').submit();
}

// ── AUTO-SUBMIT FILTER DROPDOWNS ────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('filterForm');
    if (!filterForm) return;
    filterForm.querySelectorAll('select').forEach(select => {
        select.addEventListener('change', function () {
            filterForm.submit();
        });
    });
});

// ── AUTO-HIDE SUCCESS MESSAGE ───────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const successMessage = document.getElementById('successMessage');
    if (successMessage) {
        setTimeout(function () {
            successMessage.style.transition = 'opacity 0.5s ease';
            successMessage.style.opacity = '0';
            setTimeout(() => successMessage.style.display = 'none', 500);
        }, 3000);
    }
});

// ── CLOSE MODALS ON ESC ────────────────────────────────────
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeSchemeModal();
        closeMeetingHistoryModal();
        closeModificationsHistoryModal();
        closeListMeetingModal();
    }
});

window.onclick = function (event) {
    const modal = document.getElementById('listMeetingModal');
    if (event.target === modal) {
        closeListMeetingModal();
    }
}