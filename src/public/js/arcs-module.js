'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Story Arcs Module
// ══════════════════════════════════════════════════════════════════════════════

const ArcState = { arcs: [], activeId: null, editingId: null };

const ARC_STATUS_LABEL = {
  planned: 'Planned', active: 'Active', resolved: 'Resolved', abandoned: 'Abandoned',
};

// ── List ─────────────────────────────────────────────────────────────────────

async function loadArcsList(search = '') {
  const data = await apiFetch(`${API}/arcs`).then(r => r.json());
  ArcState.arcs = data || [];
  renderArcList(search);
}

function renderArcList(search = '') {
  const listEl = document.getElementById('arc-list');
  let arcs = ArcState.arcs;
  if (search) {
    const q = search.toLowerCase();
    arcs = arcs.filter(a => a.name.toLowerCase().includes(q));
  }

  if (!arcs.length) {
    listEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:0.8rem;">No arcs yet.</div>';
    return;
  }

  // Group by status
  const groups = {};
  for (const a of arcs) {
    const s = a.status || 'active';
    if (!groups[s]) groups[s] = [];
    groups[s].push(a);
  }

  let html = '';
  for (const status of ['active', 'planned', 'resolved', 'abandoned']) {
    const group = groups[status];
    if (!group?.length) continue;
    html += `<div class="arc-group-label">${ARC_STATUS_LABEL[status]}</div>`;
    for (const a of group) {
      const active = a.id === ArcState.activeId ? ' active' : '';
      html += `<div class="arc-list-item${active}" data-arc-id="${a.id}">
        <span class="arc-color-dot" style="background:${a.color || '#c97b2a'}"></span>
        <span class="arc-list-name">${a.name}</span>
        <span class="arc-list-count">${a.node_count || 0}</span>
      </div>`;
    }
  }
  listEl.innerHTML = html;

  listEl.querySelectorAll('.arc-list-item').forEach(el => {
    el.addEventListener('click', () => openArc(el.dataset.arcId));
  });
}

// ── Viewer ───────────────────────────────────────────────────────────────────

async function openArc(id) {
  ArcState.activeId = id;
  renderArcList(document.getElementById('arc-search')?.value || '');
  showArcSection('viewer');

  const data = await apiFetch(`${API}/arcs/${id}`).then(r => r.json());
  const arc = data.arc;
  document.getElementById('arc-viewer-title').textContent = arc.name;
  document.getElementById('arc-viewer-meta').innerHTML =
    `<span class="arc-status-badge arc-status-${arc.status}">${ARC_STATUS_LABEL[arc.status] || arc.status}</span>` +
    `<span class="arc-color-swatch" style="background:${arc.color}"></span>` +
    `<span style="color:var(--text-dim);font-size:0.75rem;">Updated ${arc.updated_at?.slice(0,10) || '—'}</span>`;
  document.getElementById('arc-viewer-desc').innerHTML = renderMarkdown(arc.description || '');

  // Render arc nodes (events)
  renderArcNodes(data.nodes, id);
  // Render arc entities
  renderArcEntities(data.entities, id);

  // Highlight this arc's events on the gantt
  TL.selectedArc = {
    id: arc.id,
    color: arc.color || '#c97b2a',
    nodeIds: new Set((data.nodes || []).map(n => n.node_id)),
  };
  renderWorld();

  document.getElementById('arc-edit-btn').onclick = () => openArcEditor(arc);
  document.getElementById('arc-delete-btn').onclick = () => deleteArc(id, arc.name);
}

function clearArcHighlight() {
  if (!TL.selectedArc) return;
  TL.selectedArc = null;
  renderWorld();
}

