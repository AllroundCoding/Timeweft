'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Docs module
// ══════════════════════════════════════════════════════════════════════════════

const DocsState = { docs: [], activeId: null, editingId: null };

async function loadDocsList(search = '') {
  const q   = search ? `?search=${encodeURIComponent(search)}` : '';
  const [docRes] = await Promise.all([
    apiFetch(`${API}/docs${q}`).then(r => r.json()),
    loadFolders('docs'),
  ]);
  DocsState.docs = docRes.documents;
  renderDocList();
}

function _renderDocItem(doc, container) {
  const el = document.createElement('div');
  el.className = 'doc-list-item' + (doc.id === DocsState.activeId ? ' active' : '');
  el.dataset.docId = doc.id;
  el.innerHTML = `<div class="doc-item-title">${escapeHtml(doc.title)}</div><div class="doc-item-tags">${(doc.tags||[]).map(t => escapeHtml(t)).join(', ')}</div>`;
  el.addEventListener('click', () => openDoc(doc.id));
  container.appendChild(el);
}

function renderDocList() {
  const container = document.getElementById('docs-list');
  container.innerHTML = '';

  const folders = FolderState.docs || [];
  if (!folders.length) {
    _renderDocListFlat(container);
    return;
  }

  const tree = buildFolderTree(folders);
  const byFolder = { _unfiled: [] };
  folders.forEach(f => { byFolder[f.id] = []; });
  DocsState.docs.forEach(d => {
    const fid = d.folder_id && byFolder[d.folder_id] ? d.folder_id : '_unfiled';
    byFolder[fid].push(d);
  });

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
    items.forEach(doc => _renderDocItem(doc, body));
    tree.children(f.id).forEach(child => renderFolder(child, depth + 1));
    group.appendChild(body);

    header.addEventListener('click', () => {
      body.classList.toggle('collapsed');
      header.querySelector('.folder-toggle').textContent = body.classList.contains('collapsed') ? '▸' : '▾';
    });

    container.appendChild(group);
  }

  tree.roots.forEach(f => renderFolder(f, 0));

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
    byFolder._unfiled.forEach(doc => _renderDocItem(doc, unfiledBody));
    container.appendChild(unfiledBody);

    unfiledHeader.addEventListener('click', () => {
      unfiledBody.classList.toggle('collapsed');
      unfiledHeader.querySelector('.folder-toggle').textContent = unfiledBody.classList.contains('collapsed') ? '▸' : '▾';
    });
  }

  if (!DocsState.docs.length)
    container.innerHTML = '<div style="padding:20px 14px;color:var(--text-dim);font-size:0.8rem;">No documents found.</div>';
}

function _renderDocListFlat(container) {
  const byCategory = {};
  DocsState.docs.forEach(d => { (byCategory[d.category] = byCategory[d.category] || []).push(d); });
  const order = ['Overview','People','Places','Factions','Events','Rules','Other'];
  [...new Set([...order, ...Object.keys(byCategory)])].forEach(cat => {
    const items = byCategory[cat];
    if (!items?.length) return;
    const header = document.createElement('div');
    header.className = 'docs-category-header';
    header.textContent = cat;
    container.appendChild(header);
    items.forEach(doc => _renderDocItem(doc, container));
  });
  if (!DocsState.docs.length)
    container.innerHTML = '<div style="padding:20px 14px;color:var(--text-dim);font-size:0.8rem;">No documents found.</div>';
}

async function openDoc(id) {
  DocsState.activeId = id;
  renderDocList();
  showDocSection('viewer');
  const doc = await apiFetch(`${API}/docs/${id}`).then(r => r.json());
  document.getElementById('docs-viewer-title').textContent = doc.title;
  document.getElementById('docs-viewer-meta').innerHTML =
    `<span class="meta-tag">${doc.category}</span>` +
    (doc.tags||[]).map(t => `<span class="meta-tag">${t}</span>`).join('') +
    `<span style="color:var(--text-dim);">Updated ${doc.updated_at?.slice(0,10)||'—'}</span>`;
  document.getElementById('docs-viewer-body').innerHTML = renderMarkdown(doc.content || '');

  // Render cross-linked entities and nodes
  renderDocEntityLinks(id);
  renderDocNodeLinks(id);

  document.getElementById('docs-edit-btn').onclick   = () => openEditor(doc);
  document.getElementById('docs-delete-btn').onclick = () => deleteDoc(doc.id, doc.title);
}

function showDocSection(section) {
  document.getElementById('docs-empty').style.display  = section === 'empty'  ? 'flex'  : 'none';
  document.getElementById('docs-viewer').style.display = section === 'viewer' ? 'block' : 'none';
  document.getElementById('docs-editor').classList.toggle('active', section === 'editor');
}

async function openEditor(doc = null) {
  DocsState.editingId = doc ? doc.id : null;
  document.getElementById('editor-title').value    = doc ? doc.title    : '';
  document.getElementById('editor-category').value = doc ? doc.category : 'Other';
  document.getElementById('editor-tags').value     = doc ? (doc.tags||[]).join(', ') : '';
  document.getElementById('editor-content').value  = doc ? doc.content  : '';
  await loadFolders('docs');
  document.getElementById('editor-folder').innerHTML = renderFolderDropdown(FolderState.docs, doc?.folder_id);
  showDocSection('editor');
}

async function deleteDoc(id, title) {
  if (!confirm(`Delete "${title}"?`)) return;
  await apiFetch(`${API}/docs/${id}`, { method: 'DELETE' });
  DocsState.activeId = null;
  await loadDocsList();
  showDocSection('empty');
}
