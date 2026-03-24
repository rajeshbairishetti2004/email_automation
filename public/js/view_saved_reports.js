// ── INLINE EDIT ─────────────────────────────────────────────
            function showToast(msg) {
                const t = document.getElementById('saveToast');
                t.textContent = msg || '✓ Saved';
                t.classList.add('show');
                setTimeout(() => t.classList.remove('show'), 2200);
            }

            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.editable-cell').forEach(cell => {
                    const displayVal = cell.querySelector('.display-val');
                    const input = cell.querySelector('input, textarea');
                    const clientId = cell.dataset.client;
                    const field = cell.dataset.field;

                    // Click to edit
                    displayVal.addEventListener('click', function() {
                        cell.classList.add('editing');
                        input.focus();
                        if (input.tagName === 'TEXTAREA') {
                            input.selectionStart = input.selectionEnd = input.value.length;
                        }
                    });

                    // Save on blur
                    input.addEventListener('blur', function() {
                        saveField(cell, clientId, field, input.value);
                    });

                    // Save on Enter (for single-line inputs), Escape to cancel
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                            e.preventDefault();
                            input.blur();
                        }
                        if (e.key === 'Escape') {
                            cell.classList.remove('editing');
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
                            }// To this:
 else if ((field === 'review_sent_date' || field === 'meeting_date') && value) {
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

                // Show loader
                content.innerHTML = `
            <div style="text-align:center; padding:20px;">
                Loading scheme changes...
            </div>
        `;
                modal.style.display = 'flex';

                fetch('view_saved_reports.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
body: new URLSearchParams({
    action: 'save_review_fields',
    client_id: clientId,
    field: field,
    value: (field === 'meeting_date' && value) ? value.replace('T', ' ') + ':00' : value
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

                        // ✅ IMPORTANT:
                        // completed_ids comes from DB (clients.completed_scheme_ids)
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
                    // Use scheme.id directly for checking completion status
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
                                        // Extract only action parts (e.g. "Switch | Drop") for display
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
                                    // Update onclick so re-opening reflects latest state
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

                        // completedIds = array of integers (scheme IDs that were checked in prev review)
                        const completedIds = Array.isArray(data.completed_ids) ?
                            data.completed_ids.map(id => parseInt(id, 10)) : [];

                        let html = '<div class="scheme-list">';
                        data.schemes.forEach(scheme => {
                            const actionStep = scheme.action_step || '';
                            const actionClass = actionStep.toLowerCase().replace(/\s+/g, '-');
                            const actionColor = actionColors[actionStep.toLowerCase()] || '#ff9800';
                            // Exact ID match — 100% accurate
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
                            // Update SIP cell in the table row if present
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

            document.addEventListener('DOMContentLoaded', function() {
                if (document.querySelector('.client-checkbox')) updateSelectedCount();

                document.querySelectorAll('.client-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateSelectedCount);
                });

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

                // Show modal FIRST so scrollHeight is measurable
                document.getElementById('listMeetingModal').style.display = 'flex';

                // Reset then expand to fit all content
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
            document.addEventListener('DOMContentLoaded', function() {
                const input = document.getElementById('client-search');
                const dropdown = document.getElementById('client-search-dropdown');
                if (!input) return;

                input.addEventListener('input', function() {
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
                        onmousedown="selectClientName('${name.replace(/'/g,"\\'")}')">${name}</div>`
                                ).join('');
                                dropdown.style.display = 'block';
                            } else {
                                dropdown.innerHTML = '<div style="padding:8px 12px;color:#888;">No clients found</div>';
                                dropdown.style.display = 'block';
                            }
                        });
                });

                document.addEventListener('click', function(e) {
                    if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = 'none';
                });
            }); // ← search autocomplete block ends here

            // ── TEXTAREA AUTO-RESIZE ─────────────────────────────────────
            document.addEventListener('DOMContentLoaded', function() {
                const textarea = document.getElementById('listModalRemarks');
                if (textarea) {
                    textarea.addEventListener('input', function() {
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
            document.addEventListener('DOMContentLoaded', function() {
                const filterForm = document.getElementById('filterForm');
                if (!filterForm) return;
                filterForm.querySelectorAll('select').forEach(select => {
                    select.addEventListener('change', function() {
                        filterForm.submit();
                    });
                });
            });

            // ── AUTO-HIDE SUCCESS MESSAGE ───────────────────────────────
            document.addEventListener('DOMContentLoaded', function() {
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
                }
            });

            window.onclick = function(event) {
    const modal = document.getElementById('listMeetingModal');
    if (event.target === modal) {
        closeListMeetingModal();
    }
}

// ── TOAST ────────────────────────────────────────────────────
function showToast(msg) {
    const t = document.getElementById('saveToast');
    t.textContent = msg || '✓ Saved';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2200);
}

// ── CUSTOM DATETIME PICKER ───────────────────────────────────
(function () {
    const MONTHS = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
    const SHORT_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun',
                          'Jul','Aug','Sep','Oct','Nov','Dec'];

    let activeDtCell   = null;
    let activeDtInput  = null;  // the hidden <input type="datetime-local">
    let origDtValue    = '';

    // picker state
    let pickYear, pickMonth, pickDay, pickHour, pickMinute, pickAmPm;

    function openDtPicker(cell) {
        activeDtCell  = cell;
        activeDtInput = cell.querySelector('input[type="datetime-local"]');
        origDtValue   = activeDtInput ? activeDtInput.value : '';

        // Parse existing value or default to now
        const now = new Date();
        if (origDtValue) {
            const parts = origDtValue.split('T');
            const d = parts[0].split('-');
            const t = parts[1] ? parts[1].split(':') : ['12','00'];
            pickYear  = parseInt(d[0]);
            pickMonth = parseInt(d[1]) - 1;
            pickDay   = parseInt(d[2]);
            const h24 = parseInt(t[0]);
            pickMinute = parseInt(t[1]);
            pickAmPm = h24 >= 12 ? 'PM' : 'AM';
            pickHour = h24 % 12 || 12;
        } else {
            pickYear   = now.getFullYear();
            pickMonth  = now.getMonth();
            pickDay    = now.getDate();
            pickHour   = 12;
            pickMinute = 0;
            pickAmPm   = 'AM';
        }

        syncTimeInputs();
        renderCalendar();
        updateHeader();

        document.getElementById('dtPickerModal').classList.add('open');
    }

    function closeDtPicker(save) {
        if (save && activeDtCell && activeDtInput) {
            // Build "YYYY-MM-DDTHH:MM" value
            const h24 = to24(pickHour, pickAmPm);
            const mm  = String(pickMinute).padStart(2, '0');
            const hh  = String(h24).padStart(2, '0');
            const dd  = String(pickDay).padStart(2, '0');
            const mo  = String(pickMonth + 1).padStart(2, '0');
            const val = `${pickYear}-${mo}-${dd}T${hh}:${mm}`;

            activeDtInput.value = val;

            const clientId = activeDtCell.dataset.client;
            const field    = activeDtCell.dataset.field;
            saveField(activeDtCell, clientId, field, val);
        } else if (!save && activeDtCell) {
            // Cancel — restore original and close editing state
            if (activeDtInput) activeDtInput.value = origDtValue;
            activeDtCell.classList.remove('editing');
        }

        document.getElementById('dtPickerModal').classList.remove('open');
        activeDtCell  = null;
        activeDtInput = null;
    }

    function to24(h12, ampm) {
        if (ampm === 'AM') return h12 === 12 ? 0 : h12;
        return h12 === 12 ? 12 : h12 + 12;
    }

    function syncTimeInputs() {
        document.getElementById('dtHour').value   = pickHour;
        document.getElementById('dtMinute').value = String(pickMinute).padStart(2, '0');
        document.getElementById('dtAmBtn').classList.toggle('active', pickAmPm === 'AM');
        document.getElementById('dtPmBtn').classList.toggle('active', pickAmPm === 'PM');
    }

    function updateHeader() {
        const h24 = to24(pickHour, pickAmPm);
        const mm  = String(pickMinute).padStart(2,'0');
        const hh  = String(pickHour).padStart(2,'0');
        const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        const d = new Date(pickYear, pickMonth, pickDay);
        document.getElementById('dtHeaderDisplay').textContent =
            `${dayNames[d.getDay()]}, ${pickDay} ${SHORT_MONTHS[pickMonth]} ${pickYear}  ${hh}:${mm} ${pickAmPm}`;
    }

    function renderCalendar() {
        document.getElementById('dtMonthLabel').textContent =
            `${MONTHS[pickMonth]} ${pickYear}`;

        const grid  = document.getElementById('dtDaysGrid');
        grid.innerHTML = '';

        const firstDay = new Date(pickYear, pickMonth, 1).getDay();
        const daysInMonth = new Date(pickYear, pickMonth + 1, 0).getDate();
        const daysInPrev  = new Date(pickYear, pickMonth, 0).getDate();
        const today = new Date();

        // Prev month overflow
        for (let i = firstDay - 1; i >= 0; i--) {
            const d = daysInPrev - i;
            addDay(grid, d, 'other-month', pickMonth - 1, pickYear);
        }
        // Current month
        for (let d = 1; d <= daysInMonth; d++) {
            const isToday = (d === today.getDate() && pickMonth === today.getMonth() && pickYear === today.getFullYear());
            const isSelected = (d === pickDay);
            addDay(grid, d, (isToday ? 'today ' : '') + (isSelected ? 'selected' : ''), pickMonth, pickYear, d);
        }
        // Next month overflow
        const total = firstDay + daysInMonth;
        const remaining = total % 7 === 0 ? 0 : 7 - (total % 7);
        for (let d = 1; d <= remaining; d++) {
            addDay(grid, d, 'other-month', pickMonth + 1, pickYear);
        }
    }

    function addDay(grid, label, cls, month, year, dayVal) {
        const el = document.createElement('div');
        el.className = 'dt-day ' + cls.trim();
        el.textContent = label;
        if (dayVal) {
            el.addEventListener('click', function () {
                pickDay = dayVal;
                renderCalendar();
                updateHeader();
            });
        }
        grid.appendChild(el);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Prev / Next month
        document.getElementById('dtPrevMonth').addEventListener('click', function () {
            pickMonth--;
            if (pickMonth < 0) { pickMonth = 11; pickYear--; }
            // Keep pickDay valid
            const maxDay = new Date(pickYear, pickMonth + 1, 0).getDate();
            if (pickDay > maxDay) pickDay = maxDay;
            renderCalendar();
            updateHeader();
        });

        document.getElementById('dtNextMonth').addEventListener('click', function () {
            pickMonth++;
            if (pickMonth > 11) { pickMonth = 0; pickYear++; }
            const maxDay = new Date(pickYear, pickMonth + 1, 0).getDate();
            if (pickDay > maxDay) pickDay = maxDay;
            renderCalendar();
            updateHeader();
        });

        // Hour input
        document.getElementById('dtHour').addEventListener('input', function () {
            let v = parseInt(this.value) || 1;
            if (v < 1)  v = 1;
            if (v > 12) v = 12;
            pickHour = v;
            updateHeader();
        });

        // Minute input
        document.getElementById('dtMinute').addEventListener('input', function () {
            let v = parseInt(this.value);
            if (isNaN(v) || v < 0)  v = 0;
            if (v > 59) v = 59;
            pickMinute = v;
            updateHeader();
        });

        // AM / PM toggle
        document.getElementById('dtAmBtn').addEventListener('click', function () {
            pickAmPm = 'AM';
            syncTimeInputs();
            updateHeader();
        });
        document.getElementById('dtPmBtn').addEventListener('click', function () {
            pickAmPm = 'PM';
            syncTimeInputs();
            updateHeader();
        });

        // OK / Cancel
        document.getElementById('dtOkBtn').addEventListener('click',     function () { closeDtPicker(true);  });
        document.getElementById('dtCancelBtn').addEventListener('click', function () { closeDtPicker(false); });
         document.getElementById('dtClearBtn').addEventListener('click', function () {
    if (activeDtCell && activeDtInput) {
        // 1. Clear value
        activeDtInput.value = '';

        // 2. Save empty value to backend
        const clientId = activeDtCell.dataset.client;
        const field    = activeDtCell.dataset.field;

        saveField(activeDtCell, clientId, field, '');
    }

    // 3. Close picker
    document.getElementById('dtPickerModal').classList.remove('open');
    activeDtCell  = null;
    activeDtInput = null;
});
        // Click outside card closes (cancel)
        document.getElementById('dtPickerModal').addEventListener('click', function (e) {
            if (e.target === this) closeDtPicker(false);
        });

        // ESC closes
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.getElementById('dtPickerModal').classList.contains('open')) {
                closeDtPicker(false);
            }
        });

        // ── Wire up ALL editable cells ──────────────────────────────
        document.querySelectorAll('.editable-cell').forEach(cell => {
            const displayVal = cell.querySelector('.display-val');
            const input      = cell.querySelector('input, textarea');
            const clientId   = cell.dataset.client;
            const field      = cell.dataset.field;
            const type       = cell.dataset.type;

            if (!displayVal || !input) return;

            displayVal.addEventListener('click', function () {
                if (type === 'datetime-local') {
                    // Open custom picker instead of native input
                    openDtPicker(cell);
                } else {
                    cell.classList.add('editing');
                    input.focus();
                    if (input.tagName === 'TEXTAREA') {
                        input.selectionStart = input.selectionEnd = input.value.length;
                    }
                }
            });

            // Only non-datetime fields use blur-to-save
            if (type !== 'datetime-local') {
                input.addEventListener('blur', function () {
                    saveField(cell, clientId, field, input.value);
                });

                input.addEventListener('keydown', function (e) {
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
    });

    // Expose openDtPicker so it can be called from inline onclick if ever needed
    window.openDtPicker = openDtPicker;
})();

// ── SAVE FIELD ───────────────────────────────────────────────
function saveField(cell, clientId, field, value) {
    const input      = cell.querySelector('input, textarea');
    const displayVal = cell.querySelector('.display-val');

    fetch('view_saved_reports.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action:    'save_review_fields',
            client_id: clientId,
            field:     field,
            value:     value
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let displayHtml = '';

            if (field === 'sip_amount_lakhs' && value !== '') {
                displayHtml = parseFloat(value).toFixed(2) + ' Lakh';

            } else if ((field === 'review_sent_date' || field === 'meeting_date') && value) {
                // Parse "YYYY-MM-DDTHH:MM" safely without timezone shift
                const parts     = value.split('T');
                const dateParts = parts[0].split('-');
                const timeParts = parts[1] ? parts[1].split(':') : ['0','0'];

                const year  = parseInt(dateParts[0], 10);
                const month = parseInt(dateParts[1], 10) - 1;
                const day   = parseInt(dateParts[2], 10);
                const hours = parseInt(timeParts[0], 10);
                const mins  = parseInt(timeParts[1], 10);

                const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun',
                                'Jul','Aug','Sep','Oct','Nov','Dec'];
                const ampm = hours >= 12 ? 'PM' : 'AM';
                const h12  = (hours % 12) || 12;
                const mStr = String(mins).padStart(2, '0');

                displayHtml = `${day}-${MONTHS[month]}-${year}<br>`
                            + `<span style="color:#999;font-size:11px;">${h12}:${mStr} ${ampm}</span>`;

            } else {
                displayHtml = value ? value.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/\n/g,'<br>') : '';
            }

            if (displayHtml) {
                displayVal.innerHTML = displayHtml;
                displayVal.classList.remove('placeholder-text');
            } else {
                displayVal.innerHTML = '—';
                displayVal.classList.add('placeholder-text');
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