function renderArcNodes(nodes, arcId) {
  const listEl = document.getElementById('arc-node-list');
  if (!nodes?.length) {
    listEl.innerHTML = '<div style="color:var(--text-dim);font-size:0.8rem;">No events yet.</div>';
  } else {
    listEl.innerHTML = nodes.map(n => `
      <div class="arc-linked-item" data-node-id="${n.node_id}">
        <span class="arc-position">${n.position ?? ''}</span>
        <span class="eln-title">${n.node_title || n.node_id}</span>
        ${n.arc_label ? `<span class="arc-label-badge">${n.arc_label}</span>` : ''}
        <span class="eln-date">${n.start_date != null ? formatNodeDate(n, 'start') : ''}</span>
        <button class="eln-unlink arc-unlink-node" data-node-id="${n.node_id}" title="Remove">×</button>
      </div>`).join('');

    listEl.querySelectorAll('.arc-linked-item').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('.arc-unlink-node')) return;
        navigateToNode(el.dataset.nodeId);
      });
    });
    listEl.querySelectorAll('.arc-unlink-node').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        await apiFetch(`${API}/arcs/${arcId}/nodes/${btn.dataset.nodeId}`, { method: 'DELETE' });
        openArc(arcId);
      });
    });
  }

  // Node search
  const searchInput = document.getElementById('arc-node-search');
  const resultsEl = document.getElementById('arc-node-results');
  let timeout;
  searchInput.value = '';
  resultsEl.innerHTML = '';
  searchInput.oninput = () => {
    clearTimeout(timeout);
    const q = searchInput.value.trim();
    if (!q) { resultsEl.innerHTML = ''; return; }
    timeout = setTimeout(async () => {
      const data = await apiFetch(`${API}/nodes/search?q=${encodeURIComponent(q)}&limit=8`).then(r => r.json());
      const linkedIds = new Set((nodes || []).map(n => n.node_id));
      const matches = (data.nodes || []).filter(n => !linkedIds.has(n.id));
      if (!matches.length) { resultsEl.innerHTML = '<div class="ent-search-hint">No matches</div>'; return; }
      resultsEl.innerHTML = matches.map(n => `
        <div class="ent-search-result" data-node-id="${n.id}">
          <span class="eln-icon" style="color:${n.color || '#5566bb'}">${nodeTypeIcon(n.node_type)}</span>
          <span class="eln-title">${n.title}</span>
          <span class="eln-date">${formatNodeDate(n, 'start')}</span>
        </div>`).join('');
      resultsEl.querySelectorAll('.ent-search-result').forEach(el => {
        el.addEventListener('click', async () => {
          await apiFetch(`${API}/arcs/${arcId}/nodes`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ node_id: el.dataset.nodeId }),
          });
          openArc(arcId);
        });
      });
    }, 200);
  };
}

