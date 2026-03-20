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
  const [entRes] = await Promise.all([
    apiFetch(`${API}/entities${q}`).then(r => r.json()),
    loadFolders('entities'),
  ]);
  EntState.entities = entRes.entities;
  renderEntList();
}

function _renderEntItem(ent, container) {
  const el = document.createElement('div');
  el.className = 'ent-list-item' + (ent.id === EntState.activeId ? ' active' : '');
  el.dataset.entId = ent.id;
  el.innerHTML = `<div class="ent-list-color" style="background:${ent.color}"></div>
    <div class="ent-list-info">
      <div class="ent-item-name">${escapeHtml(ent.name)}</div>
      <div class="ent-item-type">${ent.entity_type}${ent.link_count ? ` · ${ent.link_count} linked` : ''}</div>
    </div>`;
  el.addEventListener('click', () => openEntity(ent.id));
  container.appendChild(el);
}

function renderEntList() {
  const container = document.getElementById('ent-list');
  container.innerHTML = '';

  const folders = FolderState.entities || [];
  if (!folders.length) {
    // No folders — flat list grouped by type (original behaviour)
    _renderEntListFlat(container);
    return;
  }

  const tree = buildFolderTree(folders);
  const folderMap = {};
  folders.forEach(f => { folderMap[f.id] = f; });

  // Group entities by folder_id
  const byFolder = { _unfiled: [] };
  folders.forEach(f => { byFolder[f.id] = []; });
  EntState.entities.forEach(e => {
    const fid = e.folder_id && byFolder[e.folder_id] ? e.folder_id : '_unfiled';
    byFolder[fid].push(e);
  });

  // Render folder tree recursively
  function renderFolder(f, depth) {
    const items = byFolder[f.id] || [];
    const hasChildren = tree.children(f.id).length > 0;
    const group = document.createElement('div');
    group.className = 'folder-group';
    group.dataset.folderId = f.id;

    const header = document.createElement('div');
    header.className = 'folder-header';
    header.dataset.folderId = f.id;
    header.style.paddingLeft = (8 + depth * 14) + 'px';
    const clr = f.color || 'var(--text-dim)';
    header.innerHTML = `<span class="folder-toggle">${items.length || hasChildren ? '▸' : '·'}</span>
      <span class="folder-icon" style="color:${clr}">📁</span>
      <span class="folder-name">${escapeHtml(f.name)}</span>
      <span class="folder-count">${items.length}</span>`;
    group.appendChild(header);

    const body = document.createElement('div');
    body.className = 'folder-body collapsed';
    body.style.paddingLeft = (depth * 14) + 'px';
    items.forEach(ent => _renderEntItem(ent, body));
    tree.children(f.id).forEach(child => renderFolder(child, depth + 1));
    group.appendChild(body);

    header.addEventListener('click', () => {
      body.classList.toggle('collapsed');
      header.querySelector('.folder-toggle').textContent = body.classList.contains('collapsed') ? '▸' : '▾';
    });

    container.appendChild(group);
  }

  tree.roots.forEach(f => renderFolder(f, 0));

  // Unfiled items grouped by type
  if (byFolder._unfiled.length) {
    const unfiledHeader = document.createElement('div');
    unfiledHeader.className = 'folder-header unfiled-header';
    unfiledHeader.innerHTML = `<span class="folder-toggle">▸</span>
      <span class="folder-icon" style="color:var(--text-dim)">📂</span>
      <span class="folder-name">Unfiled</span>
      <span class="folder-count">${byFolder._unfiled.length}</span>`;
    container.appendChild(unfiledHeader);

    const unfiledBody = document.createElement('div');
    unfiledBody.className = 'folder-body collapsed';
    byFolder._unfiled.forEach(ent => _renderEntItem(ent, unfiledBody));
    container.appendChild(unfiledBody);

    unfiledHeader.addEventListener('click', () => {
      unfiledBody.classList.toggle('collapsed');
      unfiledHeader.querySelector('.folder-toggle').textContent = unfiledBody.classList.contains('collapsed') ? '▸' : '▾';
    });
  }

  if (!EntState.entities.length)
    container.innerHTML = '<div style="padding:20px 14px;color:var(--text-dim);font-size:0.8rem;">No entities found.</div>';
}

function _renderEntListFlat(container) {
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
    items.forEach(ent => _renderEntItem(ent, container));
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

  // Render relationships
  renderEntityRelationships(ent.id);

  // Render linked nodes with unlink buttons
  renderEntityLinks(ent);

  // Render cross-linked documents
  renderEntityDocLinks(id);

  document.getElementById('ent-edit-btn').onclick = () => openEntEditor(ent);
  document.getElementById('ent-delete-btn').onclick = () => deleteEntity(ent.id, ent.name);
}

function showEntSection(section) {
  document.getElementById('ent-empty').style.display   = section === 'empty'  ? 'flex'  : 'none';
  document.getElementById('ent-viewer').style.display  = section === 'viewer' ? 'block' : 'none';
  document.getElementById('ent-editor').classList.toggle('active', section === 'editor');
}

