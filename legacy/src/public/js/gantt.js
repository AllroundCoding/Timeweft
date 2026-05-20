'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Gantt view
// ══════════════════════════════════════════════════════════════════════════════

// Flatten the tree into an ordered list of LANES (one per root span).
// Expanded child spans render INLINE inside their parent lane row — no separate sub-lanes.
function flattenLanes(rootNodes, ppy) {
  const minImp = lodMinIdx(ppy);
  const result = [];

  // Root-level point events → one misc lane
  const rootPoints = rootNodes.filter(n => n.type !== 'span' &&
    (IMP_INDEX[n.importance ?? 'moderate'] ?? 1) >= minImp);
  if (rootPoints.length > 0) {
    result.push({ parentSpan: null, items: rootPoints, loading: false });
  }

  for (const span of rootNodes.filter(n => n.type === 'span')) {
    if (TL.loading.has(span.id)) {
      result.push({ parentSpan: span, items: [], loading: true });
    } else {
      const children = TL.childCache[span.id] || [];
      const items = children.filter(n => (IMP_INDEX[n.importance ?? 'moderate'] ?? 1) >= minImp);
      result.push({ parentSpan: span, items, loading: false });
    }
  }
  return result;
}

// Compute the pixel height for a set of items.
// Expanded spans grow taller in-place; their children's height is folded into the row.
function computeItemsH(items, ppy, parentSpan) {
  if (!items.length) return SPAN_H + SPAN_GAP + 8;
  const minImp = lodMinIdx(ppy);
  const spans  = items.filter(n => n.type === 'span');
  const points = items.filter(n => n.type !== 'span');

  function spanH(n) {
    if (!TL.expanded.has(n.id)) return SPAN_H;
    if (TL.loading.has(n.id))   return SPAN_H + 32;
    const childItems = (TL.childCache[n.id] || [])
      .filter(c => (IMP_INDEX[c.importance ?? 'moderate'] ?? 1) >= minImp);
    return SPAN_H + computeItemsH(childItems, ppy, n);
  }

  const { rowCount, rowMap } = packSpanRows(spans);
  const rowH = Array(rowCount).fill(0);
  spans.forEach(n => { rowH[rowMap[n.id]] = Math.max(rowH[rowMap[n.id]], spanH(n)); });
  const spansH = rowH.reduce((s, h) => s + h + SPAN_GAP, 0);

  const ptMode = pointDisplayMode(parentSpan, points.length, ppy);
  let pointsH;
  if (ptMode === 'tick') {
    pointsH = points.length > 0 ? 20 : 0;
  } else if (ptMode === 'compact') {
    const stagger = staggerPoints(points, ppy);
    const maxStag = points.length ? Math.max(0, ...Object.values(stagger)) : 0;
    pointsH = points.length > 0 ? 24 + maxStag * 16 : 0;
  } else {
    const stagger = staggerPoints(points, ppy);
    const maxStag = points.length ? Math.max(0, ...Object.values(stagger)) : 0;
    pointsH = points.length > 0 ? POINT_H + maxStag * 36 : 0;
  }
  return Math.max(SPAN_H + SPAN_GAP, spansH + pointsH) + 8;
}

// Compute the pixel height a lane needs (includes the 4px top padding from baseY).
function computeLaneH(items, ppy, parentSpan) {
  if (!items.length) return SPAN_H + SPAN_GAP + 8;
  return computeItemsH(items, ppy, parentSpan) + 4;
}

function buildGanttRuler(yearMin, yearMax, ppy, canvasW, step) {
  const ruler = document.createElement('div');
  ruler.className = 'gantt-ruler';
  ruler.style.width = canvasW + 'px';
  const start = Math.ceil(yearMin / step) * step;
  for (let y = start; y <= yearMax; y += step) {
    const tick = document.createElement('div');
    tick.className = 'gantt-tick';
    tick.style.left = xOf(y, yearMin, ppy) + 'px';
    tick.textContent = formatRulerTick(y, step);
    ruler.appendChild(tick);
  }
  return ruler;
}

