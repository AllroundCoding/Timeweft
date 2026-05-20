'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Folders module — shared folder tree for docs & entities sidebars
// ══════════════════════════════════════════════════════════════════════════════

const FolderState = { docs: [], entities: [] };

async function loadFolders(type) {
  try {
    const q = type ? `?type=${type}` : '';
    const res = await apiFetch(`${API}/folders${q}`);
    const data = await res.json();
    if (type) FolderState[type] = data.folders || [];
    else { FolderState.docs = []; FolderState.entities = []; (data.folders || []).forEach(f => FolderState[f.folder_type]?.push(f)); }
  } catch { /* ignore */ }
}

function buildFolderTree(folders) {
  const byParent = {};
  folders.forEach(f => { (byParent[f.parent_id || 'root'] = byParent[f.parent_id || 'root'] || []).push(f); });
  function children(parentId) {
    return (byParent[parentId] || []).sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
  }
  return { roots: children('root'), children };
}

function renderFolderDropdown(folders, selectedId) {
  const tree = buildFolderTree(folders);
  let html = '<option value="">— No folder —</option>';
  function addOptions(nodes, depth) {
    for (const f of nodes) {
      const indent = '&nbsp;'.repeat(depth * 4);
      const sel = f.id === selectedId ? ' selected' : '';
      html += `<option value="${f.id}"${sel}>${indent}${escapeHtml(f.name)}</option>`;
      addOptions(tree.children(f.id), depth + 1);
    }
  }
  addOptions(tree.roots, 0);
  return html;
}

// ── Cross-link rendering helpers ─────────────────────────────────────────────

/** Render "Related Documents" section inside entity viewer */
async function renderEntityDocLinks(entityId) {
  const container = document.getElementById('ent-linked-docs');
  if (!container) return;
  try {
    const res = await apiFetch(`${API}/entities/${entityId}/docs`);
    const data = await res.json();
    const docs = data.docs || [];
    let html = '';

    if (docs.length) {
      html += docs.map(d => `
        <div class="cross-link-item" data-doc-id="${d.id}">
          <span class="cross-link-icon">📄</span>
          <span class="cross-link-title">${escapeHtml(d.title)}</span>
          ${d.role ? `<span class="cross-link-role">${escapeHtml(d.role)}</span>` : ''}
          <span class="cross-link-meta">${d.category || ''}</span>
          <button class="eln-unlink cross-link-unlink" data-doc-id="${d.id}" title="Unlink">×</button>
        </div>`).join('');
    } else {
      html += '<div style="color:var(--text-dim);font-size:0.8rem;margin-bottom:8px;">No linked documents yet.</div>';
    }

    // Search to add doc links
    html += `<div class="ent-link-search cross-link-search">
      <input type="text" id="ent-doc-search" placeholder="Search documents to link…" />
      <div id="ent-doc-results"></div>
    </div>`;

    container.innerHTML = html;

    // Open doc on click
    container.querySelectorAll('.cross-link-item').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('.cross-link-unlink')) return;
        openDoc(el.dataset.docId);
      });
    });

    // Unlink buttons
    container.querySelectorAll('.cross-link-unlink').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        await apiFetch(`${API}/entities/${entityId}/doc-links/${btn.dataset.docId}`, { method: 'DELETE' });
        renderEntityDocLinks(entityId);
      });
    });

    // Doc search
    const searchInput = document.getElementById('ent-doc-search');
    const resultsEl = document.getElementById('ent-doc-results');
    let searchTimeout;
    searchInput?.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      const q = searchInput.value.trim();
      if (!q) { resultsEl.innerHTML = ''; return; }
      searchTimeout = setTimeout(async () => {
        const sr = await apiFetch(`${API}/docs?search=${encodeURIComponent(q)}`).then(r => r.json());
        const linkedIds = new Set(docs.map(d => d.id));
        const matches = (sr.documents || []).filter(d => !linkedIds.has(d.id)).slice(0, 8);
        if (!matches.length) { resultsEl.innerHTML = '<div class="ent-search-hint">No matching documents</div>'; return; }
        resultsEl.innerHTML = matches.map(d => `
          <div class="ent-search-result cross-link-result" data-doc-id="${d.id}">
            <span class="cross-link-icon">📄</span>
            <span class="eln-title">${escapeHtml(d.title)}</span>
            <span class="eln-badge">${d.category || ''}</span>
          </div>`).join('');
        resultsEl.querySelectorAll('.cross-link-result').forEach(el => {
          el.addEventListener('click', async () => {
            await apiFetch(`${API}/entities/${entityId}/doc-links`, {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ doc_id: el.dataset.docId }),
            });
            renderEntityDocLinks(entityId);
          });
        });
      }, 200);
    });
  } catch { container.innerHTML = ''; }
}

