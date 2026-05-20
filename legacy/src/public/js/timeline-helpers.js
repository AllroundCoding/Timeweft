'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Data fetching
// ══════════════════════════════════════════════════════════════════════════════

let ACTIVE_CALENDAR_ID = null;

async function loadSettings() {
  const s = await apiFetch(`${API}/settings`).then(r => r.json());
  if (s.world_start != null) WORLD_YEAR_MIN    = s.world_start;
  if (s.world_end   != null) WORLD_YEAR_MAX    = s.world_end;
  DEFAULT_VIEW_START = s.default_view_start ?? null;
  DEFAULT_VIEW_END   = s.default_view_end   ?? null;
  if (s.calendar && Array.isArray(s.calendar.months) && s.calendar.months.length) {
    CALENDAR = s.calendar;
  }
  ACTIVE_CALENDAR_ID = s.active_calendar_id ?? null;
}

async function tlFetch(parentId) {
  const key = parentId ?? 'root';
  if (TL.childCache[key]) return TL.childCache[key];
  const qs = parentId ? `parent_id=${encodeURIComponent(parentId)}` : 'parent_id=null';
  const nodes = await apiFetch(`${API}/nodes?${qs}`).then(r => r.json());
  nodes.forEach(n => TL.nodeById[n.id] = n);
  TL.childCache[key] = nodes;
  return nodes;
}

// ══════════════════════════════════════════════════════════════════════════════
// Geometry helpers
// ══════════════════════════════════════════════════════════════════════════════

function vpWidth() {
  const vp = document.getElementById('tl-viewport');
  if (vp) {
    const w = vp.getBoundingClientRect().width;
    if (w > 100) return w - 8;
  }
  // Fallback: full window minus sidebar (60px) and detail panel (0 when collapsed)
  return Math.max(400, window.innerWidth - 68);
}

function fitPPY(yearMin, yearMax, w) {
  const width = w ?? vpWidth();
  return Math.max(0.00001, (width - CANVAS_PAD * 2 - 20) / Math.max(1, yearMax - yearMin));
}

function xOf(year, yearMin, ppy) {
  return CANVAS_PAD + Math.round((year - yearMin) * ppy);
}

// ══════════════════════════════════════════════════════════════════════════════
// Row packing — greedy interval scheduling for span nodes
// ══════════════════════════════════════════════════════════════════════════════

function packSpanRows(spans) {
  const sorted = [...spans].sort((a, b) => a.start_date - b.start_date);
  const rowEnds = [];
  const rowMap  = {};
  for (const s of sorted) {
    let i = rowEnds.findIndex(e => s.start_date >= e);
    if (i === -1) i = rowEnds.length;
    rowEnds[i] = s.end_date ?? s.start_date;
    rowMap[s.id] = i;
  }
  return { rowCount: Math.max(1, rowEnds.length), rowMap };
}

// Stagger nearby point chips vertically so they don't overlap.
// Sliding-window approach: O(n) after the sort.
function staggerPoints(points, ppy) {
  const sorted = [...points].sort((a, b) => a.start_date - b.start_date);
  const map = {};
  const threshold = 64 / ppy; // year distance that maps to 64px
  // Window of recent assignments: [{ date, idx }], oldest first.
  // slotRefCount[idx] tracks how many window entries occupy that slot.
  const window = [];
  const slotRefCount = [];
  let winStart = 0;

  for (const p of sorted) {
    // Slide window: drop entries that are too far left
    while (winStart < window.length && (p.start_date - window[winStart].date) >= threshold) {
      const old = window[winStart].idx;
      slotRefCount[old]--;
      winStart++;
    }
    // Find lowest free slot
    let idx = 0;
    while (idx < slotRefCount.length && slotRefCount[idx] > 0) idx++;
    map[p.id] = idx;
    // Push into window
    window.push({ date: p.start_date, idx });
    if (idx >= slotRefCount.length) slotRefCount.push(0);
    slotRefCount[idx]++;
  }
  return map;
}

// ══════════════════════════════════════════════════════════════════════════════
// Node element builders
// ══════════════════════════════════════════════════════════════════════════════

const SYMBOLS = { milestone:'★', conflict:'✕', disaster:'⚡', legend:'◈', cultural:'♪', religious:'✦', region:'⬡', kingdom:'♜', character:'◉', event:'•' };
const IMP_ALPHA = { critical: 1, major: 0.85, moderate: 0.65, minor: 0.45 };

// ── Level-of-Detail (LOD) ─────────────────────────────────────────────────────
// At far zoom only high-importance nodes are shown; more appear as you zoom in.
// Thresholds based on visible year range (adapts to any world size):
//   > 5000 years visible → critical only
//   > 500  years visible → major +
//   > 50   years visible → moderate +
//   ≤ 50   years visible → all (minor +)
const IMP_INDEX = { minor: 0, moderate: 1, major: 2, critical: 3 };

