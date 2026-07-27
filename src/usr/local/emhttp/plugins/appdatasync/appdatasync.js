let currentConfig = { backup_destination: '/mnt/user/backup/appdata', store_by_group: true, hosts: [{ name: 'local' }], groups: {} };
let pollTimer = null;
let logOffset = 0;

async function postJson(params, retries = 1) {
  let lastErr;
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const resp = await fetch(`/plugins/${PLUGIN_NAME}/include/ajax.php`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    params.toString(),
      });
      if (resp.status === 403) {
        throw new Error('Access denied.');
      }
      if (!resp.ok) {
        throw new Error('Server returned ' + resp.status);
      }
      const text = await resp.text();
      if (!text) {
        throw new Error('Empty response from server.');
      }
      try {
        return JSON.parse(text);
      } catch {
        throw new Error('Invalid response from server.');
      }
    } catch (e) {
      lastErr = e;
      if (attempt < retries) {
        await new Promise(r => setTimeout(r, 500));
      }
    }
  }
  throw lastErr;
}

function setMsg(id, text, isError) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = text;
  el.className = 'adb-msg' + (isError ? ' red-text' : '');
}

function clearMsg(id) {
  setMsg(id, '', false);
}

function normalizeCarriageReturns(text) {
  return String(text)
    .split('\n')
    .map(line => {
      const idx = line.lastIndexOf('\r');
      return idx === -1 ? line : line.slice(idx + 1);
    })
    .join('\n');
}

function showOutput(text, isError) {
  const el = document.getElementById('adbOutput');
  el.textContent = normalizeCarriageReturns(text);
  el.style.display = 'block';
  const wrap = el.closest('.adb-log-wrap');
  if (wrap) wrap.style.display = 'block';
  el.classList.toggle('has-error', isError);
  el.scrollTop = el.scrollHeight;
}

function appendOutput(text) {
  const el = document.getElementById('adbOutput');
  if (el.style.display === 'none') {
    showOutput(text, false);
  } else {
    el.textContent = normalizeCarriageReturns(el.textContent + text);
    el.scrollTop = el.scrollHeight;
  }
}

function clearLog() {
  const el = document.getElementById('adbOutput');
  el.textContent = '';
  el.style.display = 'none';
  const wrap = el.closest('.adb-log-wrap');
  if (wrap) wrap.style.display = 'none';
  logOffset = 0;
}

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

let modalOkCallback = null;
let lastFocusedElement = null;

function openModal(title, bodyHtml, okCallback) {
  lastFocusedElement = document.activeElement;
  modalOkCallback = okCallback;
  const modal = document.getElementById('adbModal');
  document.getElementById('adbModalTitle').textContent = title;
  document.getElementById('adbModalBody').innerHTML = bodyHtml;
  modal.style.display = 'block';
  const okBtn = document.getElementById('adbModalOk');
  // Run the callback first (it reads the selection from the modal body), then
  // close. closeModal() clears the body, so the callback must run before it.
  okBtn.onclick = () => {
    const cb = modalOkCallback;
    if (cb) cb();
    closeModal();
  };
  okBtn.focus();
  trapModalFocus(modal);
}

function closeModal() {
  document.getElementById('adbModal').style.display = 'none';
  document.getElementById('adbModalBody').innerHTML = '';
  modalOkCallback = null;
  if (lastFocusedElement) {
    lastFocusedElement.focus();
    lastFocusedElement = null;
  }
}

