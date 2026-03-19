'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Context menu
// ══════════════════════════════════════════════════════════════════════════════

let _ctxNode = null;
let _ctxLaneParentId = null;
let _ctxCursorYear = null;

function showCtxMenu(e, node) {
  e.preventDefault();
  e.stopPropagation();
  hideTip();
  _ctxNode = node;
  _ctxLaneParentId = null;
  _ctxCursorYear = TL.cursorYear;
  const menu = document.getElementById('ctx-menu');
  document.getElementById('ctx-edit').style.display = '';
  const addChild = document.getElementById('ctx-add-child');
  if (addChild) addChild.style.display = node.type === 'span' ? '' : 'none';
  document.getElementById('ctx-delete').style.display = '';
  document.getElementById('ctx-add-node').style.display = 'none';
  menu.style.left = e.clientX + 'px';
  menu.style.top  = e.clientY + 'px';
  menu.classList.add('open');
}

function showCtxMenuBlank(e, parentId) {
  e.preventDefault();
  e.stopPropagation();
  hideTip();
  _ctxNode = null;
  _ctxLaneParentId = parentId;
  _ctxCursorYear = TL.cursorYear;
  const menu = document.getElementById('ctx-menu');
  document.getElementById('ctx-edit').style.display = 'none';
  document.getElementById('ctx-add-child').style.display = 'none';
  document.getElementById('ctx-delete').style.display = 'none';
  document.getElementById('ctx-add-node').style.display = '';
  menu.style.left = e.clientX + 'px';
  menu.style.top  = e.clientY + 'px';
  menu.classList.add('open');
}

function hideCtxMenu() {
  document.getElementById('ctx-menu').classList.remove('open');
  _ctxNode = null;
  _ctxLaneParentId = null;
  _ctxCursorYear = null;
}

// ══════════════════════════════════════════════════════════════════════════════
// Detail panel
// ══════════════════════════════════════════════════════════════════════════════

function openNodeDetail(node) {
  document.getElementById('detail-title').textContent = node.title;
  const yearRange = node.type === 'span'
    ? `<strong>${formatNodeDate(node,'start')}</strong> → <strong>${formatNodeDate(node,'end')}</strong>`
    : `<strong>${formatNodeDate(node,'start')}</strong>`;
  const descHtml = node.description
    ? renderMarkdown(node.description)
    : '<span class="detail-no-desc">No description</span>';
  document.getElementById('detail-body').innerHTML = `
    <div class="detail-color-bar" style="background:${node.color || '#5566bb'}"></div>
    <div class="detail-meta">
      <span class="detail-badge">${node.node_type || 'event'}</span>
      <span class="detail-badge imp-${node.importance}">${node.importance || 'moderate'}</span>
      <span class="detail-badge">${node.type === 'span' ? 'span' : 'point'}</span>
    </div>
    <div class="detail-year">${yearRange}</div>
    <div class="detail-desc markdown-body">${descHtml}</div>
    <div class="detail-actions">
      <button class="btn" onclick="openEditNodeModal('${node.id}')">✎ Edit</button>
      <button class="btn btn-danger" onclick="deleteNode('${node.id}')">Delete</button>
    </div>`;
  document.getElementById('detail-panel').classList.remove('collapsed');
}

// ══════════════════════════════════════════════════════════════════════════════
// Add / Edit Node modal
// ══════════════════════════════════════════════════════════════════════════════

const SPAN_COLORS = ['#5566bb','#6655aa','#558866','#885544','#558899','#997755','#664488','#447755','#994455','#776644'];
let _modalParentId = null;
let _modalEditId   = null;

function openAddNodeModal(parentId = null) {
  _modalParentId = parentId;
  _modalEditId   = null;
  document.getElementById('modal-node-title-hdr').textContent = 'Add Node';
  document.getElementById('mn-title').value       = '';
  clearDateFields('mn-start');
  clearDateFields('mn-end');

  // Prefill start date from cursor position on the timeline
  const prefillYear = TL.cursorYear ?? _ctxCursorYear;
  if (prefillYear != null) {
    const ppy = TL.levelPPY['root'];
    if (ppy) {
      const d = decimalToDate(prefillYear);
      const tlW = Math.max(200, vpWidth() - GANTT_SIDEBAR_W);
      const step = pickStep(tlW / ppy, ppy);
      const mc  = calMonthCount();
      const diy = calDaysInYear();

      populateMonthSelect(document.getElementById('mn-start-month'));
      document.getElementById('mn-start-year').value = d.year;
      if (step < 1) {
        document.getElementById('mn-start-month').value = d.month || '';
        syncDayMax('mn-start');
      }
      if (step < 1 / mc) {
        document.getElementById('mn-start-day').value = d.day || '';
      }
      if (step < 1 / diy) {
        document.getElementById('mn-start-hour').value = d.hour || '';
        document.getElementById('mn-start-minute').value = d.minute || '';
      }
    }
  }

  document.getElementById('mn-type').value        = 'point';
  document.getElementById('mn-node-type').value   = 'event';
  document.getElementById('mn-entity-type-row').style.display = 'none';
  document.getElementById('mn-entity-type').value = 'character';
  document.getElementById('mn-importance').value  = 'moderate';
  document.getElementById('mn-desc').value        = '';
  document.getElementById('mn-color').value       = SPAN_COLORS[0];
  document.getElementById('mn-opacity').value      = 0.6;
  document.getElementById('mn-opacity-val').textContent = '60%';
  document.getElementById('mn-end-row').style.display = 'none';
  document.getElementById('mn-parent-row').style.display = 'none';
  const parentName = parentId ? (TL.nodeById[parentId]?.title || parentId) : 'Root';
  document.getElementById('mn-parent-label').textContent = `Parent: ${parentName}`;
  document.getElementById('modal-node-overlay').classList.add('open');
}