function buildGridLines(yearMin, yearMax, ppy, canvasW, step) {
  const grid = document.createElement('div');
  grid.className = 'gantt-grid';
  grid.style.width = canvasW + 'px';
  const pStep = parentStep(step);
  const start = Math.ceil(yearMin / step) * step;
  for (let y = start; y <= yearMax; y += step) {
    const nearest = Math.round(y / pStep) * pStep;
    const isMajor = Math.abs(y - nearest) < pStep * 1e-6;
    const line = document.createElement('div');
    line.className = isMajor ? 'grid-line grid-major' : 'grid-line';
    line.style.left = xOf(y, yearMin, ppy) + 'px';
    grid.appendChild(line);
  }
  return grid;
}

// Sidebar label for a lane.
function buildGanttLaneLabel(lane, h) {
  const { parentSpan, loading } = lane;
  const lbl = document.createElement('div');
  lbl.className = 'gantt-lane-lbl';
  lbl.style.height = h + 'px';
  lbl.style.paddingLeft = '8px';

  if (parentSpan) {
    const dot = document.createElement('span');
    dot.className = 'gantt-color-dot';
    dot.style.background = parentSpan.color || '#5566bb';
    lbl.appendChild(dot);
  }

  const nameEl = document.createElement('span');
  nameEl.className = 'gantt-lane-name' + (!parentSpan ? ' root' : '');
  nameEl.textContent = loading ? `${parentSpan?.title ?? ''} …`
                     : parentSpan ? parentSpan.title
                     : 'Timeline';
  lbl.appendChild(nameEl);

  const acts = document.createElement('span');
  acts.className = 'gantt-label-acts';

  const addBtn = document.createElement('button');
  addBtn.className = 'gantt-act-btn';
  addBtn.title = 'Add event';
  addBtn.textContent = '+';
  addBtn.addEventListener('click', e => {
    e.stopPropagation(); openAddNodeModal(parentSpan?.id ?? null);
  });
  acts.appendChild(addBtn);

  lbl.appendChild(acts);
  if (parentSpan) lbl.addEventListener('contextmenu', e => showCtxMenu(e, parentSpan));
  return lbl;
}

// Render items (spans + points + inline expansion panels) into a container element.
// baseY = starting Y offset inside the container.
// viewYearMin/viewYearMax = visible year range for viewport culling (null = no culling).
function renderItemsInto(container, items, yearMin, yearMax, ppy, canvasW, baseY, parentSpan, viewYearMin, viewYearMax) {
  const minImp = lodMinIdx(ppy);
  const spans  = items.filter(n => n.type === 'span');
  const points = items.filter(n => n.type !== 'span');

  // Height of a span pill: SPAN_H when collapsed, SPAN_H + child content height when expanded.
  function spanH(n) {
    if (!TL.expanded.has(n.id)) return SPAN_H;
    if (TL.loading.has(n.id))   return SPAN_H + 32;
    const childItems = (TL.childCache[n.id] || [])
      .filter(c => (IMP_INDEX[c.importance ?? 'moderate'] ?? 1) >= minImp);
    return SPAN_H + computeItemsH(childItems, ppy, n);
  }

  // Per-row heights: max of all span pill heights in each packed row.
  const { rowCount, rowMap } = packSpanRows(spans);
  const rowH = Array(rowCount).fill(0);
  spans.forEach(n => { rowH[rowMap[n.id]] = Math.max(rowH[rowMap[n.id]], spanH(n)); });

  const rowStartY = [];
  let curY = baseY;
  for (let r = 0; r < rowCount; r++) { rowStartY[r] = curY; curY += rowH[r] + SPAN_GAP; }
  const afterSpansY = curY;

  const ptMode = pointDisplayMode(parentSpan, points.length, ppy);
  const stagger = ptMode === 'tick' ? {} : staggerPoints(points, ppy);

  const cull = viewYearMin != null && viewYearMax != null;

  // Render span pills — expanded pills are taller, child content sits inside visually.
  // Spans are culled only if entirely outside the viewport.
  const visibleSpans = cull
    ? spans.filter(n => (n.end_date ?? n.start_date) >= viewYearMin && n.start_date <= viewYearMax)
    : spans;
  visibleSpans.forEach(n => container.appendChild(makeSpanEl(n, ppy, yearMin, yearMax, rowStartY[rowMap[n.id]], spanH(n))));

  // Sticky title overlays (always SPAN_H tall, anchored to the pill top).
  visibleSpans.forEach(n => {
    const dispStart = Math.max(n.start_date, yearMin);
    const dispEnd   = Math.min(n.end_date, yearMax);
    const spanX = xOf(dispStart, yearMin, ppy);
    const spanW = Math.max(8, Math.round((dispEnd - dispStart) * ppy));
    if (spanW < 20) return;
    const lo = document.createElement('div');
    lo.className = 'tl-span-lbl-overlay';
    lo.dataset.nodeId = n.id;
    lo.style.cssText = `left:${spanX}px;top:${rowStartY[rowMap[n.id]]}px;width:${spanW}px;`;
    const lbl = document.createElement('span');
    lbl.className = 'span-lbl';
    lbl.textContent = n.title;
    lo.appendChild(lbl);
    container.appendChild(lo);
  });

  // Points: stagger is computed on ALL points (O(n)), but DOM created only for visible ones.
  const visiblePoints = cull
    ? points.filter(n => n.start_date >= viewYearMin && n.start_date <= viewYearMax)
    : points;
  visiblePoints.forEach(n => container.appendChild(makePointEl(n, ppy, yearMin, afterSpansY, stagger[n.id] || 0, ptMode)));

  // Render children of expanded spans in the SAME container, starting just below the pill header.
  // They share the parent's coordinate system so they appear at the correct time positions.
  // Only recurse into spans that overlap the viewport.
  for (const n of visibleSpans) {
    if (!TL.expanded.has(n.id)) continue;
    const childBaseY = rowStartY[rowMap[n.id]] + SPAN_H;

    if (TL.loading.has(n.id)) {
      const loadEl = document.createElement('div');
      const pillX = xOf(Math.max(n.start_date, yearMin), yearMin, ppy);
      loadEl.style.cssText = `position:absolute;left:${pillX}px;top:${childBaseY}px;` +
        `height:32px;color:var(--text-dim);font-size:0.7rem;padding:8px 12px;font-style:italic;`;
      loadEl.textContent = `${n.title} …`;
      container.appendChild(loadEl);
    } else {
      const childItems = (TL.childCache[n.id] || [])
        .filter(c => (IMP_INDEX[c.importance ?? 'moderate'] ?? 1) >= minImp);
      renderItemsInto(container, childItems, yearMin, yearMax, ppy, canvasW, childBaseY, n, viewYearMin, viewYearMax);
    }
  }
}

