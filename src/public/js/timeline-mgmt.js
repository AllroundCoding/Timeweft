'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Timeline Management Module — create, rename, duplicate, delete, import/export
// ══════════════════════════════════════════════════════════════════════════════

async function loadMyTimelines() {
  try {
    const res = await apiFetch(`${API}/user/timelines`);
    const data = await res.json();
    MY_TIMELINES = data.timelines || [];
  } catch {}
}

/** Reload timeline data and re-render both the management list and the switcher dropdown. */
async function refreshTimelineUI() {
  await loadMyTimelines();
  renderTimelineList();
  renderTimelineSwitcher();
}

function setupTimelineMgmt() {
  document.getElementById('timeline-manage-btn')?.addEventListener('click', openTimelineMgmt);
  document.getElementById('timeline-mgmt-close')?.addEventListener('click', closeTimelineMgmt);
  document.getElementById('timeline-mgmt-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'timeline-mgmt-overlay') closeTimelineMgmt();
  });

  // Create new timeline
  document.getElementById('tl-create-btn')?.addEventListener('click', async () => {
    const nameEl = document.getElementById('tl-new-name');
    const errEl = document.getElementById('tl-create-error');
    const name = nameEl.value.trim();
    errEl.textContent = '';
    if (!name) { errEl.textContent = 'Name is required'; return; }
    try {
      const res = await apiFetch(`${API}/user/timelines`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
      });
      const data = await res.json();
      if (!res.ok) { errEl.textContent = data.error; return; }
      nameEl.value = '';
      await refreshTimelineUI();
    } catch (err) { errEl.textContent = err.message; }
  });

  // Import timeline
  document.getElementById('tl-import-btn')?.addEventListener('click', async () => {
    const fileEl = document.getElementById('tl-import-file');
    const nameEl = document.getElementById('tl-import-name');
    const errEl = document.getElementById('tl-import-error');
    errEl.textContent = '';
    if (!fileEl.files.length) { errEl.textContent = 'Select a .db file'; return; }
    const file = fileEl.files[0];
    const formData = new FormData();
    formData.append('file', file);
    const name = nameEl.value.trim();
    if (name) formData.append('name', name);
    try {
      const res = await apiFetch(`${API}/user/timelines/import`, {
        method: 'POST',
        body: formData,
      });
      const data = await res.json();
      if (!res.ok) { errEl.textContent = data.error; return; }
      fileEl.value = '';
      nameEl.value = '';
      await refreshTimelineUI();
    } catch (err) { errEl.textContent = err.message; }
  });
}

function openTimelineMgmt() {
  const overlay = document.getElementById('timeline-mgmt-overlay');
  if (!overlay) return;
  overlay.classList.add('open');
  renderTimelineList();
}

function closeTimelineMgmt() {
  document.getElementById('timeline-mgmt-overlay')?.classList.remove('open');
}

function renderTimelineList() {
  const list = document.getElementById('timeline-mgmt-list');
  if (!list) return;

  if (!MY_TIMELINES.length) {
    list.innerHTML = '<div style="font-size:0.85rem;color:var(--text-secondary);">No timelines</div>';
    return;
  }

  list.innerHTML = MY_TIMELINES.map(tl => `
    <div class="tl-mgmt-item" data-id="${tl.id}">
      <div class="tl-mgmt-name">
        <span class="tl-mgmt-title">${escapeHtml(tl.name)}</span>
        ${ACTIVE_TIMELINE?.id === tl.id ? '<span class="badge badge-read" style="font-size:0.65rem;">active</span>' : ''}
      </div>
      <div class="tl-mgmt-actions">
        <button class="btn" style="font-size:0.7rem;padding:2px 6px;" data-rename-tl="${tl.id}" title="Rename">Rename</button>
        <button class="btn" style="font-size:0.7rem;padding:2px 6px;" data-dup-tl="${tl.id}" title="Duplicate">Duplicate</button>
        <button class="btn" style="font-size:0.7rem;padding:2px 6px;" data-export-tl="${tl.id}" title="Export">Export</button>
        ${MY_TIMELINES.length > 1 ? `<button class="btn" style="font-size:0.7rem;padding:2px 6px;color:var(--danger);border-color:var(--danger);" data-delete-tl="${tl.id}" title="Delete">Delete</button>` : ''}
      </div>
    </div>
  `).join('');

  // Rename
  list.querySelectorAll('[data-rename-tl]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.renameTl;
      const tl = MY_TIMELINES.find(t => t.id === id);
      const name = prompt('New name:', tl?.name || '');
      if (!name?.trim()) return;
      await apiFetch(`${API}/user/timelines/${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name.trim() }),
      });
      if (ACTIVE_TIMELINE?.id === id) ACTIVE_TIMELINE.name = name.trim();
      await refreshTimelineUI();
    });
  });

  // Duplicate
  list.querySelectorAll('[data-dup-tl]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.dupTl;
      await apiFetch(`${API}/user/timelines/${id}/duplicate`, { method: 'POST' });
      await refreshTimelineUI();
    });
  });

  // Export
  list.querySelectorAll('[data-export-tl]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.exportTl;
      try {
        const res = await apiFetch(`${API}/user/timelines/${id}/export`);
        if (!res.ok) return;
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const disposition = res.headers.get('content-disposition');
        const match = disposition?.match(/filename="?(.+?)"?$/);
        a.download = match?.[1] || 'timeline.db';
        a.href = url;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      } catch {}
    });
  });

  // Delete
  list.querySelectorAll('[data-delete-tl]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.deleteTl;
      const tl = MY_TIMELINES.find(t => t.id === id);
      if (!confirm(`Delete timeline "${tl?.name}"? This cannot be undone.`)) return;
      const res = await apiFetch(`${API}/user/timelines/${id}`, { method: 'DELETE' });
      if (!res.ok) { const d = await res.json(); alert(d.error); return; }
      // If we deleted the active timeline, switch to the first available
      if (ACTIVE_TIMELINE?.id === id && MY_TIMELINES.length) {
        await loadMyTimelines();
        if (MY_TIMELINES.length) await switchTimeline(MY_TIMELINES[0].id);
      }
      await refreshTimelineUI();
    });
  });
}
