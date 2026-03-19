'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Entities module
// ══════════════════════════════════════════════════════════════════════════════

const EntState = { entities: [], activeId: null, editingId: null };

async function loadEntitiesList(search = '', entityType = '') {
  const params = new URLSearchParams();
  if (search) params.set('search', search);
  if (entityType) params.set('entity_type', entityType);
  const q = params.toString() ? `?${params}` : '';
  const res = await apiFetch(`${API}/entities${q}`);
  const data = await res.json();
  EntState.entities = data.entities;
  renderEntList();
}

function renderEntList() {
  const container = document.getElementById('ent-list');
  container.innerHTML = '';
  const byType = {};
  EntState.entities.forEach(e => { (byType[e.entity_type] = byType[e.entity_type] || []).push(e); });
  const order = ['character','faction','location','item','concept','species','other'];
  [...new Set([...order, ...Object.keys(byType)])].forEach(type => {
    const items = byType[type];
    if (!items?.length) return;
    const header = document.createElement('div');
    header.className = 'ent-type-header';
    header.textContent = type.charAt(0).toUpperCase() + type.slice(1) + 's';
    container.appendChild(header);
    items.forEach(ent => {
      const el = document.createElement('div');
      el.className = 'ent-list-item' + (ent.id === EntState.activeId ? ' active' : '');
      el.dataset.entId = ent.id;
      el.innerHTML = `<div class="ent-list-color" style="background:${ent.color}"></div>
        <div class="ent-list-info">
          <div class="ent-item-name">${ent.name}</div>
          <div class="ent-item-type">${ent.entity_type}${ent.link_count ? ` · ${ent.link_count} linked` : ''}</div>
        </div>`;
      el.addEventListener('click', () => openEntity(ent.id));
      container.appendChild(el);
    });
  });
  if (!EntState.entities.length)
    container.innerHTML = '<div style="padding:20px 14px;color:var(--text-dim);font-size:0.8rem;">No entities found.</div>';
}

async function openEntity(id) {
  EntState.activeId = id;
  renderEntList();
  showEntSection('viewer');
  const ent = await apiFetch(`${API}/entities/${id}`).then(r => r.json());
  document.getElementById('ent-viewer-title').textContent = ent.name;
  document.getElementById('ent-viewer-meta').innerHTML =
    `<span class="meta-tag" style="border-color:${ent.color};color:${ent.color}">${ent.entity_type}</span>` +
    `<span style="color:var(--text-dim);">Updated ${ent.updated_at?.slice(0,10)||'—'}</span>`;

  // Show timeline info if entity is bound to a node
  const tlEl = document.getElementById('ent-viewer-timeline');
  if (ent.node) {
    const n = ent.node;
    const dateStr = n.type === 'span'
      ? `${formatNodeDate(n,'start')} → ${formatNodeDate(n,'end')}`
      : formatNodeDate(n,'start');
    tlEl.innerHTML = `
      <div class="ent-timeline-info" data-node-id="${n.id}">
        <span class="ent-tl-date">${dateStr}</span>
        <span class="ent-tl-badge">${n.node_type || 'event'}</span>
        <span class="ent-tl-badge imp-${n.importance}">${n.importance || 'moderate'}</span>
        <span class="ent-tl-nav" title="Navigate to timeline">↗</span>
      </div>`;
    tlEl.style.display = '';
    tlEl.querySelector('.ent-timeline-info').addEventListener('click', () => navigateToNode(n.id));
  } else {
    tlEl.style.display = 'none';
  }

  document.getElementById('ent-viewer-desc').innerHTML = renderMarkdown(ent.description || '');

  // Render linked nodes with unlink buttons
  renderEntityLinks(ent);

  document.getElementById('ent-edit-btn').onclick = () => openEntEditor(ent);
  document.getElementById('ent-delete-btn').onclick = () => deleteEntity(ent.id, ent.name);
}

function showEntSection(section) {
  document.getElementById('ent-empty').style.display   = section === 'empty'  ? 'flex'  : 'none';
  document.getElementById('ent-viewer').style.display  = section === 'viewer' ? 'block' : 'none';
  document.getElementById('ent-editor').classList.toggle('active', section === 'editor');
}

function openEntEditor(ent = null) {
  EntState.editingId = ent ? ent.id : null;
  document.getElementById('ent-ed-name').value  = ent ? ent.name        : '';
  document.getElementById('ent-ed-type').value  = ent ? ent.entity_type : 'character';
  document.getElementById('ent-ed-color').value = ent ? ent.color       : '#7c6bff';
  document.getElementById('ent-ed-desc').value  = ent ? ent.description || '' : '';
  showEntSection('editor');
}

