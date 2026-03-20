'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Main render
// ══════════════════════════════════════════════════════════════════════════════

function renderWorld() {
  const root = document.getElementById('tl-root');
  if (!root) return;
  // If the viewport isn't sized yet, layout isn't ready — retry next frame
  if (vpWidth() <= 400 && document.getElementById('tl-viewport')?.getBoundingClientRect().width < 100) {
    requestAnimationFrame(renderWorld);
    return;
  }
  // Scroll state is maintained by the scroll listener in buildGantt and
  // applied synchronously after DOM append — no snapshot needed here.
  const vp    = document.getElementById('tl-viewport');
  root.innerHTML = '';
  const nodes = TL.childCache['root'] || [];
  if (nodes.length === 0) {
    vp?.classList.remove('gantt-mode');
    root.innerHTML = '<div class="tl-empty">No timeline groups yet — click <strong>+ Add Group</strong> or right-click here to start.</div>';
  } else {
    vp?.classList.add('gantt-mode');
    const gantt = buildGantt(nodes);
    root.appendChild(gantt);
    gantt._applyScroll();  // synchronous — no flash
    // Arc overlay (async, non-blocking — renders after data fetched)
    if (gantt._renderArcOverlay) gantt._renderArcOverlay();
  }
  updateStatusBar();
}

// ══════════════════════════════════════════════════════════════════════════════
// Toggle expand / collapse
// ══════════════════════════════════════════════════════════════════════════════

async function tlToggle(node) {
  if (TL.expanded.has(node.id)) {
    TL.expanded.delete(node.id);
    collapseDescendants(node.id);
    renderWorld();
    return;
  }
  if (TL.loading.has(node.id)) return;
  TL.loading.add(node.id);
  renderWorld(); // show loading indicator
  try {
    await tlFetch(node.id);
    TL.expanded.add(node.id);
  } finally {
    TL.loading.delete(node.id);
  }
  renderWorld();
}

function collapseDescendants(nodeId) {
  (TL.childCache[nodeId] || []).forEach(c => {
    if (TL.expanded.has(c.id)) { TL.expanded.delete(c.id); collapseDescendants(c.id); }
  });
}

// ══════════════════════════════════════════════════════════════════════════════
// Root-level zoom
// ══════════════════════════════════════════════════════════════════════════════

function zoomRoot(factor, anchorClientX) {
  const nodes = TL.childCache['root'] || [];
  if (!nodes.length && factor !== 0) return;

  if (factor === 0) {
    TL.levelPPY['root'] = null;
    TL.scrollLeft['root'] = null;
    TL.viewAnchor = null;
    renderWorld();
    return;
  }

  let dataMin = -1000, dataMax = 2000;
  if (nodes.length) {
    dataMin = Infinity; dataMax = -Infinity;
    for (const n of nodes) {
      if (n.start_date < dataMin) dataMin = n.start_date;
      const end = n.end_date ?? n.start_date;
      if (end > dataMax) dataMax = end;
    }
    dataMin -= 50; dataMax += 50;
  }
  const tlW = Math.max(200, vpWidth() - GANTT_SIDEBAR_W);
  const oldPPY = TL.levelPPY['root'] ?? fitPPY(dataMin, dataMax, tlW);

  // Allow deep zoom — cap at 1M PPY (~30-second resolution), independent of world range
  const maxPPY = 1_000_000;
  // Min PPY = world bounds fitting the viewport (can't zoom out further)
  const minPPY = fitPPY(WORLD_YEAR_MIN, WORLD_YEAR_MAX, tlW);
  const newPPY = Math.min(Math.max(minPPY, oldPPY * factor), maxPPY);

  // Current scroll — read live from DOM, fall back to saved state
  const tlWrapEl = document.querySelector('.gantt-timeline');
  const oldScroll = tlWrapEl?.scrollLeft ?? TL.scrollLeft['root'] ?? 0;

  // Anchor: pixel offset from timeline viewport left edge
  // Wheel zoom → cursor position; button zoom → viewport center
  let anchor;
  if (anchorClientX != null && tlWrapEl) {
    anchor = anchorClientX - tlWrapEl.getBoundingClientRect().left;
  } else {
    anchor = tlW / 2;
  }

  // Compute the year under the anchor point (using last rendered yearMin)
  const oldYearMin = TL.yearMin ?? WORLD_YEAR_MIN;
  const anchorYear = oldYearMin + (oldScroll + anchor - CANVAS_PAD) / oldPPY;

  TL.levelPPY['root'] = newPPY;
  TL.viewAnchor = { year: anchorYear, vpOffset: anchor };
  renderWorld();
}