function trapModalFocus(modal) {
  const focusable = modal.querySelectorAll('input, select, textarea, button, [href]');
  if (focusable.length === 0) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  modal.addEventListener('keydown', function handler(e) {
    if (e.key === 'Escape') {
      closeModal();
      modal.removeEventListener('keydown', handler);
    } else if (e.key === 'Tab') {
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  });
}

document.getElementById('adbModal').addEventListener('click', (e) => {
  if (e.target.id === 'adbModal') closeModal();
});

function pickGroup(callback) {
  const groups = currentConfig.groups || {};
  const names = Object.keys(groups);
  if (names.length === 0) {
    alert('No groups defined.');
    return;
  }
  const html = names.map(name =>
    `<label class="adb-modal-option"><input type="radio" name="modalGroup" value="${escapeHtml(name)}"> ${escapeHtml(name)}</label>`
  ).join('');
  openModal('Select Group', html, () => {
    const selected = document.querySelector('input[name="modalGroup"]:checked')?.value;
    if (selected) {
      callback(selected);
    }
  });
}

function pickConfiguredContainer(group, callback) {
  const containers = (currentConfig.groups[group] || [])
    .map(c => c.name)
    .filter(Boolean);
  if (containers.length === 0) {
    alert(`Group "${group}" has no containers.`);
    return;
  }
  const html = containers.map(name =>
    `<label class="adb-modal-option"><input type="radio" name="modalContainer" value="${escapeHtml(name)}"> ${escapeHtml(name)}</label>`
  ).join('');
  openModal(`Select Container in ${group}`, html, () => {
    const selected = document.querySelector('input[name="modalContainer"]:checked')?.value;
    if (selected) {
      callback(selected);
    }
  });
}

function pickBackupGroup() {
  pickGroup(group => runBackup(group));
}

function pickRestoreGroup() {
  pickGroup(group => runRestoreGroup(group));
}

function pickRestoreContainer() {
  pickGroup(group => {
    pickConfiguredContainer(group, container => runRestoreContainer(group, container));
  });
}

async function loadConfig() {
  clearMsg('configMsg');
  const data = await postJson(new URLSearchParams({ action: 'get_config', csrf_token: CSRF_TOKEN }));
  if (!data.success) {
    setMsg('configMsg', data.message || 'Failed to load config.', true);
    return;
  }
  currentConfig = data.config || currentConfig;
  renderConfig();
  updateStatus();
}

function renderConfig() {
  document.getElementById('backupDestination').value = currentConfig.backup_destination || '';
  document.getElementById('storeByGroup').value = String(Boolean(currentConfig.store_by_group));

  renderHosts();

  const container = document.getElementById('groupsContainer');
  container.innerHTML = '';
  const groups = currentConfig.groups || {};
  const names = Object.keys(groups);
  if (names.length === 0) {
    container.innerHTML = '<div class="adb-empty-state"><i class="fa fa-folder-open"></i>No groups defined yet. Add a group to organize containers for backup.</div>';
  } else {
    names.forEach(groupName => {
      container.appendChild(buildGroupNode(groupName, groups[groupName]));
    });
  }
  updateToggleGroupsButton();
}

function getHostNames() {
  const hosts = currentConfig.hosts || [];
  const names = hosts.filter(h => h && h.name).map(h => h.name);
  return names.length ? names : ['local'];
}

function buildHostOptions(selected) {
  return getHostNames().map(name => `<option value="${escapeHtml(name)}" ${name === selected ? 'selected' : ''}>${escapeHtml(name)}</option>`).join('');
}

function renderHosts() {
  const container = document.getElementById('hostsContainer');
  container.innerHTML = '';
  // The implicit 'local' host is always available for container host dropdowns,
  // but we don't show it in the editable Host Profiles list.
  const remoteHosts = (currentConfig.hosts || []).filter(h => h && h.name !== 'local');
  if (remoteHosts.length === 0) {
    container.innerHTML = '<div class="adb-empty-state"><i class="fa fa-server"></i>No remote hosts defined. The local host is always available.</div>';
    return;
  }

  const wrapper = document.createElement('div');
  wrapper.className = 'adb-host-table-wrap';
  const table = document.createElement('table');
  table.className = 'unraid tablesorter adb-host-table';
  table.innerHTML = `
    <thead>
      <tr>
        <td>Name</td>
        <td>SSH User</td>
        <td>SSH Key</td>
        <td>SSH Port</td>
        <td class="adb-host-actions"></td>
      </tr>
    </thead>
    <tbody></tbody>
  `;
  const tbody = table.querySelector('tbody');
  remoteHosts.forEach(host => tbody.appendChild(buildHostRow(host)));
  wrapper.appendChild(table);
  container.appendChild(wrapper);
  validateHostNames();
}

function buildHostRow(host) {
  const isLocal = host.name === 'local';
  const tr = document.createElement('tr');
  tr.className = isLocal ? 'adb-local-row' : '';
  tr.dataset.name = host.name || '';
  const sshKey = host.ssh_key || '';
  tr.innerHTML = `
    <td><input type="text" class="hName" value="${escapeHtml(host.name || '')}" ${isLocal ? 'disabled' : ''} onchange="rebuildHostsFromDom(); updateHostDropdowns(); validateHostNames();" style="${isLocal ? 'opacity:0.6;' : ''}"></td>
    <td><input type="text" class="hSshUser" value="${escapeHtml(host.ssh_user || '')}" ${isLocal ? 'disabled' : ''} onchange="rebuildHostsFromDom();" style="${isLocal ? 'opacity:0.6;' : ''}"></td>
    <td><input type="text" class="hSshKey" value="${escapeHtml(sshKey)}" title="${escapeHtml(sshKey)}" ${isLocal ? 'disabled' : ''} onchange="rebuildHostsFromDom();" style="${isLocal ? 'opacity:0.6;' : ''}"></td>
    <td><input type="number" class="hSshPort" value="${parseInt(host.ssh_port || 22, 10)}" min="1" max="65535" ${isLocal ? 'disabled' : ''} onchange="rebuildHostsFromDom();" style="${isLocal ? 'opacity:0.6;' : ''}"></td>
    <td class="adb-host-actions"></td>
  `;
  if (!isLocal) {
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'adb-icon-btn';
    removeBtn.title = 'Remove host';
    removeBtn.innerHTML = '<i class="fa fa-times"></i>';
    removeBtn.onclick = () => {
      if (hostIsInUse(host.name)) {
        const used = containersUsingHost(host.name);
        const list = used.slice(0, 5).map(c => `  • ${c.container} in ${c.group}`).join('\n');
        const more = used.length > 5 ? `\n  …and ${used.length - 5} more` : '';
        if (!confirm(`Host "${host.name}" is used by containers:\n${list}${more}\n\nRemove it anyway?`)) {
          return;
        }
      }
      tr.remove();
      rebuildHostsFromDom();
      updateHostDropdowns();
      validateHostNames();
    };
    tr.querySelector('.adb-host-actions').appendChild(removeBtn);
  }
  return tr;
}

function hostIsInUse(hostName) {
  return containersUsingHost(hostName).length > 0;
}

function containersUsingHost(hostName) {
  const used = [];
  const groups = currentConfig.groups || {};
  for (const [groupName, containers] of Object.entries(groups)) {
    for (const container of containers) {
      if ((container.host || 'local') === hostName) {
        used.push({ group: groupName, container: container.name || '(unnamed)' });
      }
    }
  }
  return used;
}

function validateHostNames() {
  const rows = document.querySelectorAll('#hostsContainer tbody tr');
  const counts = {};
  rows.forEach(tr => {
    const input = tr.querySelector('.hName');
    if (!input || input.disabled) return;
    const name = input.value.trim();
    counts[name] = (counts[name] || 0) + 1;
  });
  let hasError = false;
  rows.forEach(tr => {
    const input = tr.querySelector('.hName');
    if (!input || input.disabled) return;
    const name = input.value.trim();
    const duplicate = name !== '' && counts[name] > 1;
    const invalid = name === 'local';
    input.classList.toggle('adb-invalid', duplicate || invalid || name === '');
    if (duplicate || invalid || name === '') hasError = true;
  });
  return !hasError;
}

function addHost() {
  const container = document.getElementById('hostsContainer');
  let table = container.querySelector('table');
  if (!table) {
    renderHosts();
    table = container.querySelector('table');
    if (!table) return;
  }
  const tbody = table.querySelector('tbody');
  const names = new Set(getHostNames());
  let i = 1;
  while (names.has(`host-${i}`)) {
    i++;
  }
  tbody.appendChild(buildHostRow({ name: `host-${i}` }));
  rebuildHostsFromDom();
  updateHostDropdowns();
  validateHostNames();
}

function rebuildHostsFromDom() {
  const hosts = [];
  hosts.push({ name: 'local' });
  document.querySelectorAll('#hostsContainer tbody tr').forEach(tr => {
    const name = tr.querySelector('.hName')?.value.trim() || '';
    if (name === '' || name === 'local') {
      return;
    }
    hosts.push({
      name: name,
      ssh_user: tr.querySelector('.hSshUser')?.value.trim() || '',
      ssh_key: tr.querySelector('.hSshKey')?.value.trim() || '',
      ssh_port: parseInt(tr.querySelector('.hSshPort')?.value || '22', 10) || 22,
    });
  });
  currentConfig.hosts = hosts;
}

function updateHostDropdowns() {
  document.querySelectorAll('#groupsContainer .cHost').forEach(sel => {
    const selected = sel.value;
    const names = getHostNames();
    sel.innerHTML = buildHostOptions(names.includes(selected) ? selected : 'local');
    updateHostFields(sel.closest('tr'));
  });
}

function buildGroupNode(groupName, containers) {
  const fieldset = document.createElement('fieldset');
  fieldset.className = 'adb-group';
  fieldset.dataset.group = groupName;

  const legend = document.createElement('legend');
  legend.innerHTML = `
    <span class="adb-group-toggle"></span>
    <i class="fa fa-folder-o adb-group-icon"></i>
    <input type="text" class="groupName" value="${escapeHtml(groupName)}" style="width:200px;" onchange="renameGroup(this)">
    <span class="adb-group-count">${(containers || []).length} container${(containers || []).length === 1 ? '' : 's'}</span>
  `;
  const removeBtn = document.createElement('button');
  removeBtn.type = 'button';
  removeBtn.innerHTML = '<i class="fa fa-trash-o"></i> Remove Group';
  removeBtn.onclick = () => {
    const count = (currentConfig.groups[groupName] || []).length;
    if (count > 0 && !confirm(`Remove group "${groupName}" and its ${count} container${count === 1 ? '' : 's'}?`)) {
      return;
    }
    fieldset.remove();
    rebuildGroupsFromDom();
    updateToggleGroupsButton();
  };
  legend.appendChild(removeBtn);
  fieldset.appendChild(legend);

  const body = document.createElement('div');
  body.className = 'adb-group-body';

  const table = document.createElement('table');
  table.className = 'unraid tablesorter adb-container-table';
  table.innerHTML = `
    <thead>
      <tr>
        <td>Container</td>
        <td>Host</td>
        <td>Appdata Path</td>
        <td>Restart</td>
        <td>Start Delay</td>
        <td>Override</td>
        <td><i class="fa fa-trash-o" title="Remove"></i></td>
      </tr>
    </thead>
    <tbody></tbody>
  `;
  const tbody = table.querySelector('tbody');
  (containers || []).forEach(container => tbody.appendChild(buildContainerRow(container)));
  body.appendChild(table);

  const addBtn = document.createElement('button');
  addBtn.type = 'button';
  addBtn.innerHTML = '<i class="fa fa-plus"></i> Add Container';
  addBtn.style.marginTop = '8px';
  addBtn.onclick = () => {
    tbody.appendChild(buildContainerRow({}));
    rebuildGroupsFromDom();
    validateContainerNames(fieldset);
  };
  body.appendChild(addBtn);

  fieldset.appendChild(body);

  legend.addEventListener('click', (e) => {
    if (e.target.closest('input') || e.target.closest('button')) return;
    fieldset.classList.toggle('collapsed');
    updateToggleGroupsButton();
  });

  return fieldset;
}

function buildContainerRow(container) {
  const hostName = container.host || 'local';
  const override = Boolean(container.ssh_override);

  const mainRow = document.createElement('tr');
  mainRow.className = 'adb-container-main-row';
  mainRow.dataset.name = container.name || '';
  mainRow.innerHTML = `
    <td>
      <div class="adb-container-name">
        <input type="text" class="cName" value="${escapeHtml(container.name || '')}" onchange="validateContainerNames(this.closest('fieldset'))">
        <input type="button" class="cPickContainer" value="⋯" onclick="pickRunningContainer(this.closest('tr'))" title="Pick running container on host">
      </div>
    </td>
    <td>
      <select class="cHost" onchange="updateHostFields(this.closest('tr'))">
        ${buildHostOptions(hostName)}
      </select>
    </td>
    <td><input type="text" class="cPath" value="${escapeHtml(container.appdata_path || '')}" placeholder="/mnt/user/appdata/…" autocomplete="off" spellcheck="false"></td>
    <td><select class="cRestart"><option value="true" ${(container.restart ?? true) ? 'selected' : ''}>Yes</option><option value="false" ${!(container.restart ?? true) ? 'selected' : ''}>No</option></select></td>
    <td><input type="number" class="cDelay" value="${parseInt(container.start_delay || 0, 10)}" min="0"></td>
    <td class="adb-override-cell"><label class="adb-override" title="Use per-container SSH settings instead of the host profile"><input type="checkbox" class="cOverride" ${override ? 'checked' : ''} onchange="updateHostFields(this.closest('tr'))"> override</label><span class="adb-override-na" title="Only used for remote hosts">—</span></td>
    <td class="cActions"></td>
  `;

  const sshRow = document.createElement('tr');
  sshRow.className = 'adb-ssh-row';
  sshRow.dataset.name = container.name || '';
  sshRow.innerHTML = `
    <td colspan="7">
      <div class="adb-ssh-fields">
        <label>SSH User <input type="text" class="cSshUser" value="${escapeHtml(container.ssh_user || '')}"></label>
        <label>SSH Key <input type="text" class="cSshKey" value="${escapeHtml(container.ssh_key || '')}"></label>
        <label>SSH Port <input type="number" class="cSshPort" value="${parseInt(container.ssh_port || 22, 10)}" min="1" max="65535"></label>
      </div>
    </td>
  `;
  mainRow._sshRow = sshRow;

  updateHostFields(mainRow);

  const removeBtn = mainRow.querySelector('.cActions');
  if (removeBtn) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'adb-icon-btn';
    btn.title = 'Remove container';
    btn.innerHTML = '<i class="fa fa-trash-o"></i>';
    btn.onclick = () => {
      const fieldset = mainRow.closest('fieldset');
      mainRow.remove();
      sshRow.remove();
      rebuildGroupsFromDom();
      if (fieldset) validateContainerNames(fieldset);
    };
    removeBtn.appendChild(btn);
  }

  const fragment = document.createDocumentFragment();
  fragment.appendChild(mainRow);
  fragment.appendChild(sshRow);
  return fragment;
}