function renderEntityLinks(ent) {
  const linksEl = document.getElementById('ent-linked-nodes');
  let html = '';

  if (ent.linked_nodes?.length) {
    html += ent.linked_nodes.map(n => `
      <div class="ent-linked-node" data-node-id="${n.id}" title="${nodeTooltipText(n)}">
        <span class="eln-icon" style="color:${n.color || '#5566bb'}">${nodeTypeIcon(n.node_type)}</span>
        <span class="eln-title">${n.title}</span>
        ${n.role ? `<span class="eln-role">${n.role}</span>` : ''}
        <span class="eln-date">${formatNodeDate(n, 'start')}</span>
        <button class="eln-unlink" data-node-id="${n.id}" title="Unlink">×</button>
      </div>`).join('');
  } else {
    html += '<div style="color:var(--text-dim);font-size:0.8rem;margin-bottom:8px;">No linked events yet.</div>';
  }

  // Node search to add links
  html += `<div class="ent-link-search">
    <input type="text" id="ent-node-search" placeholder="Search events to link…" />
    <div class="ent-search-filters">
      <input type="number" id="ent-search-year-min" placeholder="From year" />
      <input type="number" id="ent-search-year-max" placeholder="To year" />
    </div>
    <div id="ent-node-results"></div>
  </div>`;

  linksEl.innerHTML = html;

  // Navigate on click
  linksEl.querySelectorAll('.ent-linked-node').forEach(el => {
    el.addEventListener('click', e => {
      if (e.target.closest('.eln-unlink')) return;
      navigateToNode(el.dataset.nodeId);
    });
  });

  // Unlink buttons
  linksEl.querySelectorAll('.eln-unlink').forEach(btn => {
    btn.addEventListener('click', async e => {
      e.stopPropagation();
      await apiFetch(`${API}/entities/${ent.id}/links/${btn.dataset.nodeId}`, { method: 'DELETE' });
      // Refresh entity to get updated links
      const updated = await apiFetch(`${API}/entities/${ent.id}`).then(r => r.json());
      ent.linked_nodes = updated.linked_nodes;
      renderEntityLinks(ent);
    });
  });

  // Node search input
  const searchInput = document.getElementById('ent-node-search');
  const resultsEl = document.getElementById('ent-node-results');
  let searchTimeout;

  const yearMinInput = document.getElementById('ent-search-year-min');
  const yearMaxInput = document.getElementById('ent-search-year-max');

  function doNodeSearch() {
    clearTimeout(searchTimeout);
    const q = searchInput.value.trim();
    if (!q) { resultsEl.innerHTML = ''; return; }
    searchTimeout = setTimeout(async () => {
      const params = new URLSearchParams({ q, limit: 8 });
      const yMin = yearMinInput?.value;
      const yMax = yearMaxInput?.value;
      if (yMin) params.set('date_min', yMin);
      if (yMax) params.set('date_max', yMax);

      const data = await apiFetch(`${API}/nodes/search?${params}`).then(r => r.json());
      const linkedIds = new Set((ent.linked_nodes || []).map(n => n.id));
      const matches = (data.nodes || []).filter(n => !linkedIds.has(n.id));

      if (!matches.length) {
        resultsEl.innerHTML = '<div class="ent-search-hint">No matching events</div>';
        return;
      }

      resultsEl.innerHTML = matches.map(n => `
        <div class="ent-search-result" data-node-id="${n.id}" title="${nodeTooltipText(n)}">
          <span class="eln-icon" style="color:${n.color || '#5566bb'}">${nodeTypeIcon(n.node_type)}</span>
          <span class="eln-title">${n.title}</span>
          <span class="eln-date">${formatNodeDate(n, 'start')}</span>
          <span class="eln-badge">${n.node_type || 'event'}</span>
        </div>`).join('');

      resultsEl.querySelectorAll('.ent-search-result').forEach(el => {
        el.addEventListener('click', async () => {
          await apiFetch(`${API}/entities/${ent.id}/links`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ node_id: el.dataset.nodeId }),
          });
          const updated = await apiFetch(`${API}/entities/${ent.id}`).then(r => r.json());
          ent.linked_nodes = updated.linked_nodes;
          renderEntityLinks(ent);
        });
      });
    }, 200);
  }

  searchInput?.addEventListener('input', doNodeSearch);
  yearMinInput?.addEventListener('input', doNodeSearch);
  yearMaxInput?.addEventListener('input', doNodeSearch);
}

async function deleteEntity(id, name) {
  if (!confirm(`Delete "${name}"? This will also remove it from the timeline.`)) return;
  const result = await apiFetch(`${API}/entities/${id}`, { method: 'DELETE' }).then(r => r.json());
  EntState.activeId = null;
  await loadEntitiesList();
  showEntSection('empty');
  // If a bound node was deleted, refresh the timeline
  if (result.deleted_node_id) {
    delete TL.nodeById[result.deleted_node_id];
    delete TL.childCache['root'];
    await tlFetch(null);
    renderWorld();
  }
}