async function openEntEditor(ent = null) {
  EntState.editingId = ent ? ent.id : null;
  document.getElementById('ent-ed-name').value  = ent ? ent.name        : '';
  document.getElementById('ent-ed-type').value  = ent ? ent.entity_type : 'character';
  document.getElementById('ent-ed-color').value = ent ? ent.color       : '#7c6bff';
  document.getElementById('ent-ed-desc').value  = ent ? ent.description || '' : '';
  await loadFolders('entities');
  document.getElementById('ent-ed-folder').innerHTML = renderFolderDropdown(FolderState.entities, ent?.folder_id);
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

// ── Entity Relationships ─────────────────────────────────────────────────────

const REL_INVERSE = {
  parent_of:'child of', married_to:'married to', sibling_of:'sibling of',
  member_of:'has member', leads:'led by', serves:'served by',
  ally_of:'ally of', rival_of:'rival of', enemy_of:'enemy of',
  located_in:'contains', owns:'owned by', created_by:'creator of',
};
const REL_LABELS = {
  parent_of:'parent of', married_to:'married to', sibling_of:'sibling of',
  member_of:'member of', leads:'leads', serves:'serves',
  ally_of:'ally of', rival_of:'rival of', enemy_of:'enemy of',
  located_in:'located in', owns:'owns', created_by:'created by',
  custom:'related to',
};

async function renderEntityRelationships(entityId) {
  const listEl = document.getElementById('ent-rel-list');
  const rels = await apiFetch(`${API}/entities/${entityId}/relationships`).then(r => r.json());

  if (!rels.length) {
    listEl.innerHTML = '<div style="color:var(--text-dim);font-size:0.8rem;margin-bottom:8px;">No relationships yet.</div>';
  } else {
    listEl.innerHTML = rels.map(r => {
      const isSource = r.source_id === entityId;
      const label = isSource ? (REL_LABELS[r.relationship] || r.relationship) : (REL_INVERSE[r.relationship] || r.relationship);
      const ended = r.end_node_id ? ' rel-ended' : '';
      return `<div class="rel-item${ended}" data-entity-id="${r.other_name ? (isSource ? r.target_id : r.source_id) : ''}">
        <span class="rel-dot" style="background:${r.other_color || '#7c6bff'}"></span>
        <span class="rel-label">${label}</span>
        <span class="rel-target">${r.other_name || '?'}</span>
        ${r.description ? `<span class="rel-desc">${r.description}</span>` : ''}
        <button class="rel-remove" data-rel-id="${r.id}" title="Remove">×</button>
      </div>`;
    }).join('');

    listEl.querySelectorAll('.rel-item').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('.rel-remove')) return;
        const eid = el.dataset.entityId;
        if (eid) openEntity(eid);
      });
    });
    listEl.querySelectorAll('.rel-remove').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        await apiFetch(`${API}/relationships/${btn.dataset.relId}`, { method: 'DELETE' });
        renderEntityRelationships(entityId);
      });
    });
  }

  // Relationship search
  const searchInput = document.getElementById('ent-rel-search');
  const resultsEl = document.getElementById('ent-rel-search-results');
  let timeout;
  searchInput.value = '';
  resultsEl.innerHTML = '';

  searchInput.oninput = () => {
    clearTimeout(timeout);
    const q = searchInput.value.trim();
    if (!q) { resultsEl.innerHTML = ''; return; }
    timeout = setTimeout(async () => {
      const data = await apiFetch(`${API}/entities?search=${encodeURIComponent(q)}&limit=8`).then(r => r.json());
      const matches = (data || []).filter(e => e.id !== entityId);
      if (!matches.length) { resultsEl.innerHTML = '<div class="ent-search-hint">No matches</div>'; return; }
      resultsEl.innerHTML = matches.map(e => `
        <div class="ent-search-result rel-search-result" data-entity-id="${e.id}">
          <span class="rel-dot" style="background:${e.color}"></span>
          <span class="eln-title">${e.name}</span>
          <span class="eln-badge">${e.entity_type}</span>
        </div>`).join('');

      resultsEl.querySelectorAll('.rel-search-result').forEach(el => {
        el.addEventListener('click', () => showRelTypeSelector(entityId, el.dataset.entityId, el));
      });
    }, 200);
  };

  // Graph button
  document.getElementById('ent-view-graph-btn').onclick = () => openRelGraph(entityId);
}

function showRelTypeSelector(sourceId, targetId, anchorEl) {
  // Remove any existing selector
  document.querySelector('.rel-type-selector')?.remove();
  const sel = document.createElement('div');
  sel.className = 'rel-type-selector';
  sel.innerHTML = Object.entries(REL_LABELS).map(([k, v]) =>
    `<div class="rel-type-option" data-type="${k}">${v}</div>`
  ).join('');
  anchorEl.after(sel);

  sel.querySelectorAll('.rel-type-option').forEach(opt => {
    opt.addEventListener('click', async () => {
      sel.remove();
      await apiFetch(`${API}/entities/${sourceId}/relationships`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ target_id: targetId, relationship: opt.dataset.type }),
      });
      document.getElementById('ent-rel-search').value = '';
      document.getElementById('ent-rel-search-results').innerHTML = '';
      renderEntityRelationships(sourceId);
    });
  });

  // Dismiss on outside click
  setTimeout(() => {
    document.addEventListener('click', function dismiss(e) {
      if (!sel.contains(e.target)) { sel.remove(); document.removeEventListener('click', dismiss); }
    });
  }, 0);
}

function openRelGraph(entityId) {
  document.getElementById('ent-viewer').style.display = 'none';
  document.getElementById('ent-rel-graph-wrap').style.display = '';
  document.getElementById('rel-depth-val').textContent = document.getElementById('rel-depth-slider').value;
  document.getElementById('rel-depth-slider').oninput = (e) => {
    document.getElementById('rel-depth-val').textContent = e.target.value;
    renderRelGraph(entityId);
  };
  document.getElementById('rel-mode-select').onchange = () => renderRelGraph(entityId);
  document.getElementById('rel-graph-close').onclick = () => {
    document.getElementById('ent-rel-graph-wrap').style.display = 'none';
    document.getElementById('ent-viewer').style.display = 'block';
  };
  renderRelGraph(entityId);
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