function lodMinIdx(ppy) {
  const visibleYears = Math.max(200, vpWidth() - GANTT_SIDEBAR_W) / ppy;
  if (visibleYears > 5000) return 3;  // critical only
  if (visibleYears > 500)  return 2;  // major +
  if (visibleYears > 50)   return 1;  // moderate +
  return 0;                            // all
}

function lodLabel(ppy) {
  return ['all', 'moderate+', 'major+', 'critical'][lodMinIdx(ppy)];
}

// Point display mode inside sub-timelines: tick → compact → full as zoom increases
function pointDisplayMode(parentSpan, numPoints, ppy) {
  if (!parentSpan || numPoints === 0) return 'full';
  const parentW = ((parentSpan.end_date ?? parentSpan.start_date) - parentSpan.start_date) * ppy;
  const pxPerPt = parentW / numPoints;
  if (pxPerPt < 50) return 'tick';
  if (pxPerPt < 100) return 'compact';
  return 'full';
}

function makeSpanEl(node, ppy, yearMin, yearMax, rowY, h = SPAN_H) {
  const dispStart = Math.max(node.start_date, yearMin);
  const dispEnd   = Math.min(node.end_date,   yearMax);
  const x  = xOf(dispStart, yearMin, ppy);
  const w  = Math.max(8, Math.round((dispEnd - dispStart) * ppy));
  const y  = rowY;
  const isExpanded = TL.expanded.has(node.id);
  const isLoading  = TL.loading.has(node.id);

  const el = document.createElement('div');
  el.className = `tl-span${isExpanded ? ' is-expanded' : ''}${isLoading ? ' is-loading' : ''}`;
  el.dataset.nodeId = node.id;
  const op = node.opacity != null ? node.opacity : 0.6;
  el.style.cssText  = `left:${x}px;width:${w}px;top:${y}px;height:${h}px;--span-opacity:${op};`;
  // Set per-node color; empty string lets CSS fall back to --span-fallback-bg from the active theme
  if (node.color) el.style.setProperty('--span-color', node.color);

  // Bar shows only the expand arrow; title lives in a sticky overlay sibling
  if (w >= 20) {
    const arrow = isExpanded ? '▾' : '▸';
    el.innerHTML = `<span class="span-arr">${arrow}</span>`;
  }
  el.title = node.title; // fallback tooltip for very narrow bars
  return el;
}

function makePointEl(node, ppy, yearMin, spansH, staggerIdx, mode) {
  const x     = xOf(node.start_date, yearMin, ppy);
  const baseY = spansH + 2;
  mode = mode || 'full';

  const el = document.createElement('div');
  el.className = `tl-point tl-point-${mode} imp-${node.importance || 'moderate'}`;
  el.dataset.nodeId = node.id;

  if (mode === 'tick') {
    el.style.cssText = `left:${x}px;top:${baseY}px;`;
    el.style.setProperty('--dot-color', node.color || '#8899cc');
    el.innerHTML = `<div class="pt-tick-mark"></div>`;
  } else if (mode === 'compact') {
    const chipY = baseY + staggerIdx * 14;
    el.style.cssText = `left:${x}px;top:${chipY}px;`;
    el.style.setProperty('--dot-color', node.color || '#8899cc');
    const sym  = SYMBOLS[node.node_type] || '•';
    const lineH = 4 + staggerIdx * 14;
    el.innerHTML = `<div class="pt-line" style="height:${lineH}px"></div><div class="pt-chip"><span class="pt-sym">${sym}</span></div>`;
  } else {
    const chipY = baseY + staggerIdx * 18;
    el.style.cssText = `left:${x}px;top:${chipY}px;`;
    el.style.setProperty('--dot-color', node.color || '#8899cc');
    const sym  = SYMBOLS[node.node_type] || '•';
    const lineH = 6 + staggerIdx * 18;
    el.innerHTML = `<div class="pt-line" style="height:${lineH}px"></div><div class="pt-chip"><span class="pt-sym">${sym}</span>${node.title}</div>`;
  }

  el.title = node.title;
  return el;
}

// ── Event delegation ──────────────────────────────────────────────────────────
// Attach once per lane row instead of per node. Walks up from e.target to find
// the nearest [data-node-id] element and its type (span, point, or label overlay).

function findNodeEl(target, container) {
  let el = target;
  while (el && el !== container) {
    if (el.dataset && el.dataset.nodeId) return el;
    el = el.parentElement;
  }
  return null;
}

