'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Docs module
// ══════════════════════════════════════════════════════════════════════════════

const DocsState = { docs: [], activeId: null, editingId: null };

async function loadDocsList(search = '') {
  const q   = search ? `?search=${encodeURIComponent(search)}` : '';
  const res = await apiFetch(`${API}/docs${q}`);
  const data = await res.json();
  DocsState.docs = data.documents;
  renderDocList();
}

function renderDocList() {
  const container = document.getElementById('docs-list');
  container.innerHTML = '';
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
    items.forEach(doc => {
      const el = document.createElement('div');
      el.className = 'doc-list-item' + (doc.id === DocsState.activeId ? ' active' : '');
      el.dataset.docId = doc.id;
      el.innerHTML = `<div class="doc-item-title">${doc.title}</div><div class="doc-item-tags">${(doc.tags||[]).join(', ')}</div>`;
      el.addEventListener('click', () => openDoc(doc.id));
      container.appendChild(el);
    });
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
  document.getElementById('docs-edit-btn').onclick   = () => openEditor(doc);
  document.getElementById('docs-delete-btn').onclick = () => deleteDoc(doc.id, doc.title);
}

function showDocSection(section) {
  document.getElementById('docs-empty').style.display  = section === 'empty'  ? 'flex'  : 'none';
  document.getElementById('docs-viewer').style.display = section === 'viewer' ? 'block' : 'none';
  document.getElementById('docs-editor').classList.toggle('active', section === 'editor');
}

function openEditor(doc = null) {
  DocsState.editingId = doc ? doc.id : null;
  document.getElementById('editor-title').value    = doc ? doc.title    : '';
  document.getElementById('editor-category').value = doc ? doc.category : 'Other';
  document.getElementById('editor-tags').value     = doc ? (doc.tags||[]).join(', ') : '';
  document.getElementById('editor-content').value  = doc ? doc.content  : '';
  showDocSection('editor');
}

async function deleteDoc(id, title) {
  if (!confirm(`Delete "${title}"?`)) return;
  await apiFetch(`${API}/docs/${id}`, { method: 'DELETE' });
  DocsState.activeId = null;
  await loadDocsList();
  showDocSection('empty');
}