/** Render "Related Entities" section inside doc viewer */
async function renderDocEntityLinks(docId) {
  const container = document.getElementById('doc-linked-entities');
  if (!container) return;
  try {
    const res = await apiFetch(`${API}/docs/${docId}/entities`);
    const data = await res.json();
    const entities = data.entities || [];
    let html = '';

    if (entities.length) {
      html += entities.map(e => `
        <div class="cross-link-item" data-ent-id="${e.id}">
          <span class="cross-link-icon" style="color:${e.color || '#7c6bff'}">●</span>
          <span class="cross-link-title">${escapeHtml(e.name)}</span>
          ${e.role ? `<span class="cross-link-role">${escapeHtml(e.role)}</span>` : ''}
          <span class="cross-link-meta">${e.entity_type || ''}</span>
          <button class="eln-unlink cross-link-unlink" data-ent-id="${e.id}" title="Unlink">×</button>
        </div>`).join('');
    } else {
      html += '<div style="color:var(--text-dim);font-size:0.8rem;margin-bottom:8px;">No linked entities yet.</div>';
    }

    html += `<div class="ent-link-search cross-link-search">
      <input type="text" id="doc-ent-search" placeholder="Search entities to link…" />
      <div id="doc-ent-results"></div>
    </div>`;

    container.innerHTML = html;

    container.querySelectorAll('.cross-link-item').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('.cross-link-unlink')) return;
        openEntity(el.dataset.entId);
      });
    });

    container.querySelectorAll('.cross-link-unlink').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        await apiFetch(`${API}/entities/${btn.dataset.entId}/doc-links/${docId}`, { method: 'DELETE' });
        renderDocEntityLinks(docId);
      });
    });

    const searchInput = document.getElementById('doc-ent-search');
    const resultsEl = document.getElementById('doc-ent-results');
    let searchTimeout;
    searchInput?.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      const q = searchInput.value.trim();
      if (!q) { resultsEl.innerHTML = ''; return; }
      searchTimeout = setTimeout(async () => {
        const sr = await apiFetch(`${API}/entities?search=${encodeURIComponent(q)}`).then(r => r.json());
        const linkedIds = new Set(entities.map(e => e.id));
        const matches = (sr.entities || []).filter(e => !linkedIds.has(e.id)).slice(0, 8);
        if (!matches.length) { resultsEl.innerHTML = '<div class="ent-search-hint">No matching entities</div>'; return; }
        resultsEl.innerHTML = matches.map(e => `
          <div class="ent-search-result cross-link-result" data-ent-id="${e.id}">
            <span class="cross-link-icon" style="color:${e.color || '#7c6bff'}">●</span>
            <span class="eln-title">${escapeHtml(e.name)}</span>
            <span class="eln-badge">${e.entity_type || ''}</span>
          </div>`).join('');
        resultsEl.querySelectorAll('.cross-link-result').forEach(el => {
          el.addEventListener('click', async () => {
            await apiFetch(`${API}/entities/${el.dataset.entId}/doc-links`, {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ doc_id: docId }),
            });
            renderDocEntityLinks(docId);
          });
        });
      }, 200);
    });
  } catch { container.innerHTML = ''; }
}