function openEditNodeModal(nodeId) {
  const node = TL.nodeById[nodeId];
  if (!node) return;
  _modalEditId   = nodeId;
  _modalParentId = null;
  document.getElementById('modal-node-title-hdr').textContent = 'Edit Node';
  document.getElementById('mn-title').value       = node.title;
  // Use stored components if available, otherwise decompose from REAL
  if (node.start_year != null) {
    populateMonthSelect(document.getElementById('mn-start-month'));
    document.getElementById('mn-start-year').value   = node.start_year;
    document.getElementById('mn-start-month').value  = node.start_month || '';
    document.getElementById('mn-start-day').value    = node.start_day   || '';
    document.getElementById('mn-start-hour').value   = node.start_hour  || '';
    document.getElementById('mn-start-minute').value = node.start_minute || '';
    syncDayMax('mn-start');
  } else {
    setDateFields('mn-start', node.start_date);
  }
  if (node.end_year != null) {
    populateMonthSelect(document.getElementById('mn-end-month'));
    document.getElementById('mn-end-year').value   = node.end_year;
    document.getElementById('mn-end-month').value  = node.end_month || '';
    document.getElementById('mn-end-day').value    = node.end_day   || '';
    document.getElementById('mn-end-hour').value   = node.end_hour  || '';
    document.getElementById('mn-end-minute').value = node.end_minute || '';
    syncDayMax('mn-end');
  } else if (node.end_date != null) {
    setDateFields('mn-end', node.end_date);
  } else {
    clearDateFields('mn-end');
  }
  document.getElementById('mn-type').value        = node.type;
  document.getElementById('mn-node-type').value   = node.node_type || 'event';
  document.getElementById('mn-entity-type-row').style.display = (node.node_type === 'character') ? '' : 'none';
  document.getElementById('mn-importance').value  = node.importance || 'moderate';
  document.getElementById('mn-desc').value        = node.description || '';
  document.getElementById('mn-color').value       = node.color || SPAN_COLORS[0];
  const opVal = node.opacity != null ? node.opacity : 0.6;
  document.getElementById('mn-opacity').value      = opVal;
  document.getElementById('mn-opacity-val').textContent = Math.round(opVal * 100) + '%';
  document.getElementById('mn-end-row').style.display = node.type === 'span' ? '' : 'none';
  document.getElementById('mn-parent-label').textContent = '';

  // Populate parent select with root + all loaded span nodes (excluding this node)
  const sel = document.getElementById('mn-parent-select');
  sel.innerHTML = '<option value="">Root (top level)</option>';
  const spans = Object.values(TL.nodeById)
    .filter(n => n.type === 'span' && n.id !== nodeId)
    .sort((a, b) => a.start_date - b.start_date);
  for (const s of spans) {
    const opt = document.createElement('option');
    opt.value = s.id;
    opt.textContent = s.title;
    sel.appendChild(opt);
  }
  sel.value = node.parent_id ?? '';
  document.getElementById('mn-parent-row').style.display = '';

  document.getElementById('modal-node-overlay').classList.add('open');
}

function readDateComponents(prefix) {
  const y = document.getElementById(`${prefix}-year`).value;
  if (y === '') return null;
  return {
    year:   parseInt(y, 10),
    month:  parseInt(document.getElementById(`${prefix}-month`).value, 10) || null,
    day:    parseInt(document.getElementById(`${prefix}-day`).value, 10)   || null,
    hour:   parseInt(document.getElementById(`${prefix}-hour`).value, 10)  || null,
    minute: parseInt(document.getElementById(`${prefix}-minute`).value, 10) || null,
  };
}