function updateHostFields(tr) {
  const hostName = tr.querySelector('.cHost')?.value || 'local';
  const isLocal = hostName === 'local';
  const override = tr.querySelector('.cOverride')?.checked || false;
  const sshRow = tr._sshRow;
  const overrideCell = tr.querySelector('.cOverride')?.closest('td');
  if (overrideCell) {
    /* Keep the cell present (toggling a class) so the table columns stay aligned
       across local and remote rows; CSS swaps the checkbox for a muted dash. */
    overrideCell.classList.toggle('is-local', isLocal);
  }
  if (sshRow) {
    sshRow.style.display = (!isLocal && override) ? 'table-row' : 'none';
  }
  const sshEnabled = !isLocal && override;
  if (sshRow) {
    ['.cSshUser', '.cSshKey', '.cSshPort'].forEach(sel => {
      const el = sshRow.querySelector(sel);
      if (el) {
        el.disabled = !sshEnabled;
        el.style.opacity = sshEnabled ? '' : '0.5';
        el.title = isLocal ? 'Only used for remote hosts' : (override ? 'Per-container SSH override' : 'Inherited from host profile');
      }
    });
  }
  const pickBtn = tr.querySelector('.cPickContainer');
  if (pickBtn) {
    pickBtn.title = isLocal ? 'Pick running local container' : `Pick running container on ${hostName}`;
  }
  if (isLocal) {
    const cb = tr.querySelector('.cOverride');
    if (cb) cb.checked = false;
  }
}