/** Render "Related Events" section inside doc viewer */
async function renderDocNodeLinks(docId) {
  const container = document.getElementById('doc-linked-nodes');
  if (!container) return;
  try {
    const res = await apiFetch(`${API}/docs/${docId}/nodes`);
    const data = await res.json();
    const nodes = data.nodes || [];
    let html = '';

    if (nodes.length) {
      html += nodes.map(n => `
        <div class="cross-link-item" data-node-id="${n.id}" title="${nodeTooltipText(n)}">
          <span class="eln-icon" style="color:${n.color || '#5566bb'}">${nodeTypeIcon(n.node_type)}</span>
          <span class="cross-link-title">${escapeHtml(n.title)}</span>
          ${n.role ? `<span class="cross-link-role">${escapeHtml(n.role)}</span>` : ''}
          <span class="eln-date">${formatNodeDate(n, 'start')}</span>
          <button class="eln-unlink cross-link-unlink" data-node-id="${n.id}" title="Unlink">×</button>
        </div>`).join('');
    } else {
      html += '<div style="color:var(--text-dim);font-size:0.8rem;margin-bottom:8px;">No linked events yet.</div>';
    }

    html += `<div class="ent-link-search cross-link-search">
      <input type="text" id="doc-node-search" placeholder="Search events to link…" />
      <div id="doc-node-results"></div>
    </div>`;

    container.innerHTML = html;

    container.querySelectorAll('.cross-link-item').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('.cross-link-unlink')) return;
        navigateToNode(el.dataset.nodeId);
      });
    });

    container.querySelectorAll('.cross-link-unlink').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        await apiFetch(`${API}/docs/${docId}/node-links/${btn.dataset.nodeId}`, { method: 'DELETE' });
        renderDocNodeLinks(docId);
      });
    });

    const searchInput = document.getElementById('doc-node-search');
    const resultsEl = document.getElementById('doc-node-results');
    let searchTimeout;
    searchInput?.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      const q = searchInput.value.trim();
      if (!q) { resultsEl.innerHTML = ''; return; }
      searchTimeout = setTimeout(async () => {
        const sr = await apiFetch(`${API}/nodes/search?q=${encodeURIComponent(q)}&limit=8`).then(r => r.json());
        const linkedIds = new Set(nodes.map(n => n.id));
        const matches = (sr.nodes || []).filter(n => !linkedIds.has(n.id));
        if (!matches.length) { resultsEl.innerHTML = '<div class="ent-search-hint">No matching events</div>'; return; }
        resultsEl.innerHTML = matches.map(n => `
          <div class="ent-search-result cross-link-result" data-node-id="${n.id}" title="${nodeTooltipText(n)}">
            <span class="eln-icon" style="color:${n.color || '#5566bb'}">${nodeTypeIcon(n.node_type)}</span>
            <span class="eln-title">${escapeHtml(n.title)}</span>
            <span class="eln-date">${formatNodeDate(n, 'start')}</span>
            <span class="eln-badge">${n.node_type || 'event'}</span>
          </div>`).join('');
        resultsEl.querySelectorAll('.cross-link-result').forEach(el => {
          el.addEventListener('click', async () => {
            await apiFetch(`${API}/docs/${docId}/node-links`, {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ node_id: el.dataset.nodeId }),
            });
            renderDocNodeLinks(docId);
          });
        });
      }, 200);
    });
  } catch { container.innerHTML = ''; }
}

// ══════════════════════════════════════════════════════════════════════════════
// Folder modal — lightweight prompt / confirm replacement
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Show a custom modal that acts as a prompt or confirm dialog.
 * @param {object} opts
 * @param {string} opts.title   — heading text
 * @param {string} [opts.value] — pre-filled input value (omit for confirm-only)
 * @param {string} [opts.placeholder]
 * @param {string} [opts.confirmLabel='OK']
 * @param {boolean} [opts.danger] — style confirm button as danger
 * @param {string} [opts.message] — body text (shown above input, or as sole content for confirms)
 * @returns {Promise<string|null>} resolved value or null if cancelled
 */
