'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Sharing Module — timeline switching, share management, pending deletions
// ══════════════════════════════════════════════════════════════════════════════

// ── Timeline Switcher ───────────────────────────────────────────────────────

let _sharedTimelines = []; // cached list from GET /api/user/shares

async function loadSharedTimelines() {
  try {
    const res = await apiFetch(`${API}/user/shares`);
    const data = await res.json();
    _sharedTimelines = data.shares || [];
    renderTimelineSwitcher();
  } catch {}
}

function renderTimelineSwitcher() {
  const container = document.getElementById('timeline-switcher');
  if (!container) return;
  container.style.display = '';

  const select = container.querySelector('select');
  if (!select) return;

  const currentId = ACTIVE_TIMELINE?.id || '';
  let html = '';

  // Own timelines
  if (MY_TIMELINES.length > 0) {
    html += '<optgroup label="My Timelines">';
    for (const tl of MY_TIMELINES) {
      html += `<option value="${tl.id}" ${tl.id === currentId ? 'selected' : ''}>${escapeHtml(tl.name)}</option>`;
    }
    html += '</optgroup>';
  }

  // Shared timelines
  if (_sharedTimelines.length > 0) {
    html += '<optgroup label="Shared with me">';
    for (const s of _sharedTimelines) {
      const label = s.owner_display_name || s.owner_username;
      const tlName = s.timeline_name || 'Timeline';
      html += `<option value="${s.timeline_id}" data-shared="1" ${s.timeline_id === currentId ? 'selected' : ''}>${escapeHtml(label)} - ${escapeHtml(tlName)} (${s.preset})</option>`;
    }
    html += '</optgroup>';
  }

  select.innerHTML = html;
}

async function switchTimeline(timelineId) {
  if (!timelineId) return;

  // Find in own timelines
  const own = MY_TIMELINES.find(t => t.id === timelineId);
  if (own) {
    ACTIVE_TIMELINE = makeOwnTimeline(own);
  } else {
    // Find in shared timelines
    const shared = _sharedTimelines.find(s => s.timeline_id === timelineId);
    if (!shared) return;
    ACTIVE_TIMELINE = makeSharedTimeline(shared);
  }

  // Persist selection
  localStorage.setItem('tl_active_timeline', ACTIVE_TIMELINE.id);

  // Clear all caches
  TL.childCache = {};
  TL.nodeById = {};
  TL.expanded.clear();
  TL.levelPPY = {};
  TL.scrollLeft = {};
  TL.ganttScrollTop = 0;
  TL.yearMin = null;
  TL.viewAnchor = null;

  // Update body classes for permission-based UI hiding
  updateShareBodyClasses();

  // Update banner
  updateShareBanner();

  // Reload data
  await loadSettings();
  await tlFetch(null);
  requestAnimationFrame(() => requestAnimationFrame(renderWorld));

  // Update pending deletions badge
  updatePendingBadge();
}

function updateShareBodyClasses() {
  const body = document.body;
  // Remove all share classes
  body.classList.remove('share-mode',
    'share-no-edit-timeline', 'share-no-edit-docs', 'share-no-edit-entities', 'share-no-edit-settings',
    'share-no-delete-timeline', 'share-no-delete-docs', 'share-no-delete-entities');

  if (!ACTIVE_TIMELINE || ACTIVE_TIMELINE.is_own) return;

  body.classList.add('share-mode');
  for (const area of ['timeline', 'docs', 'entities', 'settings']) {
    if (!canEdit(area)) body.classList.add(`share-no-edit-${area}`);
  }
  for (const area of ['timeline', 'docs', 'entities']) {
    if (!canDelete(area)) body.classList.add(`share-no-delete-${area}`);
  }
}

function updateShareBanner() {
  const banner = document.getElementById('share-banner');
  if (!banner) return;

  if (!ACTIVE_TIMELINE || ACTIVE_TIMELINE.is_own) {
    banner.style.display = 'none';
    return;
  }

  const name = ACTIVE_TIMELINE.owner_display_name || ACTIVE_TIMELINE.owner_username;
  const tlName = ACTIVE_TIMELINE.name;
  banner.style.display = '';
  banner.innerHTML = `Viewing <strong>${escapeHtml(name)}</strong>'s timeline <strong>${escapeHtml(tlName)}</strong>`;
}

// ── Share Management (in User Panel) ────────────────────────────────────────

async function loadMyShares() {
  try {
    const res = await apiFetch(`${API}/user/shared-by-me`);
    const data = await res.json();
    renderMyShares(data.shares || []);
  } catch {}
}