// Timeline row for a lane.
function buildGanttLaneRow(lane, yearMin, yearMax, ppy, h, canvasW, viewYearMin, viewYearMax) {
  const { items, loading } = lane;
  const row = document.createElement('div');
  row.className = 'gantt-lane-row';
  row.style.cssText = `height:${h}px;width:${canvasW}px;position:relative;box-sizing:border-box;`;

  if (loading) {
    const el = document.createElement('div');
    el.className = 'gantt-lane-loading';
    el.textContent = '…';
    row.appendChild(el);
    return row;
  }

  renderItemsInto(row, items, yearMin, yearMax, ppy, canvasW, 4, lane.parentSpan, viewYearMin, viewYearMax);
  attachLaneDelegation(row, lane.parentSpan);
  return row;
}

function buildGantt(nodes, existingGantt) {
  // ── Coordinate system ─────────────────────────────────────────────────────
  let dataMin = -1000, dataMax = 2000;
  if (nodes.length) {
    dataMin = Infinity; dataMax = -Infinity;
    for (const n of nodes) {
      if (n.start_date < dataMin) dataMin = n.start_date;
      const end = n.end_date ?? n.start_date;
      if (end > dataMax) dataMax = end;
    }
  }
  const fitMin  = DEFAULT_VIEW_START ?? Math.max(WORLD_YEAR_MIN, dataMin);
  const fitMax  = DEFAULT_VIEW_END   ?? Math.min(WORLD_YEAR_MAX, dataMax);
  const tlW     = Math.max(200, vpWidth() - GANTT_SIDEBAR_W);

  // Cap rendered canvas at 200K px (~2000 ticks max at 90px spacing).
  // The full world may be much larger; we window around the viewport center.
  const MAX_CANVAS_PX = 200_000;
  const worldRange    = WORLD_YEAR_MAX - WORLD_YEAR_MIN;

  let ppy, yearMin, yearMax;
  if (TL.levelPPY['root'] == null) {
    ppy = fitPPY(fitMin - 50, fitMax + 50, tlW);
  } else {
    ppy = TL.levelPPY['root'];
  }

  // Window the year range so the canvas stays within MAX_CANVAS_PX
  const maxYearRange = (MAX_CANVAS_PX - CANVAS_PAD * 2) / ppy;
  if (worldRange <= maxYearRange) {
    yearMin = WORLD_YEAR_MIN;
    yearMax = WORLD_YEAR_MAX;
  } else {
    let center;
    if (TL.viewAnchor) {
      center = TL.viewAnchor.year;
    } else if (TL.scrollLeft['root'] != null && TL.yearMin != null) {
      // Estimate center from saved scroll position
      const scroll = TL.scrollLeft['root'];
      center = TL.yearMin + (scroll + tlW / 2 - CANVAS_PAD) / ppy;
    } else {
      center = (fitMin + fitMax) / 2;
    }
    yearMin = center - maxYearRange / 2;
    yearMax = center + maxYearRange / 2;
    // Clamp to world bounds, extending the other end if possible
    if (yearMin < WORLD_YEAR_MIN) {
      yearMax = Math.min(WORLD_YEAR_MAX, WORLD_YEAR_MIN + maxYearRange);
      yearMin = WORLD_YEAR_MIN;
    } else if (yearMax > WORLD_YEAR_MAX) {
      yearMin = Math.max(WORLD_YEAR_MIN, WORLD_YEAR_MAX - maxYearRange);
      yearMax = WORLD_YEAR_MAX;
    }
  }
  TL.levelPPY['root'] = ppy; // store computed ppy for LOD status bar
  TL.yearMin = yearMin;       // store for zoom anchor calculation

  // Auto-fetch children for root spans (always visible, not gated by TL.expanded)
  for (const span of nodes.filter(n => n.type === 'span')) {
    if (!TL.childCache[span.id] && !TL.loading.has(span.id)) {
      TL.loading.add(span.id);
      tlFetch(span.id).then(() => { TL.loading.delete(span.id); renderWorld(); });
    }
  }

  const canvasW = Math.ceil((yearMax - yearMin) * ppy) + CANVAS_PAD * 2;

  // Compute visible year range for viewport culling (with 1-viewport buffer each side)
  const fallbackScroll = Math.max(0, ((DEFAULT_VIEW_START ?? dataMin) - yearMin) * ppy - CANVAS_PAD);
  const savedScroll = TL.viewAnchor
    ? (CANVAS_PAD + (TL.viewAnchor.year - yearMin) * ppy - TL.viewAnchor.vpOffset)
    : (TL.scrollLeft['root'] ?? fallbackScroll);
  const viewBuf     = tlW;  // 1 viewport width buffer
  const viewPxMin   = Math.max(0, savedScroll - viewBuf);
  const viewPxMax   = savedScroll + tlW + viewBuf;
  const viewYearMin = Math.max(yearMin, yearMin + (viewPxMin - CANVAS_PAD) / ppy);
  const viewYearMax = Math.min(yearMax, yearMin + (viewPxMax - CANVAS_PAD) / ppy);

  const lanes   = flattenLanes(nodes, ppy);

  // ── Build DOM ──────────────────────────────────────────────────────────────
  // When existingGantt is provided, update it in-place so .gantt-timeline
  // never leaves the DOM (preserves native scrollbar drag state).
  const reusing = !!existingGantt;
  const gantt = existingGantt || document.createElement('div');
  gantt.className = 'gantt';

  // Header: corner + ruler
  const head = document.createElement('div');
  head.className = 'gantt-head';
  const corner = document.createElement('div');
  corner.className = 'gantt-corner';
  corner.textContent = 'Timeline';
  const step = pickStep(yearMax - yearMin, ppy);
  const rulerVP = document.createElement('div');
  rulerVP.className = 'gantt-ruler-vp';
  rulerVP.appendChild(buildGanttRuler(yearMin, yearMax, ppy, canvasW, step));
  head.appendChild(corner);
  head.appendChild(rulerVP);

  // Body: labels sidebar + timeline
  const labelsEl = document.createElement('div');
  labelsEl.className = 'gantt-labels';

  const tlWrap = (reusing && gantt.querySelector('.gantt-timeline')) || document.createElement('div');
  tlWrap.className = 'gantt-timeline';
  // Abort previous scroll/sync listeners before attaching new ones
  if (tlWrap._scrollAC) tlWrap._scrollAC.abort();
  tlWrap._scrollAC = new AbortController();
  const _signal = tlWrap._scrollAC.signal;

  const track = document.createElement('div');
  track.className = 'gantt-track';
  track.style.width = canvasW + 'px';
  track.appendChild(buildGridLines(yearMin, yearMax, ppy, canvasW, step));

  if (lanes.length === 0 || (lanes.length === 1 && lanes[0].items.length === 0)) {
    const el = document.createElement('div');
    el.style.cssText = 'padding:16px;color:var(--text-dim);font-size:0.8rem;';
    el.textContent = 'No events at this zoom level';
    labelsEl.appendChild(el);
  } else {
    for (const lane of lanes) {
      const h = computeLaneH(lane.items, ppy, lane.parentSpan);
      labelsEl.appendChild(buildGanttLaneLabel(lane, h));
      track.appendChild(buildGanttLaneRow(lane, yearMin, yearMax, ppy, h, canvasW, viewYearMin, viewYearMax));
    }
  }

  if (reusing) {
    // Swap track directly — avoids zero-width intermediate state that replaceChildren causes,
    // so the browser never clamps scrollLeft to 0.
    const oldTrack = tlWrap.querySelector('.gantt-track');
    if (oldTrack) oldTrack.replaceWith(track);
    else tlWrap.appendChild(track);
    // Replace header and labels in-place
    const oldHead = gantt.querySelector('.gantt-head');
    if (oldHead) oldHead.replaceWith(head);
    const oldLabels = gantt.querySelector('.gantt-labels');
    if (oldLabels) oldLabels.replaceWith(labelsEl);
  } else {
    tlWrap.appendChild(track);
    const body = document.createElement('div');
    body.className = 'gantt-body';
    body.appendChild(labelsEl);
    body.appendChild(tlWrap);
    gantt.appendChild(head);
    gantt.appendChild(body);
  }

  // ── Scroll sync ────────────────────────────────────────────────────────────
  const defaultScroll = Math.max(0, ((DEFAULT_VIEW_START ?? dataMin) - yearMin) * ppy - CANVAS_PAD);
  let _syncing = false;

  labelsEl.addEventListener('scroll', () => {
    if (_syncing) return;
    _syncing = true;
    tlWrap.scrollTop = labelsEl.scrollTop;
    _syncing = false;
  }, { passive: true, signal: _signal });

  const isWindowed = worldRange > maxYearRange;

  // Store the pixel bounds of the culled region so scrolling past them triggers re-render
  const cullPxMin = viewPxMin;
  const cullPxMax = viewPxMax;

  tlWrap.addEventListener('scroll', () => {
    if (_syncing || !tlWrap.isConnected) return; // ignore events from detached DOM during teardown
    _syncing = true;
    rulerVP.scrollLeft      = tlWrap.scrollLeft;
    labelsEl.scrollTop      = tlWrap.scrollTop;
    TL.scrollLeft['root']   = tlWrap.scrollLeft;
    TL.ganttScrollTop       = tlWrap.scrollTop;
    _syncing = false;

    if (!TL._edgeRenderPending) {
      // Re-render if scrolled past the culled viewport region (items need to appear)
      const sl = tlWrap.scrollLeft;
      const pastCullLeft  = sl < cullPxMin;
      const pastCullRight = sl + tlW > cullPxMax;

      // When windowed, also re-render near canvas edges where more world exists
      let nearCanvasEdge = false;
      if (isWindowed) {
        const maxScroll = tlWrap.scrollWidth - tlWrap.clientWidth;
        const edgeBuf   = tlWrap.clientWidth * 2;
        nearCanvasEdge = (sl < edgeBuf && yearMin > WORLD_YEAR_MIN)
                      || (sl > maxScroll - edgeBuf && yearMax < WORLD_YEAR_MAX);
      }

      if (pastCullLeft || pastCullRight || nearCanvasEdge) {
        TL._edgeRenderPending = true;
        requestAnimationFrame(() => { TL._edgeRenderPending = false; renderWorld(); });
      }
    }
  }, { passive: true, signal: _signal });

  // Scroll is applied synchronously by the caller after appending to the DOM.
  gantt._applyScroll = () => {
    tlWrap.offsetHeight; // force layout so scrollLeft/scrollTop can be set against real dimensions
    if (TL.viewAnchor) {
      // Zoom: position the anchor year at the same viewport offset
      const anchorPx = CANVAS_PAD + (TL.viewAnchor.year - yearMin) * ppy;
      tlWrap.scrollLeft = Math.max(0, anchorPx - TL.viewAnchor.vpOffset);
      TL.viewAnchor = null; // consumed
    } else {
      tlWrap.scrollLeft = TL.scrollLeft['root'] ?? defaultScroll;
    }
    TL.scrollLeft['root']  = tlWrap.scrollLeft;  // normalize null/undefined → actual px
    rulerVP.scrollLeft     = tlWrap.scrollLeft;   // sync ruler
    tlWrap.scrollTop       = TL.ganttScrollTop;
    TL.ganttScrollTop      = tlWrap.scrollTop;    // normalize
  };

  // Arc overlay: highlight selected arc's nodes + draw connecting path
  gantt._renderArcOverlay = () => {
    // Remove previous overlay + highlights
    track.querySelector('.arc-overlay')?.remove();
    track.querySelectorAll('.arc-highlighted').forEach(el => el.classList.remove('arc-highlighted'));
    track.classList.remove('has-arc-highlight');

    const sel = TL.selectedArc;
    if (!sel || !sel.nodeIds.size) return;

    // Mark matching gantt elements with highlight class + CSS custom property for arc color
    const arcColor = sel.color;
    sel.nodeIds.forEach(nid => {
      track.querySelectorAll(`[data-node-id="${nid}"]`).forEach(el => {
        el.classList.add('arc-highlighted');
        el.style.setProperty('--arc-color', arcColor);
      });
    });

    // Dim non-highlighted nodes for contrast
    track.classList.add('has-arc-highlight');

    // Accumulate offsets up to the track element to get track-relative coords
    function posRelToTrack(el) {
      let x = 0, y = 0, cur = el;
      while (cur && cur !== track) {
        x += cur.offsetLeft;
        y += cur.offsetTop;
        cur = cur.offsetParent;
      }
      return { x, y };
    }

    // Draw connecting SVG path between consecutive arc nodes (by position in the timeline)
    const points = [];
    sel.nodeIds.forEach(nid => {
      const el = track.querySelector(`[data-node-id="${nid}"]`);
      if (!el) return;
      const pos = posRelToTrack(el);
      points.push({
        x: pos.x + el.offsetWidth / 2,
        y: pos.y + el.offsetHeight / 2,
      });
    });

    // Sort by x position so the path follows timeline order
    points.sort((a, b) => a.x - b.x);

    if (points.length < 2) return;

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.classList.add('arc-overlay');
    svg.setAttribute('width', canvasW);
    svg.setAttribute('height', track.offsetHeight || 600);
    svg.style.cssText = 'position:absolute;top:0;left:0;pointer-events:none;z-index:4;';

    // Connecting bezier path
    for (let i = 0; i < points.length - 1; i++) {
      const p1 = points[i], p2 = points[i + 1];
      const dx = p2.x - p1.x;
      const dy = p2.y - p1.y;
      const cpOff = Math.min(Math.abs(dx) * 0.35, 120);

      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d',
        `M ${p1.x} ${p1.y} C ${p1.x + cpOff} ${p1.y + dy * 0.1}, ${p2.x - cpOff} ${p2.y - dy * 0.1}, ${p2.x} ${p2.y}`);
      path.setAttribute('fill', 'none');
      path.setAttribute('stroke', arcColor);
      path.setAttribute('stroke-width', '2.5');
      path.setAttribute('stroke-dasharray', '6 4');
      path.setAttribute('opacity', '0.55');
      svg.appendChild(path);
    }

    // Dots at each node
    for (const p of points) {
      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      circle.setAttribute('cx', p.x);
      circle.setAttribute('cy', p.y);
      circle.setAttribute('r', '5');
      circle.setAttribute('fill', arcColor);
      circle.setAttribute('opacity', '0.85');
      svg.appendChild(circle);
    }

    track.appendChild(svg);
  };

  return gantt;
}