function folderModal(opts = {}) {
  return new Promise(resolve => {
    const overlay = document.getElementById('folder-modal');
    const titleEl = document.getElementById('fm-title');
    const msgEl   = document.getElementById('fm-message');
    const inputEl = document.getElementById('fm-input');
    const okBtn   = document.getElementById('fm-ok');
    const cancelBtn = document.getElementById('fm-cancel');

    titleEl.textContent = opts.title || 'Folder';
    msgEl.textContent = opts.message || '';
    msgEl.style.display = opts.message ? '' : 'none';

    const isPrompt = opts.value !== undefined || opts.placeholder;
    inputEl.style.display = isPrompt ? '' : 'none';
    inputEl.value = opts.value ?? '';
    inputEl.placeholder = opts.placeholder || '';

    okBtn.textContent = opts.confirmLabel || 'OK';
    okBtn.className = opts.danger ? 'btn btn-danger' : 'btn btn-accent';

    overlay.classList.add('open');
    if (isPrompt) { inputEl.focus(); inputEl.select(); }
    else okBtn.focus();

    function cleanup(val) {
      overlay.classList.remove('open');
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      inputEl.removeEventListener('keydown', onKey);
      overlay.removeEventListener('mousedown', onBg);
      resolve(val);
    }
    function onOk() { cleanup(isPrompt ? inputEl.value.trim() || null : true); }
    function onCancel() { cleanup(null); }
    function onKey(e) {
      if (e.key === 'Enter') onOk();
      else if (e.key === 'Escape') onCancel();
    }
    function onBg(e) { if (e.target === overlay) onCancel(); }

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    inputEl.addEventListener('keydown', onKey);
    overlay.addEventListener('mousedown', onBg);
  });
}

// ══════════════════════════════════════════════════════════════════════════════
// Folder context menu — shared for entities & docs sidebars
// ══════════════════════════════════════════════════════════════════════════════

const _fctx = { type: null, folderId: null, itemId: null, itemKind: null };

function _canEditArea(area) {
  return !document.body.classList.contains(`share-no-edit-${area}`);
}

function showFolderCtxMenu(e, folderType, opts = {}) {
  e.preventDefault();
  e.stopPropagation();
  _fctx.type = folderType;          // 'entities' or 'docs'
  _fctx.folderId = opts.folderId || null;
  _fctx.itemId = opts.itemId || null;
  _fctx.itemKind = opts.itemKind || null; // 'entity' or 'doc'

  const canEdit = _canEditArea(folderType);
  const menu = document.getElementById('folder-ctx-menu');
  const onFolder = !!_fctx.folderId;
  const onItem = !!_fctx.itemId;

  // If user can't edit this area, only show "Move" is irrelevant too — hide entirely
  if (!canEdit) return;

  document.getElementById('fctx-new-folder').style.display    = !onItem ? '' : 'none';
  document.getElementById('fctx-new-subfolder').style.display = onFolder && !onItem ? '' : 'none';
  document.getElementById('fctx-rename').style.display        = onFolder && !onItem ? '' : 'none';
  document.getElementById('fctx-color').style.display         = onFolder && !onItem ? '' : 'none';
  document.getElementById('fctx-delete').style.display        = onFolder && !onItem ? '' : 'none';
  document.getElementById('fctx-move').style.display          = onItem ? '' : 'none';
  document.getElementById('fctx-move-list').style.display     = 'none';
  document.getElementById('fctx-move-list').innerHTML         = '';

  menu.style.left = e.clientX + 'px';
  menu.style.top  = e.clientY + 'px';
  menu.classList.add('open');
}

function hideFolderCtxMenu() {
  document.getElementById('folder-ctx-menu').classList.remove('open');
  _fctx.type = _fctx.folderId = _fctx.itemId = _fctx.itemKind = null;
}

async function _refreshAfterFolderChange(folderType) {
  if (folderType === 'entities') { await loadFolders('entities'); await loadEntitiesList(); }
  else { await loadFolders('docs'); await loadDocsList(); }
}