function renderMyShares(shares) {
  const list = document.getElementById('my-shares-list');
  if (!list) return;

  if (!shares.length) {
    list.innerHTML = '<div style="font-size:0.85rem;color:var(--text-secondary);">No shares yet</div>';
    return;
  }

  list.innerHTML = shares.map(s => {
    const name = s.grantee_display_name || s.grantee_username;
    const tlName = s.timeline_name || 'Timeline';
    return `
      <div class="share-item">
        <div class="share-item-info">
          <strong>${escapeHtml(name)}</strong>
          <span style="font-size:0.75rem;color:var(--text-secondary);">${escapeHtml(tlName)}</span>
          <span class="badge badge-${s.preset}">${s.preset}</span>
        </div>
        <div class="share-item-perms">
          <span title="Timeline">T:${s.perm_timeline}</span>
          <span title="Docs">D:${s.perm_docs}</span>
          <span title="Entities">E:${s.perm_entities}</span>
          <span title="Settings">S:${s.perm_settings}</span>
        </div>
        <div class="share-item-actions">
          <button class="btn" style="font-size:0.7rem;padding:2px 6px;" data-edit-share data-timeline-id="${s.timeline_id}" data-grantee-id="${s.grantee_id}">Edit</button>
          <button class="btn" style="font-size:0.7rem;padding:2px 6px;color:var(--danger);border-color:var(--danger);" data-revoke-share data-timeline-id="${s.timeline_id}" data-grantee-id="${s.grantee_id}">Revoke</button>
        </div>
      </div>`;
  }).join('');

  list.querySelectorAll('[data-revoke-share]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const { timelineId, granteeId } = btn.dataset;
      await apiFetch(`${API}/user/shares/${timelineId}/${granteeId}`, { method: 'DELETE' });
      loadMyShares();
    });
  });

  list.querySelectorAll('[data-edit-share]').forEach(btn => {
    btn.addEventListener('click', () => {
      const { timelineId, granteeId } = btn.dataset;
      const share = shares.find(s => s.timeline_id === timelineId && s.grantee_id === granteeId);
      if (share) openShareEditModal(share);
    });
  });
}

function openShareEditModal(share) {
  const overlay = document.getElementById('share-edit-overlay');
  if (!overlay) return;

  document.getElementById('share-edit-grantee').textContent =
    share.grantee_display_name || share.grantee_username;
  document.getElementById('share-edit-timeline-id').value = share.timeline_id;
  document.getElementById('share-edit-grantee-id').value = share.grantee_id;
  document.getElementById('share-edit-preset').value = share.preset;
  document.getElementById('share-edit-perm-timeline').value = share.perm_timeline;
  document.getElementById('share-edit-perm-docs').value = share.perm_docs;
  document.getElementById('share-edit-perm-entities').value = share.perm_entities;
  document.getElementById('share-edit-perm-settings').value = share.perm_settings;

  const advSection = document.getElementById('share-edit-advanced');
  advSection.style.display = share.preset === 'custom' ? '' : 'none';

  overlay.classList.add('open');
}