// ══════════════════════════════════════════════════════════════════════════════
// Navigate to a specific node (expand parents, scroll, zoom, show detail)
// ══════════════════════════════════════════════════════════════════════════════

async function navigateToNode(nodeId) {
  // Fetch the node if not already cached
  let node = TL.nodeById[nodeId];
  if (!node) {
    try {
      node = await apiFetch(`${API}/nodes/${nodeId}`).then(r => r.json());
      if (node.error) return;
      TL.nodeById[node.id] = node;
    } catch { return; }
  }

  // Walk up the parent chain: fetch and expand each ancestor
  const ancestors = [];
  let current = node;
  while (current.parent_id) {
    ancestors.unshift(current.parent_id);
    if (!TL.nodeById[current.parent_id]) {
      const parent = await apiFetch(`${API}/nodes/${current.parent_id}`).then(r => r.json());
      if (parent.error) break;
      TL.nodeById[parent.id] = parent;
      current = parent;
    } else {
      current = TL.nodeById[current.parent_id];
    }
  }

  // Fetch children for each ancestor and expand them
  for (const pid of ancestors) {
    await tlFetch(pid);
    TL.expanded.add(pid);
  }

  // Compute a zoom level that gives context around the node
  const span = (node.end_date ?? node.start_date) - node.start_date;
  const padding = Math.max(span * 0.5, 50); // extra space around the node
  const viewStart = node.start_date - padding;
  const viewEnd = (node.end_date ?? node.start_date) + padding;
  const tlW = Math.max(200, vpWidth() - GANTT_SIDEBAR_W);
  const targetPPY = fitPPY(viewStart, viewEnd, tlW);

  // Only zoom in if the current zoom is too far out to see the node clearly
  const currentPPY = TL.levelPPY['root'];
  if (currentPPY == null || currentPPY < targetPPY * 0.5) {
    TL.levelPPY['root'] = targetPPY;
  }

  // Center the node in the viewport
  const centerYear = node.start_date + span / 2;
  TL.viewAnchor = { year: centerYear, vpOffset: tlW / 2 };

  // Render and open detail
  renderWorld();
  openNodeDetail(node);
}

// ══════════════════════════════════════════════════════════════════════════════
// Tooltip
// ══════════════════════════════════════════════════════════════════════════════

function showTip(e, node) {
  const tt = document.getElementById('tooltip');
  tt.querySelector('.tt-title').textContent = node.title;
  tt.querySelector('.tt-year').textContent  = node.type === 'span'
    ? `${formatNodeDate(node,'start')} → ${formatNodeDate(node,'end')}` : formatNodeDate(node,'start');
  tt.querySelector('.tt-type').textContent  = node.node_type || node.type;
  tt.style.display = 'block';
  posTip(e);
}
function hideTip() { document.getElementById('tooltip').style.display = 'none'; }
function posTip(e) {
  const tt = document.getElementById('tooltip');
  tt.style.left = (e.clientX + 14) + 'px';
  tt.style.top  = (e.clientY + 12) + 'px';
}
document.addEventListener('mousemove', e => {
  const tt = document.getElementById('tooltip');
  if (tt && tt.style.display !== 'none') posTip(e);
});