function getHostProfile(name) {
  return (currentConfig.hosts || []).find(h => h && h.name === name);
}

async function pickRunningContainer(tr) {
  const hostName = tr.querySelector('.cHost')?.value || 'local';
  const isLocal = hostName === 'local';
  const override = !isLocal && (tr.querySelector('.cOverride')?.checked || false);
  const sshRow = tr._sshRow;
  const nameInput = tr.querySelector('.cName');

  const params = new URLSearchParams({ action: 'get_containers', csrf_token: CSRF_TOKEN, host: hostName });
  if (!isLocal) {
    const profile = getHostProfile(hostName) || {};
    params.set('ssh_user', override ? (sshRow?.querySelector('.cSshUser')?.value.trim() || '') : (profile.ssh_user || ''));
    params.set('ssh_key', override ? (sshRow?.querySelector('.cSshKey')?.value.trim() || '') : (profile.ssh_key || ''));
    params.set('ssh_port', String(override ? (parseInt(sshRow?.querySelector('.cSshPort')?.value || '22', 10) || 22) : (profile.ssh_port || 22)));
  }

  let containers;
  try {
    const data = await postJson(params);
    if (!data.success || !Array.isArray(data.containers)) {
      alert(data.message || 'Could not load container list.');
      return;
    }
    containers = data.containers;
  } catch (e) {
    alert('Error loading containers: ' + e.message);
    return;
  }

  if (containers.length === 0) {
    alert('No running containers found on host.');
    return;
  }

  const html = containers.map(c =>
    `<label class="adb-modal-option"><input type="radio" name="modalRunningContainer" value="${escapeHtml(c)}"> ${escapeHtml(c)}</label>`
  ).join('');
  openModal(`Select Container on ${hostName}`, html, () => {
    const selected = document.querySelector('input[name="modalRunningContainer"]:checked')?.value;
    if (selected && nameInput) {
      nameInput.value = selected;
    }
  });
}