async function submitNodeModal(e) {
  e.preventDefault();
  const type        = document.getElementById('mn-type').value;
  const title       = document.getElementById('mn-title').value.trim();
  const startComp   = readDateComponents('mn-start');
  const endComp     = type === 'span' ? readDateComponents('mn-end') : null;
  const node_type   = document.getElementById('mn-node-type').value;
  const importance  = document.getElementById('mn-importance').value;
  const description = document.getElementById('mn-desc').value.trim() || null;
  const color       = document.getElementById('mn-color').value;
  const opacity     = parseFloat(document.getElementById('mn-opacity').value);

  if (!title || !startComp) return;
  if (type === 'span' && !endComp) {
    alert('Span needs an end date greater than start date.'); return;
  }

  const payload = {
    type, title, node_type, importance, description, color, opacity,
    start_year: startComp.year, start_month: startComp.month,
    start_day: startComp.day, start_hour: startComp.hour, start_minute: startComp.minute,
  };
  if (endComp) {
    payload.end_year = endComp.year; payload.end_month = endComp.month;
    payload.end_day = endComp.day; payload.end_hour = endComp.hour; payload.end_minute = endComp.minute;
  }

  if (_modalEditId) {
    // Check if parent is being changed
    const prevNode = TL.nodeById[_modalEditId];
    const prevParentId = prevNode?.parent_id ?? null;
    const selectedParent = document.getElementById('mn-parent-select').value;
    const newParentId = selectedParent === '' ? null : selectedParent;
    const parentChanged = newParentId !== prevParentId;
    if (parentChanged) payload.parent_id = newParentId ?? '';

    // Update existing
    const node = await apiFetch(`${API}/nodes/${_modalEditId}`, {
      method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    }).then(r => r.json());
    TL.nodeById[node.id] = node;

    if (parentChanged) {
      // Invalidate both old and new parent caches
      delete TL.childCache[prevParentId ?? 'root'];
      delete TL.childCache[newParentId ?? 'root'];
      TL.expanded.delete(_modalEditId);
      await Promise.all([tlFetch(prevParentId), tlFetch(newParentId)]);
    } else {
      const parentKey = node.parent_id ?? 'root';
      delete TL.childCache[parentKey];
      await tlFetch(node.parent_id);
    }
  } else {
    // Create new
    payload.parent_id = _modalParentId ?? null;
    const node = await apiFetch(`${API}/nodes`, {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    }).then(r => r.json());
    TL.nodeById[node.id] = node;
    const key = _modalParentId ?? 'root';
    delete TL.childCache[key];
    await tlFetch(_modalParentId);

    // Auto-create bound entity for character nodes
    if (node_type === 'character') {
      const entityType = document.getElementById('mn-entity-type').value || 'character';
      await apiFetch(`${API}/entities`, {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ name: title, entity_type: entityType, color: color, node_id: node.id }),
      });
      // Refresh entities list if the panel is open
      if (typeof loadEntitiesList === 'function') loadEntitiesList();
    }
  }

  document.getElementById('modal-node-overlay').classList.remove('open');
  renderWorld();
}

async function deleteNode(nodeId) {
  const node = TL.nodeById[nodeId];
  if (!confirm(`Delete "${node?.title || nodeId}"? This also deletes all children.`)) return;
  await apiFetch(`${API}/nodes/${nodeId}`, { method: 'DELETE' });
  // Clean up caches
  const key = node?.parent_id ?? 'root';
  delete TL.childCache[key];
  delete TL.childCache[nodeId];
  TL.expanded.delete(nodeId);
  collapseDescendants(nodeId);
  await tlFetch(node?.parent_id);
  document.getElementById('detail-panel').classList.add('collapsed');
  renderWorld();
}

// ══════════════════════════════════════════════════════════════════════════════
// Status bar
// ══════════════════════════════════════════════════════════════════════════════

function updateStatusBar() {
  const total = Object.values(TL.childCache).reduce((s, arr) => s + (arr?.length || 0), 0);
  const sc = document.getElementById('status-count');
  if (sc) sc.textContent = `${total} node${total !== 1 ? 's' : ''} loaded`;

  const nodes = TL.childCache['root'] || [];
  const sv = document.getElementById('status-year-range');
  if (sv && nodes.length) {
    let min = Infinity, max = -Infinity;
    for (const n of nodes) {
      if (n.start_date < min) min = n.start_date;
      const end = n.end_date ?? n.start_date;
      if (end > max) max = end;
    }
    sv.textContent = `${formatDate(min)} → ${formatDate(max)}`;
  }

  const sl = document.getElementById('status-lod');
  if (sl) {
    const ppy = TL.levelPPY['root'];
    if (ppy != null) {
      const label = lodLabel(ppy);
      sl.textContent = label === 'all' ? '' : `LOD: ${label}`;
      sl.style.display = label === 'all' ? 'none' : '';
    } else {
      sl.style.display = 'none';
    }
  }
}