function initFolderCtxMenu() {
  // Dismiss on click elsewhere
  document.addEventListener('click', e => {
    if (!e.target.closest('#folder-ctx-menu')) hideFolderCtxMenu();
  });

  // New folder (root)
  document.getElementById('fctx-new-folder')?.addEventListener('click', async () => {
    const ft = _fctx.type;
    hideFolderCtxMenu();
    const name = await folderModal({ title: 'New Folder', placeholder: 'Folder name\u2026' });
    if (!name) return;
    await apiFetch(`${API}/folders`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, folder_type: ft }),
    });
    await _refreshAfterFolderChange(ft);
  });

  // New subfolder
  document.getElementById('fctx-new-subfolder')?.addEventListener('click', async () => {
    const ft = _fctx.type;
    const parentId = _fctx.folderId;
    hideFolderCtxMenu();
    const name = await folderModal({ title: 'New Subfolder', placeholder: 'Subfolder name\u2026' });
    if (!name) return;
    await apiFetch(`${API}/folders`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, folder_type: ft, parent_id: parentId }),
    });
    await _refreshAfterFolderChange(ft);
  });

  // Rename
  document.getElementById('fctx-rename')?.addEventListener('click', async () => {
    const ft = _fctx.type;
    const fId = _fctx.folderId;
    const folder = FolderState[ft].find(f => f.id === fId);
    hideFolderCtxMenu();
    const name = await folderModal({ title: 'Rename Folder', value: folder?.name || '' });
    if (!name) return;
    await apiFetch(`${API}/folders/${fId}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name }),
    });
    await _refreshAfterFolderChange(ft);
  });

  // Change color
  document.getElementById('fctx-color')?.addEventListener('click', () => {
    const folder = FolderState[_fctx.type].find(f => f.id === _fctx.folderId);
    const input = document.createElement('input');
    input.type = 'color';
    input.value = folder?.color || '#7c6bff';
    input.style.position = 'fixed'; input.style.opacity = '0';
    document.body.appendChild(input);
    input.addEventListener('input', async () => {
      await apiFetch(`${API}/folders/${_fctx.folderId}`, {
        method: 'PUT', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ color: input.value }),
      });
      const ft = _fctx.type;
      hideFolderCtxMenu();
      input.remove();
      await _refreshAfterFolderChange(ft);
    });
    input.addEventListener('change', () => setTimeout(() => input.remove(), 100));
    input.click();
  });

  // Delete
  document.getElementById('fctx-delete')?.addEventListener('click', async () => {
    const ft = _fctx.type;
    const fId = _fctx.folderId;
    const folder = FolderState[ft].find(f => f.id === fId);
    hideFolderCtxMenu();
    const ok = await folderModal({
      title: 'Delete Folder',
      message: `Delete "${folder?.name}"? Items inside will become unfiled.`,
      confirmLabel: 'Delete',
      danger: true,
    });
    if (!ok) return;
    await apiFetch(`${API}/folders/${fId}`, { method: 'DELETE' });
    await _refreshAfterFolderChange(ft);
  });

  // Move to folder — show submenu
  document.getElementById('fctx-move')?.addEventListener('click', () => {
    const listEl = document.getElementById('fctx-move-list');
    const folders = FolderState[_fctx.type] || [];
    let html = `<button class="fctx-move-opt" data-folder-id="">— Unfiled —</button>`;
    const tree = buildFolderTree(folders);
    function addOpts(nodes, depth) {
      for (const f of nodes) {
        const indent = '&nbsp;'.repeat(depth * 3);
        const clr = f.color ? ` style="border-left:3px solid ${f.color};padding-left:8px"` : '';
        html += `<button class="fctx-move-opt" data-folder-id="${f.id}"${clr}>${indent}${escapeHtml(f.name)}</button>`;
        addOpts(tree.children(f.id), depth + 1);
      }
    }
    addOpts(tree.roots, 0);
    listEl.innerHTML = html;
    listEl.style.display = 'block';

    listEl.querySelectorAll('.fctx-move-opt').forEach(btn => {
      btn.addEventListener('click', async () => {
        const folderId = btn.dataset.folderId || null;
        const endpoint = _fctx.itemKind === 'entity'
          ? `${API}/entities/${_fctx.itemId}`
          : `${API}/docs/${_fctx.itemId}`;
        await apiFetch(endpoint, {
          method: 'PUT', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ folder_id: folderId }),
        });
        const ft = _fctx.type;
        hideFolderCtxMenu();
        await _refreshAfterFolderChange(ft);
      });
    });
  });

  // Attach contextmenu listeners to sidebar lists
  document.getElementById('ent-list')?.addEventListener('contextmenu', e => {
    e.preventDefault();
    const itemEl = e.target.closest('.ent-list-item');
    const folderEl = e.target.closest('.folder-header');
    if (itemEl) {
      const folderId = itemEl.closest('.folder-group')?.dataset.folderId || null;
      showFolderCtxMenu(e, 'entities', { folderId, itemId: itemEl.dataset.entId, itemKind: 'entity' });
    } else if (folderEl) {
      showFolderCtxMenu(e, 'entities', { folderId: folderEl.dataset.folderId });
    } else {
      showFolderCtxMenu(e, 'entities');
    }
  });

  document.getElementById('docs-list')?.addEventListener('contextmenu', e => {
    e.preventDefault();
    const itemEl = e.target.closest('.doc-list-item');
    const folderEl = e.target.closest('.folder-header');
    if (itemEl) {
      const folderId = itemEl.closest('.folder-group')?.dataset.folderId || null;
      showFolderCtxMenu(e, 'docs', { folderId, itemId: itemEl.dataset.docId, itemKind: 'doc' });
    } else if (folderEl) {
      showFolderCtxMenu(e, 'docs', { folderId: folderEl.dataset.folderId });
    } else {
      showFolderCtxMenu(e, 'docs');
    }
  });
}

/** Render "Related Documents" section inside node detail panel */
async function renderNodeDocLinks(nodeId) {
  const container = document.getElementById('node-linked-docs');
  if (!container) return;
  try {
    const res = await apiFetch(`${API}/backlinks/node/${nodeId}`);
    const data = await res.json();
    const docs = data.linked_docs || [];
    let html = '';

    if (docs.length) {
      html += docs.map(d => `
        <div class="cross-link-item" data-doc-id="${d.id}">
          <span class="cross-link-icon">📄</span>
          <span class="cross-link-title">${escapeHtml(d.title)}</span>
          ${d.role ? `<span class="cross-link-role">${escapeHtml(d.role)}</span>` : ''}
          <button class="eln-unlink cross-link-unlink" data-doc-id="${d.id}" title="Unlink">×</button>
        </div>`).join('');
    } else {
      html += '<div style="color:var(--text-dim);font-size:0.8rem;margin-bottom:8px;">No linked documents.</div>';
    }

    html += `<div class="ent-link-search cross-link-search">
      <input type="text" id="node-doc-search" placeholder="Search documents to link…" />
      <div id="node-doc-results"></div>
    </div>`;

    container.innerHTML = html;

    container.querySelectorAll('.cross-link-item').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('.cross-link-unlink')) return;
        openDoc(el.dataset.docId);
      });
    });

    container.querySelectorAll('.cross-link-unlink').forEach(btn => {
      btn.addEventListener('click', async e => {
        e.stopPropagation();
        await apiFetch(`${API}/docs/${btn.dataset.docId}/node-links/${nodeId}`, { method: 'DELETE' });
        renderNodeDocLinks(nodeId);
      });
    });

    const searchInput = document.getElementById('node-doc-search');
    const resultsEl = document.getElementById('node-doc-results');
    let searchTimeout;
    searchInput?.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      const q = searchInput.value.trim();
      if (!q) { resultsEl.innerHTML = ''; return; }
      searchTimeout = setTimeout(async () => {
        const sr = await apiFetch(`${API}/docs?search=${encodeURIComponent(q)}`).then(r => r.json());
        const linkedIds = new Set(docs.map(d => d.id));
        const matches = (sr.documents || []).filter(d => !linkedIds.has(d.id)).slice(0, 8);
        if (!matches.length) { resultsEl.innerHTML = '<div class="ent-search-hint">No matching documents</div>'; return; }
        resultsEl.innerHTML = matches.map(d => `
          <div class="ent-search-result cross-link-result" data-doc-id="${d.id}">
            <span class="cross-link-icon">📄</span>
            <span class="eln-title">${escapeHtml(d.title)}</span>
            <span class="eln-badge">${d.category || ''}</span>
          </div>`).join('');
        resultsEl.querySelectorAll('.cross-link-result').forEach(el => {
          el.addEventListener('click', async () => {
            await apiFetch(`${API}/docs/${el.dataset.docId}/node-links`, {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ node_id: nodeId }),
            });
            renderNodeDocLinks(nodeId);
          });
        });
      }, 200);
    });
  } catch { container.innerHTML = ''; }
}