function renameGroup(input) {
  const fieldset = input.closest('fieldset');
  const oldName = fieldset.dataset.group;
  const newName = input.value.trim();
  if (!newName) {
    input.value = oldName;
    return;
  }
  fieldset.dataset.group = newName;
  rebuildGroupsFromDom();
  validateGroupNames();
}

function validateGroupNames() {
  const fieldsets = document.querySelectorAll('#groupsContainer fieldset');
  const counts = {};
  fieldsets.forEach(fs => {
    const name = fs.querySelector('.groupName')?.value.trim() || fs.dataset.group || '';
    counts[name] = (counts[name] || 0) + 1;
  });
  let hasError = false;
  fieldsets.forEach(fs => {
    const input = fs.querySelector('.groupName');
    if (!input) return;
    const name = input.value.trim();
    const duplicate = name !== '' && counts[name] > 1;
    input.classList.toggle('adb-invalid', duplicate || name === '');
    if (duplicate || name === '') hasError = true;
  });
  return !hasError;
}

function validateContainerNames(fieldset) {
  if (!fieldset) return;
  const rows = fieldset.querySelectorAll('tbody tr.adb-container-main-row');
  const counts = {};
  rows.forEach(tr => {
    const input = tr.querySelector('.cName');
    const name = input?.value.trim() || '';
    if (name) counts[name] = (counts[name] || 0) + 1;
  });
  rows.forEach(tr => {
    const input = tr.querySelector('.cName');
    if (!input) return;
    const name = input.value.trim();
    const duplicate = name !== '' && counts[name] > 1;
    input.classList.toggle('adb-invalid', duplicate || name === '');
  });
}

function rebuildGroupsFromDom() {
  rebuildHostsFromDom();
  const groups = {};
  document.querySelectorAll('#groupsContainer fieldset').forEach(fs => {
    const groupName = fs.querySelector('.groupName')?.value.trim() || fs.dataset.group || 'unnamed';
    fs.dataset.group = groupName;
    groups[groupName] = [];
    fs.querySelectorAll('tbody tr.adb-container-main-row').forEach(tr => {
      const hostName = tr.querySelector('.cHost')?.value.trim() || 'local';
      const override = tr.querySelector('.cOverride')?.checked || false;
      const sshRow = tr._sshRow;
      const container = {
        name:         tr.querySelector('.cName')?.value.trim() || '',
        host:         hostName,
        appdata_path: tr.querySelector('.cPath')?.value.trim() || '',
        restart:      tr.querySelector('.cRestart')?.value === 'true',
        start_delay:  parseInt(tr.querySelector('.cDelay')?.value || '0', 10) || 0,
      };
      if (hostName !== 'local' && override && sshRow) {
        container.ssh_override = true;
        container.ssh_user = sshRow.querySelector('.cSshUser')?.value.trim() || '';
        container.ssh_key  = sshRow.querySelector('.cSshKey')?.value.trim() || '';
        container.ssh_port = parseInt(sshRow.querySelector('.cSshPort')?.value || '22', 10) || 22;
      }
      groups[groupName].push(container);
    });
    // Update container count in legend
    const countEl = fs.querySelector('.adb-group-count');
    if (countEl) {
      const count = groups[groupName].length;
      countEl.textContent = `${count} container${count === 1 ? '' : 's'}`;
    }
  });
  currentConfig.backup_destination = document.getElementById('backupDestination').value.trim();
  currentConfig.store_by_group     = document.getElementById('storeByGroup').value === 'true';
  currentConfig.groups             = groups;
}

function addGroup() {
  const container = document.getElementById('groupsContainer');
  if (container.querySelector('.adb-empty-state')) {
    container.innerHTML = '';
  }
  let i = 1;
  while (currentConfig.groups && currentConfig.groups[`group-${i}`]) {
    i++;
  }
  const name = `group-${i}`;
  container.appendChild(buildGroupNode(name, []));
  rebuildGroupsFromDom();
  updateToggleGroupsButton();
}

function setAllGroups(expand) {
  document.querySelectorAll('#groupsContainer fieldset').forEach(fs => {
    fs.classList.toggle('collapsed', !expand);
  });
  updateToggleGroupsButton();
}

function toggleAllGroups() {
  const anyExpanded = [...document.querySelectorAll('#groupsContainer fieldset')]
    .some(fs => !fs.classList.contains('collapsed'));
  setAllGroups(!anyExpanded);
}

function updateToggleGroupsButton() {
  const btn = document.getElementById('toggleGroupsBtn');
  if (!btn) return;
  const anyExpanded = [...document.querySelectorAll('#groupsContainer fieldset')]
    .some(fs => !fs.classList.contains('collapsed'));
  btn.innerHTML = anyExpanded
    ? '<i class="fa fa-angle-double-up"></i> Collapse All'
    : '<i class="fa fa-angle-double-down"></i> Expand All';
}

function toggleHosts() {
  const body = document.getElementById('hostsBody');
  if (!body) return;
  const collapsed = body.style.display === 'none';
  body.style.display = collapsed ? '' : 'none';
  updateToggleHostsButton(!collapsed);
}