// ── Drag-to-select date range ────────────────────────────────────────────────

const _drag = { active: false, row: null, parentId: null, startPx: 0, overlay: null, didDrag: false };
const DRAG_MIN_PX = 8; // minimum drag distance to trigger

function _clientXToYear(clientX) {
  const tlWrap = document.querySelector('.gantt-timeline');
  if (!tlWrap) return null;
  const rect = tlWrap.getBoundingClientRect();
  const canvasPx = tlWrap.scrollLeft + (clientX - rect.left);
  const ppy = TL.levelPPY['root'];
  if (!ppy || TL.yearMin == null) return null;
  return TL.yearMin + (canvasPx - CANVAS_PAD) / ppy;
}

function _clientXToCanvasPx(clientX) {
  const tlWrap = document.querySelector('.gantt-timeline');
  if (!tlWrap) return 0;
  const rect = tlWrap.getBoundingClientRect();
  return tlWrap.scrollLeft + (clientX - rect.left);
}

function _startDrag(e, row, parentSpan) {
  if (e.button !== 0) return;                     // left button only
  if (findNodeEl(e.target, row)) return;           // started on a node — skip
  if (document.body.classList.contains('share-no-edit-timeline')) return;

  _drag.active   = true;
  _drag.didDrag  = false;
  _drag.row      = row;
  _drag.parentId = parentSpan?.id ?? null;
  _drag.startPx  = _clientXToCanvasPx(e.clientX);

  // Create selection overlay inside the track (same parent as the row)
  const overlay = document.createElement('div');
  overlay.className = 'drag-select-overlay';
  overlay.style.left   = _drag.startPx + 'px';
  overlay.style.width  = '0px';
  overlay.style.top    = row.offsetTop + 'px';
  overlay.style.height = row.offsetHeight + 'px';
  row.parentElement.appendChild(overlay);
  _drag.overlay = overlay;
}

function _moveDrag(e) {
  if (!_drag.active) return;
  const currentPx = _clientXToCanvasPx(e.clientX);
  const dist = Math.abs(currentPx - _drag.startPx);
  if (dist >= DRAG_MIN_PX) _drag.didDrag = true;
  const left  = Math.min(_drag.startPx, currentPx);
  _drag.overlay.style.left  = left + 'px';
  _drag.overlay.style.width = dist + 'px';
}

function _endDrag(e) {
  if (!_drag.active) return;
  _drag.active = false;
  const overlay = _drag.overlay;
  if (overlay) overlay.remove();
  _drag.overlay = null;

  const endPx = _clientXToCanvasPx(e.clientX);
  if (!_drag.didDrag) return; // too short, let click handle it

  const year1 = _clientXToYear(e.clientX);
  // Reconstruct start year from canvas px
  const ppy = TL.levelPPY['root'];
  if (!ppy || TL.yearMin == null || year1 == null) return;
  const year0 = TL.yearMin + (_drag.startPx - CANVAS_PAD) / ppy;

  const startYear = Math.min(year0, year1);
  const endYear   = Math.max(year0, year1);

  openAddNodeModal(_drag.parentId, null, { startYear, endYear });
}

// Global listeners for drag (must survive across lane boundaries)
document.addEventListener('mousemove', _moveDrag);
document.addEventListener('mouseup', _endDrag);

// ── Lane event delegation ────────────────────────────────────────────────────

function attachLaneDelegation(row, parentSpan) {
  row.addEventListener('click', e => {
    if (_drag.didDrag) { _drag.didDrag = false; return; } // suppress click after drag
    const el = findNodeEl(e.target, row);
    if (!el) return;
    e.stopPropagation();
    const node = TL.nodeById[el.dataset.nodeId];
    if (!node) return;
    if (el.classList.contains('tl-span') || el.classList.contains('tl-span-lbl-overlay')) {
      tlToggle(node);
    } else {
      openNodeDetail(node);
    }
  });

  row.addEventListener('mousedown', e => _startDrag(e, row, parentSpan));

  row.addEventListener('contextmenu', e => {
    const el = findNodeEl(e.target, row);
    if (!el) {
      showCtxMenuBlank(e, parentSpan?.id ?? null);
      return;
    }
    const node = TL.nodeById[el.dataset.nodeId];
    if (node) showCtxMenu(e, node);
  });

  row.addEventListener('mouseenter', e => {
    const el = findNodeEl(e.target, row);
    if (!el) return;
    const node = TL.nodeById[el.dataset.nodeId];
    if (node) showTip(e, node);
  }, true); // capture phase for mouseenter delegation

  row.addEventListener('mouseleave', e => {
    const el = findNodeEl(e.target, row);
    if (!el) return;
    hideTip();
  }, true);
}
