'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Event listeners
// ══════════════════════════════════════════════════════════════════════════════

function setupListeners() {
  // Nav
  document.querySelectorAll('.nav-btn[data-module]').forEach(btn =>
    btn.addEventListener('click', () => switchModule(btn.dataset.module)));

  // Detail panel close
  document.getElementById('detail-close')?.addEventListener('click', () =>
    document.getElementById('detail-panel').classList.add('collapsed'));

  // Re-render after detail panel open/close transition completes
  document.getElementById('detail-panel')?.addEventListener('transitionend', (e) => {
    if (e.propertyName === 'width') renderWorld();
  });

  // Root zoom
  document.getElementById('zoom-in-btn')?.addEventListener('click',    () => zoomRoot(1.5));
  document.getElementById('zoom-out-btn')?.addEventListener('click',   () => zoomRoot(1/1.5));
  document.getElementById('zoom-reset-btn')?.addEventListener('click', () => zoomRoot(0));

  // Scroll-zoom mode toggle
  const scrollModeBtn = document.getElementById('scroll-mode-btn');
  function updateScrollModeBtn() {
    if (!scrollModeBtn) return;
    scrollModeBtn.textContent = SCROLL_ZOOM_MODE === 'scroll' ? '⇕' : 'Ctrl⇕';
    scrollModeBtn.title = SCROLL_ZOOM_MODE === 'scroll'
      ? 'Scroll to zoom (click for Ctrl+scroll)'
      : 'Ctrl+scroll to zoom (click for scroll)';
  }
  updateScrollModeBtn();
  scrollModeBtn?.addEventListener('click', () => {
    SCROLL_ZOOM_MODE = SCROLL_ZOOM_MODE === 'scroll' ? 'ctrl' : 'scroll';
    localStorage.setItem('tl-scroll-zoom', SCROLL_ZOOM_MODE);
    updateScrollModeBtn();
  });

  // Wheel zoom on the timeline viewport
  document.getElementById('tl-viewport')?.addEventListener('wheel', e => {
    // Ignore horizontal-dominant scrolls (tilt wheel / side buttons)
    if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;
    const wantsZoom = SCROLL_ZOOM_MODE === 'scroll'
      ? !e.ctrlKey && !e.metaKey && !e.shiftKey   // bare scroll = zoom
      : e.ctrlKey || e.metaKey;                     // ctrl/meta + scroll = zoom
    if (!wantsZoom) return;
    e.preventDefault();
    zoomRoot(e.deltaY < 0 ? 1.15 : 1/1.15, e.clientX);
  }, { passive: false });

  // Middle-mouse drag to pan
  {
    const vp = document.getElementById('tl-viewport');
    let dragging = false, startX = 0, startY = 0, startSL = 0, startST = 0;
    vp?.addEventListener('pointerdown', e => {
      if (e.button !== 1) return;   // middle button only
      e.preventDefault();
      dragging = true;
      startX = e.clientX; startY = e.clientY;
      const tl = vp.querySelector('.gantt-timeline');
      startSL = tl?.scrollLeft ?? 0;
      startST = tl?.scrollTop ?? 0;
      vp.style.cursor = 'grabbing';
      vp.setPointerCapture(e.pointerId);
    });
    vp?.addEventListener('pointermove', e => {
      if (!dragging) return;
      const tl = vp.querySelector('.gantt-timeline');
      if (!tl) return;
      tl.scrollLeft = startSL - (e.clientX - startX);
      tl.scrollTop  = startST - (e.clientY - startY);
    });
    vp?.addEventListener('pointerup', e => {
      if (e.button !== 1 || !dragging) return;
      dragging = false;
      vp.style.cursor = '';
      vp.releasePointerCapture(e.pointerId);
    });
    // Block browser auto-scroll on middle click
    vp?.addEventListener('auxclick', e => { if (e.button === 1) e.preventDefault(); });
  }

  // Track cursor position on the timeline for date prefill
  {
    const vp = document.getElementById('tl-viewport');
    vp?.addEventListener('mousemove', e => {
      const tlWrap = vp.querySelector('.gantt-timeline');
      if (!tlWrap) { TL.cursorYear = null; return; }
      const rect = tlWrap.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right) { TL.cursorYear = null; return; }
      const pxInCanvas = tlWrap.scrollLeft + (e.clientX - rect.left);
      const ppy = TL.levelPPY['root'];
      if (ppy && TL.yearMin != null) {
        TL.cursorYear = TL.yearMin + (pxInCanvas - CANVAS_PAD) / ppy;
      }
    });
    vp?.addEventListener('mouseleave', () => { TL.cursorYear = null; });
  }

  // Add node button (root level)
  document.getElementById('add-node-btn')?.addEventListener('click', () => openAddNodeModal(null));

  // Node modal
  document.getElementById('modal-node-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'modal-node-overlay')
      document.getElementById('modal-node-overlay').classList.remove('open');
  });
  document.getElementById('modal-node-form')?.addEventListener('submit', submitNodeModal);
  document.getElementById('mn-cancel')?.addEventListener('click', () =>
    document.getElementById('modal-node-overlay').classList.remove('open'));
  document.getElementById('mn-type')?.addEventListener('change', e =>
    document.getElementById('mn-end-row').style.display = e.target.value === 'span' ? '' : 'none');
  document.getElementById('mn-node-type')?.addEventListener('change', e =>
    document.getElementById('mn-entity-type-row').style.display = e.target.value === 'character' ? '' : 'none');
  document.getElementById('mn-opacity')?.addEventListener('input', e =>
    document.getElementById('mn-opacity-val').textContent = Math.round(e.target.value * 100) + '%');
  initDateFieldSync('mn-start');
  initDateFieldSync('mn-end');

  // Settings modal
  document.getElementById('settings-nav-btn')?.addEventListener('click', () => {
    document.getElementById('s-world-start').value  = WORLD_YEAR_MIN;
    document.getElementById('s-world-end').value    = WORLD_YEAR_MAX;
    document.getElementById('s-view-start').value   = DEFAULT_VIEW_START ?? '';
    document.getElementById('s-view-end').value     = DEFAULT_VIEW_END   ?? '';
    document.getElementById('settings-overlay').classList.add('open');
  });
  document.getElementById('settings-cancel')?.addEventListener('click', () =>
    document.getElementById('settings-overlay').classList.remove('open'));
  document.getElementById('s-open-cal-editor')?.addEventListener('click', () => {
    document.getElementById('settings-overlay').classList.remove('open');
    openCalendarEditor();
  });
  document.getElementById('settings-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const viewStart = document.getElementById('s-view-start').value.trim();
    const viewEnd   = document.getElementById('s-view-end').value.trim();
    const body = {
      world_start:        Number(document.getElementById('s-world-start').value),
      world_end:          Number(document.getElementById('s-world-end').value),
      default_view_start: viewStart === '' ? null : Number(viewStart),
      default_view_end:   viewEnd   === '' ? null : Number(viewEnd),
    };
    await apiFetch(`${API}/settings`, { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
    WORLD_YEAR_MIN    = body.world_start;
    WORLD_YEAR_MAX    = body.world_end;
    DEFAULT_VIEW_START = body.default_view_start;
    DEFAULT_VIEW_END   = body.default_view_end;
    TL.levelPPY['root'] = null;
    TL.scrollLeft['root'] = null;
    document.getElementById('settings-overlay').classList.remove('open');
    renderWorld();
  });

  // Calendar editor
  setupCalendarEditor();

  // Context menu
  document.addEventListener('click', () => { hideCtxMenu(); hideFolderCtxMenu(); });
  document.addEventListener('contextmenu', e => {
    if (!e.target.closest('[data-node-id]')) hideCtxMenu();
    if (!e.target.closest('#ent-list') && !e.target.closest('#docs-list')) hideFolderCtxMenu();
  });
  document.getElementById('ctx-edit')?.addEventListener('click', () => {
    if (_ctxNode) openEditNodeModal(_ctxNode.id);
    hideCtxMenu();
  });
  document.getElementById('ctx-add-child')?.addEventListener('click', () => {
    if (_ctxNode) openAddNodeModal(_ctxNode.id);
    hideCtxMenu();
  });
  document.getElementById('ctx-delete')?.addEventListener('click', () => {
    if (_ctxNode) deleteNode(_ctxNode.id);
    hideCtxMenu();
  });
  document.getElementById('ctx-add-point')?.addEventListener('click', () => {
    openAddNodeModal(_ctxLaneParentId, 'point');
    hideCtxMenu();
  });
  document.getElementById('ctx-add-span')?.addEventListener('click', () => {
    openAddNodeModal(_ctxLaneParentId, 'span');
    hideCtxMenu();
  });
  document.getElementById('ctx-add-group')?.addEventListener('click', () => {
    openAddNodeModal(null); // root level = group
    hideCtxMenu();
  });

  // Right-click on root timeline area (outside lanes) to add a group
  document.getElementById('tl-viewport')?.addEventListener('contextmenu', e => {
    // Only fire if the click wasn't inside a lane row, lane label, or node element
    if (e.target.closest('.gantt-lane-row') || e.target.closest('.gantt-lane-lbl') || e.target.closest('[data-node-id]')) return;
    showCtxMenuRoot(e);
  });

  // Folder context menus for entity/doc sidebars
  initFolderCtxMenu();

  // Story arcs module
  initArcsModule();

  // ResizeObserver: re-render when viewport size changes (window resize, panel open/close)
  // (fixes initial render when getBoundingClientRect fires before layout is final)
  const tlVP = document.getElementById('tl-viewport');
  if (tlVP && typeof ResizeObserver !== 'undefined') {
    let prevW = 0;
    let resizeTimer = 0;
    new ResizeObserver(entries => {
      if (SplitPane.isDragging) return;  // skip re-renders while dragging the divider
      const w = Math.round(entries[0].contentRect.width);
      if (w !== prevW) {
        prevW = w;
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(renderWorld, 150);
      }
    }).observe(tlVP);
  }
}