function updateToggleHostsButton(collapsed) {
  const btn = document.getElementById('toggleHostsBtn');
  if (!btn) return;
  btn.innerHTML = collapsed
    ? '<i class="fa fa-chevron-down"></i> Expand'
    : '<i class="fa fa-chevron-up"></i> Collapse';
}

function validateConfig() {
  let valid = true;
  clearMsg('configMsg');

  const dest = document.getElementById('backupDestination').value.trim();
  if (!dest || !dest.startsWith('/mnt/')) {
    setMsg('configMsg', 'Backup destination must be a path under /mnt/.', true);
    document.getElementById('backupDestination').classList.add('adb-invalid');
    valid = false;
  } else {
    document.getElementById('backupDestination').classList.remove('adb-invalid');
  }

  if (!validateHostNames()) {
    setMsg('configMsg', 'Fix duplicate or invalid host names.', true);
    valid = false;
  }

  if (!validateGroupNames()) {
    setMsg('configMsg', 'Fix duplicate or empty group names.', true);
    valid = false;
  }

  document.querySelectorAll('#groupsContainer fieldset').forEach(fs => {
    validateContainerNames(fs);
  });

  let missingContainerName = false;
  document.querySelectorAll('#groupsContainer .cName.adb-invalid').forEach(el => {
    missingContainerName = true;
  });
  if (missingContainerName) {
    setMsg('configMsg', 'Fix duplicate or empty container names.', true);
    valid = false;
  }

  return valid;
}

async function saveConfig() {
  rebuildGroupsFromDom();
  if (!validateConfig()) return;

  setMsg('configMsg', 'Saving…', false);
  try {
    const data = await postJson(new URLSearchParams({
      action:     'save_config',
      csrf_token: CSRF_TOKEN,
      config:     JSON.stringify(currentConfig),
    }));
    setMsg('configMsg', data.success ? 'Saved.' : ('Error: ' + data.message), !data.success);
    if (data.success) {
      await loadConfig();
    }
  } catch (e) {
    setMsg('configMsg', 'Error: ' + e.message, true);
  }
}

async function runBackup(group) {
  const params = new URLSearchParams({
    action:     'run_backup',
    csrf_token: CSRF_TOKEN,
    dry_run:    String(document.getElementById('dryRunCheck').checked),
    debug:      String(document.getElementById('debugCheck').checked),
  });
  if (group) {
    params.set('group', group);
  }
  await startJob(params, 'Backup');
}

async function runRestoreGroup(group) {
  if (!group) {
    alert('No group selected.');
    return;
  }
  if (!confirm(`Restore group "${group}" from the latest backup?\nThis will overwrite current appdata.`)) {
    return;
  }
  const params = new URLSearchParams({
    action:     'run_restore',
    csrf_token: CSRF_TOKEN,
    group:      group,
    dry_run:    String(document.getElementById('dryRunCheck').checked),
    debug:      String(document.getElementById('debugCheck').checked),
  });
  await startJob(params, 'Restore');
}

async function runRestoreContainer(group, container) {
  if (!group || !container) {
    alert('Select a group and container.');
    return;
  }
  if (!confirm(`Restore container "${container}" in group "${group}"?\nThis will overwrite current appdata.`)) {
    return;
  }
  const params = new URLSearchParams({
    action:      'run_restore_container',
    csrf_token:  CSRF_TOKEN,
    group:       group,
    container:   container,
    dry_run:     String(document.getElementById('dryRunCheck').checked),
    debug:       String(document.getElementById('debugCheck').checked),
  });
  await startJob(params, 'Restore');
}

async function startJob(params, label) {
  logOffset = 0;
  document.getElementById('adbOutput').textContent = '';
  showOutput(`${label} starting…`, false);
  setButtonsDisabled(true);

  try {
    const data = await postJson(params);
    if (!data.success) {
      showOutput('Error: ' + data.message, true);
      setButtonsDisabled(false);
      return;
    }
    pollTimer = setInterval(pollLog, document.hidden ? 5000 : 1500);
  } catch (e) {
    showOutput('Error: ' + e.message, true);
    setButtonsDisabled(false);
  }
}

async function pollLog() {
  try {
    const data = await postJson(new URLSearchParams({
      action:     'poll_log',
      csrf_token: CSRF_TOKEN,
      offset:     logOffset,
    }));
    if (!data.success) {
      return;
    }
    if (data.content) {
      appendOutput(data.content);
      logOffset = data.offset;
    }
    if (data.done || data.failed) {
      clearInterval(pollTimer);
      pollTimer = null;
      setButtonsDisabled(false);
      updateStatus();
      if (data.failed) {
        appendOutput('\nFAILED');
        const el = document.getElementById('adbOutput');
        el.classList.add('has-error');
      }
    }
  } catch (e) {
    // Surface the error once, then stop polling if it persists?
    // For now keep polling; transient errors happen.
  }
}

function setButtonsDisabled(disabled) {
  ['backupAllBtn', 'backupGroupBtn', 'restoreGroupBtn', 'restoreContainerBtn'].forEach(id => {
    document.getElementById(id).disabled = disabled;
  });
  if (disabled) {
    setStatusIcon('running');
  } else {
    updateStatus();
  }
}

function setStatusIcon(state) {
  const icon = document.getElementById('adbStatusIcon');
  if (!icon) return;
  const glyphs = {
    idle:    'fa fa-check-circle',
    running: 'fa fa-refresh fa-spin',
    failed:  'fa fa-exclamation-circle',
    unknown: 'fa fa-circle-o-notch fa-spin',
  };
  icon.className = 'adb-status-icon ' + (glyphs[state] || glyphs.unknown) + ' ' + (state || 'unknown');
}