function setupShareEditModal() {
  const presetSelect = document.getElementById('share-edit-preset');
  presetSelect?.addEventListener('change', e => {
    const adv = document.getElementById('share-edit-advanced');
    if (e.target.value === 'custom') {
      adv.style.display = '';
    } else {
      adv.style.display = 'none';
    }
  });

  document.getElementById('share-edit-cancel')?.addEventListener('click', () => {
    document.getElementById('share-edit-overlay').classList.remove('open');
  });

  document.getElementById('share-edit-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'share-edit-overlay')
      e.target.classList.remove('open');
  });

  document.getElementById('share-edit-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const timelineId = document.getElementById('share-edit-timeline-id').value;
    const granteeId = document.getElementById('share-edit-grantee-id').value;
    const preset = document.getElementById('share-edit-preset').value;

    const body = { preset };
    if (preset === 'custom') {
      body.perm_timeline = document.getElementById('share-edit-perm-timeline').value;
      body.perm_docs = document.getElementById('share-edit-perm-docs').value;
      body.perm_entities = document.getElementById('share-edit-perm-entities').value;
      body.perm_settings = document.getElementById('share-edit-perm-settings').value;
    }

    await apiFetch(`${API}/user/shares/${timelineId}/${granteeId}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    document.getElementById('share-edit-overlay').classList.remove('open');
    loadMyShares();
    loadSharedTimelines(); // refresh switcher if we edited perms of a timeline we're viewing
  });
}

async function submitNewShare() {
  const username = document.getElementById('share-username').value.trim();
  const preset = document.getElementById('share-preset').value;
  const errEl = document.getElementById('share-create-error');
  errEl.textContent = '';

  if (!username) { errEl.textContent = 'Username required'; return; }

  // Use the currently active timeline (must be own)
  if (!ACTIVE_TIMELINE || !ACTIVE_TIMELINE.is_own) {
    errEl.textContent = 'Switch to your own timeline to share it';
    return;
  }

  try {
    const body = { username, timeline_id: ACTIVE_TIMELINE.id, preset };
    // If custom, gather per-area values
    if (preset === 'custom') {
      body.perm_timeline = document.getElementById('share-new-perm-timeline').value;
      body.perm_docs = document.getElementById('share-new-perm-docs').value;
      body.perm_entities = document.getElementById('share-new-perm-entities').value;
      body.perm_settings = document.getElementById('share-new-perm-settings').value;
    }
    const res = await apiFetch(`${API}/user/shares`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) { errEl.textContent = data.error; return; }
    document.getElementById('share-username').value = '';
    loadMyShares();
    loadSharedTimelines();
  } catch (err) {
    errEl.textContent = err.message;
  }
}

// ── Pending Deletions ───────────────────────────────────────────────────────

async function updatePendingBadge() {
  const badge = document.getElementById('pending-deletions-badge');
  const btn = document.getElementById('pending-nav-btn');
  if (!badge) return;

  try {
    const res = await apiFetch(`${API}/pending-deletions/count`);
    const data = await res.json();
    if (data.count > 0 && isOwnTimeline()) {
      badge.textContent = data.count;
      badge.style.display = '';
      if (btn) btn.style.display = '';
    } else {
      badge.style.display = 'none';
      if (btn) btn.style.display = 'none';
    }
  } catch {
    badge.style.display = 'none';
    if (btn) btn.style.display = 'none';
  }
}

async function loadPendingDeletions() {
  const list = document.getElementById('pending-deletions-list');
  if (!list) return;

  try {
    const res = await apiFetch(`${API}/pending-deletions`);
    const data = await res.json();
    const items = data.pending_deletions || [];

    if (!items.length) {
      list.innerHTML = '<div style="font-size:0.85rem;color:var(--text-secondary);">No pending deletions</div>';
      return;
    }

    list.innerHTML = items.map(pd => `
      <div class="pending-deletion-item">
        <div class="pd-info">
          <span class="badge badge-${pd.resource_type}">${pd.resource_type}</span>
          <strong>${escapeHtml(pd.resource_title || pd.resource_id)}</strong>
          <span style="font-size:0.75rem;color:var(--text-secondary);">by ${escapeHtml(pd.requested_by_username)}</span>
        </div>
        <div class="pd-actions">
          <button class="btn" style="font-size:0.7rem;padding:2px 6px;color:var(--accent);border-color:var(--accent);" data-approve-pd="${pd.id}">Approve</button>
          <button class="btn" style="font-size:0.7rem;padding:2px 6px;color:var(--danger);border-color:var(--danger);" data-reject-pd="${pd.id}">Reject</button>
        </div>
      </div>
    `).join('');

    list.querySelectorAll('[data-approve-pd]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await apiFetch(`${API}/pending-deletions/${btn.dataset.approvePd}/approve`, { method: 'POST' });
        loadPendingDeletions();
        updatePendingBadge();
      });
    });

    list.querySelectorAll('[data-reject-pd]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await apiFetch(`${API}/pending-deletions/${btn.dataset.rejectPd}/reject`, { method: 'POST' });
        loadPendingDeletions();
        updatePendingBadge();
      });
    });
  } catch {}
}

// ── Setup ───────────────────────────────────────────────────────────────────

function setupSharingModule() {
  // Timeline switcher
  document.getElementById('timeline-switcher')?.querySelector('select')?.addEventListener('change', e => {
    switchTimeline(e.target.value);
  });

  // New share form
  document.getElementById('share-create-btn')?.addEventListener('click', submitNewShare);

  // New share preset toggle
  document.getElementById('share-preset')?.addEventListener('change', e => {
    const adv = document.getElementById('share-new-advanced');
    if (adv) adv.style.display = e.target.value === 'custom' ? '' : 'none';
  });

  // Share edit modal
  setupShareEditModal();

  // Pending deletions nav button
  document.getElementById('pending-nav-btn')?.addEventListener('click', () => {
    document.getElementById('pending-overlay').classList.add('open');
    loadPendingDeletions();
  });
  document.getElementById('pending-close')?.addEventListener('click', () => {
    document.getElementById('pending-overlay').classList.remove('open');
  });
  document.getElementById('pending-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'pending-overlay') e.target.classList.remove('open');
  });
}