function renderArcEntities(entities, arcId) {
  const listEl = document.getElementById('arc-entity-list');
  if (!entities?.length) {
    listEl.innerHTML = '<div style="color:var(--text-dim);font-size:0.8rem;">No participants yet.</div>';
  } else {
    listEl.innerHTML = entities.map(e => `
      <div class="arc-linked-item" data-entity-id="${e.entity_id}">
        <span class="rel-dot" style="background:${e.entity_color || '#7c6bff'}"></span>
        <span class="eln-title">${e.entity_name || e.entity_id}</span>
        ${e.role ? `<span class="arc-label-badge">${e.role}</span>` : ''}
        <span class="eln-badge">${e.entity_type || ''}</span>
        <button class="eln-unlink arc-unlink-ent" data-entity-id="${e.entity_id}" title="Remove">×</button>
      </div>`).join('');

    listEl.querySelectorAll('.arc-linked-item[data-entity-id]').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('.arc-unlink-ent')) return;
        switchModule('entities');
        setTimeout(() => openEntity(el.dataset.entityId), 100);
      });
    });
    listEl.querySelectorAll('.arc-unlink-ent').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        await apiFetch(`${API}/arcs/${arcId}/entities/${btn.dataset.entityId}`, { method: 'DELETE' });
        openArc(arcId);
      });
    });
  }

  // Entity search
  const searchInput = document.getElementById('arc-entity-search');
  const resultsEl = document.getElementById('arc-entity-results');
  let timeout;
  searchInput.value = '';
  resultsEl.innerHTML = '';
  searchInput.oninput = () => {
    clearTimeout(timeout);
    const q = searchInput.value.trim();
    if (!q) { resultsEl.innerHTML = ''; return; }
    timeout = setTimeout(async () => {
      const data = await apiFetch(`${API}/entities?search=${encodeURIComponent(q)}&limit=8`).then(r => r.json());
      const linkedIds = new Set((entities || []).map(e => e.entity_id));
      const matches = (data || []).filter(e => !linkedIds.has(e.id));
      if (!matches.length) { resultsEl.innerHTML = '<div class="ent-search-hint">No matches</div>'; return; }
      resultsEl.innerHTML = matches.map(e => `
        <div class="ent-search-result" data-entity-id="${e.id}">
          <span class="rel-dot" style="background:${e.color}"></span>
          <span class="eln-title">${e.name}</span>
          <span class="eln-badge">${e.entity_type}</span>
        </div>`).join('');
      resultsEl.querySelectorAll('.ent-search-result').forEach(el => {
        el.addEventListener('click', async () => {
          await apiFetch(`${API}/arcs/${arcId}/entities`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ entity_id: el.dataset.entityId }),
          });
          openArc(arcId);
        });
      });
    }, 200);
  };
}

// ── Editor ───────────────────────────────────────────────────────────────────

function openArcEditor(arc = null) {
  ArcState.editingId = arc ? arc.id : null;
  document.getElementById('arc-ed-name').value   = arc ? arc.name : '';
  document.getElementById('arc-ed-color').value  = arc ? (arc.color || '#c97b2a') : '#c97b2a';
  document.getElementById('arc-ed-status').value = arc ? (arc.status || 'active') : 'active';
  document.getElementById('arc-ed-desc').value   = arc ? (arc.description || '') : '';
  showArcSection('editor');
}

async function saveArc() {
  const name = document.getElementById('arc-ed-name').value.trim();
  if (!name) return;
  const body = {
    name,
    color: document.getElementById('arc-ed-color').value,
    status: document.getElementById('arc-ed-status').value,
    description: document.getElementById('arc-ed-desc').value,
  };
  const isNew = !ArcState.editingId;
  const url = isNew ? `${API}/arcs` : `${API}/arcs/${ArcState.editingId}`;
  const result = await apiFetch(url, {
    method: isNew ? 'POST' : 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  }).then(r => r.json());

  await loadArcsList();
  openArc(isNew ? result.id : ArcState.editingId);
}

async function deleteArc(id, name) {
  if (!confirm(`Delete arc "${name}"?`)) return;
  await apiFetch(`${API}/arcs/${id}`, { method: 'DELETE' });
  ArcState.activeId = null;
  TL.selectedArc = null;
  await loadArcsList();
  showArcSection('empty');
  renderWorld();
}

function showArcSection(section) {
  document.getElementById('arc-empty').style.display   = section === 'empty'  ? 'flex'  : 'none';
  document.getElementById('arc-viewer').style.display  = section === 'viewer' ? 'block' : 'none';
  document.getElementById('arc-editor').classList.toggle('active', section === 'editor');
}

// ── Init ─────────────────────────────────────────────────────────────────────

function initArcsModule() {
  document.getElementById('arc-new-btn')?.addEventListener('click', () => openArcEditor());
  document.getElementById('arc-save-btn')?.addEventListener('click', saveArc);
  document.getElementById('arc-cancel-btn')?.addEventListener('click', () => {
    if (ArcState.activeId) openArc(ArcState.activeId);
    else showArcSection('empty');
  });
  document.getElementById('arc-search')?.addEventListener('input', e => {
    renderArcList(e.target.value.trim());
  });
}