async function updateStatus() {
  try {
    const data = await postJson(new URLSearchParams({ action: 'job_status', csrf_token: CSRF_TOKEN }));
    const badge = document.getElementById('adbStatusBadge');
    const meta = document.getElementById('adbStatusMeta');
    if (!data.success) {
      badge.textContent = 'Unknown';
      badge.className = 'adb-status-badge';
      meta.textContent = '';
      setStatusIcon('unknown');
      return;
    }

    if (data.running) {
      badge.textContent = `Running (PID ${data.pid})`;
      badge.className = 'adb-status-badge running';
      setStatusIcon('running');
    } else {
      badge.textContent = 'Idle';
      badge.className = 'adb-status-badge idle';
      setStatusIcon('idle');
    }

    const parts = [];
    if (data.last_run) {
      parts.push(`Last run: ${data.last_run}`);
    }
    if (data.last_result) {
      parts.push(`Result: ${data.last_result}`);
      if (data.last_result === 'failed') {
        badge.className = 'adb-status-badge failed';
        setStatusIcon('failed');
      }
    }
    meta.textContent = parts.join(' · ');
  } catch (e) {
    document.getElementById('adbStatusBadge').textContent = 'Unknown';
    document.getElementById('adbStatusBadge').className = 'adb-status-badge';
    setStatusIcon('unknown');
  }
}

async function attachToRunningJob() {
  try {
    const status = await postJson(new URLSearchParams({ action: 'job_status', csrf_token: CSRF_TOKEN }));
    if (!status.success || !status.running) {
      return;
    }

    logOffset = 0;
    document.getElementById('adbOutput').textContent = '';
    showOutput(`Reconnected to running job (PID ${status.pid})…`, false);
    setButtonsDisabled(true);

    const log = await postJson(new URLSearchParams({ action: 'poll_log', csrf_token: CSRF_TOKEN, offset: 0 }));
    if (log.success && log.content) {
      showOutput(log.content, false);
      logOffset = log.offset;
    }

    if (!pollTimer) {
      pollTimer = setInterval(pollLog, document.hidden ? 5000 : 1500);
    }
  } catch (e) {
    // Fail silently; the periodic status update will show state.
  }
}

function updateScheduleVisibility() {
  const freq = document.getElementById('scheduleFrequency').value;
  document.getElementById('scheduleTimeWrap').style.display  = freq === 'custom' ? 'none' : 'inline';
  document.getElementById('scheduleDayWrap').style.display   = freq === 'weekly' ? 'inline' : 'none';
  document.getElementById('scheduleCronWrap').style.display  = freq === 'custom' ? 'inline' : 'none';
  if (freq === 'custom') {
    validateScheduleCron();
  } else {
    document.getElementById('scheduleCronHint').textContent = '';
    document.getElementById('scheduleCron').classList.remove('adb-invalid');
  }
}

function validateScheduleCron() {
  const expr = document.getElementById('scheduleCron').value;
  const hint = document.getElementById('scheduleCronHint');
  const fields = expr.trim().split(/\s+/);
  const patterns = [
    /^(\*|[\*\/\-,0-9]+)$/,
    /^(\*|[\*\/\-,0-9]+)$/,
    /^(\*|[\*\/\-,?LW0-9]+)$/i,
    /^(\*|[\*\/\-,A-Z0-9]+)$/i,
    /^(\*|[\*\/\-,A-Z0-9]+)$/i,
  ];
  let valid = fields.length === 5;
  if (valid) {
    for (let i = 0; i < 5; i++) {
      if (!patterns[i].test(fields[i])) {
        valid = false;
        break;
      }
    }
  }
  document.getElementById('scheduleCron').classList.toggle('adb-invalid', !valid);
  hint.textContent = valid ? 'Cron expression looks valid.' : 'Enter a valid 5-field cron expression.';
  hint.className = 'adb-field-hint' + (valid ? '' : ' adb-invalid-label');
  return valid;
}

async function loadSchedule() {
  try {
    const data = await postJson(new URLSearchParams({ action: 'get_settings', csrf_token: CSRF_TOKEN }));
    if (!data.success) {
      return;
    }
    const s = data.settings || {};
    document.getElementById('scheduleEnabled').value = String(Boolean(s.schedule_enabled ?? false));
    document.getElementById('scheduleFrequency').value = s.schedule_frequency ?? 'daily';
    document.getElementById('scheduleTime').value = s.schedule_time ?? '02:00';
    document.getElementById('scheduleDay').value = String(s.schedule_day ?? 0);
    document.getElementById('scheduleCron').value = s.schedule_cron ?? '0 2 * * *';
    const groups = Array.isArray(s.schedule_groups)
      ? s.schedule_groups
      : String(s.schedule_groups ?? '').split(',').map(g => g.trim()).filter(Boolean);
    document.getElementById('scheduleGroups').value = groups.join(',');
    document.getElementById('scheduleDryRun').value = String(Boolean(s.schedule_dry_run ?? false));
    updateScheduleVisibility();
    updateScheduleEnabledStyle();
  } catch (e) {
    // Fail silently; default controls remain usable.
  }
}

function updateScheduleEnabledStyle() {
  const section = document.getElementById('scheduleSection');
  section.classList.toggle('adb-schedule-enabled', document.getElementById('scheduleEnabled').value === 'true');
}

