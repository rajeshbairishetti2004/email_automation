function updateSenderDetails(selectObj) {
    const selectedOption = selectObj.options[selectObj.selectedIndex];
    const name = selectedOption.getAttribute('data-name');
    const mobile = selectedOption.getAttribute('data-mobile');
    const designation = selectedOption.getAttribute('data-designation');
    const email = selectedOption.value;

    if (!name) { return; }

    const hiddenNameField = document.getElementById('from_name');
    if (hiddenNameField) { hiddenNameField.value = name; }

    const newSignature = "Regards,\n\n" +
        name + ",\n" +
        designation + ",\n" +
        "Finance Doctor Private Limited.\n\n" +
        "Mobile - " + mobile + ".\n" +
        "Email - " + email + "\n" +
        "Url: www.financedoctor.in";

    let visibleSignatureBox = document.getElementById('signature_block');
    if (!visibleSignatureBox) {
        visibleSignatureBox = document.querySelector('textarea[name="signature_block"]');
    }

    if (visibleSignatureBox) {
        visibleSignatureBox.value = newSignature;
        visibleSignatureBox.dispatchEvent(new Event('blur'));
        visibleSignatureBox.style.transition = "background-color 0.5s";
        visibleSignatureBox.style.backgroundColor = "#e8f0fe";
        setTimeout(() => { visibleSignatureBox.style.backgroundColor = "#fff"; }, 800);
    }

    const hiddenSigField = document.getElementById('custom_signature_for_email');
    if (hiddenSigField) { hiddenSigField.value = newSignature; }
    filterCcOptionsBySender();
}

// Keep CC text input in sync with multi-select selections
function syncCcFromSelect() {
    const select = document.getElementById('cc_multi_select');
    const input  = document.getElementById('cc_emails');
    const selectAll = document.getElementById('cc_select_all');
    if (!select || !input) return;
    const selected = Array.from(select.selectedOptions).map(o => o.value).filter(Boolean);
    input.value = selected.join(', ');
    updateCcSummary(selected);

    if (selectAll) {
        const total = select.options.length;
        selectAll.checked = total > 0 && selected.length === total;
    }

    syncCheckboxesFromSelect();
}

// Filter CC options to exclude the currently selected sender
function filterCcOptionsBySender() {
    const senderSelect = document.getElementById('from_email');
    const senderEmail  = senderSelect ? senderSelect.value.toLowerCase() : '';
    const select = document.getElementById('cc_multi_select');
    const datalist = document.getElementById('all-emails');
    const checkboxList = document.getElementById('cc_checkbox_list');
    if (!select || !datalist) return;

    const existingSelected = new Set(Array.from(select.selectedOptions).map(o => o.value));

    // Merge any newly added options with the cached list
    const mergedOptions = Array.from(new Set([
        ...(window.allCcOptions || []),
        ...Array.from(datalist.options).map(o => o.value),
        ...Array.from(select.options).map(o => o.value)
    ].filter(Boolean)));
    window.allCcOptions = mergedOptions;

    select.innerHTML = '';
    datalist.innerHTML = '';
    if (checkboxList) {
        checkboxList.innerHTML = '';
    }

    mergedOptions.forEach(email => {
        if (!email) return;
        if (email.toLowerCase() === senderEmail) return; // exclude current sender

        const opt1 = document.createElement('option');
        opt1.value = email;
        opt1.textContent = email;
        if (existingSelected.has(email)) opt1.selected = true;
        select.appendChild(opt1);

        const opt2 = document.createElement('option');
        opt2.value = email;
        datalist.appendChild(opt2);

        if (checkboxList) {
            const label = document.createElement('label');
            label.className = 'cc-checkbox-item';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = email;
            checkbox.checked = existingSelected.has(email);
            checkbox.addEventListener('change', onCcCheckboxChange);

            label.appendChild(checkbox);
            label.appendChild(document.createTextNode(email));
            checkboxList.appendChild(label);
        }
    });

    syncCcFromSelect();
}

document.addEventListener('DOMContentLoaded', function() {
    // Cache all CC options from the initial PHP render
    const select = document.getElementById('cc_multi_select');
    if (select) {
        window.allCcOptions = Array.from(select.options).map(o => o.value).filter(Boolean);
    }
    filterCcOptionsBySender();
    updateAttachmentList();
});

function toggleCcSelectAll() {
    const select = document.getElementById('cc_multi_select');
    const selectAll = document.getElementById('cc_select_all');
    if (!select || !selectAll) return;

    Array.from(select.options).forEach(opt => { opt.selected = selectAll.checked; });
    syncCcFromSelect();
}

function onCcCheckboxChange(event) {
    const select = document.getElementById('cc_multi_select');
    if (!select) return;
    const email = event.target.value;
    const option = Array.from(select.options).find(o => o.value === email);
    if (option) {
        option.selected = event.target.checked;
    }
    syncCcFromSelect();
}

function syncCheckboxesFromSelect() {
    const select = document.getElementById('cc_multi_select');
    const checkboxList = document.getElementById('cc_checkbox_list');
    if (!select || !checkboxList) return;
    const selectedSet = new Set(Array.from(select.selectedOptions).map(o => o.value));
    checkboxList.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.checked = selectedSet.has(cb.value);
    });
}

// Lightweight summary of selected CCs for quick glance
function updateCcSummary(selectedEmails) {
    const summaryEl = document.getElementById('cc_summary');
    if (!summaryEl) return;
    const list = (selectedEmails || []).filter(Boolean);
    summaryEl.textContent = list.length ? `Selected: ${list.join(', ')}` : 'Selected: none';
}

// --- Attachment List Logic ---
function updateAttachmentList() {
    const input = document.getElementById('email_attachments_input');
    const list = document.getElementById('selected_attachment_list');
    list.innerHTML = '';
    Array.from(input.files).forEach((file, idx) => {
        const li = document.createElement('li');
        li.style.cssText = "margin-bottom: 6px; display: flex; align-items: center;";
        li.innerHTML = `<span>📎 <strong>${file.name}</strong></span>
            <a href="#" style="color:red; margin-left:10px; font-size:12px;" onclick="removeSelectedFile(${idx});return false;">🗑 Remove</a>`;
        list.appendChild(li);
    });
}

// Remove file from input (by recreating FileList)
function removeSelectedFile(idx) {
    const input = document.getElementById('email_attachments_input');
    const dt = new DataTransfer();
    Array.from(input.files).forEach((file, i) => {
        if (i !== idx) dt.items.add(file);
    });
    input.files = dt.files;
    updateAttachmentList();
}