// ══════════════════════════════════════════════════════════════════════════════
// Boot
// ══════════════════════════════════════════════════════════════════════════════

async function boot() {
  try {
    setupListeners();
    setupUserPanel();
    setupAdminPanel();
    setupSharingModule();
    setupTimelineMgmt();

    // Load timelines in parallel and determine active one
    await Promise.all([loadMyTimelines(), loadSharedTimelines()]);

    const savedTlId = localStorage.getItem('tl_active_timeline');
    const matchOwn = MY_TIMELINES.find(t => t.id === savedTlId);
    const matchShared = _sharedTimelines.find(s => s.timeline_id === savedTlId);
    if (matchOwn) {
      ACTIVE_TIMELINE = makeOwnTimeline(matchOwn);
    } else if (matchShared) {
      ACTIVE_TIMELINE = makeSharedTimeline(matchShared);
    } else if (MY_TIMELINES.length) {
      ACTIVE_TIMELINE = makeOwnTimeline(MY_TIMELINES[0]);
    }
    renderTimelineSwitcher();
    updateShareBodyClasses();
    updateShareBanner();

    // Docs module listeners (need DOM ready)
    document.getElementById('docs-new-btn')?.addEventListener('click', () => {
      DocsState.activeId = null; renderDocList(); openEditor();
    });
    document.getElementById('editor-cancel-btn')?.addEventListener('click', () => {
      if (DocsState.activeId) openDoc(DocsState.activeId); else showDocSection('empty');
    });
    document.getElementById('editor-save-btn')?.addEventListener('click', async () => {
      const payload = {
        title:     document.getElementById('editor-title').value.trim() || 'Untitled',
        category:  document.getElementById('editor-category').value,
        tags:      document.getElementById('editor-tags').value.split(',').map(t => t.trim()).filter(Boolean),
        content:   document.getElementById('editor-content').value,
        folder_id: document.getElementById('editor-folder').value || null,
      };
      const isNew  = !DocsState.editingId;
      const url    = isNew ? `${API}/docs` : `${API}/docs/${DocsState.editingId}`;
      const saved  = await apiFetch(url, { method: isNew ? 'POST' : 'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r => r.json());
      DocsState.activeId  = saved.id;
      DocsState.editingId = null;
      await loadDocsList();
      openDoc(saved.id);
    });
    document.getElementById('docs-search')?.addEventListener('input', e => loadDocsList(e.target.value));

    // Entities module listeners
    document.getElementById('ent-new-btn')?.addEventListener('click', () => {
      EntState.activeId = null; renderEntList(); openEntEditor();
    });
    document.getElementById('ent-cancel-btn')?.addEventListener('click', () => {
      if (EntState.activeId) openEntity(EntState.activeId); else showEntSection('empty');
    });
    document.getElementById('ent-save-btn')?.addEventListener('click', async () => {
      const payload = {
        name:        document.getElementById('ent-ed-name').value.trim() || 'Unnamed',
        entity_type: document.getElementById('ent-ed-type').value,
        color:       document.getElementById('ent-ed-color').value,
        description: document.getElementById('ent-ed-desc').value,
        folder_id:   document.getElementById('ent-ed-folder').value || null,
      };
      const isNew = !EntState.editingId;
      const url   = isNew ? `${API}/entities` : `${API}/entities/${EntState.editingId}`;
      const saved = await apiFetch(url, { method: isNew ? 'POST' : 'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r => r.json());
      EntState.activeId  = saved.id;
      EntState.editingId = null;
      await loadEntitiesList();
      openEntity(saved.id);
    });
    document.getElementById('ent-search')?.addEventListener('input', e => {
      loadEntitiesList(e.target.value, document.getElementById('ent-type-select').value);
    });
    document.getElementById('ent-type-select')?.addEventListener('change', e => {
      loadEntitiesList(document.getElementById('ent-search').value, e.target.value);
    });

    // Load settings (world bounds + default view) before the first render.
    await loadSettings();

    // Initialize split pane system
    initDividerDrag();
    initSplitPositionPicker();
    initMentionAutocomplete();

    // Load root nodes, restore split state, then double-rAF before first render.
    // Two frames guarantees layout is fully committed (display:flex change is
    // processed) before vpWidth()/getBoundingClientRect run inside buildGantt.
    await tlFetch(null);

    // Restore saved split state
    const savedSplit = localStorage.getItem('tl-split-module');
    if (savedSplit && savedSplit !== 'timeline') {
      openSecondary(savedSplit);
    } else {
      // Full-screen timeline (ensure nav button is active)
      document.querySelector('.nav-btn[data-module="timeline"]')?.classList.add('active');
      applySplitLayout();
      requestAnimationFrame(() => requestAnimationFrame(renderWorld));
    }

    // Update pending deletions badge (non-blocking)
    updatePendingBadge();

  } catch (err) {
    document.body.innerHTML = `
      <div style="display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:16px;color:#e05c5c;font-family:Georgia,serif;">
        <div style="font-size:2rem;">⚠</div>
        <div style="font-size:1.1rem;">Could not connect to the Timeline API</div>
        <div style="font-size:0.85rem;color:#8b91a8;">Make sure the server is running: <code style="background:#1e2230;padding:2px 8px;border-radius:4px;">node server/api.js</code></div>
      </div>`;
  }
}

// ── Auth-gated startup ──────────────────────────────────────────────────────
(async () => {
  setupAuthListeners();
  try {
    const authenticated = await initAuth();
    if (authenticated) boot();
  } catch (err) {
    document.body.innerHTML = `
      <div style="display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:16px;color:#e05c5c;font-family:Georgia,serif;">
        <div style="font-size:2rem;">⚠</div>
        <div style="font-size:1.1rem;">Could not connect to the Timeline API</div>
        <div style="font-size:0.85rem;color:#8b91a8;">Make sure the server is running: <code style="background:#1e2230;padding:2px 8px;border-radius:4px;">npm run dev</code></div>
      </div>`;
  }
})();