function gatherSchedule() {
  return {
    schedule_enabled:  document.getElementById('scheduleEnabled').value === 'true',
    schedule_frequency: document.getElementById('scheduleFrequency').value,
    schedule_time:     document.getElementById('scheduleTime').value,
    schedule_day:      parseInt(document.getElementById('scheduleDay').value, 10),
    schedule_cron:     document.getElementById('scheduleCron').value,
    schedule_groups:   document.getElementById('scheduleGroups').value.split(',').map(s => s.trim()).filter(Boolean),
    schedule_dry_run:  document.getElementById('scheduleDryRun').value === 'true',
  };
}

async function saveSchedule() {
  clearMsg('scheduleMsg');
  if (document.getElementById('scheduleFrequency').value === 'custom' && !validateScheduleCron()) {
    setMsg('scheduleMsg', 'Fix the cron expression before saving.', true);
    return;
  }
  setMsg('scheduleMsg', 'Saving…', false);
  try {
    const data = await postJson(new URLSearchParams({
      action:     'save_settings',
      csrf_token: CSRF_TOKEN,
      settings:   JSON.stringify(gatherSchedule()),
    }));
    setMsg('scheduleMsg', data.success ? 'Saved.' : ('Error: ' + data.message), !data.success);
    if (data.success) {
      updateScheduleEnabledStyle();
    }
  } catch (e) {
    setMsg('scheduleMsg', 'Error: ' + e.message, true);
  }
}

async function loadHistory() {
  const container = document.getElementById('historyContainer');
  try {
    const data = await postJson(new URLSearchParams({ action: 'get_history', csrf_token: CSRF_TOKEN }));
    if (!data.success) {
      container.innerHTML = '<div class="adb-empty-state"><i class="fa fa-exclamation-circle"></i>Could not load history.</div>';
      return;
    }
    renderHistory(data.history || []);
  } catch (e) {
    container.innerHTML = '<div class="adb-empty-state"><i class="fa fa-exclamation-circle"></i>Error loading history: ' + escapeHtml(e.message) + '</div>';
  }
}

function renderHistory(history) {
  const container = document.getElementById('historyContainer');
  if (!Array.isArray(history) || history.length === 0) {
    container.innerHTML = '<div class="adb-empty-state"><i class="fa fa-history"></i>No runs recorded yet.</div>';
    return;
  }

  const rows = history.map(h => {
    const started  = h.started_at  ? new Date(h.started_at).toLocaleString()  : '—';
    const finished = h.finished_at ? new Date(h.finished_at).toLocaleString() : '—';
    const groups   = Array.isArray(h.groups) ? h.groups.join(', ') : 'all';
    const resultClass = h.result === 'success' ? 'adb-result-success' : (h.result === 'failed' ? 'adb-result-failed' : '');
    const result   = h.result ? h.result.toUpperCase() : 'UNKNOWN';
    const basename = h.log_file ? String(h.log_file).split('/').pop() : '';
    const viewBtn  = basename
      ? '<button type="button" class="adb-icon-btn" onclick="viewHistoryLog(\'' + escapeHtml(basename) + '\')" title="View log"><i class="fa fa-file-text-o"></i></button>'
      : '';
    return '<tr>'
      + '<td title="Finished: ' + escapeHtml(finished) + '">' + escapeHtml(started) + '</td>'
      + '<td>' + escapeHtml(h.operation || 'Backup') + '</td>'
      + '<td>' + escapeHtml(groups) + '</td>'
      + '<td class="' + resultClass + '">' + escapeHtml(result) + '</td>'
      + '<td>' + viewBtn + '</td>'
      + '</tr>';
  }).join('');

  container.innerHTML =
    '<div class="adb-history-table-wrap">' +
      '<table class="unraid tablesorter adb-history-table">' +
        '<thead><tr><td>Started</td><td>Operation</td><td>Groups</td><td>Result</td><td></td></tr></thead>' +
        '<tbody>' + rows + '</tbody>' +
      '</table>' +
    '</div>';
}

function refreshHistory() {
  loadHistory();
}

async function viewHistoryLog(filename) {
  if (!filename) return;
  try {
    const data = await postJson(new URLSearchParams({ action: 'view_log', csrf_token: CSRF_TOKEN, filename }));
    if (!data.success) {
      showOutput('Error: ' + (data.message || 'Could not load log.'), true);
      return;
    }
    logOffset = data.content ? data.content.length : 0;
    showOutput(data.content || '(empty log)', false);
  } catch (e) {
    showOutput('Error: ' + e.message, true);
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  try {
    await loadConfig();
    await loadSchedule();
    await loadHistory();
    await attachToRunningJob();
    document.getElementById('scheduleFrequency').addEventListener('change', updateScheduleVisibility);
    document.getElementById('scheduleCron').addEventListener('input', validateScheduleCron);
    document.getElementById('scheduleEnabled').addEventListener('change', updateScheduleEnabledStyle);
    setInterval(updateStatus, 10000);
    $('#backupDestination').fileTreeAttach();
  } catch (e) {
    const badge = document.getElementById('adbStatusBadge');
    if (badge) {
      badge.textContent = 'Error';
      badge.className = 'adb-status-badge failed';
    }
    const meta = document.getElementById('adbStatusMeta');
    if (meta) meta.textContent = 'Initialization failed: ' + e.message;
    console.error(e);
  }
});

// Pause/slow polling when tab hidden to save resources.
document.addEventListener('visibilitychange', () => {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = setInterval(pollLog, document.hidden ? 5000 : 1500);
  }
});
